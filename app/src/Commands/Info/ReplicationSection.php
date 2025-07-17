<?php

namespace Redis\Commands\Info;

use Redis\Config\ReplicationConfig;

class ReplicationSection implements InfoSectionInterface
{
    public function getName(): string
    {
        return 'replication';
    }

    public function getKeyValuePairs(): array
    {
        $config = ReplicationConfig::getInstance();
        $pairs = [
            'role' => $config->getRole(),
        ];

        // Add master-specific info if this is a slave
        if ($config->isSlave()) {
            $pairs['master_host'] = $config->getMasterHost();
            $pairs['master_port'] = $config->getMasterPort();
        }

        return $pairs;
    }
}
