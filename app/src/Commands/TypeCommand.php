<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;

class TypeCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'type';
    private const int MIN_ARGS_COUNT = 1;

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function execute(array $args): RESPResponse
    {
        if (count($args) < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        $key = $args[0];
        $type = $this->storage->getType($key);

        // Redis TYPE command returns a simple string response
        return ResponseFactory::simpleString($type);
    }
}
