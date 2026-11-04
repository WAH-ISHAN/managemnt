INSERT OR IGNORE INTO modules(profile_id,module_id,enabled,sort_order) SELECT id,'StudyTools',1,26 FROM local_profiles WHERE persona='uni';
