<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Transaction\TransactionManager;

class ExecCommand implements RedisCommand
{
    public function __construct(
        private readonly TransactionManager $transactionManager,
    ) {
    }

    public function execute(array $args): RESPResponse
    {
        if (!$this->transactionManager->isInTransaction()) {
            return ResponseFactory::error("ERR EXEC without MULTI");
        }

        $results = $this->transactionManager->executeQueue();
        return ResponseFactory::array($results);
    }
}
