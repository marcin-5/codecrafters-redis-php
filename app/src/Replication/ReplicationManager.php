<?php

namespace Redis\Replication;

use Redis\RESP\Response\ArrayResponse;
use Socket;

class ReplicationManager
{
    /** @var array<int, Socket> */
    private array $replicas = [];

    /**
     * Register a socket as a replica (after PSYNC handshake)
     */
    public function addReplica(Socket $socket): void
    {
        $this->replicas[spl_object_id($socket)] = $socket;
        echo "Replica registered: " . spl_object_id($socket) . PHP_EOL;
    }

    /**
     * Check if a socket is a registered replica
     */
    public function isReplica(Socket $socket): bool
    {
        return isset($this->replicas[spl_object_id($socket)]);
    }

    /**
     * Propagate a command to all connected replicas
     */
    public function propagateCommand(string $commandName, array $args): void
    {
        if (empty($this->replicas)) {
            return; // No replicas to propagate to
        }

        $commandArray = array_merge([$commandName], $args);
        $response = new ArrayResponse($commandArray);
        $serialized = $response->serialize();

        $disconnectedReplicas = [];

        foreach ($this->replicas as $id => $replica) {
            $result = @socket_write($replica, $serialized);
            if ($result === false) {
                // Mark for removal if write failed
                $disconnectedReplicas[] = $replica;
            }
        }

        // Remove disconnected replicas
        foreach ($disconnectedReplicas as $replica) {
            $this->removeReplica($replica);
        }

        if (!empty($this->replicas)) {
            echo "Propagated command '{$commandName}' to " . count($this->replicas) . " replica(s)" . PHP_EOL;
        }
    }

    /**
     * Remove a replica (when connection is lost)
     */
    public function removeReplica(Socket $socket): void
    {
        $id = spl_object_id($socket);
        if (isset($this->replicas[$id])) {
            unset($this->replicas[$id]);
            echo "Replica unregistered: " . $id . PHP_EOL;
        }
    }

    /**
     * Get count of connected replicas
     */
    public function getReplicaCount(): int
    {
        return count($this->replicas);
    }
}
