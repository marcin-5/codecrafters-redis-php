<?php

namespace Redis\Commands;

use Redis\Replication\ReplicationManager;
use Redis\RESP\Response\IntegerResponse;
use Redis\RESP\Response\RESPResponse;

readonly class WaitCommand implements RedisCommand
{
    public function __construct(
        private ReplicationManager $replicationManager,
    ) {
    }

    public function execute(object $client, array $args): RESPResponse
    {
        // WAIT numreplicas timeout
        if (count($args) < 2) {
            return new IntegerResponse(0);
        }

        $numReplicas = (int)$args[0];
        $timeoutMs = (int)$args[1];

        echo "WAIT command: requesting $numReplicas replicas, timeout {$timeoutMs}ms" . PHP_EOL;

        // If 0 replicas requested, return immediately
        if ($numReplicas === 0) {
            return new IntegerResponse(0);
        }

        // Wait for acknowledgments from replicas
        $acknowledgedCount = $this->replicationManager->waitForAcknowledgments($numReplicas, $timeoutMs);

        echo "WAIT result: $acknowledgedCount replicas acknowledged" . PHP_EOL;
        return new IntegerResponse($acknowledgedCount);
    }
}
