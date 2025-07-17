<?php

namespace Redis\Storage;

class StorageFactory
{
    public static function createStorage(array $config): StorageInterface
    {
        $inMemoryStorage = new InMemoryStorage();

        // Check if RDB persistence is configured
        if (isset($config['dir']) && isset($config['dbfilename'])) {
            $rdbPath = rtrim($config['dir'], '/') . '/' . $config['dbfilename'];
            return new PersistentStorage($inMemoryStorage, $rdbPath);
        }

        return $inMemoryStorage;
    }
}
