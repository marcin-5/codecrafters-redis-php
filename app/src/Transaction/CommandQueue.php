<?php

namespace Redis\Transaction;

use Redis\Commands\RedisCommand;

class CommandQueue implements \IteratorAggregate
{
    private ?CommandQueueNode $head = null;
    private ?CommandQueueNode $tail = null;
    private int $size = 0;

    public function enqueue(string $commandName, array $args, RedisCommand $command): void
    {
        $newNode = new CommandQueueNode($commandName, $args, $command);
        if ($this->tail === null) {
            // First node
            $this->head = $this->tail = $newNode;
        } else {
            // Add to tail
            $this->tail->next = $newNode;
            $this->tail = $newNode;
        }
        $this->size++;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function clear(): void
    {
        $this->head = $this->tail = null;
        $this->size = 0;
    }

    /**
     * Execute all commands in the queue and return results
     */
    public function executeAll(): array
    {
        $results = [];
        while (!$this->isEmpty()) {
            $node = $this->dequeue();
            // The redundant null check has been removed, as the loop condition
            // ensures that dequeue() will not return null here.
            $results[] = $node->command->execute($node->args);
        }
        return $results;
    }

    public function isEmpty(): bool
    {
        return $this->head === null;
    }

    public function dequeue(): ?CommandQueueNode
    {
        if ($this->head === null) {
            return null;
        }
        $node = $this->head;
        $this->head = $this->head->next;
        if ($this->head === null) {
            $this->tail = null;
        }
        $this->size--;
        return $node;
    }

    /**
     * Get all commands as array (for debugging/inspection)
     */
    public function toArray(): array
    {
        return array_map(
            static fn(CommandQueueNode $node) => [
                'name' => $node->commandName,
                'args' => $node->args,
                'command' => $node->command,
            ],
            iterator_to_array($this, false),
        );
    }

    public function getIterator(): \Traversable
    {
        $current = $this->head;
        while ($current !== null) {
            yield $current;
            $current = $current->next;
        }
    }
}
