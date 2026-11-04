CREATE TABLE profile_roles (
    profile_id TEXT NOT NULL REFERENCES local_profiles(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK(role IN ('employee','uni','school','freelancer','business','job_seeker')),
    PRIMARY KEY(profile_id,role)
);

-- Retain the existing single selection on upgrade. No personal records or
-- module preferences are rewritten by this migration.
INSERT INTO profile_roles(profile_id,role)
SELECT id,persona FROM local_profiles
WHERE persona IN ('employee','uni','school','freelancer','business','job_seeker');
