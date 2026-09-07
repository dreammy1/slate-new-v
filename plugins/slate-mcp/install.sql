CREATE TABLE IF NOT EXISTS slatemcp_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  token_prefix VARCHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes_json TEXT NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY slatemcp_token_hash (token_hash),
  KEY slatemcp_tenant_active (tenant_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
