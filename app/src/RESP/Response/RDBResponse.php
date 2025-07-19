<?php

namespace Redis\RESP\Response;

class RDBResponse implements RESPResponse
{
    private string $rdbData;

    public function __construct(string $rdbData)
    {
        $this->rdbData = $rdbData;
    }

    public function serialize(): string
    {
        $length = strlen($this->rdbData);
        return "\${$length}\r\n{$this->rdbData}";
    }

    public function getRdbData(): string
    {
        return $this->rdbData;
    }
}
