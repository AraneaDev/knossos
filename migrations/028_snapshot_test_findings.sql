-- The test findings a snapshot was archived with, by key.
--
-- test_findings holds only what the active scan judged. A quality gate that
-- compares today's findings with a baseline's needs the baseline's, so the
-- archive copies them when it captures a snapshot. A row is a finding as
-- TestFindingKey names it: `key` is the sha1 of rule_id, sub_rule, file,
-- target and message, so it survives a rescan and a line moved by an edit
-- above it, and the line is not kept. Every finding is stored, suppressed or
-- not: suppression is read from knossos.json when a reader asks, so an edit
-- to it applies to a baseline too. `target` is the canonical name of the
-- production component the double was checked against, as the key reads
-- it; `file` and `target` are NULL for a finding that has neither.
--
-- Whether a snapshot has a record is not whether it has rows: a snapshot with
-- no findings has none and was still recorded, and one archived before this
-- migration was never recorded. `scan_snapshots.test_findings_recorded` tells
-- them apart: 1 when the archive wrote this snapshot's findings (possibly
-- none), 0 for every row that already exists, whose findings are gone.
--
-- Nor does a scan's having no findings mean the test rules judged it: the
-- scan active when a database is upgraded from before them has none because
-- they never ran. `scans.test_findings_judged` is 1 once a scan's findings
-- were written (possibly none), 0 for every scan that already exists; the
-- archive copies it into `test_findings_recorded`.
--
-- Rows follow the snapshot: deleting it (retention pruning, a removed scan or
-- project) deletes them, and the code that deletes a snapshot also deletes
-- them explicitly, since a scan writes with foreign keys off.
ALTER TABLE scan_snapshots ADD COLUMN test_findings_recorded INTEGER NOT NULL DEFAULT 0 CHECK (test_findings_recorded IN (0, 1));

ALTER TABLE scans ADD COLUMN test_findings_judged INTEGER NOT NULL DEFAULT 0 CHECK (test_findings_judged IN (0, 1));

CREATE TABLE snapshot_test_findings (
    project_id TEXT NOT NULL,
    scan_id TEXT NOT NULL,
    key TEXT NOT NULL,
    rule_id TEXT NOT NULL,
    category TEXT NOT NULL,
    sub_rule TEXT NOT NULL,
    confidence TEXT NOT NULL CHECK (confidence IN ('definite', 'warning')),
    file TEXT NULL,
    target TEXT NULL,
    message TEXT NOT NULL,
    PRIMARY KEY (scan_id, key),
    FOREIGN KEY (scan_id) REFERENCES scan_snapshots(scan_id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX snapshot_test_findings_project_idx ON snapshot_test_findings(project_id);
