<?php

namespace Redis\Replication;

use Socket;

/**
 * A value object to represent a replica's state.
 */
class Replica
{
    public function __construct(
        public readonly Socket $socket,
        public int $offset = 0,
    ) {
    }
}
