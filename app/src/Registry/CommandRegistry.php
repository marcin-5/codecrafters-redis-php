<?php

namespace Redis\Registry;

use Redis\Commands\ConfigGetCommand;
use Redis\Commands\EchoCommand;
use Redis\Commands\GetCommand;
use Redis\Commands\InfoCommand;
use Redis\Commands\KeysCommand;
use Redis\Commands\PingCommand;
use Redis\Commands\PsyncCommand;
use Redis\Commands\RedisCommand;
use Redis\Commands\ReplconfCommand;
use Redis\Commands\SetCommand;
use Redis\Commands\TypeCommand;
use Redis\Commands\WaitCommand;
use Redis\Commands\XAddCommand;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageFactory;

class CommandRegistry
{
    private array $commands = [];

    public static function createWithDefaults(array $config = [], $replicationManager = null): self
    {
        $registry = new self();

        // Create shared storage instance
        $storage = StorageFactory::createStorage($config);

        // Register commands, injecting storage where needed
        $registry->register('PING', new PingCommand());
        $registry->register('ECHO', new EchoCommand());
        $registry->register('SET', new SetCommand($storage));
        $registry->register('GET', new GetCommand($storage));
        $registry->register('TYPE', new TypeCommand($storage));
        $registry->register('CONFIG', new ConfigGetCommand($config));
        $registry->register('KEYS', new KeysCommand($storage));
        $registry->register('INFO', new InfoCommand());
        $registry->register('REPLCONF', new ReplconfCommand());
        $registry->register('PSYNC', new PsyncCommand());
        $registry->register('XADD', new XAddCommand($storage));

        // Register WAIT command if replication manager is provided
        if ($replicationManager !== null) {
            $registry->register('WAIT', new WaitCommand($replicationManager));
        }

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
