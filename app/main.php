<?php

error_reporting(E_ALL);

// Load autoloader
require_once __DIR__ . '/autoload.php';

use Redis\Config\ArgumentParser;
use Redis\Config\ReplicationConfig;
use Redis\Registry\CommandRegistry;
use Redis\Replication\ReplicationClient;
use Redis\Replication\ReplicationManager;
use Redis\RESP\RESPParser;
use Redis\Server\RedisServer;

// Parse command line arguments
$config = ArgumentParser::parse($argv);

// Configure replication if --replicaof is provided
$replicationClient = null;
if (isset($config['replicaof'])) {
    $replicationConfig = ReplicationConfig::getInstance();
    $replicationConfig->setReplicaOf(
        $config['replicaof']['host'],
        $config['replicaof']['port'],
    );

    // Create replication client with replica port
    $replicationClient = new ReplicationClient(
        $config['replicaof']['host'],
        $config['replicaof']['port'],
        $config['port'],
    );
}

// Initialize components
$parser = new RESPParser();
$replicationManager = new ReplicationManager();
$registry = CommandRegistry::createWithDefaults($config, $replicationManager);
$server = new RedisServer('localhost', $config['port'], $parser, $registry, $replicationManager);

// Handle replication handshake if this is a replica
if ($replicationClient) {
    echo "Running as replica of {$config['replicaof']['host']}:{$config['replicaof']['port']}\n";

    try {
        $replicationClient->performHandshake();
        $masterSocket = $replicationClient->getSocket();
        if ($masterSocket) {
            $server->registerMasterConnection($masterSocket);
            
            // Process any remaining buffered data from handshake
            $bufferedData = $replicationClient->getBufferedData();
            if (!empty($bufferedData)) {
                echo "Processing buffered data from handshake: " . json_encode($bufferedData) . PHP_EOL;
                $server->processBufferedMasterData($masterSocket, $bufferedData);
            }
        } else {
            throw new Exception("Failed to get master socket after handshake.");
        }
    } catch (Exception $e) {
        echo "Failed to perform handshake: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Setup signal handlers for graceful shutdown (only if PCNTL extension is available)
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () use ($server, $replicationClient) {
        echo "\nShutting down server...\n";
        $server->stop();
        if ($replicationClient) {
            $replicationClient->disconnect();
        }
        exit(0);
    });
}

// Start the server
try {
    $server->start();
} catch (Exception $e) {
    echo "Server error: " . $e->getMessage() . "\n";
    exit(1);
}
