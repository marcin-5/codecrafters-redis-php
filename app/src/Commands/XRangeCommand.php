<?php


namespace Redis\Commands;

use Redis\RESP\Response\ArrayResponse;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;

class XRangeCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'xrange';
    private const int MIN_ARGS_COUNT = 3; // stream_key, start, end

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function execute(object $client, array $args): RESPResponse
    {
        $parseResult = $this->parseArguments($args);
        if ($parseResult instanceof RESPResponse) {
            return $parseResult; // Return error response
        }

        [$streamKey, $start, $end, $count] = $parseResult;

        try {
            [$normalizedStart, $normalizedEnd] = $this->normalizeRangeBounds($start, $end);
            $results = $this->storage->xrange($streamKey, $normalizedStart, $normalizedEnd, $count);
            $formattedResults = $this->formatResults($results);

            return new ArrayResponse($formattedResults);
        } catch (\InvalidArgumentException $e) {
            return ResponseFactory::error($e->getMessage());
        } catch (\Exception $e) {
            return ResponseFactory::error("ERR " . $e->getMessage());
        }
    }

    private function parseArguments(array $args): array|RESPResponse
    {
        $argCount = count($args);

        if ($argCount < self::MIN_ARGS_COUNT) {
            return ResponseFactory::wrongNumberOfArguments(self::COMMAND_NAME);
        }

        $streamKey = $args[0];
        $start = $args[1];
        $end = $args[2];
        $count = null;

        // Optional COUNT argument
        if ($argCount >= 5 && strtoupper($args[3]) === 'COUNT') {
            if (!is_numeric($args[4])) {
                return ResponseFactory::error("ERR value is not an integer or out of range");
            }
            $count = (int)$args[4];
            if ($count < 0) {
                return ResponseFactory::error("ERR COUNT can't be negative");
            }
        }

        return [$streamKey, $start, $end, $count];
    }

    private function normalizeRangeBounds(string $start, string $end): array
    {
        // Replace '-' with 0 for the minimum sequence number
        if ($start === '-') {
            $start = '0-0';
        }

        // Replace '+' with the maximum possible timestamp and sequence
        if ($end === '+') {
            $end = PHP_INT_MAX . '-' . PHP_INT_MAX;
        }

        return [$start, $end];
    }

    private function formatResults(array $results): array
    {
        $formattedResults = [];
        foreach ($results as $id => $fields) {
            $entryArray = [$id];

            // Convert fields (which is a key-value map) to a flat array of [field, value, field, value, ...]
            $fieldsArray = [];
            foreach ($fields as $field => $value) {
                $fieldsArray[] = $field;
                $fieldsArray[] = $value;
            }

            $entryArray[] = $fieldsArray;
            $formattedResults[] = $entryArray;
        }

        return $formattedResults;
    }
}
