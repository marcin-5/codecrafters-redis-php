<?php
error_reporting(E_ALL);

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// Since the tester restarts your program quite often, setting SO_REUSEADDR
// ensures that we don't run into 'Address already in use' errors
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, "localhost", 6379);
socket_listen($sock, 5);

while (true) {
    $client = socket_accept($sock); // Wait for client connection
    
    if ($client === false) {
        continue;
    }
    
    while (true) {
        $input = socket_read($client, 1024);
        
        if ($input === false || $input === '') {
            break; // Client disconnected
        }
        
        // Send hardcoded PONG response for any command
        socket_write($client, "+PONG\r\n");
    }
    
    socket_close($client);
}
