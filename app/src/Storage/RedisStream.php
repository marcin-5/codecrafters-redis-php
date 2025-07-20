<?php

namespace Redis\Storage;

class RedisStream
{
    private array $entries = [];

    public function addEntry(string $id, array $fields): string
    {
        // Validate and potentially auto-generate ID
        $finalId = $this->validateAndProcessId($id);

        $this->entries[$finalId] = $fields;

        return $finalId;
    }

    private function validateAndProcessId(string $id): string
    {
        // For now, we'll accept the ID as provided
        return $id;
    }

    public function getEntries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return empty($this->entries);
    }
}
