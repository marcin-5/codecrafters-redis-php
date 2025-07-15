<?php

namespace Redis\RESP\Response;

class IntegerResponse implements RESPResponse
{
    private int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function serialize(): string
    {
        return ":{$this->value}\r\n";
    }
}
