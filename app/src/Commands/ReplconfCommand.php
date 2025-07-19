<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class ReplconfCommand implements RedisCommand
{
    public function execute(array $args): RESPResponse
    {
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

            case 'getack':
                // Handle GETACK command - this should be handled by ReplicaCommandProcessor
                // when received from master, but if executed directly, return OK
                echo "REPLCONF GETACK received (should be handled by replica processor)" . PHP_EOL;
                return ResponseFactory::ok();

            default:
                // For unknown subcommands, still return OK for now
                echo "Unknown REPLCONF subcommand: {$subcommand}" . PHP_EOL;
                return ResponseFactory::ok();
        }
    }
}
