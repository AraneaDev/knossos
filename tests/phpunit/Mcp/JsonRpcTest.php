<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\JsonRpc;
use Knossos\Mcp\Protocol\Profile20251125;
use Knossos\Mcp\Protocol\Profile20260728;
use Knossos\Mcp\Protocol\UnsupportedProtocolVersionException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The JSON-RPC wire every transport frames through: the envelopes, and the
 * byte cap that replaces an oversized message with an error for its id.
 */
final class JsonRpcTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testTheEnvelopesHaveTheWireShape(): void
    {
        assertSame(['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]], JsonRpc::success(7, ['ok' => true]));
        assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Parse error'));
        assertSame(['code' => -32022, 'message' => 'm', 'data' => ['supported' => []]], JsonRpc::error('a', JsonRpc::UNSUPPORTED_PROTOCOL_VERSION, 'm', ['supported' => []])['error']);
    }

    /** A message over the cap becomes the -32001 error for the same id; one at the cap is sent as it is. */
    #[Group('mcp')]
    public function testAnOversizedMessageIsReplacedByAnErrorForItsId(): void
    {
        $message = JsonRpc::success('r/1', ['text' => str_repeat('x', 100)]);
        $exact = strlen(json_encode($message, JsonRpc::WIRE_FLAGS));

        assertSame([json_encode($message, JsonRpc::WIRE_FLAGS), false], JsonRpc::encode($message, $exact));
        [$encoded, $oversized] = JsonRpc::encode($message, $exact - 1);
        assertSame(true, $oversized);
        assertSame('{"jsonrpc":"2.0","id":"r\/1","error":{"code":-32001,"message":"Response exceeds the configured byte limit."}}', $encoded);
    }

    /** The frozen revisions keep their codes; they only name them now. */
    #[Group('mcp')]
    public function testTheProtocolProfilesKeepTheirCodes(): void
    {
        assertSame(-32002, (new Profile20251125())->resourceNotFoundCode());
        assertSame(-32602, (new Profile20260728())->resourceNotFoundCode());
        assertSame(-32022, UnsupportedProtocolVersionException::CODE);
    }
}
