<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\FileContextQueryService;
use Knossos\Query\FileViolationQuery;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A `knossos.json` that cannot be read was read as one declaring no
 * policies, so a typo in the file turned every policy check off without a
 * word. The loader already answers a project without the file with no
 * policies; a failure is a real one, and is now passed on with its message.
 */
final class UnreadablePolicyConfigTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    /** No file, a readable one, an unreadable one, and policies passed in. */
    #[Group('query')]
    public function testThePoliciesCarryTheLoadersMessageWhenTheFileCannotBeRead(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-policies-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            self::assertSame(['policies' => [], 'error' => null], FileViolationQuery::policies($root, null));
            $declared = [['id' => 'no-calls', 'from_boundary' => 'Edge', 'deny_targets' => ['Core']]];
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'policies' => $declared], JSON_THROW_ON_ERROR));
            self::assertSame(['policies' => $declared, 'error' => null], FileViolationQuery::policies($root, null));
            file_put_contents($root . '/knossos.json', '{"version": 1,');
            self::assertSame(['policies' => [], 'error' => 'Invalid JSON configuration: Syntax error'], FileViolationQuery::policies($root, null));
            self::assertSame(['policies' => $declared, 'error' => null], FileViolationQuery::policies($root, $declared));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file's context lists no rules it could not read, and warns why rather than implying there are none. */
    #[Group('query')]
    public function testFileContextWarnsThatTheRulesCouldNotBeRead(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/knossos.json', '{"version": 1,');
            $context = (new FileContextQueryService($pdo))->fileContext($projectId, 'src/Core/Greeter.php');
            self::assertSame('ok', $context->data['status']);
            self::assertSame([], $context->data['policies']);
            self::assertSame(["The project's knossos.json could not be read, so no declared rules are listed: Invalid JSON configuration: Syntax error"], $context->warnings);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
