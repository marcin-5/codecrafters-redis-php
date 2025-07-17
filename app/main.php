<?php

error_reporting(E_ALL);

// Load autoloader
require_once __DIR__ . '/autoload.php';

use Redis\Registry\CommandRegistry;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\RESPParser;

// --------------------------------------------------
// Parse command-line arguments
// --------------------------------------------------
$config = [];
$listenPort = 6379;                 // <-- default port
$cliArgs = array_slice($argv, 1);

for ($i = 0; $i < count($cliArgs); $i++) {
    switch ($cliArgs[$i]) {
        case '--dir':
            if (isset($cliArgs[$i + 1])) {
                $config['dir'] = $cliArgs[++$i];
            }
            break;

        case '--dbfilename':
            if (isset($cliArgs[$i + 1])) {
                $config['dbfilename'] = $cliArgs[++$i];
            }
            break;

        case '--port':
            if (isset($cliArgs[$i + 1])) {
                $listenPort = (int)$cliArgs[++$i];
            }
            break;
    }
}

// --------------------------------------------------
// Initialise parser, registry and TCP server
// --------------------------------------------------
$parser = new RESPParser();
$registry = CommandRegistry::createWithDefaults($config);

$server_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server_sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($server_sock, "localhost", $listenPort);
socket_listen($server_sock, 5);

// Array to hold all client sockets
$clients = [];

echo "Server started on localhost:6379\n";

while (true) {
    // Prepare arrays for socket_select
    $read = $clients;
    $read[] = $server_sock; // Add server socket to monitor for new connections
    $write = null;
    $except = null;

    // Wait for activity on any socket (non-blocking with timeout)
    $activity = socket_select($read, $write, $except, 0, 200000); // 200ms timeout

    if ($activity === false) {
        break; // Error occurred
    }

    // Check if server socket has activity (new connection)
    if (in_array($server_sock, $read)) {
        $new_client = socket_accept($server_sock);
        if ($new_client !== false) {
            $clients[] = $new_client;
            echo "New client connected\n";
        }
        // Remove server socket from read array
        $key = array_search($server_sock, $read);
        unset($read[$key]);
    }

    // Handle activity on client sockets
    foreach ($read as $client_sock) {
        $input = socket_read($client_sock, 1024);

        if ($input === false || $input === '') {
            // Client disconnected
            echo "Client disconnected\n";
            $key = array_search($client_sock, $clients);
            unset($clients[$key]);
            socket_close($client_sock);
        } else {
            try {
                // Parse the RESP command
                $parsed = $parser->parse($input);

                if (is_array($parsed) && !empty($parsed)) {
                    $commandName = $parsed[0];
                    $args = array_slice($parsed, 1);

                    // Execute command and get response object
                    $response = $registry->execute($commandName, $args);

                    // Serialize and send response
                    socket_write($client_sock, $response->serialize());
                } else {
                    $errorResponse = ResponseFactory::error('ERR invalid command format');
                    socket_write($client_sock, $errorResponse->serialize());
                }
            } catch (Exception $e) {
                $errorResponse = ResponseFactory::error('ERR ' . $e->getMessage());
                socket_write($client_sock, $errorResponse->serialize());
            }
        }
    }
}

// Cleanup
foreach ($clients as $client) {
    socket_close($client);
}
socket_close($server_sock);
