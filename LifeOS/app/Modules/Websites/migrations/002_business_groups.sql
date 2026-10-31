ALTER TABLE website_profiles ADD COLUMN business_name TEXT NOT NULL DEFAULT '';
CREATE INDEX website_profiles_business ON website_profiles(business_name COLLATE NOCASE);
