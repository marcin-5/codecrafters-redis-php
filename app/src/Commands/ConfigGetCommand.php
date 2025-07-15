<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class ConfigGetCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'config get';
    private const int MIN_ARGS_COUNT = 1;

    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function execute(array $args): RESPResponse
    {
        if (count($args) < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        $result = [];

        foreach ($args as $key) {
            $key = strtolower($key);

            // Support pattern matching with wildcards
            if (str_contains($key, '*')) {
                $pattern = str_replace('*', '.*', preg_quote($key, '/'));
                foreach ($this->config as $configKey => $configValue) {
                    if (preg_match("/^{$pattern}$/", $configKey)) {
                        $result[] = $configKey;
                        $result[] = $configValue;
                    }
                }
            } else {
                // Exact match
                if (isset($this->config[$key])) {
                    $result[] = $key;
                    $result[] = $this->config[$key];
                }
            }
        }

        return ResponseFactory::array($result);
    }
}
