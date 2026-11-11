ALTER TABLE mcp_approvals ADD COLUMN interval_seconds INTEGER NOT NULL DEFAULT 3600;
ALTER TABLE mcp_approvals ADD COLUMN bind_ip INTEGER NOT NULL DEFAULT 0;
ALTER TABLE mcp_approvals ADD COLUMN approved_ip TEXT NOT NULL DEFAULT '';
CREATE TABLE IF NOT EXISTS mcp_requests(id TEXT PRIMARY KEY,token_id TEXT NOT NULL,user_id TEXT NOT NULL,tool TEXT NOT NULL,ip TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',created_at INTEGER NOT NULL,decided_at INTEGER);
CREATE INDEX IF NOT EXISTS mcp_requests_user ON mcp_requests(user_id,status);
