<?php

namespace Redis\Commands;

use Redis\RESP\Response\RESPResponse;

interface RedisCommand
{
    public function execute(array $args): RESPResponse;
}
