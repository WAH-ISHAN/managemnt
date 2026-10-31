CREATE TABLE salary_plans (
 id INTEGER PRIMARY KEY, month TEXT NOT NULL UNIQUE, income REAL NOT NULL CHECK(income>0),
 bills REAL NOT NULL DEFAULT 0 CHECK(bills>=0), loan_payments REAL NOT NULL DEFAULT 0 CHECK(loan_payments>=0),
 living REAL NOT NULL DEFAULT 0 CHECK(living>=0), savings_percent REAL NOT NULL CHECK(savings_percent BETWEEN 0 AND 100),
 investment_percent REAL NOT NULL CHECK(investment_percent BETWEEN 0 AND 100),
 emergency_account_id INTEGER REFERENCES accounts(id), emergency_months INTEGER NOT NULL DEFAULT 3 CHECK(emergency_months BETWEEN 1 AND 24),
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE financial_obligations (
 id INTEGER PRIMARY KEY, title TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('bill','loan')),
 amount REAL NOT NULL CHECK(amount>0), due_date TEXT NOT NULL, interval TEXT NOT NULL DEFAULT 'none' CHECK(interval IN ('none','monthly')),
 anchor_day INTEGER NOT NULL CHECK(anchor_day BETWEEN 1 AND 31), account_id INTEGER NOT NULL REFERENCES accounts(id),
 category_id INTEGER NOT NULL REFERENCES expense_categories(id), original_balance REAL, outstanding REAL,
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(kind<>'loan' OR (original_balance>0 AND outstanding>=0))
);
CREATE TABLE financial_payments (
 id INTEGER PRIMARY KEY, obligation_id INTEGER NOT NULL REFERENCES financial_obligations(id), due_date TEXT NOT NULL,
 paid_date TEXT NOT NULL, amount REAL NOT NULL CHECK(amount>0), expense_id INTEGER REFERENCES expenses(id) ON DELETE SET NULL,
 status TEXT NOT NULL DEFAULT 'paid' CHECK(status IN ('paid','reversed')), UNIQUE(obligation_id,due_date)
);
CREATE TABLE financial_recurrences (
 id INTEGER PRIMARY KEY, title TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('income','expense')),
 amount REAL NOT NULL CHECK(amount>0), income_type TEXT NOT NULL DEFAULT 'salary' CHECK(income_type IN ('salary','freelance','business','other')),
 account_id INTEGER REFERENCES accounts(id), category_id INTEGER REFERENCES expense_categories(id),
 interval TEXT NOT NULL CHECK(interval IN ('daily','weekly','monthly','yearly')), next_date TEXT NOT NULL,
 anchor_day INTEGER NOT NULL CHECK(anchor_day BETWEEN 1 AND 31), active INTEGER NOT NULL DEFAULT 0 CHECK(active IN (0,1)),
 error_text TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE financial_occurrences (
 id INTEGER PRIMARY KEY, recurrence_id INTEGER NOT NULL REFERENCES financial_recurrences(id), due_date TEXT NOT NULL,
 income_id INTEGER REFERENCES income(id) ON DELETE SET NULL, expense_id INTEGER REFERENCES expenses(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(recurrence_id,due_date)
);
CREATE INDEX idx_obligations_due ON financial_obligations(active,due_date);
CREATE INDEX idx_recurrences_due ON financial_recurrences(active,next_date);
