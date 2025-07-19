<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class PsyncCommand implements RedisCommand
{
    // Hardcoded replication ID for now
    private const string REPL_ID = '8371b4fb1155b71f4a04d3e1bc3e18c4a990aeeb';
    private const int REPL_OFFSET = 0;

    public function execute(array $args): RESPResponse
    {
        if (count($args) < 2) {
            return ResponseFactory::wrongNumberOfArguments('psync');
        }

        $replId = $args[0];
        $replOffset = $args[1];

        echo "Received PSYNC with replId: {$replId}, offset: {$replOffset}" . PHP_EOL;

        // For initial sync (? and -1), respond with FULLRESYNC
        if ($replId === '?' && $replOffset === '-1') {
            $response = "FULLRESYNC " . self::REPL_ID . " " . self::REPL_OFFSET;
            echo "Responding with: {$response}" . PHP_EOL;
            return ResponseFactory::ok($response);
        }

        // For other cases, we could implement incremental sync logic in the future
        // For now, always do full resync
        $response = "FULLRESYNC " . self::REPL_ID . " " . self::REPL_OFFSET;
        echo "Responding with: {$response}" . PHP_EOL;
        return ResponseFactory::ok($response);
    }
}
