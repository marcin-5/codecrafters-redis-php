<?php

namespace Redis\Replication;

use Redis\RESP\Response\ArrayResponse;
use Socket;

class ReplicationManager
{
    /** @var array<int, Socket> */
    private array $replicas = [];

    /**
     * Add a replica to the list of connected replicas
     */
    public function addReplica(Socket $replica): void
    {
        $replicaId = spl_object_id($replica);
        $this->replicas[$replicaId] = $replica;
        echo "Added replica: {$replicaId}" . PHP_EOL;
    }

    /**
     * Remove a replica from the list
     */
    public function removeReplica(Socket $replica): void
    {
        $replicaId = spl_object_id($replica);
        unset($this->replicas[$replicaId]);
        echo "Removed replica: {$replicaId}" . PHP_EOL;
    }

    /**
     * Check if a socket is a registered replica
     */
    public function isReplica(Socket $socket): bool
    {
        $socketId = spl_object_id($socket);
        return isset($this->replicas[$socketId]);
    }

    /**
     * Get the number of connected replicas
     */
    public function getReplicaCount(): int
    {
        return count($this->replicas);
    }

    /**
     * Propagate a command to all connected replicas
     */
    public function propagateCommand(string $commandName, array $args): void
    {
        if (empty($this->replicas)) {
            return;
        }

        $command = new ArrayResponse(array_merge([$commandName], $args));
        $serializedCommand = $command->serialize();

        foreach ($this->replicas as $replicaId => $replica) {
            $result = socket_write($replica, $serializedCommand);
            if ($result === false) {
                echo "Failed to propagate command to replica {$replicaId}: " .
                    socket_strerror(socket_last_error($replica)) . PHP_EOL;
                // Remove failed replica
                unset($this->replicas[$replicaId]);
            } else {
                echo "Propagated '{$commandName}' to replica {$replicaId}" . PHP_EOL;
            }
        }
    }
}
