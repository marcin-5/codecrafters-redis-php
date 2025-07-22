<?php

namespace Redis\Storage;

use Redis\Utils\StreamResultFormatter;

class RedisStream
{
    private array $entries = [];
    private ?StreamEntryId $lastId = null;

    /**
     * Reads entries from multiple streams, applying a single count limit across all streams.
     * This method correctly handles the logic for the XREAD command with a COUNT option.
     *
     * @param array $streamKeys Array of stream keys.
     * @param array $ids Array of IDs to read after (one for each stream key).
     * @param int|null $count Optional total count limit for entries returned.
     * @param callable(string): ?RedisStream $getStreamCallback Callback to get a stream by key.
     * @return array Array of results in XREAD format.
     */
    public static function read(array $streamKeys, array $ids, ?int $count, callable $getStreamCallback): array
    {
        if ($count === 0) {
            return [];
        }
        $allEntries = [];
        foreach ($streamKeys as $index => $streamKey) {
            $stream = $getStreamCallback($streamKey);
            if ($stream === null) {
                continue; // Skip non-existent streams
            }
            // Fetch entries from each stream. Fetching up to 'count' is a reasonable heuristic.
            $streamEntries = $stream->readAfter($ids[$index], $count);
            foreach ($streamEntries as $id => $fields) {
                $allEntries[] = ['streamKey' => $streamKey, 'id' => $id, 'fields' => $fields];
            }
        }
        if (empty($allEntries)) {
            return [];
        }
        // Sort all collected entries by ID to respect stream ordering.
        usort($allEntries, static fn($a, $b) => strcmp($a['id'], $b['id']));
        // Apply the global count limit.
        $limitedEntries = ($count !== null) ? array_slice($allEntries, 0, $count) : $allEntries;
        // Group by stream key to format the result.
        $groupedByStream = [];
        foreach ($limitedEntries as $entry) {
            $groupedByStream[$entry['streamKey']][$entry['id']] = $entry['fields'];
        }
        // Build final result set, preserving original stream key order.
        $results = [];
        foreach ($streamKeys as $streamKey) {
            if (isset($groupedByStream[$streamKey])) {
                $results[] = [$streamKey, StreamResultFormatter::format($groupedByStream[$streamKey])];
            }
        }
        return $results;
    }

    /**
     * Reads entries after the specified ID (exclusive), optimized for sorted entry IDs.
     *
     * @param string $afterId ID to read after (exclusive), or '$' for the last entry.
     * @param int|null $count Optional count limit.
     * @return array Associative array of entry IDs to field-value maps.
     */
    public function readAfter(string $afterId, ?int $count = null): array
    {
        $startId = $this->resolveReadStartId($afterId);
        if ($startId === null) {
            return [];
        }
        return $this->findEntriesAfter($startId, $count);
    }

    /**
     * Resolves the start ID for reading, handling the special '$' case.
     */
    private function resolveReadStartId(string $afterId): ?StreamEntryId
    {
        if ($afterId === '$') {
            return $this->lastId;
        }
        return StreamEntryId::parse($afterId);
    }

    /**
     * Finds entries with IDs greater than the given start ID using a binary search.
     * This is efficient but assumes that the keys of $this->entries are sorted.
     */
    private function findEntriesAfter(StreamEntryId $startId, ?int $count): array
    {
        $keys = array_keys($this->entries);
        $startIndex = $this->findFirstEntryIndex($keys, $startId, false);
        if ($startIndex === -1) {
            return [];
        }
        $slicedKeys = array_slice($keys, $startIndex, $count);
        $result = [];
        foreach ($slicedKeys as $key) {
            $result[$key] = $this->entries[$key];
        }
        return $result;
    }

