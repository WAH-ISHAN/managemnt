CREATE TABLE school_bag_checks(class_id INTEGER NOT NULL REFERENCES school_records(id),date TEXT NOT NULL,PRIMARY KEY(class_id,date));
