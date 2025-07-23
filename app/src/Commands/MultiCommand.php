<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Transaction\TransactionManager;

class MultiCommand implements RedisCommand
{
    public function __construct(
        private readonly TransactionManager $transactionManager,
    ) {
    }

    public function execute(array $args): RESPResponse
    {
        if ($this->transactionManager->isInTransaction()) {
            return ResponseFactory::error("ERR MULTI calls can not be nested");
        }

        $this->transactionManager->startTransaction();
        return ResponseFactory::ok();
    }
}
