<?php

namespace Redis\Commands\Info;

interface InfoSectionInterface
{
    public function getName(): string;

    public function getKeyValuePairs(): array;
}
