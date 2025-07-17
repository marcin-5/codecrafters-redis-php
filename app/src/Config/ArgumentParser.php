<?php

namespace Redis\Config;

use InvalidArgumentException;

class ArgumentParser
{
    public static function parse(array $argv): array
    {
        if (empty($argv)) {
            throw new InvalidArgumentException('No arguments provided');
        }

        $config = [
            'port' => 6379,
            'dir' => '/tmp/rdb',
            'dbfilename' => 'dump.rdb',
            'replicaof' => null
        ];

        // Define valid arguments, expected value count, and handlers
        $argDefinition = [
            '--port' => [
                'values' => 1,
                'handler' => function (&$config, $values) {
                    $port = (int)$values[0];
                    if ($port < 1 || $port > 65535) {
                        throw new InvalidArgumentException("Port must be between 1 and 65535, got: $port");
                    }
                    $config['port'] = $port;
                }
            ],
            '--dir' => [
                'values' => 1,
                'handler' => function (&$config, $values) {
                    $dir = $values[0];
                    if (empty($dir) || strpos($dir, '..') !== false) {
                        throw new InvalidArgumentException("Invalid directory path: $dir");
                    }
                    $config['dir'] = $dir;
                }
            ],
            '--dbfilename' => [
                'values' => 1,
                'handler' => function (&$config, $values) {
                    $filename = $values[0];
                    if (empty($filename) || strpos($filename, '/') !== false) {
                        throw new InvalidArgumentException("Invalid filename: $filename");
                    }
                    $config['dbfilename'] = $filename;
                }
            ],
            '--replicaof' => [
                'values' => 2,
                'handler' => function (&$config, $values) {
                    $host = $values[0];
                    $port = (int)$values[1];

                    if (empty($host)) {
                        throw new InvalidArgumentException("Host cannot be empty");
                    }
                    if ($port < 1 || $port > 65535) {
                        throw new InvalidArgumentException("Replica port must be between 1 and 65535, got: $port");
                    }

                    $config['replicaof'] = [
                        'host' => $host,
                        'port' => $port
                    ];
                }
            ]
        ];

        // Parse arguments while handling quoted strings
        $parsedArgs = self::splitArguments($argv);

        // Process parsed arguments
        for ($i = 1; $i < count($parsedArgs);) {
            $arg = $parsedArgs[$i];

            if (!isset($argDefinition[$arg])) {
                throw new InvalidArgumentException("Unknown argument: $arg");
            }

            $definition = $argDefinition[$arg];
            $expectedCount = $definition['values'];
            $values = array_slice($parsedArgs, $i + 1, $expectedCount);

            if (count($values) !== $expectedCount) {
                $valueText = $expectedCount === 1 ? 'value' : 'values';
                throw new InvalidArgumentException(
                    "Argument $arg expects $expectedCount $valueText, got " . count($values),
                );
            }

            // Call handler to process argument and values
            $definition['handler']($config, $values);

            $i += 1 + $expectedCount; // Skip to the next option
        }

        return $config;
    }

    /**
     * Splits command-line arguments while preserving quoted strings.
     * Handles cases like --replicaof "localhost 6379"
     *
     * @param array $argv Raw arguments from command line
     * @return array Parsed arguments with quoted strings properly split
     */
    private static function splitArguments(array $argv): array
    {
        if (empty($argv)) {
            return [];
        }

        $result = [$argv[0]]; // Keep the program name

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            // Check if this argument contains spaces (indicating it was quoted)
            if (str_contains($arg, ' ')) {
                // Split the quoted argument by spaces
                $parts = explode(' ', $arg);
                $result = array_merge($result, $parts);
            } else {
                // Regular argument, add as-is
                $result[] = $arg;
            }
        }

        return $result;
    }
}
