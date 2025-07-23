<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Transaction\TransactionManager;

readonly class MultiCommand implements RedisCommand
{
    public function __construct(
        private TransactionManager $transactionManager,
    ) {
    }

    public function execute(object $client, array $args): RESPResponse
    {
        if ($this->transactionManager->isInTransaction($client)) {
            return ResponseFactory::error("ERR MULTI calls can not be nested");
        }

        $this->transactionManager->startTransaction($client);
        return ResponseFactory::ok();
    }
}
