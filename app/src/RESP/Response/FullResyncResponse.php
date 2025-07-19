<?php

namespace Redis\RESP\Response;

class FullResyncResponse implements RESPResponse
{
    private string $replId;
    private int $replOffset;
    private string $rdbData;

    public function __construct(string $replId, int $replOffset, string $rdbData)
    {
        $this->replId = $replId;
        $this->replOffset = $replOffset;
        $this->rdbData = $rdbData;
    }

    public function serialize(): string
    {
        // First send the FULLRESYNC response
        $fullresyncMessage = "FULLRESYNC {$this->replId} {$this->replOffset}";
        $fullresyncResponse = new SimpleStringResponse($fullresyncMessage);

        // Then send the RDB file in bulk string format (without trailing \r\n)
        $rdbLength = strlen($this->rdbData);

        return $fullresyncResponse->serialize() . "\${$rdbLength}\r\n{$this->rdbData}";
    }

    public function getRdbData(): string
    {
        return $this->rdbData;
    }

    public function getReplId(): string
    {
        return $this->replId;
    }

    public function getReplOffset(): int
    {
        return $this->replOffset;
    }
}
