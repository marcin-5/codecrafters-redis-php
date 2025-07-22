<?php

namespace Redis\Commands;

use InvalidArgumentException;
use Redis\RESP\Response\ArrayResponse;
use Redis\RESP\Response\BlockingWaitResponse;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Server\ClientWaitingManager;
use Redis\Storage\RedisStream;
use Redis\Storage\StorageInterface;
use Socket;

class XReadCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'xread';
    private const int MIN_ARGS_COUNT = 3; // STREAMS stream_key id
    private const string COUNT_KEYWORD = 'COUNT';
    private const string BLOCK_KEYWORD = 'BLOCK';
    private const string STREAMS_KEYWORD = 'STREAMS';
    private const string SPECIAL_ID_LATEST = '$';

    private ?Socket $clientSocket = null;
    private ?ClientWaitingManager $waitingManager = null;

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function setClientSocket(Socket $clientSocket): void
    {
        $this->clientSocket = $clientSocket;
    }

    public function setWaitingManager(ClientWaitingManager $waitingManager): void
    {
        $this->waitingManager = $waitingManager;
    }

    public function execute(array $args): RESPResponse
    {
        try {
            [$streamKeys, $ids, $count, $blockTimeout] = $this->parseArguments($args);

            // Non-blocking read
            if ($blockTimeout === null) {
                $results = RedisStream::read(
                    $streamKeys,
                    $ids,
                    $count,
                    fn($key) => $this->storage->getStream($key),
                );
                return new ArrayResponse($results);
            }

            // For blocking read, handle '$' ID specially
            $resolvedIds = $this->resolveStreamIdsForBlocking($streamKeys, $ids);

            // Blocking read - for '$' ID, we should not try immediate read
            // as '$' means "wait for new entries after the current latest"
            $shouldTryImmediateRead = true;
            foreach ($ids as $id) {
                if ($id === self::SPECIAL_ID_LATEST) {
                    $shouldTryImmediateRead = false;
                    break;
                }
            }

            if ($shouldTryImmediateRead) {
                $results = RedisStream::read(
                    $streamKeys,
                    $resolvedIds,
                    $count,
                    fn($key) => $this->storage->getStream($key),
                );

                // If we have results, return them immediately
                if (!empty($results)) {
                    return new ArrayResponse($results);
                }
            }

            // No immediate results or '$' ID used - register client as waiting
            if ($this->clientSocket && $this->waitingManager) {
                $this->waitingManager->addWaitingClient(
                    $this->clientSocket,
                    $streamKeys,
                    $resolvedIds,
                    $count,
                    $blockTimeout,
                );

                // Return special marker indicating client is now waiting
                // This will be handled by the server to not send a response yet
                return new BlockingWaitResponse();
            }

            // If a blocking read is requested but the waiting manager isn't configured,
            // return null as if the request timed out immediately.
            return ResponseFactory::null();
        } catch (InvalidArgumentException $e) {
            return ResponseFactory::error($e->getMessage());
        } catch (\Exception $e) {
            return ResponseFactory::error("ERR " . $e->getMessage());
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function parseArguments(array $args): array
    {
        if (count($args) < self::MIN_ARGS_COUNT) {
            throw new InvalidArgumentException(
                "ERR wrong number of arguments for '" . self::COMMAND_NAME . "' command",
            );
        }

        $streamsIndex = $this->findStreamsKeywordIndex($args);
        if ($streamsIndex === null) {
            throw new InvalidArgumentException('ERR syntax error');
        }

        $optionsArgs = array_slice($args, 0, $streamsIndex);
        $streamAndIdArgs = array_slice($args, $streamsIndex + 1);

        [$count, $blockTimeout] = $this->parseOptionsArguments($optionsArgs);
        [$streamKeys, $ids] = $this->parseStreamKeysAndIds($streamAndIdArgs);

        return [$streamKeys, $ids, $count, $blockTimeout];
    }

    private function findStreamsKeywordIndex(array $args): ?int
    {
        foreach ($args as $i => $arg) {
            if (strtoupper($arg) === self::STREAMS_KEYWORD) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function parseOptionsArguments(array $optionsArgs): array
    {
        $count = null;
        $blockTimeout = null;

        for ($i = 0; $i < count($optionsArgs); $i++) {
            $keyword = strtoupper($optionsArgs[$i]);

            if ($keyword === self::COUNT_KEYWORD) {
                if ($count !== null) {
                    throw new InvalidArgumentException('ERR syntax error');
                }
                if (!isset($optionsArgs[$i + 1])) {
                    throw new InvalidArgumentException('ERR syntax error');
                }
                $countValue = $optionsArgs[$i + 1];
                if (!is_numeric($countValue)) {
                    throw new InvalidArgumentException('ERR value is not an integer or out of range');
                }
                $count = (int)$countValue;
                if ($count < 0) {
                    throw new InvalidArgumentException("ERR COUNT can't be negative");
                }
                $i++; // Skip the value part
            } elseif ($keyword === self::BLOCK_KEYWORD) {
                if ($blockTimeout !== null) {
                    throw new InvalidArgumentException('ERR syntax error');
                }
                if (!isset($optionsArgs[$i + 1])) {
                    throw new InvalidArgumentException('ERR syntax error');
                }
                $blockValue = $optionsArgs[$i + 1];
                if (!is_numeric($blockValue)) {
                    throw new InvalidArgumentException('ERR timeout is not an integer or out of range');
                }
                $blockTimeout = (int)$blockValue;
                if ($blockTimeout < 0) {
                    throw new InvalidArgumentException("ERR timeout is negative");
                }
                $i++; // Skip the value part
            } else {
                throw new InvalidArgumentException('ERR syntax error');
            }
        }

        return [$count, $blockTimeout];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function parseStreamKeysAndIds(array $args): array
    {
        $remainingCount = count($args);
        if ($remainingCount === 0 || $remainingCount % 2 !== 0) {
            throw new InvalidArgumentException(
                "ERR Unbalanced XREAD list of streams: for each stream key an ID or '$' must be specified.",
            );
        }
        $streamCount = $remainingCount / 2;
        $streamKeys = array_slice($args, 0, $streamCount);
        $ids = array_slice($args, $streamCount);
        return [$streamKeys, $ids];
    }

    /**
     * Resolves stream IDs for blocking read, converting '$' to the last entry ID.
     */
    private function resolveStreamIdsForBlocking(array $streamKeys, array $ids): array
    {
        $resolvedIds = $ids;
        foreach ($streamKeys as $i => $streamKey) {
            if ($ids[$i] === self::SPECIAL_ID_LATEST) {
                $stream = $this->storage->getStream($streamKey);
                $lastId = $stream ? $stream->getLastId() : null;

                if ($lastId) {
                    $resolvedIds[$i] = $lastId->toString();
                } else {
                    // If the stream doesn't exist or is empty, '$' should wait for any new entry
                    // Use '0-0' as the base ID so any new entry will be greater
                    $resolvedIds[$i] = '0-0';
                }
            }
        }
        return $resolvedIds;
    }
}
