<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use JsonException;
use stdClass;

/**
 * One request as the line a worker reads.
 *
 * The protocol's `params` is always a JSON object. A PHP array with nothing in
 * it encodes as a list, `[]`, which a worker that checks the shape refuses, so
 * a request without parameters (`shutdown`) is written with `{}`.
 */
final class RequestFrame
{
    /**
     * The message as one newline-terminated JSON line.
     *
     * @param array<string, mixed> $message
     * @throws JsonException when the message cannot be encoded
     */
    public static function encode(array $message): string
    {
        if (($message['params'] ?? null) === []) {
            $message['params'] = new stdClass();
        }

        return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
