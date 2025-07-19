<?php

namespace Redis\RESP\Response;

class PsyncResponse implements RESPResponse
{
    private string $fullresyncMessage;
    private string $rdbContent;

    public function __construct(string $fullresyncMessage, string $rdbContent)
    {
        $this->fullresyncMessage = $fullresyncMessage;
        $this->rdbContent = $rdbContent;
    }

    public function serialize(): string
    {
        // First send the FULLRESYNC response using SimpleStringResponse
        $fullresyncResponse = new SimpleStringResponse($this->fullresyncMessage);

        // Then send the RDB file using RDBResponse
        $rdbResponse = new RDBResponse($this->rdbContent);

        return $fullresyncResponse->serialize() . $rdbResponse->serialize();
    }
}
