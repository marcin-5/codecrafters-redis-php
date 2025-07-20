<?php

namespace Redis\Storage;

use InvalidArgumentException;

class RedisStream
{
    private array $entries = [];
    private ?StreamEntryId $lastId = null;

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
}
