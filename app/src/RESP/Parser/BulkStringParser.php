<?php

namespace Redis\RESP\Parser;

use Exception;

class BulkStringParser implements RESPTypeParser
{
    public function canParse(string $data, int $offset): bool
    {
        return isset($data[$offset]) && $data[$offset] === '$';
    }

    /**
     * @throws Exception
     */
    public function parse(string $data, int &$offset): ?string
    {
        $offset++; // Skip the '$'
        $end = strpos($data, "\r\n", $offset);
        if ($end === false) {
            throw new Exception("Invalid bulk string format");
        }

        $length = (int)substr($data, $offset, $end - $offset);
        $offset = $end + 2; // Move past \r\n

        if ($length === -1) {
            return null; // Null bulk string
        }

        if ($length === 0) {
            $offset += 2; // Skip empty string's \r\n
            return '';
        }

        $value = substr($data, $offset, $length);
        $offset += $length + 2; // Move past string and \r\n
        return $value;
    }
}
