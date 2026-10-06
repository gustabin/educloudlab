ALTER TABLE dataset_versions DROP COLUMN error_message;

DELETE FROM jobs WHERE type = 'profile';
ALTER TABLE jobs
    MODIFY type ENUM('ingest','sql_query','transform','validate','pipeline_run','cleanup') NOT NULL;
