<?php

declare(strict_types=1);

/**
 * Prove that tools/release-lifecycle's rollback restored the backup.
 *
 *   php tools/lifecycle-restore-check.php BEFORE UPGRADED RESTORED
 *
 * Each argument is the `list-snapshots --json` output at one point of the
 * lifecycle: BEFORE when the backup was taken, UPGRADED after the upgrade scan,
 * RESTORED after the backup was copied back. The rollback used to be judged by
 * its commands exiting 0, which a copy that changed nothing also does. So the
 * upgrade has to have added a snapshot, and the restored database has to hold
 * exactly the snapshots the backup did, with the same one active. Exit 0 when
 * it does, 1 when it does not or an argument is not a snapshot listing, 64 when
 * called wrong.
 */

if ($argc !== 4) {
    fwrite(STDERR, "usage: php tools/lifecycle-restore-check.php BEFORE UPGRADED RESTORED\n");
    exit(64);
}

/** Print why the rollback is not proven and stop with a failing status. */
function lifecycleRefuse(string $message): never
{
    fwrite(STDERR, "release lifecycle: {$message}\n");
    exit(1);
}

/**
 * The snapshot ids in one listing, and the active one.
 *
 * @return array{0: list<string>, 1: string|null}
 */
function lifecycleSnapshots(string $file, string $label): array
{
    try {
        $listing = json_decode(is_file($file) ? (string) file_get_contents($file) : '', true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        lifecycleRefuse("the {$label} snapshot listing is not JSON: " . $exception->getMessage());
    }
    $snapshots = is_array($listing) ? ($listing['data']['snapshots'] ?? null) : null;
    if (!is_array($snapshots)) {
        lifecycleRefuse("the {$label} snapshot listing holds no data.snapshots");
    }
    $ids = [];
    $active = null;
    foreach ($snapshots as $snapshot) {
        if (!is_array($snapshot) || !is_string($snapshot['scan_id'] ?? null)) {
            lifecycleRefuse("the {$label} snapshot listing has a snapshot without a scan_id");
        }
        $ids[] = $snapshot['scan_id'];
        if (($snapshot['active'] ?? false) === true) {
            $active = $snapshot['scan_id'];
        }
    }
    sort($ids, SORT_STRING);

    return [$ids, $active];
}

[$before, $beforeActive] = lifecycleSnapshots($argv[1], 'pre-upgrade');
[$upgraded, $upgradedActive] = lifecycleSnapshots($argv[2], 'upgraded');
[$restored, $restoredActive] = lifecycleSnapshots($argv[3], 'restored');

if ($before === [] || $beforeActive === null) {
    lifecycleRefuse('the backup was taken of a database with no active snapshot, so restoring it proves nothing');
}
if ($upgradedActive === $beforeActive || count($upgraded) <= count($before)) {
    lifecycleRefuse('the upgrade scan added no snapshot, so the rollback has nothing to undo');
}
if ($restored !== $before || $restoredActive !== $beforeActive) {
    lifecycleRefuse(sprintf(
        'the restored database is not the backup: it holds %d snapshots with %s active, the backup %d with %s active',
        count($restored),
        $restoredActive ?? 'none',
        count($before),
        $beforeActive,
    ));
}
printf("release lifecycle: the rollback restored the backup's %d snapshot(s), %s active\n", count($restored), $beforeActive);
