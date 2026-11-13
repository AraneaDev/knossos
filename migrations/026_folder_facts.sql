-- Per-folder facts and companions for the creation brief.
--
-- folder_facts holds what the files directly inside one directory have in
-- common, per extension and role (source or test), so a brief can say "files
-- here are final classes named *Service" without reading the graph at query
-- time. folder_companions holds which other files were added in the same
-- commits as a folder's files, computed from git once per HEAD.
--
-- Both are derived data and belong to the project, not to a scan: neither
-- refers to scans, and a rescan that changes nothing leaves the rows as they
-- are. They are rebuilt from the graph and from git, so they are not in
-- snapshots or bundles. Removing the project removes them.
CREATE TABLE folder_facts (
    project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    directory TEXT NOT NULL,
    extension TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('source', 'test')),
    files INTEGER NOT NULL,
    facts_json TEXT NOT NULL,
    PRIMARY KEY (project_id, directory, extension, role)
) WITHOUT ROWID;

CREATE TABLE folder_companions (
    project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    git_head TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('ok', 'unavailable')),
    companions_json TEXT NOT NULL,
    PRIMARY KEY (project_id)
) WITHOUT ROWID;
