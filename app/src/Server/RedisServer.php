<?php

namespace Redis\Server;

use Exception;
use Redis\Registry\CommandRegistry;
use Redis\Replication\ReplicationManager;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\RESPParser;
use Socket;

class RedisServer
{
    private const int BUFFER_SIZE = 1024;
    private const int SELECT_TIMEOUT_MICROSECONDS = 200000; // 200ms
    private ?Socket $serverSocket = null;
    /** @var array<int, Socket> */
    private array $clients = [];
    private bool $running = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly RESPParser $parser,
        private readonly CommandRegistry $registry,
        private readonly ReplicationManager $replicationManager,
    ) {
    }

    /**
     * Start the server and listen for connections
     * @throws Exception
     */
    public function start(): void
    {
        $this->initializeServerSocket();
        $this->running = true;
        echo "Server started on {$this->host}:{$this->port}" . PHP_EOL;
        $this->eventLoop();
    }

    /**
     * Create the server socket, bind it, and start listening.
     * @throws Exception
     */
    private function initializeServerSocket(): void
    {
        $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->serverSocket === false) {
            throw new Exception('Failed to create socket: ' . socket_strerror(socket_last_error()));
        }
        socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!socket_bind($this->serverSocket, $this->host, $this->port)) {
            throw new Exception('Failed to bind socket: ' . socket_strerror(socket_last_error($this->serverSocket)));
        }
        if (!socket_listen($this->serverSocket, 5)) {
            throw new Exception(
                'Failed to listen on socket: ' . socket_strerror(socket_last_error($this->serverSocket)),
            );
        }
    }

    /**
     * Main event loop
     */
    private function eventLoop(): void
    {
        while ($this->running) {
            $this->handleSocketActivity();
        }
    }

    /**
     * Handle socket activity using select()
     */
    private function handleSocketActivity(): void
    {
        $read = array_values($this->clients);
        $read[] = $this->serverSocket;
        $write = null;
        $except = null;
        // The $read array is modified by socket_select to contain only sockets with activity
        $activity = socket_select($read, $write, $except, 0, self::SELECT_TIMEOUT_MICROSECONDS);
        if ($activity === false) {
            $this->running = false;
            return;
        }
        if ($activity === 0) {
            return; // Timeout, continue loop
        }
        // Check for new connections
        if (in_array($this->serverSocket, $read, true)) {
            $this->acceptNewConnection();
        }
        // Handle client activity
        foreach ($read as $clientSocket) {
            if ($clientSocket === $this->serverSocket) {
                continue; // Skip server socket, it's for new connections
            }
            $this->handleClientActivity($clientSocket);
        }
    }

    /**
     * Accept a new client connection
     */
    private function acceptNewConnection(): void
    {
        $newClient = socket_accept($this->serverSocket);
        if ($newClient !== false) {
            // Use spl_object_id to get a unique ID for the socket object
            $this->clients[spl_object_id($newClient)] = $newClient;
            echo "New client connected" . PHP_EOL;
        }
    }

    /**
     * Handle activity on a client socket
     */
    private function handleClientActivity(Socket $clientSocket): void
    {
        $input = socket_read($clientSocket, self::BUFFER_SIZE);
        if ($input === false || $input === '') {
            $this->disconnectClient($clientSocket);
            return;
        }
        try {
            $this->processClientInput($clientSocket, $input);
        } catch (Exception $e) {
            $this->sendErrorResponse($clientSocket, $e->getMessage());
        }
    }

    /**
     * Disconnect a client
     */
    private function disconnectClient(Socket $clientSocket): void
    {
        echo "Client disconnected" . PHP_EOL;

        // Remove from replicas if it was one
        $this->replicationManager->removeReplica($clientSocket);

        // Use spl_object_id to find and remove the client
        unset($this->clients[spl_object_id($clientSocket)]);
        socket_close($clientSocket);
    }

    /**
     * Process input from a client
     * @throws Exception
     */
    private function processClientInput(Socket $clientSocket, string $input): void
    {
        $commandParts = $this->parseCommandAndArgs($input);
        if ($commandParts === null) {
            $this->sendErrorResponse($clientSocket, 'invalid command format');
            return;
        }
        [$commandName, $args] = $commandParts;
        echo "Received command: {$commandName}" . (empty($args) ? '' : ' ' . implode(' ', $args)) . PHP_EOL;

        $response = $this->registry->execute($commandName, $args);
        $this->sendResponse($clientSocket, $response);

        // Handle replication logic
        $this->handleReplicationForCommand($clientSocket, $commandName, $args);
    }

    /**
     * Parses the raw input string into a command and its arguments.
     *
     * @return array{0: string, 1: array}|null
     * @throws Exception
     */
    private function parseCommandAndArgs(string $input): ?array
    {
        $parsed = $this->parser->parse($input);
        if (!is_array($parsed) || empty($parsed) || !is_string($parsed[0])) {
            return null;
        }
        return [
            array_shift($parsed),
            $parsed,
        ];
    }

    /**
     * Send an error response to a client
     */
    private function sendErrorResponse(Socket $clientSocket, string $message): void
    {
        $errorResponse = ResponseFactory::error('ERR ' . $message);
        $this->sendResponse($clientSocket, $errorResponse);
    }

    /**
     * Send a response to a client
     */
    private function sendResponse(Socket $clientSocket, $response): void
    {
        socket_write($clientSocket, $response->serialize());
    }

    /**
     * Handle replication logic for commands
     */
    private function handleReplicationForCommand(Socket $clientSocket, string $commandName, array $args): void
    {
        $upperCommand = strtoupper($commandName);

        // Register replica after successful PSYNC
        if ($upperCommand === 'PSYNC') {
            $this->replicationManager->addReplica($clientSocket);
            return;
        }

        // Don't propagate commands from replicas back to other replicas
        if ($this->replicationManager->isReplica($clientSocket)) {
            return;
        }

        // Propagate write commands to replicas
        if ($this->isWriteCommand($upperCommand)) {
            $this->replicationManager->propagateCommand($commandName, $args);
        }
    }

    /**
     * Check if a command is a write command that should be propagated
     */
    private function isWriteCommand(string $commandName): bool
    {
        $writeCommands = [
            'SET',
            'DEL',
        ];

        return in_array($commandName, $writeCommands, true);
    }

    /**
     * Stop the server
     */
    public function stop(): void
    {
        $this->running = false;
        $this->cleanup();
        echo "Server stopped" . PHP_EOL;
    }

    /**
     * Cleanup resources
     */
    private function cleanup(): void
    {
        foreach ($this->clients as $client) {
            socket_close($client);
        }
        $this->clients = [];
        if ($this->serverSocket) {
            socket_close($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    public function getReplicationManager(): ReplicationManager
    {
        return $this->replicationManager;
    }
}
