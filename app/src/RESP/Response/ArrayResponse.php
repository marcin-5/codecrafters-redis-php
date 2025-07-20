<?php

namespace Redis\RESP\Response;

class ArrayResponse implements RESPResponse
{
    private array $elements;

    public function __construct(array $elements)
    {
        $this->elements = $elements;
    }

    public function getElements(): array
    {
        return $this->elements;
    }

    public function serialize(): string
    {
        $count = count($this->elements);
        $result = "*{$count}\r\n";

        foreach ($this->elements as $element) {
            if ($element instanceof RESPResponse) {
                $result .= $element->serialize();
            } else {
                // Auto-wrap non-RESP values in appropriate response types
                $result .= $this->wrapValue($element)->serialize();
            }
        }

        return $result;
    }

    private function wrapValue(mixed $value): RESPResponse
    {
        if (is_string($value)) {
            return new BulkStringResponse($value);
        } elseif (is_int($value)) {
            return new IntegerResponse($value);
        } elseif (is_null($value)) {
            return new BulkStringResponse(null);
        } elseif (is_array($value)) {
            // Recursively handle nested arrays
            return new ArrayResponse($value);
        } else {
            return new SimpleStringResponse((string)$value);
        }
    }
}
