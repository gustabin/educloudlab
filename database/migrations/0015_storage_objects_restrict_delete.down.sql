ALTER TABLE storage_objects DROP FOREIGN KEY fk_storage_objects_container;
ALTER TABLE storage_objects
    ADD CONSTRAINT fk_storage_objects_container FOREIGN KEY (tenant_id, container_id)
        REFERENCES storage_containers (tenant_id, id) ON DELETE CASCADE;
