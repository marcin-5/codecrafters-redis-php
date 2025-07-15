<?php

namespace Redis\RESP;

use Exception;
use Redis\RESP\Parser\ArrayParser;
use Redis\RESP\Parser\BulkStringParser;
use Redis\RESP\Parser\SimpleStringParser;

class RESPParser
{
    private array $parsers = [];

    public function __construct()
    {
        $this->parsers[] = new SimpleStringParser();
        $this->parsers[] = new BulkStringParser();
        $this->parsers[] = new ArrayParser($this); // Pass self for recursive parsing
    }

    /**
     * @throws Exception
     */
    public function parseNext(string $data, int &$offset): mixed
    {
        foreach ($this->parsers as $parser) {
            if ($parser->canParse($data, $offset)) {
                return $parser->parse($data, $offset);
            }
        }

        throw new Exception("Unknown RESP type at offset $offset");
    }

    /**
     * @throws Exception
     */
    public function parse(string $data): mixed
    {
        $offset = 0;
        return $this->parseNext($data, $offset);
    }
}
