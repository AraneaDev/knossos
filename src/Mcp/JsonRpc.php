<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * The JSON-RPC 2.0 wire: its error codes, its two envelopes, and how a
 * message is encoded for a transport.
 *
 * Every transport and the dispatcher frame what they send through here, so
 * a code means one thing in every place it is written, and the byte cap
 * replaces an oversized message the same way on stdio as over HTTP.
 */
final class JsonRpc
{
    /** The protocol version every envelope declares. */
    public const VERSION = '2.0';

    /** The body is not JSON, or a frame is too large to read. */
    public const PARSE_ERROR = -32700;

    /** The JSON is not a valid request object. */
    public const INVALID_REQUEST = -32600;

    /** No such method. */
    public const METHOD_NOT_FOUND = -32601;

    /** The method exists but its params are malformed. */
    public const INVALID_PARAMS = -32602;

    /** An unexpected failure in the server. */
    public const INTERNAL_ERROR = -32603;

    /** Too many requests arrived while the server was busy with a scan. */
    public const SERVER_BUSY = -32000;

    /** The response exceeds the configured byte limit. */
    public const RESPONSE_TOO_LARGE = -32001;

    /** An unknown resource, under the `2025-11-25` revision. */
    public const RESOURCE_NOT_FOUND = -32002;

    /** A request arrived before `notifications/initialized`. */
    public const NOT_INITIALIZED = -32003;

    /** The request body contradicts its HTTP headers. */
    public const HEADER_MISMATCH = -32020;

    /** The client asked for a protocol revision this server does not serve. */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /**
     * The flags a message is encoded with on the wire. ResultEnricher
     * measures max_chars with them, so a result is measured at the size the
     * transport sends.
     */
    public const WIRE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct() {}

    /**
     * An error envelope, optionally carrying structured data such as the supported revisions.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    public static function error(mixed $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => self::VERSION, 'id' => $id, 'error' => $error];
    }

    /**
     * A success envelope.
     *
     * @return array<string, mixed>
     */
    public static function success(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result];
    }

    /**
     * `$message` as the wire carries it, and whether it had to be replaced:
     * one longer than `$maxBytes` becomes a {@see self::RESPONSE_TOO_LARGE}
     * error for the same id, encoded without the slash flag as it always was.
     *
     * @param array<string, mixed> $message
     * @return array{0: string, 1: bool}
     */
    public static function encode(array $message, int $maxBytes): array
    {
        $encoded = json_encode($message, self::WIRE_FLAGS);
        if (strlen($encoded) <= $maxBytes) {
            return [$encoded, false];
        }

        return [json_encode(self::error($message['id'] ?? null, self::RESPONSE_TOO_LARGE, 'Response exceeds the configured byte limit.'), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), true];
    }
}
