INSERT OR IGNORE INTO modules(profile_id,module_id,enabled,sort_order) SELECT id,'Employee',1,25 FROM local_profiles WHERE persona='employee';
