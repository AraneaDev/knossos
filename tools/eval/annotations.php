#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Dev-only. The annotations of one project in the eval's graph database, so
 * the harness can give every session a clean slate, or hand a second pass
 * exactly the notes its first pass left.
 *
 *   annotations.php clear  <db> <project-root>
 *   annotations.php export <db> <project-root> <file>   writes the rows as JSON, prints how many a model wrote
 *   annotations.php import <db> <project-root> <file>   replaces the project's rows with the file's, prints how many a model wrote
 *
 * The project is the one whose root is <project-root>.
 */

require_once dirname(__DIR__) . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
[, $command, $db, $root] = $argv + [null, null, null, null];
$file = $argv[4] ?? null;
if (!in_array($command, ['clear', 'export', 'import'], true) || !is_string($db) || !is_string($root) || ($command !== 'clear' && !is_string($file))) {
    fwrite(STDERR, "usage: annotations.php clear|export|import <db> <project-root> [file]\n");
    exit(2);
}

if ($command === 'import' && !is_file($file)) {
    // Importing nothing would hand a second pass an empty table and measure it as if it had notes.
    fwrite(STDERR, "annotations.php: no notes file at {$file}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// A shared watcher the previous session started can still be rescanning
// the reset workspace, and a large repository holds the write lock for
// longer than ten seconds.
$pdo->exec('PRAGMA busy_timeout = 120000');
$find = $pdo->prepare('SELECT id FROM projects WHERE root_realpath = ?');
$find->execute([realpath($root) ?: $root]);
$project = $find->fetchColumn();
// An open read keeps this connection in a read transaction, and SQLite answers
// a write that would have to upgrade it with BUSY at once, without waiting.
$find->closeCursor();
$columns = 'canonical_name, kind, value, author, basis_json, created_at, updated_at';
$byModel = static fn(array $rows): int => count(array_filter($rows, static fn(array $row): bool => $row['author'] === 'model'));

if ($command === 'export') {
    // A repository not scanned yet has no notes; its file still says so, for the second pass to import.
    $all = [];
    if (is_string($project)) {
        $rows = $pdo->prepare("SELECT {$columns} FROM annotations WHERE project_id = ? ORDER BY canonical_name, kind, author");
        $rows->execute([$project]);
        $all = $rows->fetchAll(PDO::FETCH_ASSOC);
    }
    file_put_contents($file, json_encode($all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo $byModel($all), "\n";
    exit(0);
}
if (!is_string($project)) {
    // Nor any to clear or restore.
    echo "0\n";
    exit(0);
}

$rows = $command === 'import' ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
// IMMEDIATE takes the write lock up front, where busy_timeout waits for it;
// a deferred transaction would only ask for it at the first write.
$pdo->exec('BEGIN IMMEDIATE');
$pdo->prepare('DELETE FROM annotations WHERE project_id = ?')->execute([$project]);
if ($command === 'import') {
    $insert = $pdo->prepare("INSERT INTO annotations(project_id, {$columns}) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $row) {
        $insert->execute([$project, $row['canonical_name'], $row['kind'], $row['value'], $row['author'], $row['basis_json'], $row['created_at'], $row['updated_at']]);
    }
}
$pdo->exec('COMMIT');
echo $byModel($rows), "\n";
