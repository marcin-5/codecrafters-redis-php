<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class ReplconfCommand implements RedisCommand
{
    public function execute(array $args): RESPResponse
    {
        // For now, we ignore all arguments and just return OK
        // In the future, we can handle different REPLCONF subcommands here

        if (empty($args)) {
            return ResponseFactory::wrongNumberOfArguments('replconf');
        }

        $subcommand = strtolower($args[0]);

        switch ($subcommand) {
            case 'listening-port':
                // Handle listening-port configuration
                echo "Replica listening on port: " . ($args[1] ?? 'unknown') . PHP_EOL;
                return ResponseFactory::ok();

            case 'capa':
                // Handle capabilities configuration
                echo "Replica capabilities: " . ($args[1] ?? 'unknown') . PHP_EOL;
                return ResponseFactory::ok();

            default:
                // For unknown subcommands, still return OK for now
                echo "Unknown REPLCONF subcommand: {$subcommand}" . PHP_EOL;
                return ResponseFactory::ok();
        }
    }
}
