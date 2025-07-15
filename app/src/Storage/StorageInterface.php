<?php

namespace Redis\Storage;

interface StorageInterface
{
    public function set(string $key, mixed $value): bool;

    public function get(string $key): mixed;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    public function clear(): bool;
}
