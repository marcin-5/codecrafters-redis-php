<?php

namespace Redis\Replication;

use Exception;
use Redis\Registry\CommandRegistry;
use Redis\RESP\Response\ArrayResponse;
use Redis\RESP\RESPParser;
use Socket;

class ReplicaCommandProcessor
{
    private string $buffer = '';
    private int $replicationOffset = 0;
    private ?Socket $masterSocket = null;

    public function __construct(
        private readonly RESPParser $parser,
        private readonly CommandRegistry $registry,
        ?Socket $masterSocket = null,
    ) {
        $this->masterSocket = $masterSocket;
    }

    /**
     * Set the master socket for sending responses
     */
    public function setMasterSocket(Socket $masterSocket): void
    {
        $this->masterSocket = $masterSocket;
    }

    /**
     * Get the current replication offset
     */
    public function getReplicationOffset(): int
    {
        return $this->replicationOffset;
    }

    /**
     * Process incoming data from master, handling partial/multiple commands
     */
    public function processIncomingData(string $data): void
    {
        // Add new data to buffer
        $this->buffer .= $data;
        echo "Buffer now contains: " . json_encode($this->buffer) . PHP_EOL;

        // Process all complete commands in the buffer
        while ($this->hasCompleteCommand()) {
            try {
                $commandInfo = $this->extractNextCommand();
                if ($commandInfo !== null) {
                    $this->executeReplicatedCommand($commandInfo['command'], $commandInfo['length']);
                }
            } catch (Exception $e) {
                echo "Error processing replicated command: " . $e->getMessage() . PHP_EOL;
                break;
            }
        }
    }

    /**
     * Check if buffer contains at least one complete RESP command
     */
    private function hasCompleteCommand(): bool
    {
        if (empty($this->buffer)) {
            return false;
        }

        try {
            $offset = 0;
            $this->parser->parseNext($this->buffer, $offset);
            echo "hasCompleteCommand: YES, parsed successfully" . PHP_EOL;
            return true; // If parsing succeeds, we have a complete command
        } catch (Exception $e) {
            echo "hasCompleteCommand: NO - " . $e->getMessage() . PHP_EOL;
            return false; // Not enough data for a complete command
        }
    }

    /**
     * Extract and remove the next complete command from buffer
     * @throws Exception
     */
    private function extractNextCommand(): ?array
    {
        if (empty($this->buffer)) {
            return null;
        }

        $offset = 0;
        $originalOffset = $offset;
        $parsed = $this->parser->parseNext($this->buffer, $offset);

        // Calculate the byte length of this command
        $commandLength = $offset - $originalOffset;

        echo "Extracted command: " . json_encode($parsed) . ", length: $commandLength" . PHP_EOL;

        // Remove the processed command from buffer
        $this->buffer = substr($this->buffer, $offset);

        if (is_array($parsed) && !empty($parsed)) {
            return [
                'command' => $parsed,
                'length' => $commandLength
            ];
        }

        return null;
    }

    /**
     * Execute a replicated command without sending a response (unless it's REPLCONF GETACK)
     */
    private function executeReplicatedCommand(array $commandParts, int $commandLength): void
    {
        if (empty($commandParts) || !is_string($commandParts[0])) {
            return;
        }

        $commandName = $commandParts[0];
        $args = array_slice($commandParts, 1);

        echo "Processing replicated command: {$commandName}" .
            (empty($args) ? '' : ' ' . implode(' ', $args)) . PHP_EOL;

        // Handle REPLCONF GETACK specially - respond BEFORE updating offset
        if (strtoupper($commandName) === 'REPLCONF' && !empty($args) && strtolower($args[0]) === 'getack') {
            // Respond with current offset BEFORE updating it
            $this->handleGetAckCommand($args);
            // Now update the offset to include this GETACK command
            $this->replicationOffset += $commandLength;
            echo "Updated replication offset to: {$this->replicationOffset} (after GETACK)" . PHP_EOL;
            return;
        }

        // For all other commands, update offset first, then execute
        $this->replicationOffset += $commandLength;
        echo "Updated replication offset to: {$this->replicationOffset}" . PHP_EOL;

        // Execute other commands but don't send response (replica mode)
        try {
            $this->registry->execute($commandName, $args);
            // Note: We don't send the response anywhere - replicas are silent for normal commands
        } catch (Exception $e) {
            echo "Error executing replicated command '{$commandName}': " . $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Handle REPLCONF GETACK command by sending back the current offset
     */
    private function handleGetAckCommand(array $args): void
    {
        if ($this->masterSocket === null) {
            echo "Cannot send GETACK response: no master socket available" . PHP_EOL;
            return;
        }

        try {
            // Send back the current replication offset (before processing this GETACK)
            $response = new ArrayResponse([
                'REPLCONF',
                'ACK',
                (string)$this->replicationOffset
            ]);

            $serialized = $response->serialize();
            echo "Sending GETACK response: " . json_encode($serialized) . PHP_EOL;
            $result = socket_write($this->masterSocket, $serialized);

            if ($result === false) {
                echo "Failed to send GETACK response: " . socket_strerror(
                        socket_last_error($this->masterSocket),
                    ) . PHP_EOL;
            } else {
                echo "Sent REPLCONF ACK {$this->replicationOffset} to master ({$result} bytes written)" . PHP_EOL;
            }
        } catch (Exception $e) {
            echo "Error sending GETACK response: " . $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Check if this is a REPLCONF GETACK command
     */
    private function isGetAckCommand(array $parsed): bool
    {
        return count($parsed) >= 2 &&
            strtoupper($parsed[0]) === 'REPLCONF' &&
            strtoupper($parsed[1]) === 'GETACK';
    }
}
