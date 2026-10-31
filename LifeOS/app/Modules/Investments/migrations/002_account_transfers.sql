ALTER TABLE asset_transactions ADD COLUMN account_id INTEGER REFERENCES accounts(id);
