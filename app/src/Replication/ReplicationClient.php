<?php

namespace Redis\Replication;

use Exception;
use Redis\RESP\Response\ArrayResponse;
use Socket;

class ReplicationClient
{
    private const BUFFER_SIZE = 1024;
    private ?Socket $socket = null;
    private bool $connected = false;

    public function __construct(
        private readonly string $masterHost,
        private readonly int $masterPort,
    ) {
    }

    /**
     * Send any command to master using ArrayResponse.
     * @throws Exception
     */
    public function sendCommand(array $commandParts): void
    {
        $commandString = implode(' ', $commandParts);
        $command = new ArrayResponse($commandParts);
        $this->sendPayload($command->serialize(), "command '{$commandString}'");
        echo "Sent command: {$commandString}" . PHP_EOL;
    }

    /**
     * @throws Exception
     */
    private function sendPayload(string $payload, string $errorContext): void
    {
        $this->ensureConnected();
        if (socket_write($this->socket, $payload) === false) {
            throw new Exception(
                "Failed to send {$errorContext}: " .
                socket_strerror(socket_last_error($this->socket)),
            );
        }
    }

    private function ensureConnected(): void
    {
        if (!$this->connected || $this->socket === null) {
            throw new Exception('Not connected to master');
        }
    }

    /**
     * Perform the handshake sequence with the master.
     * @throws Exception
     */
    public function performHandshake(): void
    {
        $this->connect();
        $this->ping();
        $response = $this->readResponse();
        echo 'Received response: ' . trim($response) . PHP_EOL;
    }

    /**
     * Connect to the master server.
     * @throws Exception
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            throw new Exception('Failed to create socket: ' . socket_strerror(socket_last_error()));
        }
        if (!socket_connect($this->socket, $this->masterHost, $this->masterPort)) {
            throw new Exception('Failed to connect to master: ' . socket_strerror(socket_last_error($this->socket)));
        }
        $this->connected = true;
        echo "Connected to master {$this->masterHost}:{$this->masterPort}" . PHP_EOL;
    }

    /**
     * Send PING command to master.
     * @throws Exception
     */
    public function ping(): void
    {
        $pingCommand = new ArrayResponse(['PING']);
        $this->sendPayload($pingCommand->serialize(), 'PING');
        echo 'Sent PING to master' . PHP_EOL;
    }

    /**
     * Read response from master.
     * @throws Exception
     */
    public function readResponse(): string
    {
        $this->ensureConnected();
        $response = socket_read($this->socket, self::BUFFER_SIZE);
        if ($response === false) {
            throw new Exception('Failed to read response: ' . socket_strerror(socket_last_error($this->socket)));
        }
        return $response;
    }

    /**
     * Close the connection.
     */
    public function disconnect(): void
    {
        if ($this->connected && $this->socket) {
            socket_close($this->socket);
            $this->socket = null;
            $this->connected = false;
            echo 'Disconnected from master' . PHP_EOL;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}
