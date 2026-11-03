INSERT OR IGNORE INTO modules(profile_id,module_id,enabled,sort_order) SELECT id,'School',1,24 FROM local_profiles WHERE persona='school';
