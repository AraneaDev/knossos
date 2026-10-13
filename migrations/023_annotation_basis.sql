-- A model's annotation is an observation of code that can change under it.
-- `basis_json` keeps the files it rests on, each with the content hash it had
-- when the note was written: the component's own file and the files the agent
-- cited. A reader compares them with the files' current hashes and marks the
-- note stale when one changed or is gone. A person's note rests on nothing
-- and keeps `[]`.
ALTER TABLE annotations ADD COLUMN basis_json TEXT NOT NULL DEFAULT '[]';
