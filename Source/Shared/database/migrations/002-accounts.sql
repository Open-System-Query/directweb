CREATE TABLE users_next (id TEXT PRIMARY KEY, name TEXT NOT NULL, username TEXT NOT NULL UNIQUE COLLATE NOCASE, email TEXT UNIQUE COLLATE NOCASE, email_verified INTEGER NOT NULL DEFAULT 0, email_mfa INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL, role TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, totp TEXT, recovery TEXT NOT NULL DEFAULT '[]', totp_step INTEGER NOT NULL DEFAULT 0, session_version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL);
INSERT INTO users_next(id,name,username,email,password,role,enabled,totp,recovery,totp_step,session_version,created_at) SELECT id,name,email,email,password,role,enabled,totp,recovery,totp_step,session_version,created_at FROM users;
DROP TABLE users;
ALTER TABLE users_next RENAME TO users;
CREATE TABLE email_challenges (id TEXT PRIMARY KEY,user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,purpose TEXT NOT NULL,destination TEXT NOT NULL,hash TEXT NOT NULL,session_hash TEXT NOT NULL,version INTEGER NOT NULL,expires_at INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
CREATE INDEX email_challenge_lookup ON email_challenges(user_id,purpose,session_hash);
