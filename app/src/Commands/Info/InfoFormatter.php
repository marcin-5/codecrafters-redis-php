<?php

namespace Redis\Commands\Info;

class InfoFormatter
{
    public function formatMultipleSections(array $sections): string
    {
        $output = '';

        foreach ($sections as $section) {
            if (!empty($output)) {
                $output .= "\r\n"; // Add blank line between sections
            }

            $output .= "# {$section->getName()}\r\n";
            $output .= $this->formatSection($section);
        }

        return $output;
    }

    public function formatSection(InfoSectionInterface $section): string
    {
        $output = '';

        foreach ($section->getKeyValuePairs() as $key => $value) {
            $output .= "{$key}:{$value}\r\n";
        }

        return $output;
    }
}
