<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Two agent sessions write notes on one component. The second write replaced
 * the first without a word, and the first session's finding was gone; the old
 * text only showed in `previous`, after the fact. A write now keeps a model
 * note it would lose unless the caller says to replace it, or keeps its text.
 */
final class AnnotationOverwriteTest extends KnossosTestCase
{
    #[Group('query')]
    public function testADifferentModelNoteIsKeptUnlessReplaced(): void
    {
        [$queries, $project, $pdo] = $this->withNote('first finding');

        $refused = $queries->upsertAnnotation($project, 'App\\Checkout', 'note', 'second finding', execute: true);

        self::assertFalse($refused->data['executed']);
        self::assertSame('refused', $refused->data['action']);
        self::assertSame('first finding', $refused->data['previous']['value']);
        self::assertStringContainsString('replace', $refused->summary);
        self::assertSame('first finding', $pdo->query("SELECT value FROM annotations WHERE kind = 'note'")->fetchColumn());
    }

    #[Group('query')]
    public function testReplaceTrueReplacesIt(): void
    {
        [$queries, $project, $pdo] = $this->withNote('first finding');

        $written = $queries->upsertAnnotation($project, 'App\\Checkout', 'note', 'second finding', execute: true, replace: true);

        self::assertTrue($written->data['executed']);
        self::assertStringContainsString('Replaced', $written->summary);
        self::assertSame('second finding', $pdo->query("SELECT value FROM annotations WHERE kind = 'note'")->fetchColumn());
    }

    /** Extending a note keeps everything it said, so it needs no flag. */
    #[Group('query')]
    public function testAnExtensionThatKeepsTheOldTextIsWritten(): void
    {
        [$queries, $project, $pdo] = $this->withNote('first finding');

        $written = $queries->upsertAnnotation($project, 'App\\Checkout', 'note', 'first finding; and a second one', execute: true);

        self::assertTrue($written->data['executed']);
        self::assertSame('first finding; and a second one', $pdo->query("SELECT value FROM annotations WHERE kind = 'note'")->fetchColumn());
    }

    /** Re-recording the same text is how a stale note is confirmed. */
    #[Group('query')]
    public function testTheSameTextIsWritten(): void
    {
        [$queries, $project] = $this->withNote('first finding');

        self::assertTrue($queries->upsertAnnotation($project, 'App\\Checkout', 'note', 'first finding', execute: true)->data['executed']);
    }

    /** A person edits their own note in the pane; the guard is for one agent overwriting another. */
    #[Group('query')]
    public function testAUserNoteIsReplacedAsBefore(): void
    {
        [$queries, $project] = $this->withNote('the team says so', 'user');

        self::assertTrue($queries->upsertAnnotation($project, 'App\\Checkout', 'note', 'the team changed its mind', execute: true, author: 'user')->data['executed']);
    }

    /** A preview says, in its summary, that nothing was written. */
    #[Group('query')]
    public function testAPreviewSaysNothingWasWritten(): void
    {
        [$queries, $project] = $this->withNote('first finding');

        $preview = $queries->upsertAnnotation($project, 'App\\Checkout', 'note', 'second finding');

        self::assertStringStartsWith('Preview only, nothing written:', $preview->summary);
        self::assertStringContainsString('replace', implode(' ', $preview->warnings), 'The preview already says the write would be refused.');
    }

    /** @return array{0: ArchitectureQueryService, 1: string, 2: \PDO} */
    private function withNote(string $value, string $author = 'model'): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', $value, execute: true, author: $author);

        return [$queries, $ids['project'], $pdo];
    }
}
