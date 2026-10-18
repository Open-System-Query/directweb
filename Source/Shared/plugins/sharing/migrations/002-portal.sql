ALTER TABLE shares ADD COLUMN code TEXT;
CREATE UNIQUE INDEX shares_code ON shares(code);
CREATE TABLE share_codes(code TEXT PRIMARY KEY,created_at TEXT NOT NULL);
CREATE TABLE share_uploads(id TEXT PRIMARY KEY,owner TEXT NOT NULL,session_hash TEXT NOT NULL,manifest TEXT NOT NULL,options TEXT NOT NULL,size INTEGER NOT NULL,expires_at INTEGER NOT NULL,result TEXT);
