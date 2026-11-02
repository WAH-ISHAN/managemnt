CREATE TABLE holiday_datasets (
    id INTEGER PRIMARY KEY,
    country TEXT NOT NULL CHECK(country='LK'),
    year INTEGER NOT NULL CHECK(year BETWEEN 2000 AND 2100),
    revision TEXT NOT NULL,
    source_title TEXT NOT NULL,
    source_url TEXT NOT NULL,
    published_on TEXT NOT NULL,
    imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(country,year)
);
CREATE TABLE holidays (
    id INTEGER PRIMARY KEY,
    dataset_id INTEGER NOT NULL REFERENCES holiday_datasets(id) ON DELETE CASCADE,
    date TEXT NOT NULL,
    title_en TEXT NOT NULL,
    title_si TEXT,
    title_ta TEXT,
    is_public INTEGER NOT NULL CHECK(is_public IN (0,1)),
    is_bank INTEGER NOT NULL CHECK(is_bank IN (0,1)),
    is_mercantile INTEGER NOT NULL CHECK(is_mercantile IN (0,1)),
    is_poya INTEGER NOT NULL CHECK(is_poya IN (0,1)),
    jurisdiction TEXT NOT NULL DEFAULT 'Sri Lanka',
    UNIQUE(dataset_id,date,title_en)
);
CREATE INDEX holidays_date ON holidays(date,dataset_id);
CREATE TABLE school_term_datasets (
    id INTEGER PRIMARY KEY,
    authority TEXT NOT NULL,
    year INTEGER NOT NULL CHECK(year BETWEEN 2000 AND 2100),
    revision TEXT NOT NULL,
    source_title TEXT NOT NULL,
    source_url TEXT NOT NULL,
    published_on TEXT NOT NULL,
    UNIQUE(authority,year)
);
CREATE TABLE school_terms (
    id INTEGER PRIMARY KEY,
    dataset_id INTEGER NOT NULL REFERENCES school_term_datasets(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    starts_on TEXT NOT NULL,
    ends_on TEXT NOT NULL,
    CHECK(starts_on<=ends_on)
);
