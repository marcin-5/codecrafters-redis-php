<?php

namespace Redis\Transaction;

use Redis\Commands\RedisCommand;

class CommandQueueNode
{
    public function __construct(
        public readonly string $commandName,
        public readonly array $args,
        public readonly RedisCommand $command,
        public ?CommandQueueNode $next = null,
    ) {
    }
}
