<?php

declare(strict_types=1);

/*
 * Writes every copy of the Knossos skill from its one source,
 * skills/graph/SKILL.md, or with --check refuses when a committed copy differs
 * from what it would write.
 *
 *   php tools/skill-copies.php [--check] [--root=DIR]
 *
 * Each agent host loads the skill from its own plugin tree under its own
 * name, so a copy differs from the source only in its `name:` line and in the
 * line saying where it comes from. Kept by hand, the copies drifted one edit
 * at a time and a test had to catch each one; generated, an edit goes to the
 * source and the copies follow. --root names the checkout, this one unless
 * given; the tests point it at a stale copy to see the check refuse.
 */
require_once __DIR__ . '/lib/cli-arguments.php';
$options = cliOptions('usage: php tools/skill-copies.php [--check] [--root=DIR]', ['root'], ['check'], 64);
$root = isset($options['root']) ? rtrim($options['root'], '/') : dirname(__DIR__);
$check = isset($options['check']);

const SKILL_SOURCE = 'skills/graph/SKILL.md';

/** Copy path => the skill name its host loads it under. */
const SKILL_COPIES = [
    'plugins/knossos/skills/knossos/SKILL.md' => 'knossos',
];

$source = file_get_contents($root . '/' . SKILL_SOURCE);
if (!is_string($source) || preg_match('/\A---\n(.*?)\n---\n/s', $source, $frontmatter) !== 1) {
    fwrite(STDERR, SKILL_SOURCE . " has no frontmatter to rename.\n");
    exit(1);
}
$stale = [];
foreach (SKILL_COPIES as $path => $name) {
    $renamed = preg_replace('/^name: .*$/m', 'name: ' . $name, $frontmatter[0], 1);
    $contents = $renamed
        . "\n<!-- Generated from " . SKILL_SOURCE . " by tools/skill-copies.php; edit that file, not this one. -->\n"
        . substr($source, strlen($frontmatter[0]));
    $target = $root . '/' . $path;
    if ($check) {
        if (!is_file($target) || file_get_contents($target) !== $contents) {
            $stale[] = $path;
        }
        continue;
    }
    file_put_contents($target, $contents);
}
if ($stale !== []) {
    fwrite(STDERR, 'Skill copies are stale: ' . implode(', ', $stale) . '; run php tools/skill-copies.php' . PHP_EOL);
    exit(1);
}
echo $check ? "Skill copies are current.\n" : "Wrote the skill copies.\n";
