CREATE TABLE life_expiries(id INTEGER PRIMARY KEY, title TEXT NOT NULL, kind TEXT NOT NULL, expires_on TEXT NOT NULL, remind_days INTEGER NOT NULL DEFAULT 30, notes TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','done')));
CREATE INDEX life_expiries_date ON life_expiries(status,expires_on);
CREATE TABLE life_subscriptions(id INTEGER PRIMARY KEY, name TEXT NOT NULL, amount REAL NOT NULL CHECK(amount>0), cadence TEXT NOT NULL CHECK(cadence IN ('monthly','yearly')), renews_on TEXT NOT NULL, notes TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','cancelled')));
CREATE INDEX life_subscriptions_date ON life_subscriptions(status,renews_on);
CREATE TABLE life_loans(id INTEGER PRIMARY KEY, person TEXT NOT NULL, direction TEXT NOT NULL CHECK(direction IN ('lent','borrowed')), original_amount REAL NOT NULL CHECK(original_amount>0), due_on TEXT, notes TEXT NOT NULL DEFAULT '', created_on TEXT NOT NULL);
CREATE TABLE life_loan_payments(id INTEGER PRIMARY KEY, loan_id INTEGER NOT NULL REFERENCES life_loans(id) ON DELETE CASCADE, paid_on TEXT NOT NULL, amount REAL NOT NULL CHECK(amount>0));
CREATE INDEX life_loan_payments_loan ON life_loan_payments(loan_id);
