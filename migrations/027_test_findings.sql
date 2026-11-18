-- Test-fidelity findings: a test double that no longer matches the code it
-- replaces, or an assertion that cannot fail.
--
-- Shaped like classifications: node_id is the test double or test case the
-- finding is about, target_node_id the production component it was checked
-- against, when there is one. A finding belongs to the scan that judged it:
-- every scan that rebuilds the graph replaces the project's findings
-- wholesale, so last_scan_id is always the scan that last ran the rules, and
-- a scan that takes the no-change path leaves them as they were. They are not
-- in snapshots or bundles.
CREATE TABLE test_findings (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    node_id TEXT NOT NULL,
    target_node_id TEXT NULL,
    category TEXT NOT NULL,
    origin TEXT NOT NULL,
    confidence TEXT NOT NULL CHECK (confidence IN ('definite', 'warning')),
    rule_id TEXT NOT NULL,
    sub_rule TEXT NOT NULL,
    message TEXT NOT NULL,
    file_id TEXT NULL,
    start_line INTEGER NULL CHECK (start_line IS NULL OR start_line >= 1),
    end_line INTEGER NULL CHECK (end_line IS NULL OR end_line >= start_line),
    attributes_json TEXT NOT NULL DEFAULT '{}',
    last_scan_id TEXT NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (target_node_id) REFERENCES nodes(id) ON DELETE SET NULL,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (last_scan_id) REFERENCES scans(id) ON DELETE RESTRICT,
    UNIQUE (project_id, node_id, rule_id, sub_rule, start_line, message)
);

CREATE INDEX test_findings_project_scan_idx ON test_findings(project_id, last_scan_id);
CREATE INDEX test_findings_target_idx ON test_findings(target_node_id);
CREATE INDEX test_findings_node_idx ON test_findings(node_id);
CREATE INDEX test_findings_file_idx ON test_findings(file_id);
