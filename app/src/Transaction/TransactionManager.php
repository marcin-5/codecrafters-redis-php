<?php

namespace Redis\Transaction;

use Redis\Commands\RedisCommand;

class TransactionManager
{
    /**
     * Uses SplObjectStorage to map client connection objects to their
     * transaction state. This ensures each client has an isolated transaction.
     * @var \SplObjectStorage<object, ClientTransactionContext>
     */
    private \SplObjectStorage $clientContexts;

    public function __construct()
    {
        $this->clientContexts = new \SplObjectStorage();
    }

    public function startTransaction(object $client): void
    {
        $context = $this->getContextFor($client);
        if ($context->inTransaction) {
            // This would be the place to handle nested MULTI calls for a client
            return;
        }
        $context->inTransaction = true;
        $context->commandQueue->clear();
    }

    /**
     * Gets or creates the transaction context for a given client.
     * The $client parameter should be a unique object representing the connection.
     */
    private function getContextFor(object $client): ClientTransactionContext
    {
        if (!isset($this->clientContexts[$client])) {
            $this->clientContexts[$client] = new ClientTransactionContext();
        }
        return $this->clientContexts[$client];
    }

    public function isInTransaction(object $client): bool
    {
        if (!isset($this->clientContexts[$client])) {
            return false;
        }
        return $this->getContextFor($client)->inTransaction;
    }

    public function queueCommand(object $client, string $commandName, array $args, RedisCommand $command): void
    {
        $this->getContextFor($client)->commandQueue->enqueue($commandName, $args, $command);
    }

    public function executeQueue(object $client): array
    {
        $context = $this->getContextFor($client);
        $results = $context->commandQueue->executeAll();
        $this->clearTransaction($client);
        return $results;
    }

    private function clearTransaction(object $client): void
    {
        if (isset($this->clientContexts[$client])) {
            unset($this->clientContexts[$client]);
        }
    }

    public function discardTransaction(object $client): void
    {
        $this->clearTransaction($client);
    }

    public function getQueueSize(object $client): int
    {
        if (!isset($this->clientContexts[$client])) {
            return 0;
        }
        return $this->getContextFor($client)->commandQueue->getSize();
    }

    public function getQueuedCommands(object $client): array
    {
        if (!isset($this->clientContexts[$client])) {
            return [];
        }
        return $this->getContextFor($client)->commandQueue->toArray();
    }
}
