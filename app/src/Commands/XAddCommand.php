<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;

class XAddCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'xadd';
    private const int MIN_ARGS_COUNT = 4; // stream_key, id, field, value

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function execute(object $client, array $args): RESPResponse
    {
        $argCount = count($args);

        if ($argCount < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        // Check if we have an even number of field-value pairs
        if (($argCount - 2) % 2 !== 0) {
            return ResponseFactory::error("ERR wrong number of arguments for XADD");
        }

        $streamKey = $args[0];
        $id = $args[1];

        // Validate that we have at least one field-value pair
        if ($argCount === 2) {
            return ResponseFactory::error("ERR wrong number of arguments for 'xadd' command");
        }

        // Parse field-value pairs
        $fields = [];
        for ($i = 2; $i < $argCount; $i += 2) {
            $field = $args[$i];
            $value = $args[$i + 1];
            $fields[$field] = $value;
        }

        try {
            $entryId = $this->storage->xadd($streamKey, $id, $fields);

            return ResponseFactory::string($entryId);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::error($e->getMessage());
        } catch (\Exception $e) {
            return ResponseFactory::error("ERR " . $e->getMessage());
        }
    }
}
