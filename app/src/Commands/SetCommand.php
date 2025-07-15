<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;

class SetCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'set';
    private const int MIN_ARGS_COUNT = 2;

    public function __construct(private StorageInterface $storage)
    {
    }

    public function execute(array $args): RESPResponse
    {
        if (count($args) < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        $key = $args[0];
        $value = $args[1];

        $this->storage->set($key, $value);
        return ResponseFactory::ok();
    }
}
