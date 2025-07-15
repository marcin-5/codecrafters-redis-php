<?php

namespace Redis\RESP\Response;

class SimpleStringResponse implements RESPResponse
{
    private string $value {
        get {
            return $this->value;
        }
    }

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function serialize(): string
    {
        return "+{$this->value}\r\n";
    }

}
