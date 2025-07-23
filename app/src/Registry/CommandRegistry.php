<?php

namespace Redis\Registry;

use Redis\Commands\ConfigGetCommand;
use Redis\Commands\EchoCommand;
use Redis\Commands\ExecCommand;
use Redis\Commands\GetCommand;
use Redis\Commands\IncrCommand;
use Redis\Commands\InfoCommand;
use Redis\Commands\KeysCommand;
use Redis\Commands\MultiCommand;
use Redis\Commands\PingCommand;
use Redis\Commands\PsyncCommand;
use Redis\Commands\RedisCommand;
use Redis\Commands\ReplconfCommand;
use Redis\Commands\SetCommand;
use Redis\Commands\TypeCommand;
use Redis\Commands\WaitCommand;
use Redis\Commands\XAddCommand;
use Redis\Commands\XRangeCommand;
use Redis\Commands\XReadCommand;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageFactory;
use Redis\Storage\StorageInterface;
use Redis\Transaction\TransactionManager;

class CommandRegistry
{
    private array $commands = [];
    private TransactionManager $transactionManager;

    private function __construct(private readonly StorageInterface $storage)
    {
        $this->transactionManager = new TransactionManager();
    }

    public static function createWithDefaults(array $config = [], $replicationManager = null): self
    {
        // Create shared storage instance
        $storage = StorageFactory::createStorage($config);
        $registry = new self($storage);

        // Register commands, injecting storage where needed
        $registry->register('PING', new PingCommand());
        $registry->register('ECHO', new EchoCommand());
        $registry->register('GET', new GetCommand($storage));
        $registry->register('SET', new SetCommand($storage));
        $registry->register('INCR', new IncrCommand($storage));
        $registry->register('TYPE', new TypeCommand($storage));
        $registry->register('CONFIG', new ConfigGetCommand($config));
        $registry->register('KEYS', new KeysCommand($storage));
        $registry->register('INFO', new InfoCommand());
        $registry->register('REPLCONF', new ReplconfCommand());
        $registry->register('PSYNC', new PsyncCommand());
        $registry->register('XADD', new XAddCommand($storage));
        $registry->register('XRANGE', new XRangeCommand($storage));
        $registry->register('XREAD', new XReadCommand($storage));

        // Register transaction commands
        $registry->register('MULTI', new MultiCommand($registry->transactionManager));
        $registry->register('EXEC', new ExecCommand($registry->transactionManager));

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

    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    public function getCommand(string $name): ?RedisCommand
    {
        return $this->commands[strtoupper($name)] ?? null;
    }

    public function execute(string $commandName, array $args): RESPResponse
    {
        $commandName = strtoupper($commandName);

        // Handle CONFIG subcommands
        if ($commandName === 'CONFIG' && !empty($args)) {
            $subCommand = strtoupper($args[0]);
            if ($subCommand === 'GET') {
                $subArgs = array_slice($args, 1);
                return $this->executeCommand('CONFIG', $subArgs);
            }
        }

        return $this->executeCommand($commandName, $args);
    }

    private function executeCommand(string $commandName, array $args): RESPResponse
    {
        if (!isset($this->commands[$commandName])) {
            return ResponseFactory::unknownCommand($commandName);
        }

        $command = $this->commands[$commandName];

        // If we're in a transaction and this isn't MULTI, EXEC, or DISCARD, queue it
        if ($this->transactionManager->isInTransaction() &&
            !in_array($commandName, ['MULTI', 'EXEC', 'DISCARD'])) {
            $this->transactionManager->queueCommand($commandName, $args, $command);
            return ResponseFactory::queued();
        }

        // Execute immediately
        return $command->execute($args);
    }

    public function getTransactionManager(): TransactionManager
    {
        return $this->transactionManager;
    }
}
