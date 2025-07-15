<?php

namespace Redis\RESP\Parser;

interface RESPTypeParser
{
    public function canParse(string $data, int $offset): bool;

    public function parse(string $data, int &$offset): mixed;
}
