<?php

namespace Redis\RESP\Response;

interface RESPResponse
{
    public function serialize(): string;
}
