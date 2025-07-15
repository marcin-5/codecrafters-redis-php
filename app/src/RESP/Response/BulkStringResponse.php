<?php

namespace Redis\RESP\Response;

class BulkStringResponse implements RESPResponse
{
    private ?string $value;

    public function __construct(?string $value)
    {
        $this->value = $value;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function serialize(): string
    {
        if ($this->value === null) {
            return "$-1\r\n";
        }

        $length = strlen($this->value);
        return "\${$length}\r\n{$this->value}\r\n";
    }
}
