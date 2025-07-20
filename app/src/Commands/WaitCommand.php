<?php

namespace Redis\Commands;

use Redis\Replication\ReplicationManager;
use Redis\RESP\Response\IntegerResponse;
use Redis\RESP\Response\RESPResponse;

class WaitCommand implements RedisCommand
{
    public function __construct(
        private readonly ReplicationManager $replicationManager,
    ) {
    }

    public function execute(array $args): RESPResponse
    {
        // WAIT numreplicas timeout
        if (count($args) < 2) {
            return new IntegerResponse(0);
        }

        $numReplicas = (int)$args[0];
        $timeout = (int)$args[1];

        // Get the current number of connected replicas
        $connectedReplicas = $this->replicationManager->getReplicaCount();

        // If no replicas are requested or no replicas are connected, return immediately
        if ($numReplicas === 0 || $connectedReplicas === 0) {
            return new IntegerResponse($connectedReplicas);
        }

        return new IntegerResponse(min($numReplicas, $connectedReplicas));
    }
}
