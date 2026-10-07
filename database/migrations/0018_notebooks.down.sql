DROP TABLE notebook_runs;
DROP TABLE notebooks;
DELETE FROM jobs WHERE type = 'notebook_run';
ALTER TABLE jobs
    MODIFY type ENUM('ingest','sql_query','transform','validate','pipeline_run','cleanup','profile','semantic_query') NOT NULL;
DELETE FROM resources WHERE type = 'notebook';
