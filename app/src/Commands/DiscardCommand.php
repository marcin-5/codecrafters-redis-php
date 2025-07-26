<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Transaction\TransactionManager;

readonly class DiscardCommand implements RedisCommand
{
    public function __construct(
        private TransactionManager $transactionManager,
    ) {
    }

    public function execute(object $client, array $args): RESPResponse
    {
        if (!$this->transactionManager->isInTransaction($client)) {
            return ResponseFactory::error("ERR DISCARD without MULTI");
        }

        $this->transactionManager->discardTransaction($client);
        return ResponseFactory::ok();
    }
}
