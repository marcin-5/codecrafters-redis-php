<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;

class IncrCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'incr';
    private const int MIN_ARGS_COUNT = 1;

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function execute(object $client, array $args): RESPResponse
    {
        if (count($args) < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        $key = $args[0];

        // Get the current value
        $currentValue = $this->storage->get($key);

        // If key doesn't exist, treat as 0
        if ($currentValue === null) {
            $newValue = 1;
        } else {
            // Validate that the current value is an integer or can be converted to one
            if (!is_numeric($currentValue)) {
                return ResponseFactory::error('ERR value is not an integer or out of range');
            }

            $intValue = (int)$currentValue;

            // Check if the string representation matches the integer representation
            // This ensures we don't accept values like "3.14" or "3abc"
            if ((string)$intValue !== $currentValue) {
                return ResponseFactory::error('ERR value is not an integer or out of range');
            }

            $newValue = $intValue + 1;
        }

        // Store the new value
        $this->storage->set($key, (string)$newValue);

        // Return the new value as an integer response
        return ResponseFactory::integer($newValue);
    }
}
