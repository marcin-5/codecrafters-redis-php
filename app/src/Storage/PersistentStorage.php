<?php

namespace Redis\Storage;

use Redis\Storage\RDB\RDBPersistence;

class PersistentStorage implements StorageInterface
{
    private StorageInterface $storage;
    private RDBPersistence $rdb;
    private int $lastSaveTime;
    private int $changesSinceLastSave = 0;

    // Configuration
    private int $saveInterval = 60; // Save every 60 seconds
    private int $saveThreshold = 100; // Save after 100 changes

    public function __construct(StorageInterface $storage, string $rdbFilePath)
    {
        $this->storage = $storage;
        $this->rdb = new RDBPersistence($rdbFilePath);
        $this->lastSaveTime = time();

        // Load existing data on startup
        $this->loadFromRDB();
    }

    private function loadFromRDB(): void
    {
        $loaded = $this->rdb->load();

        // Clear existing data and load from RDB
        $this->storage->clear();

        foreach ($loaded['data'] as $key => $value) {
            $expiry = isset($loaded['expiry'][$key]) ? $loaded['expiry'][$key] : null;
            $this->storage->set($key, $value, $expiry);
        }

        // Reset change counter after loading
        $this->changesSinceLastSave = 0;
    }

    public function clear(): bool
    {
        $result = $this->storage->clear();

        if ($result) {
            $this->changesSinceLastSave++;
            $this->checkAutoSave();
        }

        return $result;
    }

    private function checkAutoSave(): void
    {
        $currentTime = time();
        $timeSinceLastSave = $currentTime - $this->lastSaveTime;

        // Save if enough time has passed OR enough changes have occurred
        if ($timeSinceLastSave >= $this->saveInterval ||
            $this->changesSinceLastSave >= $this->saveThreshold) {
            $this->saveToRDB();
        }
    }

    private function saveToRDB(): bool
    {
        // Extract data and expiry from the in-memory storage
        $data = $this->extractDataFromStorage();
        $expiry = $this->extractExpiryFromStorage();

        $result = $this->rdb->save($data, $expiry);

        if ($result) {
            $this->lastSaveTime = time();
            $this->changesSinceLastSave = 0;
        }

        return $result;
    }

    private function extractDataFromStorage(): array
    {
        // This is a bit tricky since we need to access private data
        // We'll use reflection or add a method to InMemoryStorage
        if ($this->storage instanceof InMemoryStorage) {
            return $this->getPrivateProperty($this->storage, 'data');
        }

        return [];
    }

    private function getPrivateProperty(object $object, string $property): array
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        return $property->getValue($object) ?? [];
    }

    private function extractExpiryFromStorage(): array
    {
        if ($this->storage instanceof InMemoryStorage) {
            return $this->getPrivateProperty($this->storage, 'expiry');
        }

        return [];
    }

    /**
     * Manually trigger RDB save
     */
    public function save(): bool
    {
        return $this->saveToRDB();
    }

    public function set(string $key, mixed $value, ?int $expiryMs = null): bool
    {
        $result = $this->storage->set($key, $value, $expiryMs);

        if ($result) {
            $this->changesSinceLastSave++;
            $this->checkAutoSave();
        }

        return $result;
    }

    public function get(string $key): mixed
    {
        return $this->storage->get($key);
    }

    public function delete(string $key): bool
    {
        $result = $this->storage->delete($key);

        if ($result) {
            $this->changesSinceLastSave++;
            $this->checkAutoSave();
        }

        return $result;
    }

    /**
     * Get save statistics
     */
    public function getSaveStats(): array
    {
        return [
            'last_save_time' => $this->lastSaveTime,
            'changes_since_last_save' => $this->changesSinceLastSave,
            'rdb_file_exists' => $this->rdb->exists(),
            'rdb_file_path' => $this->rdb->getFilePath()
        ];
    }

    public function exists(string $key): bool
    {
        return $this->storage->exists($key);
    }

    /**
     * Configure auto-save behavior
     */
    public function configureSave(int $interval = 60, int $threshold = 100): void
    {
        $this->saveInterval = $interval;
        $this->saveThreshold = $threshold;
    }

    public function keys(): array
    {
        return $this->storage->keys();
    }

    public function getType(string $key): string
    {
        return $this->storage->getType($key);
    }

    public function xadd(string $key, string $id, array $fields): string
    {
        return $this->storage->xadd($key, $id, $fields);
    }

    public function getStream(string $key): ?RedisStream
    {
        return $this->storage->getStream($key);
    }

    public function xrange(string $key, string $start, string $end, ?int $count = null): array
    {
        return $this->storage->xrange($key, $start, $end, $count);
    }

    public function xread(array $streamKeys, array $ids, ?int $count = null): array
    {
        return $this->storage->xread($streamKeys, $ids, $count);
    }
}
