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

    public function getType(string $key): string
    {
        if (!$this->exists($key)) {
            return 'none';
        }

        $value = $this->data[$key];

        if (is_string($value)) {
            return 'string';
        } elseif ($value instanceof RedisStream) {
            return 'stream';
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

    public function xadd(string $key, string $id, array $fields): string
    {
        // Get existing stream or create new one
        $stream = $this->getStream($key);
        if ($stream === null) {
            $stream = new RedisStream();
            $this->data[$key] = $stream;
        }

        return $stream->addEntry($id, $fields);
    }

    public function getStream(string $key): ?RedisStream
    {
        $value = $this->get($key);
        return ($value instanceof RedisStream) ? $value : null;
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

    public function xrange(string $key, string $start, string $end, ?int $count = null): array
    {
        $stream = $this->getStream($key);

        if ($stream === null) {
            // Return empty array if stream doesn't exist
            return [];
        }

        return $stream->range($start, $end, $count);
    }

    public function xread(array $streamKeys, array $ids, ?int $count = null): array
    {
        return RedisStream::read($streamKeys, $ids, $count, fn($key) => $this->getStream($key));
    }

    /**
     * Get all expiry data (for persistence purposes)
     */
    public function getAllExpiry(): array
    {
        return $this->expiry;
    }
}
