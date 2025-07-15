<?php

namespace Redis\RESP\Parser;

use Exception;

class SimpleStringParser implements RESPTypeParser
{
    public function canParse(string $data, int $offset): bool
    {
        return isset($data[$offset]) && $data[$offset] === '+';
    }

    /**
     * @throws Exception
     */
    public function parse(string $data, int &$offset): string
    {
        $offset++; // Skip the '+'
        $end = strpos($data, "\r\n", $offset);
        if ($end === false) {
            throw new Exception("Invalid simple string format");
        }

        $value = substr($data, $offset, $end - $offset);
        $offset = $end + 2; // Move past \r\n
        return $value;
    }
}
