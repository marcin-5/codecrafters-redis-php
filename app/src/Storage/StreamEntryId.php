<?php

namespace Redis\Storage;

class StreamEntryId
{
    private int $milliseconds;
    private int $sequence;
    private bool $autoSequence; // Flag to indicate if sequence should be auto-generated

    public function __construct(int $milliseconds, int $sequence, bool $autoSequence = false)
    {
        $this->milliseconds = $milliseconds;
        $this->sequence = $sequence;
        $this->autoSequence = $autoSequence;
    }

    public static function parse(string $id): self
    {
        if ($id === '*') {
            // Auto-generate with current timestamp
            $milliseconds = (int)(microtime(true) * 1000);
            $sequence = 0;
            return new self($milliseconds, $sequence, true);
        }

        // Check for <milliseconds>-* format
        if (preg_match('/^(\d+)-\*$/', $id, $matches)) {
            $milliseconds = (int)$matches[1];
            return new self($milliseconds, 0, true); // sequence will be determined later
        }

        // Check for standard <milliseconds>-<sequence> format
        if (!preg_match('/^(\d+)-(\d+)$/', $id, $matches)) {
            throw new \InvalidArgumentException("ERR Invalid stream ID specified as stream command argument");
        }

        $milliseconds = (int)$matches[1];
        $sequence = (int)$matches[2];

        return new self($milliseconds, $sequence, false);
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
        return new self($this->milliseconds, $sequence, false);
    }

    public function isGreaterThan(StreamEntryId $other): bool
    {
        if ($this->milliseconds > $other->milliseconds) {
            return true;
        }

        if ($this->milliseconds === $other->milliseconds) {
            return $this->sequence > $other->sequence;
        }

        return false;
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
