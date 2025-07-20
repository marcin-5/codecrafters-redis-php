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

        // Always return the actual number of connected replicas
        // Even if WAIT is called with a number lesser than the number of connected replicas,
        // the master should return the count of connected replicas
        return new IntegerResponse($connectedReplicas);
    }
}
