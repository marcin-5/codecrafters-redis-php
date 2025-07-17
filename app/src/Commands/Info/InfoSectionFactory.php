<?php

namespace Redis\Commands\Info;

class InfoSectionFactory
{
    private array $sections = [];

    public function __construct()
    {
        $this->sections['replication'] = new ReplicationSection();
    }

    public function getSection(string $sectionName): ?InfoSectionInterface
    {
        return $this->sections[strtolower($sectionName)] ?? null;
    }

    public function getAllSections(): array
    {
        return $this->sections;
    }
}
