<?php

namespace Redis\Replication;

use Exception;
use Redis\Registry\CommandRegistry;
use Redis\RESP\RESPParser;

class ReplicaCommandProcessor
{
    private string $buffer = '';

    public function __construct(
        private readonly RESPParser $parser,
        private readonly CommandRegistry $registry,
    ) {
    }

    /**
     * Process incoming data from master, handling partial/multiple commands
     */
    public function processIncomingData(string $data): void
    {
        // Add new data to buffer
        $this->buffer .= $data;

        // Process all complete commands in the buffer
        while ($this->hasCompleteCommand()) {
            try {
                $command = $this->extractNextCommand();
                if ($command !== null) {
                    $this->executeReplicatedCommand($command);
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
            return true; // If parsing succeeds, we have a complete command
        } catch (Exception $e) {
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
        $parsed = $this->parser->parseNext($this->buffer, $offset);

        // Remove the processed command from buffer
        $this->buffer = substr($this->buffer, $offset);

        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }

        return null;
    }

    /**
     * Execute a replicated command without sending a response
     */
    private function executeReplicatedCommand(array $commandParts): void
    {
        if (empty($commandParts) || !is_string($commandParts[0])) {
            return;
        }

        $commandName = array_shift($commandParts);
        $args = $commandParts;

        echo "Processing replicated command: {$commandName}" .
            (empty($args) ? '' : ' ' . implode(' ', $args)) . PHP_EOL;

        // Execute the command but don't send response (replica mode)
        try {
            $this->registry->execute($commandName, $args);
            // Note: We don't send the response anywhere - replicas are silent
        } catch (Exception $e) {
            echo "Error executing replicated command '{$commandName}': " . $e->getMessage() . PHP_EOL;
        }
    }
}
