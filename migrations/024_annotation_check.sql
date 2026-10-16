-- A person can flag a component's note for the model to look at again: a
-- `check` annotation, written from the pane, whose value is the reason. The
-- kind is held to a CHECK constraint, so the table is rebuilt with it, every
-- column kept. Nothing references annotations, so rebuilding the table
-- cascades nothing.
CREATE TABLE annotations_new (
    project_id TEXT NOT NULL,
    canonical_name TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN
        ('intended_boundary', 'confirmed_dead', 'false_positive', 'intentional', 'note', 'check')),
    value TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT 'model' CHECK (author IN ('user', 'model')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    basis_json TEXT NOT NULL DEFAULT '[]',
    PRIMARY KEY (project_id, canonical_name, kind, author),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
INSERT INTO annotations_new (project_id, canonical_name, kind, value, author, created_at, updated_at, basis_json)
    SELECT project_id, canonical_name, kind, value, author, created_at, updated_at, basis_json FROM annotations;
DROP TABLE annotations;
ALTER TABLE annotations_new RENAME TO annotations;
CREATE INDEX annotations_project_idx ON annotations(project_id);
