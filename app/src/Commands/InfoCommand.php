<?php

namespace Redis\Commands;

use Redis\Commands\Info\InfoFormatter;
use Redis\Commands\Info\InfoSectionFactory;
use Redis\RESP\Response\BulkStringResponse;
use Redis\RESP\Response\RESPResponse;

class InfoCommand implements RedisCommand
{
    private InfoSectionFactory $sectionFactory;
    private InfoFormatter $formatter;

    public function __construct()
    {
        $this->sectionFactory = new InfoSectionFactory();
        $this->formatter = new InfoFormatter();
    }

    public function execute(array $args): RESPResponse
    {
        // If specific section is requested
        if (count($args) > 0) {
            $sectionName = strtolower($args[0]);
            $section = $this->sectionFactory->getSection($sectionName);

            if ($section) {
                $output = $this->formatter->formatSection($section);
                return new BulkStringResponse($output);
            }

            // If section not found, return empty response
            return new BulkStringResponse('');
        }

        // If no section specified, return all sections
        $allSections = $this->sectionFactory->getAllSections();
        $output = $this->formatter->formatMultipleSections($allSections);
        return new BulkStringResponse($output);
    }
}
