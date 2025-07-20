<?php

namespace Redis\Replication;

use Redis\RESP\Response\ArrayResponse;
use Socket;

class ReplicationManager
{
    /** @var array<int, Replica> */
    private array $replicas = [];

    /** @var int Current master replication offset */
    private int $masterOffset = 0;

    /**
     * Add a replica to the list of connected replicas.
     */
    public function addReplica(Socket $socket): void
    {
        $replicaId = spl_object_id($socket);
        $this->replicas[$replicaId] = new Replica($socket, 0);
        echo "Added replica: {$replicaId}" . PHP_EOL;
    }

    /**
     * Remove a replica from the list.
     */
    public function removeReplica(Socket $socket): void
    {
        $replicaId = spl_object_id($socket);
        if (isset($this->replicas[$replicaId])) {
            unset($this->replicas[$replicaId]);
            echo "Removed replica: {$replicaId}" . PHP_EOL;
        }
    }

    /**
     * Check if a socket is a registered replica.
     */
    public function isReplica(Socket $socket): bool
    {
        return isset($this->replicas[spl_object_id($socket)]);
    }

    /**
     * Get the number of connected replicas.
     */
    public function getReplicaCount(): int
    {
        return count($this->replicas);
    }

    /**
     * Get current master replication offset.
     */
    public function getMasterOffset(): int
    {
        return $this->masterOffset;
    }

    /**
     * Propagate a command to all connected replicas.
     */
    public function propagateCommand(string $commandName, array $args): void
    {
        if (empty($this->replicas)) {
            return;
        }

        $command = new ArrayResponse(array_merge([$commandName], $args));
        $serializedCommand = $command->serialize();
        $this->masterOffset += strlen($serializedCommand);

        foreach ($this->replicas as $replicaId => $replica) {
            if (socket_write($replica->socket, $serializedCommand) === false) {
                $this->handleFailedReplica($replicaId, "propagate command to");
            } else {
                echo "Propagated '{$commandName}' to replica {$replicaId}" . PHP_EOL;
            }
        }
    }

    /**
     * Handles the removal and logging of a failed replica.
     */
    private function handleFailedReplica(int $replicaId, string $context): void
    {
        if (isset($this->replicas[$replicaId])) {
            $error = socket_strerror(socket_last_error($this->replicas[$replicaId]->socket));
            echo "Failed to {$context} replica {$replicaId}: {$error}" . PHP_EOL;
            unset($this->replicas[$replicaId]);
        }
    }

    /**
     * Wait for replicas to acknowledge up to the current master offset.
     *
     * @param int $numReplicas Number of replicas to wait for
     * @param int $timeoutMs Timeout in milliseconds
     * @return int Number of replicas that acknowledged
     */
    public function waitForAcknowledgments(int $numReplicas, int $timeoutMs): int
    {
        if (empty($this->replicas) || $numReplicas <= 0) {
            return count($this->replicas);
        }

        $targetOffset = $this->masterOffset;
        echo "Waiting for $numReplicas replicas to reach offset $targetOffset (timeout: {$timeoutMs}ms)" . PHP_EOL;

        // If no write commands have been executed (offset is 0),
        // return the actual number of connected replicas (they're all synchronized)
        if ($targetOffset === 0) {
            $connectedCount = count($this->replicas);
            echo "No write commands executed, returning $connectedCount connected replicas" . PHP_EOL;
            return $connectedCount;
        }

        $this->sendGetAckToAllReplicas();

        $startTime = microtime(true) * 1000;
        $acknowledgedReplicas = 0;
        $pendingReplicas = array_keys($this->replicas);

        while ($acknowledgedReplicas < $numReplicas && !empty($pendingReplicas)) {
            $elapsedTime = (microtime(true) * 1000) - $startTime;
            if ($elapsedTime >= $timeoutMs) {
                echo "Timeout reached after {$elapsedTime}ms" . PHP_EOL;
                break;
            }

            $remainingTimeout = max(0, $timeoutMs - $elapsedTime);
            $responses = $this->collectAckResponses($pendingReplicas, $remainingTimeout);

            foreach ($responses as $replicaId => $offset) {
                if ($offset >= $targetOffset) {
                    $acknowledgedReplicas++;
                    echo "Replica $replicaId acknowledged with offset $offset" . PHP_EOL;
                    $pendingReplicas = array_diff($pendingReplicas, [$replicaId]);
                }
            }

            if (!empty($pendingReplicas) && $acknowledgedReplicas < $numReplicas) {
                usleep(1000); // 1ms
            }
        }

        echo "WAIT completed: $acknowledgedReplicas replicas acknowledged out of " . count(
                $this->replicas,
            ) . " total" . PHP_EOL;
        return $acknowledgedReplicas;
    }

    /**
     * Send REPLCONF GETACK * to all replicas.
     */
    private function sendGetAckToAllReplicas(): void
    {
        $getAckCommand = new ArrayResponse(['REPLCONF', 'GETACK', '*']);
        $serializedCommand = $getAckCommand->serialize();

        foreach ($this->replicas as $replicaId => $replica) {
            if (socket_write($replica->socket, $serializedCommand) === false) {
                $this->handleFailedReplica($replicaId, "send GETACK to");
            } else {
                echo "Sent GETACK to replica {$replicaId}" . PHP_EOL;
            }
        }
    }

    /**
     * Collect ACK responses from replicas within timeout.
     *
     * @param int[] $replicaIds List of replica IDs to check
     * @param float $timeoutMs Timeout in milliseconds
     * @return array<int, int> Array of replicaId => offset
     */
    private function collectAckResponses(array $replicaIds, float $timeoutMs): array
    {
        $responses = [];
        $sockets = [];
        foreach ($replicaIds as $replicaId) {
            if (isset($this->replicas[$replicaId])) {
                $sockets[$replicaId] = $this->replicas[$replicaId]->socket;
            }
        }

        if (empty($sockets)) {
            return [];
        }

        $read = array_values($sockets);
        $write = null;
        $except = null;
        $timeoutSec = intval($timeoutMs / 1000);
        $timeoutMicro = intval(($timeoutMs % 1000) * 1000);

        if (socket_select($read, $write, $except, $timeoutSec, $timeoutMicro) > 0) {
            foreach ($read as $socket) {
                $replicaId = array_search($socket, $sockets, true);
                if ($replicaId !== false) {
                    $data = socket_read($socket, 1024);
                    if (!empty($data)) {
                        $offset = $this->parseAckResponse($data);
                        if ($offset !== null) {
                            $responses[$replicaId] = $offset;
                            if (isset($this->replicas[$replicaId])) {
                                $this->replicas[$replicaId]->offset = $offset;
                            }
                        }
                    }
                }
            }
        }

        return $responses;
    }

    /**
     * Parse REPLCONF ACK response to extract offset.
     */
    private function parseAckResponse(string $data): ?int
    {
        if (preg_match('/REPLCONF\r\n\$3\r\nACK\r\n\$(\d+)\r\n(\d+)/', $data, $matches)) {
            return (int)$matches[2];
        }
        if (preg_match('/ACK\r\n\$\d+\r\n(\d+)/', $data, $matches)) {
            return (int)$matches[1];
        }
        echo "Could not parse ACK response: " . json_encode($data) . PHP_EOL;
        return null;
    }
}
