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

            // Handle auto-generation with '*'
            if ($id === '*') {
                return $this->generateNextId($entryId);
            }

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
