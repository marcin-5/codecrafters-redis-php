<?php

namespace Redis\Registry;

use Redis\Commands\EchoCommand;
use Redis\Commands\PingCommand;
use Redis\Commands\RedisCommand;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class CommandRegistry
{
    private array $commands = [];

    public static function createWithDefaults(): self
    {
        $registry = new self();
        $registry->register('PING', new PingCommand());
        $registry->register('ECHO', new EchoCommand());
        return $registry;
    }

    public function register(string $name, RedisCommand $command): void
    {
        $this->commands[strtoupper($name)] = $command;
    }

    // Static factory method to create a registry with default commands

    public function execute(string $commandName, array $args): RESPResponse
    {
        $commandName = strtoupper($commandName);

        if (!isset($this->commands[$commandName])) {
            return ResponseFactory::unknownCommand($commandName);
        }

        return $this->commands[$commandName]->execute($args);
    }
}
