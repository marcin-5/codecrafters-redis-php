<?php

namespace Redis\Commands;

use InvalidArgumentException;
use Redis\RESP\Response\ArrayResponse;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\RedisStream;
use Redis\Storage\StorageInterface;

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
        try {
            [$streamKeys, $ids, $count] = $this->parseArguments($args);
            $results = RedisStream::read(
                $streamKeys,
                $ids,
                $count,
                fn($key) => $this->storage->getStream($key),
            );
            return new ArrayResponse($results);
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
        $count = $this->parseCountArgument($optionsArgs);
        [$streamKeys, $ids] = $this->parseStreamKeysAndIds($streamAndIdArgs);
        return [$streamKeys, $ids, $count];
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
    private function parseCountArgument(array $optionsArgs): ?int
    {
        $count = null;
        for ($i = 0; $i < count($optionsArgs); $i++) {
            if (strtoupper($optionsArgs[$i]) === self::COUNT_KEYWORD) {
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
            } else {
                throw new InvalidArgumentException('ERR syntax error');
            }
        }
        return $count;
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
}
