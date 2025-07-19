<?php

namespace Redis\Commands;

use Redis\RESP\Response\ResponseFactory;
use Redis\RESP\Response\RESPResponse;

class PsyncCommand implements RedisCommand
{
    // Hardcoded replication ID for now
    private const string REPL_ID = '8371b4fb1155b71f4a04d3e1bc3e18c4a990aeeb';
    private const int REPL_OFFSET = 0;

    // Empty RDB file content for now
    private const string EMPTY_RDB_BASE64 = 'UkVESVMwMDEx+glyZWRpcy12ZXIFNy4yLjD6CnJlZGlzLWJpdHPAQPoFY3RpbWXCbQi8ZfoIdXNlZC1tZW3CsMQQAPoIYW9mLWJhc2XAAP/wbjv+wP9aog==';

    public function execute(array $args): RESPResponse
    {
        if (count($args) < 2) {
            return ResponseFactory::wrongNumberOfArguments('psync');
        }

        $replId = $args[0];
        $replOffset = $args[1];

        echo "Received PSYNC with replId: {$replId}, offset: {$replOffset}" . PHP_EOL;

        // For initial sync (? and -1), respond with FULLRESYNC + RDB file
        if ($replId === '?' && $replOffset === '-1') {
            echo "Performing full resynchronization" . PHP_EOL;

            // Decode the empty RDB file from base64
            $emptyRdbData = base64_decode(self::EMPTY_RDB_BASE64);

            echo "Sending FULLRESYNC " . self::REPL_ID . " " . self::REPL_OFFSET . PHP_EOL;
            echo "Sending RDB file (" . strlen($emptyRdbData) . " bytes)" . PHP_EOL;

            return ResponseFactory::fullResync(self::REPL_ID, self::REPL_OFFSET, $emptyRdbData);
        }

        // For other cases, we could implement incremental sync logic in the future
        // For now, always do full resync
        echo "Performing full resynchronization (fallback)" . PHP_EOL;

        $emptyRdbData = base64_decode(self::EMPTY_RDB_BASE64);

        echo "Sending FULLRESYNC " . self::REPL_ID . " " . self::REPL_OFFSET . PHP_EOL;
        echo "Sending RDB file (" . strlen($emptyRdbData) . " bytes)" . PHP_EOL;

        return ResponseFactory::fullResync(self::REPL_ID, self::REPL_OFFSET, $emptyRdbData);
    }
}
