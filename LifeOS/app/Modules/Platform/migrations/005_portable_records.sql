ALTER TABLE platform_imports ADD COLUMN target TEXT NOT NULL DEFAULT 'tasks';
ALTER TABLE platform_audit ADD COLUMN actor TEXT NOT NULL DEFAULT 'profile-owner';
ALTER TABLE platform_history ADD COLUMN actor TEXT NOT NULL DEFAULT 'profile-owner';
CREATE TRIGGER platform_employee_history AFTER UPDATE OF data ON employee_records WHEN OLD.data<>NEW.data BEGIN INSERT INTO platform_history(entity,row_id,before_json,after_json) VALUES('employee_records',NEW.id,json_object('data',OLD.data,'kind',OLD.kind),json_object('data',NEW.data,'kind',NEW.kind));END;
CREATE TRIGGER platform_study_history AFTER UPDATE OF data ON study_records WHEN OLD.data<>NEW.data BEGIN INSERT INTO platform_history(entity,row_id,before_json,after_json) VALUES('study_records',NEW.id,json_object('data',OLD.data,'kind',OLD.kind),json_object('data',NEW.data,'kind',NEW.kind));END;
