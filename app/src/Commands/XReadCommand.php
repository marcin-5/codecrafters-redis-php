<?php

namespace Redis\Commands;

use InvalidArgumentException;
use Redis\RESP\Response\ArrayResponse;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;
use Redis\Utils\StreamResultFormatter;

class XReadCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'xread';
    private const int MIN_ARGS_COUNT = 3; // STREAMS stream_key id
    private const string COUNT_KEYWORD = 'COUNT';
    private const string STREAMS_KEYWORD = 'STREAMS';

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function execute(array $args): RESPResponse
    {
        $parseResult = $this->parseArguments($args);
        if ($parseResult instanceof RESPResponse) {
            return $parseResult; // Return error response
        }

        [$streamKeys, $ids, $count] = $parseResult;

        try {
            $results = $this->readFromStreams($streamKeys, $ids, $count);
            return new ArrayResponse($results);
        } catch (InvalidArgumentException $e) {
            return ResponseFactory::error($e->getMessage());
        } catch (\Exception $e) {
            return ResponseFactory::error("ERR " . $e->getMessage());
        }
    }

    private function parseArguments(array $args): array|RESPResponse
    {
        if (count($args) < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        $count = $this->parseCountArgument($args);
        if ($count instanceof RESPResponse) {
            return $count;
        }

        $streamsIndex = $this->findStreamsKeywordIndex($args);
        if ($streamsIndex === null) {
            return ResponseFactory::syntaxError();
        }

        return $this->parseStreamKeysAndIds($args, $streamsIndex, $count);
    }

    private function parseCountArgument(array $args): int|null|RESPResponse
    {
        $argCount = count($args);
        for ($i = 0; $i < $argCount - 2; $i++) {
            if (strtoupper($args[$i]) === self::COUNT_KEYWORD) {
                if ($i + 1 >= $argCount) {
                    return ResponseFactory::syntaxError();
                }
                if (!is_numeric($args[$i + 1])) {
                    return ResponseFactory::error("ERR value is not an integer or out of range");
                }
                $count = (int)$args[$i + 1];
                if ($count < 0) {
                    return ResponseFactory::error("ERR COUNT can't be negative");
                }
                return $count;
            }
        }
        return null;
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

    private function parseStreamKeysAndIds(array $args, int $streamsIndex, ?int $count): array|RESPResponse
    {
        $remainingArgs = array_slice($args, $streamsIndex + 1);
        $remainingCount = count($remainingArgs);

        if ($remainingCount === 0 || $remainingCount % 2 !== 0) {
            return ResponseFactory::error(
                "ERR Unbalanced XREAD list of streams: for each stream key an ID or '$' must be specified.",
            );
        }

        $streamCount = $remainingCount / 2;
        $streamKeys = array_slice($remainingArgs, 0, $streamCount);
        $ids = array_slice($remainingArgs, $streamCount);

        return [$streamKeys, $ids, $count];
    }

    private function readFromStreams(array $streamKeys, array $ids, ?int $count): array
    {
        $results = [];
        foreach ($streamKeys as $index => $streamKey) {
            $id = $ids[$index];

            $stream = $this->storage->getStream($streamKey);
            if ($stream === null) {
                continue; // Skip non-existent streams
            }

            try {
                $streamResults = $stream->readAfter($id, $count);
                if (!empty($streamResults)) {
                    $results[] = [$streamKey, StreamResultFormatter::format($streamResults)];
                }
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("ERR Invalid stream ID specified as stream command argument");
            }
        }
        return $results;
    }
}
