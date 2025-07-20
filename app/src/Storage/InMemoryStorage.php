<?php

namespace Redis\Storage;

class InMemoryStorage implements StorageInterface
{
    private array $data = [];
    private array $expiry = []; // Store expiry timestamps

    public function set(string $key, mixed $value, ?int $expiryMs = null): bool
    {
        $this->data[$key] = $value;

        if ($expiryMs !== null) {
            $this->expiry[$key] = (int)(microtime(true) * 1000) + $expiryMs;
        } else {
            // Remove any existing expiry if no new expiry is set
            unset($this->expiry[$key]);
        }

        return true;
    }

    public function get(string $key): mixed
    {
        // Check if key has expired
        if ($this->hasExpired($key)) {
            $this->delete($key);
            return null;
        }

        return $this->data[$key] ?? null;
    }

    private function hasExpired(string $key): bool
    {
        if (!isset($this->expiry[$key])) {
            return false;
        }

        $currentTime = (int)(microtime(true) * 1000);
        return $currentTime >= $this->expiry[$key];
    }

    public function delete(string $key): bool
    {
        $existed = isset($this->data[$key]);

        if ($existed) {
            unset($this->data[$key]);
        }

        // Also remove expiry
        unset($this->expiry[$key]);

        return $existed;
    }

    public function clear(): bool
    {
        $this->data = [];
        $this->expiry = [];
        return true;
    }

    public function keys(): array
    {
        // Lazily purge expired keys
        foreach (array_keys($this->expiry) as $key) {
            if ($this->hasExpired($key)) {
                $this->delete($key);
            }
        }

        return array_keys($this->data);
    }

    public function getType(string $key): string
    {
        if (!$this->exists($key)) {
            return 'none';
        }

        $value = $this->data[$key];

        if (is_string($value)) {
            return 'string';
        }

        return '';
    }

    public function exists(string $key): bool
    {
        if ($this->hasExpired($key)) {
            $this->delete($key);
            return false;
        }

        return array_key_exists($key, $this->data);
    }

    /**
     * Get all expiry data (for persistence purposes)
     */
    public function getAllExpiry(): array
    {
        return $this->expiry;
    }
}
