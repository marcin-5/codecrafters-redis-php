<?php

namespace Redis\Storage;

class RedisStream
{
    private array $entries = [];
    private ?StreamEntryId $lastId = null;

    public function addEntry(string $id, array $fields): string
    {
        // Validate and potentially auto-generate ID
        $finalId = $this->validateAndProcessId($id);

        $this->entries[$finalId->toString()] = $fields;
        $this->lastId = $finalId;

        return $finalId->toString();
    }

    private function validateAndProcessId(string $id): StreamEntryId
    {
        try {
            $entryId = StreamEntryId::parse($id);

            // Handle full auto-generation with '*'
            if ($id === '*') {
                return $this->generateNextId($entryId);
            }

            // Handle partial auto-generation with '<milliseconds>-*'
            if ($entryId->isAutoSequence()) {
                return $this->generateSequenceForTimestamp($entryId);
            }

            // Handle explicit ID validation
            return $this->validateExplicitId($entryId);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        }
    }

    private function generateNextId(StreamEntryId $baseId): StreamEntryId
    {
        if ($this->lastId === null) {
            // First entry - use the generated timestamp with sequence 0
            return $baseId;
        }

        // If the timestamp is the same as the last entry, increment sequence
        if ($baseId->getMilliseconds() === $this->lastId->getMilliseconds()) {
            return new StreamEntryId(
                $baseId->getMilliseconds(),
                $this->lastId->getSequence() + 1,
            );
        }

        // If timestamp is greater, use sequence 0
        if ($baseId->getMilliseconds() > $this->lastId->getMilliseconds()) {
            return new StreamEntryId($baseId->getMilliseconds(), 0);
        }

        // If timestamp is smaller, use last timestamp with incremented sequence
        return new StreamEntryId(
            $this->lastId->getMilliseconds(),
            $this->lastId->getSequence() + 1,
        );
    }

    private function generateSequenceForTimestamp(StreamEntryId $entryId): StreamEntryId
    {
        $milliseconds = $entryId->getMilliseconds();

        if ($this->lastId === null) {
            // First entry - for 0-*, we need to use at least 0-1 (since 0-0 is not allowed)
            if ($milliseconds === 0) {
                return $entryId->withSequence(1);
            }
            // For any other timestamp, use sequence 0
            return $entryId->withSequence(0);
        }

        // If the timestamp is the same as the last entry, increment sequence
        if ($milliseconds === $this->lastId->getMilliseconds()) {
            return $entryId->withSequence($this->lastId->getSequence() + 1);
        }

        // If timestamp is greater than last entry, use sequence 0
        if ($milliseconds > $this->lastId->getMilliseconds()) {
            return $entryId->withSequence(0);
        }

        // If timestamp is smaller than last entry, this is an error
        throw new \InvalidArgumentException(
            "ERR The ID specified in XADD is equal or smaller than the target stream top item",
        );
    }

    private function validateExplicitId(StreamEntryId $entryId): StreamEntryId
    {
        // Special case: check for 0-0 specifically
        $zeroId = StreamEntryId::zero();
        if ($entryId->equals($zeroId)) {
            throw new \InvalidArgumentException(
                "ERR The ID specified in XADD must be greater than 0-0",
            );
        }

        // Validate that the ID is greater than the last ID (if stream has entries)
        if ($this->lastId !== null) {
            if (!$entryId->isGreaterThan($this->lastId)) {
                throw new \InvalidArgumentException(
                    "ERR The ID specified in XADD is equal or smaller than the target stream top item",
                );
            }
        } else {
            // First entry - must be greater than 0-0 (already checked above)
            if (!$entryId->isGreaterThan($zeroId)) {
                throw new \InvalidArgumentException(
                    "ERR The ID specified in XADD must be greater than 0-0",
                );
            }
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
