<?php

namespace Redis\Config;

class ReplicationConfig
{
    private static ?self $instance = null;
    private string $role = 'master';
    private ?string $masterHost = null;
    private ?int $masterPort = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setReplicaOf(string $masterHost, int $masterPort): void
    {
        $this->role = 'slave';
        $this->masterHost = $masterHost;
        $this->masterPort = $masterPort;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getMasterHost(): ?string
    {
        return $this->masterHost;
    }

    public function getMasterPort(): ?int
    {
        return $this->masterPort;
    }

    public function isSlave(): bool
    {
        return $this->role === 'slave';
    }

    public function isMaster(): bool
    {
        return $this->role === 'master';
    }
}
