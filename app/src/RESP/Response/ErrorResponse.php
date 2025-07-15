<?php

namespace Redis\RESP\Response;

class ErrorResponse implements RESPResponse
{
    private string $message {
        get {
            return $this->message;
        }
    }

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function serialize(): string
    {
        return "-{$this->message}\r\n";
    }

}
