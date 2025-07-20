<?php

namespace Redis\Storage;

use InvalidArgumentException;

class RedisStream
{
    private array $entries = [];
    private ?StreamEntryId $lastId = null;

    /**
     * Reads entries from multiple streams starting after the specified IDs.
     * This method handles the logic for XREAD command.
     *
     * @param array $streamKeys Array of stream keys
     * @param array $ids Array of IDs to read after (one for each stream key)
     * @param int|null $count Optional count limit
     * @param callable $getStreamCallback Callback to get stream by key
     * @return array Array of results in XREAD format
     */
    public static function read(array $streamKeys, array $ids, ?int $count, callable $getStreamCallback): array
    {
        $results = [];
        foreach ($streamKeys as $index => $streamKey) {
            $streamId = $ids[$index];
            $stream = $getStreamCallback($streamKey);
            if ($stream === null) {
                continue; // Skip non-existent streams
            }
            $streamEntries = $stream->readAfter($streamId, $count);
            if (!empty($streamEntries)) {
                $results[] = [$streamKey, \Redis\Utils\StreamResultFormatter::format($streamEntries)];
            }
        }
        return $results;
    }

    /**
     * Reads entries after the specified ID (exclusive).
     * Handles special '$' ID by using the last entry ID.
     *
     * @param string $afterId ID to read after (exclusive), or '$' for last entry
     * @param int|null $count Optional count limit
     * @return array Associative array of entry IDs to field-value maps
     */
    public function readAfter(string $afterId, ?int $count = null): array
    {
        $startId = $this->resolveReadStartId($afterId);
        if ($startId === null) {
            return [];
        }
        return $this->filterEntriesAfter($startId, $count);
    }

    /**
     * Resolves the start ID for reading, handling special '$' case.
     */
    private function resolveReadStartId(string $afterId): ?StreamEntryId
    {
        if ($afterId === '$') {
            return $this->lastId; // Can be null if stream is empty
        }
        return StreamEntryId::parse($afterId);
    }

    /**
     * Filters entries that come after the specified ID (exclusive).
     */
    private function filterEntriesAfter(StreamEntryId $startId, ?int $count): array
    {
        return $this->filterEntries(
            fn(StreamEntryId $entryId): bool => $entryId->isGreaterThan($startId),
            $count,
        );
    }

    /**
     * Common method to filter entries based on a predicate.
     */
    private function filterEntries(callable $predicate, ?int $count): array
    {
        $result = [];
        $counter = 0;
        foreach ($this->entries as $idStr => $fields) {
            $entryId = StreamEntryId::parse($idStr);
            if ($predicate($entryId)) {
                $result[$idStr] = $fields;
                $counter++;
                if ($count !== null && $counter >= $count) {
                    break;
                }
            }
        }
        return $result;
    }

    public function addEntry(string $id, array $fields): string
    {
        $finalId = $this->resolveEntryId($id);
        $this->entries[$finalId->toString()] = $fields;
        $this->lastId = $finalId;
        return $finalId->toString();
    }

    private function resolveEntryId(string $id): StreamEntryId
    {
        $entryId = StreamEntryId::parse($id);
        if ($entryId->isAutoSequence()) {
            // For '*' the timestamp is also auto-generated.
            // For '<ms>-*' the timestamp is explicit.
            $isTimestampAuto = ($id === '*');
            return $this->generateAutoSequenceId($entryId, $isTimestampAuto);
        }
        return $this->validateExplicitId($entryId);
    }

    private function generateAutoSequenceId(StreamEntryId $requestedId, bool $isTimestampAuto): StreamEntryId
    {
        if ($this->lastId === null) {
            // First entry. `0-0` is invalid, so for a timestamp of 0, the sequence must be at least 1.
            $sequence = ($requestedId->getMilliseconds() === 0) ? 1 : 0;
            return $requestedId->withSequence($sequence);
        }
        $requestedMilliseconds = $requestedId->getMilliseconds();
        $lastMilliseconds = $this->lastId->getMilliseconds();
        // If requested timestamp is greater than the last one, the new sequence starts at 0.
        if ($requestedMilliseconds > $lastMilliseconds) {
            return $requestedId->withSequence(0);
        }
        // If requested timestamp is the same, or if it's smaller but allowed to be updated (the '*' case),
        // we increment the sequence based on the last ID.
        if ($requestedMilliseconds === $lastMilliseconds || $isTimestampAuto) {
            return $this->lastId->incrementSequence();
        }
        // Otherwise, the requested timestamp is smaller than the last one for a '<ms>-*' ID, which is an error.
        throw new InvalidArgumentException(
            "ERR The ID specified in XADD is equal or smaller than the target stream top item",
        );
    }

    private function validateExplicitId(StreamEntryId $entryId): StreamEntryId
    {
        // Explicitly check for "0-0" as it has a specific error message.
        if ($entryId->equals(StreamEntryId::zero())) {
            throw new InvalidArgumentException(
                "ERR The ID specified in XADD must be greater than 0-0",
            );
        }
        // The new ID must be greater than the last ID, or "0-0" for an empty stream.
        $minValidId = $this->lastId ?? StreamEntryId::zero();
        if (!$entryId->isGreaterThan($minValidId)) {
            throw new InvalidArgumentException(
                "ERR The ID specified in XADD is equal or smaller than the target stream top item",
            );
        }
        return $entryId;
    }

    public function getEntries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return empty($this->entries);
    }

    public function getLastId(): ?StreamEntryId
    {
        return $this->lastId;
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
        return $this->filterEntriesInRange($startId, $endId, $count);
    }

    /**
     * Filters entries within the specified range.
     */
    private function filterEntriesInRange(StreamEntryId $startId, StreamEntryId $endId, ?int $count): array
    {
        return $this->filterEntries(
            fn(StreamEntryId $entryId): bool => $this->isEntryInRange($entryId, $startId, $endId),
            $count,
        );
    }

    /**
     * Checks if an entry is within the specified range (inclusive).
     */
    private function isEntryInRange(StreamEntryId $entryId, StreamEntryId $startId, StreamEntryId $endId): bool
    {
        return ($entryId->isGreaterThan($startId) || $entryId->equals($startId)) &&
            ($endId->isGreaterThan($entryId) || $entryId->equals($endId));
    }
}
