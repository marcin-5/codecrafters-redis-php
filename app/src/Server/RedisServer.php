<?php

namespace Redis\Server;

use Exception;
use Redis\Commands\XAddCommand;
use Redis\Commands\XReadCommand;
use Redis\Registry\CommandRegistry;
use Redis\Replication\ReplicaCommandProcessor;
use Redis\Replication\ReplicationManager;
use Redis\RESP\Response\BlockingWaitResponse;
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
    private ClientWaitingManager $waitingManager;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly RESPParser $parser,
        private readonly CommandRegistry $registry,
        private readonly ReplicationManager $replicationManager,
    ) {
        $this->waitingManager = new ClientWaitingManager();
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
            $this->handleWaitingClientTimeouts();
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
        $socketId = spl_object_id($clientSocket);

        if (isset($this->replicaProcessors[$socketId])) {
            // Data from master
            $data = socket_read($clientSocket, self::BUFFER_SIZE);
            if ($data === false || $data === '') {
                echo "Connection to master lost." . PHP_EOL;
                $this->disconnectClient($clientSocket);
                // You might want to add logic here to attempt reconnection
                return;
            }
            $this->replicaProcessors[$socketId]->processIncomingData($data);
        } else {
            // Data from a regular client
            $this->handleRegularClient($clientSocket);
        }
    }

    /**
     * Disconnect a client
     */
    private function disconnectClient(Socket $clientSocket): void
    {
        $socketId = spl_object_id($clientSocket);
        echo "Client disconnected: {$socketId}" . PHP_EOL;

        // Clean up resources
        unset($this->clients[$socketId]);
        unset($this->replicaProcessors[$socketId]);
        $this->replicationManager->removeReplica($clientSocket);

        // Remove client from waiting list
        $this->waitingManager->removeClientSocket($clientSocket);

        socket_close($clientSocket);
    }

    private function handleRegularClient(Socket $clientSocket): void
    {
        $data = socket_read($clientSocket, self::BUFFER_SIZE);
        if ($data === false || $data === '') {
            $this->disconnectClient($clientSocket);
            return;
        }

        try {
            $offset = 0;
            $parsedCommand = $this->parser->parseNext($data, $offset);

            if (!is_array($parsedCommand) || empty($parsedCommand)) {
                return;
            }

            // Validate we have at least one element before shifting
            if (count($parsedCommand) === 0) {
                return;
            }

            $commandName = strtolower(array_shift($parsedCommand));
            $args = $parsedCommand;

            // Set up waiting manager for XREAD commands
            $command = $this->registry->getCommand($commandName);
            if ($command instanceof XReadCommand) {
                $command->setClientSocket($clientSocket);
                $command->setWaitingManager($this->waitingManager);
            }

            // Pass $clientSocket as the first parameter
            $response = $this->registry->execute($clientSocket, $commandName, $args);

            // Handle blocking wait response
            if ($response instanceof BlockingWaitResponse) {
                return;
            }

            $this->sendResponse($clientSocket, $response);

            // Handle XADD notifications - validate args before accessing
            if ($command instanceof XAddCommand && !empty($args)) {
                $notifiedClients = $this->waitingManager->checkAndNotifyWaitingClients(
                    $args[0], // stream key
                    $this->registry->getStorage(),
                );

                foreach ($notifiedClients as $clientInfo) {
                    $this->sendResponse($clientInfo['socket'], $clientInfo['response']);
                }
            }

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
        $writeCommands = [
            'SET',
            'DEL',
            'EXPIRE',
            'FLUSHALL',
            'FLUSHDB',
            'XADD', // Add XADD as a write command
        ];

        return in_array($commandName, $writeCommands, true);
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
     * Handle timeouts for waiting clients
     */
    private function handleWaitingClientTimeouts(): void
    {
        $timedOutClients = $this->waitingManager->checkTimeouts();

        foreach ($timedOutClients as $clientInfo) {
            $this->sendResponse($clientInfo['socket'], $clientInfo['response']);
        }
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
