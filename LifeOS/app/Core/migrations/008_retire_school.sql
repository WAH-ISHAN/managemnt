-- Retire configuration only. Preserve historical records and migration checksums.
UPDATE modules SET enabled=0 WHERE module_id='School';
DELETE FROM profile_roles WHERE role='school';
UPDATE local_profiles SET persona='custom',dashboard_layout='balanced',template=''
WHERE persona='school';
UPDATE local_profiles SET template='' WHERE template='grade_11';
