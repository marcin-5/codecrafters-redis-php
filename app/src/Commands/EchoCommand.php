<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class EchoCommand implements RedisCommand
{
    public function execute(object $client, array $args): RESPResponse
    {
        if (empty($args)) {
            return ResponseFactory::wrongNumberOfArguments('echo');
        }

        return ResponseFactory::string($args[0]);
    }
}
