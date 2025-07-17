<?php

namespace Redis\Config;

use InvalidArgumentException;

class ArgumentParser
{
    public static function parse(array $argv): array
    {
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
                    $config['port'] = (int)$values[0];
                }
            ],
            '--dir' => [
                'values' => 1,
                'handler' => function (&$config, $values) {
                    $config['dir'] = $values[0];
                }
            ],
            '--dbfilename' => [
                'values' => 1,
                'handler' => function (&$config, $values) {
                    $config['dbfilename'] = $values[0];
                }
            ],
            '--replicaof' => [
                'values' => 2,
                'handler' => function (&$config, $values) {
                    $config['replicaof'] = [
                        'host' => $values[0],
                        'port' => (int)$values[1]
                    ];
                }
            ]
        ];

        // Parse arguments while respecting quotations
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
                throw new InvalidArgumentException("Argument $arg expects $expectedCount values.");
            }

            // Call handler to process argument and values
            $definition['handler']($config, $values);

            $i += 1 + $expectedCount; // Skip to the next option
        }

        return $config;
    }

    /**
     * Splits command-line arguments while preserving quoted strings.
     *
     * @param array $argv Raw arguments.
     * @return array Parsed arguments.
     */
    private static function splitArguments(array $argv): array
    {
        $command = implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));
        return array_merge([array_shift($argv)], str_getcsv($command, ' '));
    }
}
