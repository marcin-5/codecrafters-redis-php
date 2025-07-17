<?php

namespace Redis\Storage\RDB;

class RDBFormat
{
    // RDB Version and opcodes based on Redis RDB format
    const int REDIS_RDB_VERSION = 11;
    const int REDIS_RDB_OPCODE_AUXFIELD = 0xFA;
    const int REDIS_RDB_OPCODE_RESIZEDB = 0xFB;
    const int REDIS_RDB_OPCODE_EXPIRETIME_MS = 0xFC;
    const int REDIS_RDB_OPCODE_SELECTDB = 0xFE;
    const int REDIS_RDB_OPCODE_EOF = 0xFF;
    const int REDIS_RDB_TYPE_STRING = 0x00;


    public function serialize(array $data, array $expiry): string
    {
        $rdb = '';

        // RDB file header: "REDIS" + version as ASCII
        $rdb .= "REDIS";
        $rdb .= sprintf('%04d', self::REDIS_RDB_VERSION); // 4-character ASCII version

        // Select database 0
        $rdb .= chr(self::REDIS_RDB_OPCODE_SELECTDB);
        $rdb .= $this->encodeLength(0);

        // Encode key-value pairs
        foreach ($data as $key => $value) {
            // Handle expiry if present
            if (isset($expiry[$key])) {
                $rdb .= chr(self::REDIS_RDB_OPCODE_EXPIRETIME_MS);
                $rdb .= pack('P', $expiry[$key]); // 8-byte little-endian timestamp
            }

            // Encode key-value pair as string type
            $rdb .= chr(self::REDIS_RDB_TYPE_STRING);
            $rdb .= $this->encodeString($key);
            $rdb .= $this->encodeString($value);
        }

        // End of RDB file
        $rdb .= chr(self::REDIS_RDB_OPCODE_EOF);

        // Add CRC64 checksum (8 bytes) - simplified version
        $rdb .= $this->calculateChecksum($rdb);

        return $rdb;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x40) {
            // 6-bit length
            return chr($length);
        } elseif ($length < 0x4000) {
            // 14-bit length
            return chr(0x40 | ($length >> 8)) . chr($length & 0xFF);
        } else {
            // 32-bit length
            return chr(0x80) . pack('V', $length);
        }
    }

    private function encodeString(string $str): string
    {
        $length = strlen($str);
        return $this->encodeLength($length) . $str;
    }

//    private function calculateChecksum(string $data): string
//    {
//        // Simplified CRC64 - in production, use proper CRC64 implementation
//        $crc = crc32($data);
//        return pack('V', $crc) . pack('V', 0); // 8-byte checksum (simplified)
//    }

    private function calculateChecksum(string $data): string
    {
        // Real CRC64 implementation (complex)
        $crc = 0xFFFFFFFFFFFFFFFF; // 64-bit initial value
        $polynomial = 0x42F0E1EBA9EA3693; // Redis polynomial

        for ($i = 0; $i < strlen($data); $i++) {
            $byte = ord($data[$i]);
            $crc ^= $byte;

            for ($j = 0; $j < 8; $j++) {
                if ($crc & 1) {
                    $crc = ($crc >> 1) ^ $polynomial;
                } else {
                    $crc >>= 1;
                }
            }
        }

        return pack('P', $crc ^ 0xFFFFFFFFFFFFFFFF); // 8-byte little-endian
    }

    public function deserialize(string $rdbData): array
    {
        $offset = 0;
        $data = [];
        $expiry = [];

        // Check header
        if (substr($rdbData, 0, 5) !== 'REDIS') {
            throw new \InvalidArgumentException('Invalid RDB file: missing REDIS header');
        }
        $offset += 5;

        // Read version as ASCII string, not binary
        $versionString = substr($rdbData, $offset, 4);
        $offset += 4;

        // Convert ASCII version to integer
        $version = (int)$versionString;

        if ($version > self::REDIS_RDB_VERSION) {
            throw new \InvalidArgumentException("Unsupported RDB version: $version");
        }

        // Parse RDB content
        while ($offset < strlen($rdbData)) {
            $opcode = ord($rdbData[$offset]);
            $offset++;

            switch ($opcode) {
                case 0xFA: // Auxiliary field
                    // Read key-value pair for auxiliary data (skip it for now)
                    $this->decodeString($rdbData, $offset); // key
                    $this->decodeString($rdbData, $offset); // value
                    break;

                case 0xFB: // Hash table size information
                    // Read hash table size info (skip it for now)
                    $this->decodeLength($rdbData, $offset); // hash table size (total key-value pairs)
                    $this->decodeLength($rdbData, $offset); // expire hash table size (keys with expiry)
                    break;

                case self::REDIS_RDB_OPCODE_SELECTDB:
                    // Skip database selection for now (assuming DB 0)
                    $this->decodeLength($rdbData, $offset);
                    break;

                case self::REDIS_RDB_OPCODE_EXPIRETIME_MS:
                    // Read expiry timestamp
                    $expiryTimestamp = unpack('P', substr($rdbData, $offset, 8))[1];
                    $offset += 8;

                    // Read the actual key-value pair
                    $type = ord($rdbData[$offset]);
                    $offset++;

                    if ($type === self::REDIS_RDB_TYPE_STRING) {
                        $key = $this->decodeString($rdbData, $offset);
                        $value = $this->decodeString($rdbData, $offset);

                        $data[$key] = $value;
                        $expiry[$key] = $expiryTimestamp;
                    }
                    break;

                case self::REDIS_RDB_TYPE_STRING:
                    // Regular string key-value pair
                    $key = $this->decodeString($rdbData, $offset);
                    $value = $this->decodeString($rdbData, $offset);
                    $data[$key] = $value;
                    break;

                case self::REDIS_RDB_OPCODE_EOF:
                    // End of file reached
                    break 2;

                default:
                    throw new \InvalidArgumentException("Unknown RDB opcode: $opcode");
            }
        }

        return ['data' => $data, 'expiry' => $expiry];
    }

    private function decodeString(string $data, int &$offset): string
    {
        $first = ord($data[$offset]);

        // Check if this is a special encoding
        if (($first & 0xC0) === 0xC0) {
            $offset++;
            $encodingType = $first & 0x3F;

            switch ($encodingType) {
                case 0: // 8-bit integer
                    $value = ord($data[$offset]);
                    $offset++;
                    return (string)$value;

                case 1: // 16-bit integer
                    $value = unpack('v', substr($data, $offset, 2))[1];
                    $offset += 2;
                    return (string)$value;

                case 2: // 32-bit integer
                    $value = unpack('V', substr($data, $offset, 4))[1];
                    $offset += 4;
                    return (string)$value;

                default:
                    throw new \InvalidArgumentException("Unknown string encoding: $encodingType");
            }
        }

        // Regular length-prefixed string
        $length = $this->decodeLength($data, $offset);
        $str = substr($data, $offset, $length);
        $offset += $length;
        return $str;
    }

    private function decodeLength(string $data, int &$offset): int
    {
        $first = ord($data[$offset]);
        $offset++;

        if (($first & 0xC0) === 0x00) {
            // 6-bit length
            return $first & 0x3F;
        } elseif (($first & 0xC0) === 0x40) {
            // 14-bit length
            $second = ord($data[$offset]);
            $offset++;
            return (($first & 0x3F) << 8) | $second;
        } elseif (($first & 0xC0) === 0x80) {
            // 32-bit length
            $length = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            return $length;
        } else {
            throw new \InvalidArgumentException('Invalid length encoding');
        }
    }
}
