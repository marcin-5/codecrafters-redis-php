<?php

namespace Redis\RESP\Response;

class ResponseFactory
{
    public static function ok(string $message = 'OK'): SimpleStringResponse
    {
        return new SimpleStringResponse($message);
    }

    public static function pong(): SimpleStringResponse
    {
        return new SimpleStringResponse('PONG');
    }

    public static function string(string $value): BulkStringResponse
    {
        return new BulkStringResponse($value);
    }

    public static function error(string $message): ErrorResponse
    {
        return new ErrorResponse($message);
    }

    public static function integer(int $value): IntegerResponse
    {
        return new IntegerResponse($value);
    }

    public static function array(array $elements): ArrayResponse
    {
        return new ArrayResponse($elements);
    }

    public static function null(): BulkStringResponse
    {
        return new BulkStringResponse(null);
    }

    public static function psync(string $fullresyncMessage, string $rdbContent): PsyncResponse
    {
        return new PsyncResponse($fullresyncMessage, $rdbContent);
    }

    // Common error responses
    public static function unknownCommand(string $command): ErrorResponse
    {
        return new ErrorResponse("ERR unknown command '$command'");
    }

    public static function wrongNumberOfArguments(string $command): ErrorResponse
    {
        return new ErrorResponse("ERR wrong number of arguments for '$command' command");
    }

    public static function syntaxError(): ErrorResponse
    {
        return new ErrorResponse('ERR syntax error');
    }

    public static function invalidExpireTime(): ErrorResponse
    {
        return new ErrorResponse('ERR invalid expire time');
    }
}
