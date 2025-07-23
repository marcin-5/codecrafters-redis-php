<?php

namespace Redis\Transaction;

use Redis\Commands\RedisCommand;

class TransactionManager
{
    private bool $inTransaction = false;
    private CommandQueue $commandQueue;

    public function __construct()
    {
        $this->commandQueue = new CommandQueue();
    }

    public function startTransaction(): void
    {
        $this->inTransaction = true;
        $this->commandQueue->clear();
    }

    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function queueCommand(string $commandName, array $args, RedisCommand $command): void
    {
        $this->commandQueue->enqueue($commandName, $args, $command);
    }

    public function executeQueue(): array
    {
        $results = $this->commandQueue->executeAll();
        $this->clearTransaction();
        return $results;
    }

    private function clearTransaction(): void
    {
        $this->inTransaction = false;
        $this->commandQueue->clear();
    }

    public function discardTransaction(): void
    {
        $this->clearTransaction();
    }

    public function getQueueSize(): int
    {
        return $this->commandQueue->getSize();
    }

    /**
     * Get queued commands for inspection (mainly for testing)
     */
    public function getQueuedCommands(): array
    {
        return $this->commandQueue->toArray();
    }
}
