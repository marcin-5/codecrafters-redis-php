<?php

namespace Redis\Storage\RDB;

class RDBPersistence
{
    private RDBFormat $formatter;
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->formatter = new RDBFormat();
        $this->filePath = $filePath;
    }

    public function save(array $data, array $expiry): bool
    {
        try {
            $rdbData = $this->formatter->serialize($data, $expiry);

            // Write atomically using temporary file
            $tempFile = $this->filePath . '.tmp';
            $result = file_put_contents($tempFile, $rdbData, LOCK_EX);

            if ($result === false) {
                return false;
            }

            // Atomic rename
            return rename($tempFile, $this->filePath);
        } catch (\Exception $e) {
            // Log error in production
            error_log("RDB save failed: " . $e->getMessage());
            return false;
        }
    }

    public function load(): array
    {
        if (!file_exists($this->filePath)) {
            return ['data' => [], 'expiry' => []];
        }

        try {
            $rdbData = file_get_contents($this->filePath);
            if ($rdbData === false) {
                return ['data' => [], 'expiry' => []];
            }

            $result = $this->formatter->deserialize($rdbData);

            // Clean up expired keys during load
            $currentTime = (int)(microtime(true) * 1000);
            $cleanData = [];
            $cleanExpiry = [];

            foreach ($result['data'] as $key => $value) {
                if (isset($result['expiry'][$key])) {
                    if ($result['expiry'][$key] > $currentTime) {
                        $cleanData[$key] = $value;
                        $cleanExpiry[$key] = $result['expiry'][$key];
                    }
                    // Skip expired keys
                } else {
                    $cleanData[$key] = $value;
                }
            }

            return ['data' => $cleanData, 'expiry' => $cleanExpiry];
        } catch (\Exception $e) {
            // Log error in production
            error_log("RDB load failed: " . $e->getMessage());
            return ['data' => [], 'expiry' => []];
        }
    }

    public function exists(): bool
    {
        return file_exists($this->filePath);
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
