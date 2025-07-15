<?php

namespace Redis\Storage;

class InMemoryStorage implements StorageInterface
{
    private array $data = [];

    public function set(string $key, mixed $value): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): bool
    {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
            return true;
        }
        return false;
    }

    public function clear(): bool
    {
        $this->data = [];
        return true;
    }
}
