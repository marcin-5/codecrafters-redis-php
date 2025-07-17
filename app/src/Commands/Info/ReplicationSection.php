<?php

namespace Redis\Commands\Info;

class ReplicationSection implements InfoSectionInterface
{
    public function getName(): string
    {
        return 'replication';
    }

    public function getKeyValuePairs(): array
    {
        return [
            'role' => 'master',
        ];
    }
}
