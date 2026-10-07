-- Fix (found in M7): a deleted dataset kept its table_name, so the unique key (workspace_id, layer, table_name)
-- made re-creating <layer>.<table> fail. Deleted datasets now release the name (CleanupHandler); this releases
-- the names of datasets deleted before the fix.

UPDATE datasets d JOIN resources r ON r.tenant_id = d.tenant_id AND r.id = d.resource_id
   SET d.table_name = NULL
 WHERE r.status = 'deleted' AND d.table_name IS NOT NULL;
