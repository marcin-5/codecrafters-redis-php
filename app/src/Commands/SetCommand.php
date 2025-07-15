<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;

class SetCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'set';
    private const int MIN_ARGS_COUNT = 2;

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function execute(array $args): RESPResponse
    {
        if (count($args) < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        $key = $args[0];
        $value = $args[1];
        $optionalArgs = array_slice($args, 2);

        $parsedOptions = $this->parseOptions($optionalArgs);
        if ($parsedOptions instanceof RESPResponse) {
            return $parsedOptions;
        }

        $this->storage->set($key, $value, $parsedOptions['expiryMs'] ?? null);

        return ResponseFactory::ok();
    }

    /**
     * @param string[] $options
     * @return array{expiryMs?: int}|RESPResponse
     */
    private function parseOptions(array $options): array|RESPResponse
    {
        $parsed = [];
        $i = 0;
        $count = count($options);

        while ($i < $count) {
            $option = strtoupper($options[$i]);

            switch ($option) {
                case 'PX':
                    if (!isset($options[$i + 1])) {
                        return ResponseFactory::syntaxError();
                    }
                    $value = $options[$i + 1];
                    $expiryMs = (int)$value;
                    if ($expiryMs <= 0) {
                        return ResponseFactory::invalidExpireTime();
                    }
                    $parsed['expiryMs'] = $expiryMs;
                    $i += 2; // Consumed option and value
                    break;
                default:
                    return ResponseFactory::syntaxError();
            }
        }

        return $parsed;
    }
}
