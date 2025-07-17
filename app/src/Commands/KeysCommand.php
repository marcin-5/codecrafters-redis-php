<?php

namespace Redis\Commands;

use InvalidArgumentException;
use Redis\RESP\Response\ArrayResponse;
use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;
use Redis\Storage\StorageInterface;

/**
 * Implements the Redis KEYS command – finds all keys matching the specified pattern.
 */
class KeysCommand implements RedisCommand
{
    private const string COMMAND_NAME = 'keys';

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function execute(array $args): RESPResponse
    {
        // --- 1. Validate arguments & obtain the pattern --------------------
        $pattern = $this->validateAndGetPattern($args);
        if ($pattern === null) {            // validation already produced an error response
            return ResponseFactory::syntaxError();
        }

        // --- 2. Execute business logic ------------------------------------
        try {
            $matchingKeys = $this->findKeysMatchingPattern($pattern);
            return new ArrayResponse($matchingKeys);
        } catch (InvalidArgumentException) {
            // Pattern could not be converted to a valid regex
            return ResponseFactory::syntaxError();
        }
    }

    /**
     * Check the argument list and return the (non-empty) pattern,
     * or `null` when a syntax error should be returned.
     */
    private function validateAndGetPattern(array $args): ?string
    {
        if (count($args) !== 1) {
            return null;
        }

        $pattern = $args[0];
        return $pattern === '' ? null : $pattern;
    }

    /**
     * Finds all keys matching the given glob-style pattern.
     *
     * @param string $pattern
     * @return array<string>
     */
    private function findKeysMatchingPattern(string $pattern): array
    {
        $regex = $this->convertToRegex($pattern);
        $matchingKeys = [];

        foreach ($this->storage->keys() as $key) {   // use new abstraction
            if (preg_match($regex, $key) === 1) {
                $matchingKeys[] = $key;
            }
        }

        sort($matchingKeys, SORT_STRING);
        return $matchingKeys;
    }


    /**
     * Converts a Redis glob-style pattern to a PCRE regex and validates it.
     *
     * @throws InvalidArgumentException When the produced regex is invalid.
     */
    private function convertToRegex(string $pattern): string
    {
        // Escape, then replace Redis wildcards with their regex equivalents
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace(['\*', '\?', '\[', '\]'], ['.*', '.', '[', ']'], $pattern);
        $regex = '/^' . $pattern . '$/';

        // Validate compiled regex once
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException('Invalid glob pattern.');
        }

        return $regex;
    }
}
