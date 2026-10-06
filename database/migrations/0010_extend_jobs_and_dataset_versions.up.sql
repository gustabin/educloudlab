-- M4: 'profile' jobs (schema discovery of raw uploads) and a user-safe error message per dataset version.

ALTER TABLE jobs
    MODIFY type ENUM('ingest','sql_query','transform','validate','pipeline_run','cleanup','profile') NOT NULL;

ALTER TABLE dataset_versions
    ADD COLUMN error_message VARCHAR(300) NULL AFTER error_code;
