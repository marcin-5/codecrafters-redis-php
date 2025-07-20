<?php

namespace Redis\Storage;

interface StorageInterface
{
    public function set(string $key, mixed $value, ?int $expiryMs = null): bool;

    public function get(string $key): mixed;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    public function keys(): array;

    public function getType(string $key): string;

}
