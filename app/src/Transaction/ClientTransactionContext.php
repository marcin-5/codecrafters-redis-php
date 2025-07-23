<?php

namespace Redis\Transaction;

/**
 * This class holds the state for a single client's transaction.
 */
class ClientTransactionContext
{
    public bool $inTransaction = false;
    public CommandQueue $commandQueue;

    public function __construct()
    {
        $this->commandQueue = new CommandQueue();
    }
}
