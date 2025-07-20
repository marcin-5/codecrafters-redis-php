<?php

namespace Redis\Storage;

readonly class StreamEntryId
{
    public function __construct(
        private int $milliseconds,
        private int $sequence,
        private bool $autoSequence = false,
    ) {
    }

    public static function parse(string $id): self
    {
        if ($id === '*') {
            // Auto-generate with current timestamp
            return new self((int)(microtime(true) * 1000), 0, true);
        }

        // Check for <milliseconds>-* or <milliseconds>-<sequence> format
        if (preg_match('/^(\d+)-(\*|\d+)$/', $id, $matches)) {
            $milliseconds = (int)$matches[1];
            $sequencePart = $matches[2];

            if ($sequencePart === '*') {
                return new self($milliseconds, 0, true);
            }

            return new self($milliseconds, (int)$sequencePart);
        }

        throw new \InvalidArgumentException("ERR Invalid stream ID specified as stream command argument");
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public function toString(): string
    {
        return "{$this->milliseconds}-{$this->sequence}";
    }

    public function getMilliseconds(): int
    {
        return $this->milliseconds;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function isAutoSequence(): bool
    {
        return $this->autoSequence;
    }

    public function withSequence(int $sequence): self
    {
        return new self($this->milliseconds, $sequence);
    }

    public function isGreaterThan(StreamEntryId $other): bool
    {
        return ([$this->milliseconds, $this->sequence] <=> [$other->milliseconds, $other->sequence]) > 0;
    }

    public function equals(StreamEntryId $other): bool
    {
        return $this->milliseconds === $other->milliseconds
            && $this->sequence === $other->sequence;
    }

    public function incrementSequence(): self
    {
        return new self($this->milliseconds, $this->sequence + 1);
    }
}
