<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class PingCommand implements RedisCommand
{
    public function execute(array $args): RESPResponse
    {
        if (empty($args)) {
            return ResponseFactory::pong();
        }

        // PING with message - return the message as bulk string
        return ResponseFactory::string($args[0]);
    }
}
