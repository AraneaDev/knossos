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
 *   annotations.php import <db> <project-root> <file>   replaces the project's rows with the file's
 *
 * The project is the one whose root is <project-root>.
 */

[, $command, $db, $root] = $argv + [null, null, null, null];
$file = $argv[4] ?? null;
if (!in_array($command, ['clear', 'export', 'import'], true) || !is_string($db) || !is_string($root) || ($command !== 'clear' && !is_string($file))) {
    fwrite(STDERR, "usage: annotations.php clear|export|import <db> <project-root> [file]\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $db, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 10000');
$find = $pdo->prepare('SELECT id FROM projects WHERE root_realpath = ?');
$find->execute([realpath($root) ?: $root]);
$project = $find->fetchColumn();
if (!is_string($project)) {
    // A repository not scanned yet has no notes to clear, keep or restore.
    echo "0\n";
    exit(0);
}
$columns = 'canonical_name, kind, value, author, basis_json, created_at, updated_at';

if ($command === 'export') {
    $rows = $pdo->prepare("SELECT {$columns} FROM annotations WHERE project_id = ? ORDER BY canonical_name, kind, author");
    $rows->execute([$project]);
    $all = $rows->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($file, json_encode($all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo count(array_filter($all, static fn(array $row): bool => $row['author'] === 'model')), "\n";
    exit(0);
}

$pdo->beginTransaction();
$pdo->prepare('DELETE FROM annotations WHERE project_id = ?')->execute([$project]);
if ($command === 'import') {
    $rows = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $insert = $pdo->prepare("INSERT INTO annotations(project_id, {$columns}) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $row) {
        $insert->execute([$project, $row['canonical_name'], $row['kind'], $row['value'], $row['author'], $row['basis_json'], $row['created_at'], $row['updated_at']]);
    }
}
$pdo->commit();
echo "0\n";
