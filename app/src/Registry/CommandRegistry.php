<?php

namespace Redis\Registry;

use Redis\Commands\ConfigGetCommand;
use Redis\Commands\EchoCommand;
use Redis\Commands\GetCommand;
use Redis\Commands\PingCommand;
use Redis\Commands\RedisCommand;
use Redis\Commands\SetCommand;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\InMemoryStorage;

class CommandRegistry
{
    private array $commands = [];

    public static function createWithDefaults(array $config = []): self
    {
        $registry = new self();

        // Create shared storage instance
        $storage = new InMemoryStorage();

        // Register commands, injecting storage where needed
        $registry->register('PING', new PingCommand());
        $registry->register('ECHO', new EchoCommand());
        $registry->register('SET', new SetCommand($storage));
        $registry->register('GET', new GetCommand($storage));
        $registry->register('CONFIG', new ConfigGetCommand($config));

        return $registry;
    }

    public function register(string $name, RedisCommand $command): void
    {
        $this->commands[strtoupper($name)] = $command;
    }

    public function execute(string $commandName, array $args): RESPResponse
    {
        $commandName = strtoupper($commandName);

        // Handle CONFIG subcommands
        if ($commandName === 'CONFIG' && !empty($args)) {
            $subCommand = strtoupper($args[0]);
            if ($subCommand === 'GET') {
                $subArgs = array_slice($args, 1);
                return $this->commands['CONFIG']->execute($subArgs);
            }
        }

        if (!isset($this->commands[$commandName])) {
            return ResponseFactory::unknownCommand($commandName);
        }

        return $this->commands[$commandName]->execute($args);
    }
}
