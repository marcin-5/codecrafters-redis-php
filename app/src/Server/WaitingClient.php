<?php

namespace Redis\Server;

use Socket;

readonly class WaitingClient
{
    public function __construct(
        private Socket $socket,
        private array $streamKeys,
        private array $ids,
        private ?int $count,
        private int $blockTimeout,
        private float $startTime,
    ) {
    }

    public function getSocket(): Socket
    {
        return $this->socket;
    }

    public function getStreamKeys(): array
    {
        return $this->streamKeys;
    }

    public function getIds(): array
    {
        return $this->ids;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function getBlockTimeout(): int
    {
        return $this->blockTimeout;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }
}
