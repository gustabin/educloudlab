DROP TABLE semantic_queries;
DROP TABLE dashboards;
DROP TABLE semantic_models;
DELETE FROM jobs WHERE type = 'semantic_query';
ALTER TABLE jobs
    MODIFY type ENUM('ingest','sql_query','transform','validate','pipeline_run','cleanup','profile') NOT NULL;
DELETE FROM resources WHERE type = 'semantic_model';
ALTER TABLE resources
    MODIFY type ENUM('storage','lakehouse','dataset','pipeline','notebook','dashboard') NOT NULL;
