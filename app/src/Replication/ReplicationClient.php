<?php

namespace Redis\Replication;

use Exception;
use Redis\RESP\Response\ArrayResponse;
use Socket;

class ReplicationClient
{
    private const BUFFER_SIZE = 4096;
    private ?Socket $socket = null;
    private bool $connected = false;
    private string $buffer = '';

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
        $response = $this->readSingleResponse();
        echo 'Received PING response: ' . trim($response) . PHP_EOL;

        // Step 2: Send REPLCONF listening-port <PORT>
        $this->sendReplconfListeningPort();
        $response = $this->readSingleResponse();
        echo 'Received REPLCONF listening-port response: ' . trim($response) . PHP_EOL;

        // Step 3: Send REPLCONF capa psync2
        $this->sendReplconfCapabilities();
        $response = $this->readSingleResponse();
        echo 'Received REPLCONF capa response: ' . trim($response) . PHP_EOL;

        // Step 4: Send PSYNC ? -1
        $this->sendPsync();
        $response = $this->readSingleResponse();
        echo 'Received PSYNC response: ' . trim($response) . PHP_EOL;

        // Step 5: Handle RDB file that follows FULLRESYNC
        $this->handleRdbFile();

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
     * Read a single RESP response (used during handshake)
     * @throws Exception
     */
    private function readSingleResponse(): string
    {
        $this->ensureConnected();

        // Keep reading until we have a complete response
        while (true) {
            if (empty($this->buffer)) {
                $data = socket_read($this->socket, self::BUFFER_SIZE);
                if ($data === false) {
                    throw new Exception(
                        'Failed to read response: ' . socket_strerror(socket_last_error($this->socket)),
                    );
                }

                if ($data === '') {
                    throw new Exception('Connection closed by master');
                }

                $this->buffer .= $data;
            }

            // Try to extract a single response
            if ($this->buffer[0] === '+') {
                // Simple string response
                $crlfPos = strpos($this->buffer, "\r\n");
                if ($crlfPos !== false) {
                    $response = substr($this->buffer, 0, $crlfPos + 2);
                    $this->buffer = substr($this->buffer, $crlfPos + 2);
                    return $response;
                }
            } elseif ($this->buffer[0] === '-') {
                // Error response
                $crlfPos = strpos($this->buffer, "\r\n");
                if ($crlfPos !== false) {
                    $response = substr($this->buffer, 0, $crlfPos + 2);
                    $this->buffer = substr($this->buffer, $crlfPos + 2);
                    return $response;
                }
            }

            // If we don't have a complete response yet, continue reading
            if (strlen($this->buffer) < 2 || !str_contains($this->buffer, "\r\n")) {
                $data = socket_read($this->socket, self::BUFFER_SIZE);
                if ($data === false) {
                    throw new Exception(
                        'Failed to read response: ' . socket_strerror(socket_last_error($this->socket)),
                    );
                }
                if ($data === '') {
                    throw new Exception('Connection closed by master');
                }
                $this->buffer .= $data;
                continue;
            }

            // For other response types, we might need more sophisticated parsing
            // but for handshake, we only expect simple strings and errors
            throw new Exception('Unexpected response format: ' . substr($this->buffer, 0, 10));
        }
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
     * Handle the RDB file that comes after FULLRESYNC response
     * @throws Exception
     */
    private function handleRdbFile(): void
    {
        // Read the RDB file header: $<length>\r\n
        while (true) {
            if (empty($this->buffer)) {
                $data = socket_read($this->socket, self::BUFFER_SIZE);
                if ($data === false || $data === '') {
                    throw new Exception('Failed to read RDB file header');
                }
                $this->buffer .= $data;
            }

            // Look for RDB file format: $<length>\r\n
            if ($this->buffer[0] === '$') {
                $crlfPos = strpos($this->buffer, "\r\n");
                if ($crlfPos === false) {
                    // Need more data for complete header
                    continue;
                }

                $length = (int)substr($this->buffer, 1, $crlfPos - 1);
                $dataStart = $crlfPos + 2;

                // Make sure we have all the RDB data
                while (strlen($this->buffer) < $dataStart + $length) {
                    $data = socket_read($this->socket, self::BUFFER_SIZE);
                    if ($data === false || $data === '') {
                        throw new Exception('Failed to read RDB file data');
                    }
                    $this->buffer .= $data;
                }

                // Extract RDB data
                $rdbData = substr($this->buffer, $dataStart, $length);
                echo "Received and processed RDB file ({$length} bytes)" . PHP_EOL;

                // Remove RDB data from buffer, keep any remaining data for further processing
                $this->buffer = substr($this->buffer, $dataStart + $length);
                echo "Remaining buffer after RDB: " . json_encode($this->buffer) . PHP_EOL;

                break;
            } else {
                throw new Exception('Expected RDB file but got: ' . substr($this->buffer, 0, 10));
            }
        }
    }

    /**
     * Get any buffered data that was read during handshake but not consumed
     */
    public function getBufferedData(): string
    {
        $data = $this->buffer;
        $this->buffer = '';
        return $data;
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
