CREATE TABLE website_costs (
    id INTEGER PRIMARY KEY,
    website_id INTEGER NOT NULL REFERENCES website_profiles(id) ON DELETE RESTRICT,
    cost_date TEXT NOT NULL,
    kind TEXT NOT NULL CHECK(kind IN ('hosting','domain','maintenance','development','other')),
    amount REAL NOT NULL CHECK(amount>0),
    vendor TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    expense_id INTEGER UNIQUE REFERENCES expenses(id) ON DELETE SET NULL,
    money_recorded INTEGER NOT NULL DEFAULT 0 CHECK(money_recorded IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX website_costs_period ON website_costs(website_id,cost_date,id);
