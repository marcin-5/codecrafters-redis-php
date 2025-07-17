<?php

namespace Redis\Config;

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

        for ($i = 1; $i < count($argv); $i++) {
            switch ($argv[$i]) {
                case '--port':
                    if (isset($argv[$i + 1])) {
                        $config['port'] = (int)$argv[$i + 1];
                        $i++; // Skip the next argument as it's the port value
                    }
                    break;

                case '--dir':
                    if (isset($argv[$i + 1])) {
                        $config['dir'] = $argv[$i + 1];
                        $i++; // Skip the next argument as it's the dir value
                    }
                    break;

                case '--dbfilename':
                    if (isset($argv[$i + 1])) {
                        $config['dbfilename'] = $argv[$i + 1];
                        $i++; // Skip the next argument as it's the dbfilename value
                    }
                    break;

                case '--replicaof':
                    if (isset($argv[$i + 1])) {
                        $replicaofValue = $argv[$i + 1];
                        $parts = explode(' ', $replicaofValue);
                        if (count($parts) === 2) {
                            $config['replicaof'] = [
                                'host' => $parts[0],
                                'port' => (int)$parts[1]
                            ];
                        }
                        $i++; // Skip the next argument as it's the replicaof value
                    }
                    break;
            }
        }

        return $config;
    }
}
