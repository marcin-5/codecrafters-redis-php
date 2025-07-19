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
        private readonly int $replicaPort,
    ) {
    }

    /**
     * Retrieves the current socket instance.
     *
     * @return Socket|null Returns the socket instance if available, or null if not set.
     */
    public function getSocket(): ?Socket
    {
        return $this->socket;
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

        // Step 1: Send PING
        $this->ping();
        $response = $this->readResponse();
        echo 'Received PING response: ' . trim($response) . PHP_EOL;

        // Step 2: Send REPLCONF listening-port <PORT>
        $this->sendReplconfListeningPort();
        $response = $this->readResponse();
        echo 'Received REPLCONF listening-port response: ' . trim($response) . PHP_EOL;

        // Step 3: Send REPLCONF capa psync2
        $this->sendReplconfCapabilities();
        $response = $this->readResponse();
        echo 'Received REPLCONF capa response: ' . trim($response) . PHP_EOL;

        // Step 4: Send PSYNC ? -1
        $this->sendPsync();
        $response = $this->readResponse();
        echo 'Received PSYNC response: ' . trim($response) . PHP_EOL;

        echo 'Handshake completed successfully!' . PHP_EOL;
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
     * Send REPLCONF listening-port <PORT> command to master.
     * Format: *3\r\n$8\r\nREPLCONF\r\n$14\r\nlistening-port\r\n$4\r\n6380\r\n
     * @throws Exception
     */
    private function sendReplconfListeningPort(): void
    {
        $replconfCommand = new ArrayResponse([
            'REPLCONF',
            'listening-port',
            (string)$this->replicaPort
        ]);
        $this->sendPayload($replconfCommand->serialize(), 'REPLCONF listening-port');
        echo "Sent REPLCONF listening-port {$this->replicaPort} to master" . PHP_EOL;
    }

    /**
     * Send REPLCONF capa psync2 command to master.
     * Format: *3\r\n$8\r\nREPLCONF\r\n$4\r\ncapa\r\n$6\r\npsync2\r\n
     * @throws Exception
     */
    private function sendReplconfCapabilities(): void
    {
        $replconfCommand = new ArrayResponse([
            'REPLCONF',
            'capa',
            'psync2'
        ]);
        $this->sendPayload($replconfCommand->serialize(), 'REPLCONF capa');
        echo 'Sent REPLCONF capa psync2 to master' . PHP_EOL;
    }

    /**
     * Send PSYNC ? -1 command to master.
     * Format: *3\r\n$5\r\nPSYNC\r\n$1\r\n?\r\n$2\r\n-1\r\n
     * @throws Exception
     */
    private function sendPsync(): void
    {
        $psyncCommand = new ArrayResponse([
            'PSYNC',
            '?',
            '-1'
        ]);
        $this->sendPayload($psyncCommand->serialize(), 'PSYNC');
        echo 'Sent PSYNC ? -1 to master' . PHP_EOL;
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
