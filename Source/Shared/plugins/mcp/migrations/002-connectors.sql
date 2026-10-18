CREATE TABLE IF NOT EXISTS oauth_client_redirects(client_id TEXT NOT NULL REFERENCES oauth_clients(id) ON DELETE CASCADE,uri TEXT NOT NULL,PRIMARY KEY(client_id,uri));
CREATE TABLE IF NOT EXISTS oauth_refresh(hash TEXT PRIMARY KEY,family TEXT NOT NULL,client_id TEXT NOT NULL,user_id TEXT NOT NULL,session_version INTEGER NOT NULL,scopes TEXT NOT NULL,sites TEXT NOT NULL,token_id TEXT NOT NULL,expires_at INTEGER NOT NULL,used INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS oauth_refresh_family ON oauth_refresh(family);
