<?php

namespace Redis\RESP\Parser;

use Exception;
use Redis\RESP\RESPParser;

class ArrayParser implements RESPTypeParser
{
    private RESPParser $parser;

    public function __construct(RESPParser $parser)
    {
        $this->parser = $parser;
    }

    public function canParse(string $data, int $offset): bool
    {
        return isset($data[$offset]) && $data[$offset] === '*';
    }

    /**
     * @throws Exception
     */
    public function parse(string $data, int &$offset): array
    {
        $offset++; // Skip the '*'
        $end = strpos($data, "\r\n", $offset);
        if ($end === false) {
            throw new Exception("Invalid array format");
        }

        $count = (int)substr($data, $offset, $end - $offset);
        $offset = $end + 2; // Move past \r\n

        $elements = [];
        for ($i = 0; $i < $count; $i++) {
            $elements[] = $this->parser->parseNext($data, $offset);
        }

        return $elements;
    }
}