    /**
     * Performs a binary search on ID strings to find the first index matching the specified criteria.
     *
     * @param string[] $idStrings
     * @param StreamEntryId $targetId
     * @param bool $inclusive If true, finds >= target. If false, finds > target.
     * @return int
     */
    private function findFirstEntryIndex(array $idStrings, StreamEntryId $targetId, bool $inclusive): int
    {
        $low = 0;
        $high = count($idStrings) - 1;
        $ans = -1;

        while ($low <= $high) {
            $mid = (int)(($low + $high) / 2);
            $midId = StreamEntryId::parse($idStrings[$mid]);

            $isGreater = $midId->isGreaterThan($targetId);
            if ($inclusive) {
                $match = $isGreater || $midId->equals($targetId);
            } else {
                $match = $isGreater;
            }

            if ($match) {
                $ans = $mid;
                $high = $mid - 1; // Try to find an earlier one
            } else {
                $low = $mid + 1; // Look in the right half
            }
        }

        return $ans;
    }

    /**
     * Retrieves a range of entries from the stream.
     *
     * @param string $start Start ID (inclusive)
     * @param string $end End ID (inclusive)
     * @param int|null $count Optional count limit
     * @return array Associative array of entry IDs to field-value maps
     */
    public function range(string $start, string $end, ?int $count = null): array
    {
        $startId = StreamEntryId::parse($start);
        $endId = StreamEntryId::parse($end);

        $keys = array_keys($this->entries);
        $startIndex = $this->findFirstEntryIndex($keys, $startId, true);

        if ($startIndex === -1) {
            return [];
        }

        // Find the index of the first entry with an ID greater than the end ID.
        // This marks the upper boundary of our range (exclusive).
        $endBoundaryIndex = $this->findFirstEntryIndex($keys, $endId, false);

        $length = 0;
        if ($endBoundaryIndex !== -1) {
            // If a boundary is found, the length is the difference between the indices.
            $length = $endBoundaryIndex - $startIndex;
        } else {
            // If no boundary is found, all entries from startIndex to the end are included.
            $length = count($keys) - $startIndex;
        }

        if ($length <= 0) {
            return [];
        }

        // Apply the count limit if provided.
        if ($count !== null) {
            $length = min($length, $count);
        }

        $entryKeys = array_slice($keys, $startIndex, $length);

        $result = [];
        foreach ($entryKeys as $key) {
            $result[$key] = $this->entries[$key];
        }

        return $result;
    }

    public function addEntry(string $id, array $fields): string
    {
        $finalId = $this->resolveEntryId($id);
        $this->entries[$finalId->toString()] = $fields;
        $this->lastId = $finalId;
        // This implementation relies on entries being added with monotonically increasing IDs
        // to keep the $this->entries array sorted by key for efficient reads.
        return $finalId->toString();
    }

    /**
     * Parses the input ID, validates it, and finalizes it if auto-generation is required.
     */
    private function resolveEntryId(string $id): StreamEntryId
    {
        if ($id === '0-0') {
            throw new \InvalidArgumentException('ERR The ID specified in XADD must be greater than 0-0');
        }

        $parsedId = StreamEntryId::parse($id);

        if ($parsedId->isAutoSequence()) {
            return $this->finalizeAutoId($parsedId);
        }

        if ($this->lastId !== null && !$parsedId->isGreaterThan($this->lastId)) {
            throw new \InvalidArgumentException(
                'ERR The ID specified in XADD is equal or smaller than the target stream top item',
            );
        }

        return $parsedId;
    }

    /**
     * Generates the final StreamEntryId for an ID that requires an auto-generated sequence number.
     */
    private function finalizeAutoId(StreamEntryId $templateId): StreamEntryId
    {
        $timestamp = $templateId->getMilliseconds();
        if ($this->lastId !== null && $this->lastId->getMilliseconds() > $timestamp) {
            throw new \InvalidArgumentException(
                'ERR The ID specified in XADD is smaller than the master instance\'s time',
            );
        }
        if ($this->lastId !== null && $this->lastId->getMilliseconds() === $timestamp) {
            return $this->lastId->incrementSequence();
        }
        return new StreamEntryId($timestamp, $timestamp === 0 ? 1 : 0);
    }

    public function getLastId(): ?StreamEntryId
    {
        return $this->lastId;
    }

}
