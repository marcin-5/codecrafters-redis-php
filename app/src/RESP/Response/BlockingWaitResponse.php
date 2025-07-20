<?php

namespace Redis\RESP\Response;

class BlockingWaitResponse implements RESPResponse
{
    public function serialize(): string
    {
        // This response should never be serialized as it's just a marker
        throw new \RuntimeException('BlockingWaitResponse should not be serialized');
    }
}
