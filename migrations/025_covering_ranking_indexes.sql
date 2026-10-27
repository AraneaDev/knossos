-- Let the hub ranking count degrees and read boundary memberships from
-- indexes alone.
--
-- Every read of the graph leaves out what enrichers contributed, by origin,
-- and the endpoint indexes did not hold origin: each edge a degree count
-- selected was read from its table row. On a scan of this repository
-- (100,000 edges) one count took 190 ms against 37 ms from the index, and
-- architecture_health ran past its one-second default before its edge walk
-- finished. Appending origin keeps every seek these indexes served (the
-- leading columns are unchanged) and makes the counts covering.
--
-- The node-ordered membership read wanted boundary_id beside node_id for the
-- same reason: 52,000 row reads and a sort, 150 ms against 30 ms.
--
-- Plain DROP and CREATE INDEX statements are transactional, so this migration
-- runs under the migration runner's own transaction (no
-- -- migrate:no-transaction marker).
DROP INDEX edges_project_source_idx;
CREATE INDEX edges_project_source_idx ON edges(project_id, source_id, kind, origin);
DROP INDEX edges_project_target_idx;
CREATE INDEX edges_project_target_idx ON edges(project_id, target_id, kind, origin);
DROP INDEX boundary_memberships_project_node_idx;
CREATE INDEX boundary_memberships_project_node_idx ON boundary_memberships(project_id, node_id, boundary_id);
