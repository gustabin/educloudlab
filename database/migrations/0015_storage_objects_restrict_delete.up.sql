-- Security gate M7-02: a container with objects must never be deleted by the database cascade (the object rows
-- would vanish while their bytes stay on disk, outside the storage quota). The service deletes objects first.

ALTER TABLE storage_objects DROP FOREIGN KEY fk_storage_objects_container;
ALTER TABLE storage_objects
    ADD CONSTRAINT fk_storage_objects_container FOREIGN KEY (tenant_id, container_id)
        REFERENCES storage_containers (tenant_id, id) ON DELETE RESTRICT;
