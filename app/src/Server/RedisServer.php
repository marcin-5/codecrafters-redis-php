<?php

namespace Redis\Server;

use Exception;
use Redis\Registry\CommandRegistry;
use Redis\Replication\ReplicaCommandProcessor;
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
    /** @var array<int, ReplicaCommandProcessor> */
    private array $replicaProcessors = [];
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
     * Registers a master connection for command propagation.
     *
     * @param Socket $masterSocket The socket representing the master connection.
     * @return void
     */
    public function registerMasterConnection(Socket $masterSocket): void
    {
        $socketId = spl_object_id($masterSocket);
        $this->clients[$socketId] = $masterSocket;
        $this->replicaProcessors[$socketId] = new ReplicaCommandProcessor(
            $this->parser, $this->registry, $masterSocket,
        );
        echo "Master connection registered for command propagation." . PHP_EOL;
    }

    /**
     * Process buffered data from master connection
     */
    public function processBufferedMasterData(Socket $masterSocket, string $data): void
    {
        $socketId = spl_object_id($masterSocket);
        if (isset($this->replicaProcessors[$socketId])) {
            $this->replicaProcessors[$socketId]->processIncomingData($data);
        }
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

        foreach ($read as $clientSocket) {
            if ($clientSocket !== $this->serverSocket) {
                $this->handleClientActivity($clientSocket);
            }
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
        $socketId = spl_object_id($clientSocket);
        if (isset($this->replicaProcessors[$socketId])) {
            $this->processMasterData($clientSocket, $socketId);
        } else {
            $this->processClientCommand($clientSocket);
        }
    }

    private function processMasterData(Socket $clientSocket, int $socketId): void
    {
        $data = socket_read($clientSocket, self::BUFFER_SIZE);
        if ($data === false || $data === '') {
            $this->disconnectClient($clientSocket, "Connection to master lost.");
            return;
        }
        $this->replicaProcessors[$socketId]->processIncomingData($data);
    }

    /**
     * Disconnect a client
     */
    private function disconnectClient(Socket $clientSocket, string $reason): void
    {
        $socketId = spl_object_id($clientSocket);
        echo "Client disconnected: {$socketId}. Reason: {$reason}" . PHP_EOL;
        unset($this->clients[$socketId], $this->replicaProcessors[$socketId]);
        $this->replicationManager->removeReplica($clientSocket);
        socket_close($clientSocket);
    }

    private function processClientCommand(Socket $clientSocket): void
    {
        $data = socket_read($clientSocket, self::BUFFER_SIZE);
        if ($data === false || $data === '') {
            $this->disconnectClient($clientSocket, "Connection closed by client.");
            return;
        }

        try {
            $offset = 0;
            $parsedCommand = $this->parser->parseNext($data, $offset);
            if (!is_array($parsedCommand) || empty($parsedCommand)) {
                return;
            }

            $commandName = strtolower(array_shift($parsedCommand));
            $args = $parsedCommand;
            $response = $this->registry->execute($commandName, $args);
            $this->sendResponse($clientSocket, $response);
            $this->handleReplicationForCommand($clientSocket, $commandName, $args);
        } catch (Exception $e) {
            echo "Error processing client command: " . $e->getMessage() . PHP_EOL;
            $this->sendErrorResponse($clientSocket, $e->getMessage());
        }
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
        $writeCommands = ['SET', 'DEL', 'INCR', 'DECR', 'LPUSH', 'RPUSH', 'HSET'];
        return in_array($commandName, $writeCommands, true);
    }

    /**
     * Send an error response to a client
     */
    private function sendErrorResponse(Socket $clientSocket, string $message): void
    {
        $errorResponse = ResponseFactory::error($message);
        $this->sendResponse($clientSocket, $errorResponse);
    }
}
