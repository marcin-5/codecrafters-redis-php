<?php

namespace Redis\RESP\Response;

class SimpleStringResponse implements RESPResponse
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function serialize(): string
    {
        return "+{$this->value}\r\n";
    }
}
