CREATE TABLE platform_object_maps(peer TEXT NOT NULL,uuid TEXT NOT NULL,kind TEXT NOT NULL,local_id INTEGER,base_json TEXT,PRIMARY KEY(peer,uuid));
CREATE TABLE platform_local_ids(kind TEXT NOT NULL,row_id INTEGER NOT NULL,uuid TEXT NOT NULL UNIQUE,PRIMARY KEY(kind,row_id));
CREATE TRIGGER platform_task_identity_deleted AFTER DELETE ON tasks BEGIN DELETE FROM platform_local_ids WHERE kind='tasks' AND row_id=OLD.id;UPDATE platform_sync_map SET local_id=NULL WHERE local_id=OLD.id;END;
CREATE TRIGGER platform_contact_identity_deleted AFTER DELETE ON life_contacts BEGIN DELETE FROM platform_local_ids WHERE kind='contacts' AND row_id=OLD.id;UPDATE platform_object_maps SET local_id=NULL WHERE kind='contacts' AND local_id=OLD.id;END;
CREATE TRIGGER platform_document_identity_deleted AFTER DELETE ON documents BEGIN DELETE FROM platform_local_ids WHERE kind='documents' AND row_id=OLD.id;UPDATE platform_object_maps SET local_id=NULL WHERE kind='documents' AND local_id=OLD.id;END;
