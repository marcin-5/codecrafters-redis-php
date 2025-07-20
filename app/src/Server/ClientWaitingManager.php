<?php

namespace Redis\Server;

use Redis\RESP\Response\ArrayResponse;
use Redis\RESP\Response\ResponseFactory;
use Redis\Storage\RedisStream;
use Redis\Storage\StorageInterface;
use Socket;

class ClientWaitingManager
{
    /** @var array<string, WaitingClient[]> Stream key -> array of waiting clients */
    private array $waitingClients = [];

    public function addWaitingClient(
        Socket $clientSocket,
        array $streamKeys,
        array $ids,
        ?int $count,
        int $blockTimeout,
    ): void {
        $waitingClient = new WaitingClient(
            $clientSocket,
            $streamKeys,
            $ids,
            $count,
            $blockTimeout,
            microtime(true),
        );

        // Register client for all streams they're waiting on
        foreach ($streamKeys as $streamKey) {
            if (!isset($this->waitingClients[$streamKey])) {
                $this->waitingClients[$streamKey] = [];
            }
            $this->waitingClients[$streamKey][] = $waitingClient;
        }
    }

    public function checkAndNotifyWaitingClients(string $streamKey, StorageInterface $storage): array
    {
        if (!isset($this->waitingClients[$streamKey])) {
            return [];
        }

        $notifiedClients = [];
        $remainingClients = [];

        foreach ($this->waitingClients[$streamKey] as $waitingClient) {
            // Try to read data for this client
            $results = RedisStream::read(
                $waitingClient->getStreamKeys(),
                $waitingClient->getIds(),
                $waitingClient->getCount(),
                fn($key) => $storage->getStream($key),
            );

            if (!empty($results)) {
                // Client can be satisfied, send response
                $response = new ArrayResponse($results);
                $notifiedClients[] = [
                    'socket' => $waitingClient->getSocket(),
                    'response' => $response
                ];

                // Remove this client from all streams they were waiting on
                $this->removeWaitingClient($waitingClient);
            } else {
                // Client still needs to wait
                $remainingClients[] = $waitingClient;
            }
        }

        $this->waitingClients[$streamKey] = $remainingClients;

        return $notifiedClients;
    }

    private function removeWaitingClient(WaitingClient $clientToRemove): void
    {
        foreach ($this->waitingClients as $streamKey => &$clients) {
            $clients = array_filter($clients, fn($client) => $client !== $clientToRemove);
        }
    }

    public function checkTimeouts(): array
    {
        $currentTime = microtime(true);
        $timedOutClients = [];

        foreach ($this->waitingClients as $streamKey => &$clients) {
            $remainingClients = [];

            foreach ($clients as $waitingClient) {
                $elapsedTime = $currentTime - $waitingClient->getStartTime();
                $timeoutSeconds = $waitingClient->getBlockTimeout() / 1000.0;

                if ($waitingClient->getBlockTimeout() !== 0 && $elapsedTime >= $timeoutSeconds) {
                    // Client timed out
                    $timedOutClients[] = [
                        'socket' => $waitingClient->getSocket(),
                        'response' => ResponseFactory::null()
                    ];

                    // Remove this client from all streams
                    $this->removeWaitingClient($waitingClient);
                } else {
                    $remainingClients[] = $waitingClient;
                }
            }

            $clients = $remainingClients;
        }

        // Clean up empty stream entries
        $this->waitingClients = array_filter($this->waitingClients, fn($clients) => !empty($clients));

        return $timedOutClients;
    }

    public function removeClientSocket(Socket $clientSocket): void
    {
        foreach ($this->waitingClients as $streamKey => &$clients) {
            $clients = array_filter($clients, fn($client) => $client->getSocket() !== $clientSocket);
        }

        // Clean up empty stream entries
        $this->waitingClients = array_filter($this->waitingClients, fn($clients) => !empty($clients));
    }

    public function hasWaitingClients(): bool
    {
        return !empty($this->waitingClients);
    }
}
