<?php

namespace Redis\RESP\Response;

class ErrorResponse implements RESPResponse
{
    private string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function serialize(): string
    {
        return "-{$this->message}\r\n";
    }
}
