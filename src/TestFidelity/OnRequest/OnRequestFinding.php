<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\OnRequest;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\OnRequestCategory;
use Knossos\TestFidelity\TestFindingKey;

/**
 * One on-request finding: computed when asked, never stored.
 *
 * Keyed by {@see TestFindingKey} as a stored finding is, so it has the same
 * identity across calls; its item id is `on_request:` and that key, which
 * keeps the sort's last tie-break stable (preflight F20). The key reads the
 * finding's identity, never its message: a message carries counts and the
 * base ref, which move between runs of one finding (G6-12).
 */
final readonly class OnRequestFinding
{
    /**
     * @param string $file the project-relative file the finding is about, the test file for `OVER_MOCKING`
     * @param ?string $double the double's display name; null for a finding about a whole file
     * @param ?string $target the target's display name; null when the finding has none
     * @param ?string $targetCanonical the target's canonical name, which the key reads
     * @param string $identity what tells two findings of one rule, file and target apart, which the key reads; empty when nothing does
     */
    public function __construct(
        public OnRequestCategory $category,
        public FindingConfidence $confidence,
        public string $ruleId,
        public string $subRule,
        public string $message,
        public string $file,
        public int $line,
        public ?string $double = null,
        public ?string $target = null,
        public ?string $targetCanonical = null,
        public string $identity = '',
    ) {}

    /** The identity that survives a rescan and a line move. */
    public function key(): string
    {
        return TestFindingKey::of($this->ruleId, $this->subRule, $this->file, $this->targetCanonical, $this->identity);
    }

    /**
     * The finding as an `audit_tests` item.
     *
     * @return array{id: string, key: string, category: string, confidence: string, rule_id: string, sub_rule: string, message: string, file: string, line: int, double: ?string, target: ?string, tier: string}
     */
    public function toItem(): array
    {
        $key = $this->key();

        return [
            'id' => 'on_request:' . $key,
            'key' => $key,
            'category' => $this->category->value,
            'confidence' => $this->confidence->value,
            'rule_id' => $this->ruleId,
            'sub_rule' => $this->subRule,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'double' => $this->double,
            'target' => $this->target,
            'tier' => 'on_request',
        ];
    }
}
