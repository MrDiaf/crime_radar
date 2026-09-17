PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    police_id INTEGER NOT NULL UNIQUE,
    occurred_at TEXT NOT NULL,
    title TEXT NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    event_type TEXT NOT NULL,
    location_name TEXT NOT NULL,
    latitude REAL NOT NULL CHECK (latitude BETWEEN -90 AND 90),
    longitude REAL NOT NULL CHECK (longitude BETWEEN -180 AND 180),
    source_url TEXT NOT NULL,
    imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_events_occurred_at ON events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_type ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_location ON events(location_name);

CREATE TABLE IF NOT EXISTS crime_statistics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL CHECK (year BETWEEN 1900 AND 2200),
    region TEXT NOT NULL,
    crime_type TEXT NOT NULL,
    incident_count INTEGER NOT NULL CHECK (incident_count >= 0),
    source TEXT NOT NULL DEFAULT 'Brå',
    imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(year, region, crime_type, source)
);

CREATE INDEX IF NOT EXISTS idx_statistics_year ON crime_statistics(year);
CREATE INDEX IF NOT EXISTS idx_statistics_region ON crime_statistics(region);
CREATE INDEX IF NOT EXISTS idx_statistics_type ON crime_statistics(crime_type);

CREATE TABLE IF NOT EXISTS import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT,
    status TEXT NOT NULL CHECK (status IN ('running', 'success', 'failed')),
    records_seen INTEGER NOT NULL DEFAULT 0,
    records_written INTEGER NOT NULL DEFAULT 0,
    message TEXT
);
