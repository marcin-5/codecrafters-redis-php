<?php

namespace Redis\Utils;

class StreamResultFormatter
{
    /**
     * Formats stream results from key-value format to Redis protocol format.
     * Converts array of [id => [field => value, ...]] to array of [id, [field, value, field, value, ...]]
     */
    public static function format(array $results): array
    {
        $formatted = [];
        foreach ($results as $id => $fields) {
            $fieldsArray = [];
            foreach ($fields as $field => $value) {
                $fieldsArray[] = $field;
                $fieldsArray[] = $value;
            }
            $formatted[] = [$id, $fieldsArray];
        }
        return $formatted;
    }
}
