ALTER TABLE connectivity_events ADD COLUMN local_deleted INTEGER NOT NULL DEFAULT 0 CHECK(local_deleted IN (0,1));
ALTER TABLE connectivity_events ADD COLUMN remote_deleted INTEGER NOT NULL DEFAULT 0 CHECK(remote_deleted IN (0,1));
CREATE TRIGGER connectivity_appointment_deleted BEFORE DELETE ON planner_appointments
BEGIN
    UPDATE connectivity_events SET local_deleted=1 WHERE appointment_id=OLD.id;
END;
