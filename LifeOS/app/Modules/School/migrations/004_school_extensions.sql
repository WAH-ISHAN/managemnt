CREATE TABLE school_reward_redemptions(request_id INTEGER PRIMARY KEY REFERENCES school_reward_requests(id),guardian_id TEXT NOT NULL,redeemed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
