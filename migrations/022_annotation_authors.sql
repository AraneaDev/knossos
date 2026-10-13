-- An annotation records who wrote it: a person, through the pane (`user`), or
-- an agent (`model`). Both may annotate the same component with the same kind,
-- so the author joins the key. Every earlier row says `agent`: written through
-- the tool or the pane alike, they cannot be told apart, so they become
-- `model`, what they claimed. Nothing references annotations, so rebuilding
-- the table cascades nothing.
CREATE TABLE annotations_new (
    project_id TEXT NOT NULL,
    canonical_name TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN
        ('intended_boundary', 'confirmed_dead', 'false_positive', 'intentional', 'note')),
    value TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT 'model' CHECK (author IN ('user', 'model')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (project_id, canonical_name, kind, author),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
INSERT INTO annotations_new SELECT project_id, canonical_name, kind, value, 'model', created_at, updated_at FROM annotations;
DROP TABLE annotations;
ALTER TABLE annotations_new RENAME TO annotations;
CREATE INDEX annotations_project_idx ON annotations(project_id);
