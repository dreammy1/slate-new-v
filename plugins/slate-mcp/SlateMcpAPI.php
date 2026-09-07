<?php
/**
 * Slate AI Gateway.
 *
 * This is intentionally a narrow MCP-compatible API. It never exposes generic
 * SQL, filesystem access, user credentials, payments, or destructive tools.
 */
class SlateMcpAPI {
    private const SCHEMA_V = '1';
    private const SCOPES = ['react-sites.read', 'react-sites.write', 'react-sites.publish'];
    private static bool $schemaChecked = false;

    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            if (Database::setting('slate-mcp.schema_v') === self::SCHEMA_V) return;
            $sql = trim((string)@file_get_contents(__DIR__ . '/install.sql'));
            if ($sql === '') return;
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
                if (trim($statement) !== '') Database::query(trim($statement));
            }
            Database::setSetting('slate-mcp.schema_v', self::SCHEMA_V);
        } catch (Throwable $e) {
            if (function_exists('slate_log')) slate_log('Slate MCP schema failed: ' . $e->getMessage(), 'warning');
        }
    }

    public static function availableScopes(): array {
        $scopes = [
            'react-sites.read'    => 'Read React sites, documents, and published manifests',
            'react-sites.write'   => 'Create and update React site documents and settings',
            'react-sites.publish' => 'Publish a reviewed React site revision',
        ];
        return Hook::applyFilters('slate_mcp_scopes', $scopes);
    }

    public static function createToken(string $label, array $scopes, string $expiresAt = ''): array {
        self::ensureSchema();
        $label = mb_substr(trim($label), 0, 120);
        if ($label === '') throw new InvalidArgumentException('A token label is required.');
        $available = self::availableScopes();
        $allowedScopes = array_is_list($available) ? $available : array_keys($available);
        $scopes = array_values(array_intersect($allowedScopes, array_unique(array_map('strval', $scopes))));
        if (!$scopes) throw new InvalidArgumentException('Select at least one scope.');
        $expires = null;
        if (trim($expiresAt) !== '') {
            $time = strtotime($expiresAt . ' 23:59:59');
            if (!$time || $time <= time()) throw new InvalidArgumentException('Expiry must be a future date.');
            $expires = gmdate('Y-m-d H:i:s', $time);
        }
        $raw = 'slmcp_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefix = substr($raw, 0, 16);
        $id = Database::insert('slatemcp_tokens', [
            'tenant_id' => current_tenant_id(), 'label' => $label, 'token_prefix' => $prefix,
            'token_hash' => hash('sha256', $raw), 'scopes_json' => json_encode($scopes),
            'created_by' => (int)Auth::userId(), 'expires_at' => $expires,
        ]);
        AuditLog::record('slate_mcp.token_created', 'token#' . $id, ['scopes' => $scopes]);
        return ['id' => $id, 'token' => $raw, 'prefix' => $prefix, 'scopes' => $scopes, 'expires_at' => $expires];
    }

    public static function listTokens(): array {
        self::ensureSchema();
        $rows = Database::rows('SELECT id, label, token_prefix, scopes_json, created_by, last_used_at, expires_at, revoked_at, created_at FROM slatemcp_tokens WHERE tenant_id = ? ORDER BY id DESC', [current_tenant_id()]);
        foreach ($rows as &$row) $row['scopes'] = self::decode((string)$row['scopes_json']);
        return $rows;
    }

    public static function revokeToken(int $id): void {
        self::ensureSchema();
        Database::update('slatemcp_tokens', ['revoked_at' => slate_db_now()], 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
        AuditLog::record('slate_mcp.token_revoked', 'token#' . $id);
    }

    public static function handle(array $request, string $rawToken): array {
        $id = $request['id'] ?? null;
        if (($request['jsonrpc'] ?? '') !== '2.0' || !is_string($request['method'] ?? null)) return self::rpcError($id, -32600, 'Invalid JSON-RPC request.');
        $context = self::authenticate($rawToken);
        if (!$context) return self::rpcError($id, -32001, 'Unauthorized or expired Slate AI Gateway token.');
        try {
            $method = $request['method'];
            if ($method === 'initialize') return self::rpcResult($id, ['protocolVersion' => '2025-03-26', 'capabilities' => ['tools' => (object)[]], 'serverInfo' => ['name' => 'slate-ai-gateway', 'version' => '0.1.0']]);
            if ($method === 'notifications/initialized') return self::rpcResult($id, (object)[]);
            if ($method === 'tools/list') return self::rpcResult($id, ['tools' => self::tools($context)]);
            if ($method !== 'tools/call') return self::rpcError($id, -32601, 'Method not found.');
            $params = is_array($request['params'] ?? null) ? $request['params'] : [];
            $name = (string)($params['name'] ?? '');
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $result = self::withTenant($context, static fn() => self::callTool($context, $name, $arguments));
            return self::rpcResult($id, ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'isError' => false]);
        } catch (Throwable $e) {
            return self::rpcResult($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        }
    }

    private static function tools(array $context): array {
        $tools = [];
        if (self::allows($context, 'react-sites.read')) {
            $tools[] = ['name' => 'slate_react_sites_list', 'description' => 'List the current tenant’s React sites and publication state.', 'inputSchema' => ['type' => 'object', 'properties' => (object)[]]];
            $tools[] = ['name' => 'slate_react_site_get', 'description' => 'Read one React site with editable route documents and media mappings.', 'inputSchema' => self::schema(['site_id' => ['type' => 'integer']], ['site_id'])];
            $tools[] = ['name' => 'slate_react_manifest_get', 'description' => 'Read the active published manifest by its opaque public identifier.', 'inputSchema' => self::schema(['public_id' => ['type' => 'string']], ['public_id'])];
        }
        if (self::allows($context, 'react-sites.write')) {
            $tools[] = ['name' => 'slate_react_document_upsert', 'description' => 'Create or update one route document. Changes remain draft until published.', 'inputSchema' => self::schema(['site_id' => ['type' => 'integer'], 'document_id' => ['type' => 'integer'], 'route' => ['type' => 'string'], 'schema' => ['type' => 'string'], 'data' => ['type' => 'object'], 'locale' => ['type' => 'string']], ['site_id', 'route', 'schema', 'data'])];
            $tools[] = ['name' => 'slate_react_site_settings_save', 'description' => 'Update a React site name and frontend origins. Does not publish content.', 'inputSchema' => self::schema(['site_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'public_origin' => ['type' => 'string'], 'preview_origin' => ['type' => 'string']], ['site_id', 'name'])];
        }
        if (self::allows($context, 'react-sites.publish')) $tools[] = ['name' => 'slate_react_site_publish', 'description' => 'Publish the current site documents and mapped media as a new immutable manifest revision.', 'inputSchema' => self::schema(['site_id' => ['type' => 'integer'], 'note' => ['type' => 'string']], ['site_id'])];
        return Hook::applyFilters('slate_mcp_tools', $tools, $context);
    }

    private static function callTool(array $context, string $name, array $args): array {
        $handled = Hook::applyFilters('slate_mcp_call_tool', null, $name, $args, $context);
        if ($handled !== null) {
            return is_array($handled) ? $handled : ['result' => $handled];
        }

        if (!class_exists('ReactSiteBridgeAPI')) throw new RuntimeException('React Site Bridge must be installed and enabled before using these tools.');
        return match ($name) {
            'slate_react_sites_list' => self::read($context, static fn() => ['sites' => ReactSiteBridgeAPI::listSites()]),
            'slate_react_site_get' => self::read($context, static function () use ($args) { $siteId = (int)($args['site_id'] ?? 0); $site = ReactSiteBridgeAPI::getSite($siteId); if (!$site) throw new InvalidArgumentException('React site not found.'); return ['site' => $site, 'documents' => ReactSiteBridgeAPI::listDocuments($siteId), 'media' => ReactSiteBridgeAPI::listMediaMappings($siteId)]; }),
            'slate_react_manifest_get' => self::read($context, static function () use ($context, $args) { $result = ReactSiteBridgeAPI::activeManifestByPublicId((string)($args['public_id'] ?? '')); if (!$result || (int)($result['site']['tenant_id'] ?? 0) !== (int)$context['tenant_id']) throw new InvalidArgumentException('Published manifest not found.'); return $result; }),
            'slate_react_document_upsert' => self::write($context, static function () use ($args) { $id = ReactSiteBridgeAPI::saveDocument((int)$args['site_id'], (int)($args['document_id'] ?? 0), (string)$args['route'], (string)$args['schema'], json_encode($args['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string)($args['locale'] ?? 'en')); return ['document_id' => $id, 'draft' => true]; }),
            'slate_react_site_settings_save' => self::write($context, static function () use ($args) { ReactSiteBridgeAPI::saveSite((int)$args['site_id'], (string)$args['name'], (string)($args['public_origin'] ?? ''), (string)($args['preview_origin'] ?? '')); return ['saved' => true]; }),
            'slate_react_site_publish' => self::publish($context, static function () use ($args) { return ReactSiteBridgeAPI::publish((int)$args['site_id'], (string)($args['note'] ?? 'Published through Slate AI Gateway')); }),
            default => throw new InvalidArgumentException('The requested tool is not available for this token.'),
        };
    }

    public static function authenticate(string $rawToken): ?array {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) > 256) return null;
        $row = Database::row('SELECT * FROM slatemcp_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())', [hash('sha256', $rawToken)]);
        if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $rawToken))) return null;
        Database::update('slatemcp_tokens', ['last_used_at' => slate_db_now()], 'id = ?', [(int)$row['id']]);
        return ['tenant_id' => (int)$row['tenant_id'], 'scopes' => self::decode((string)$row['scopes_json']), 'token_id' => (int)$row['id']];
    }

    private static function withTenant(array $context, callable $callback): mixed {
        $hadOverride = array_key_exists('SLATE_TENANT_OVERRIDE', $GLOBALS);
        $previous = $GLOBALS['SLATE_TENANT_OVERRIDE'] ?? null;
        $GLOBALS['SLATE_TENANT_OVERRIDE'] = (int)$context['tenant_id'];
        try { return $callback(); }
        finally { if ($hadOverride) $GLOBALS['SLATE_TENANT_OVERRIDE'] = $previous; else unset($GLOBALS['SLATE_TENANT_OVERRIDE']); }
    }

    private static function read(array $context, callable $callback): mixed { if (!self::allows($context, 'react-sites.read')) throw new RuntimeException('This token does not grant React Sites read access.'); return $callback(); }
    private static function write(array $context, callable $callback): mixed { if (!self::allows($context, 'react-sites.write')) throw new RuntimeException('This token does not grant React Sites write access.'); return $callback(); }
    private static function publish(array $context, callable $callback): mixed { if (!self::allows($context, 'react-sites.publish')) throw new RuntimeException('This token does not grant React Sites publish access.'); return $callback(); }
    public static function allows(array $context, string $scope): bool { return in_array($scope, (array)($context['scopes'] ?? []), true); }
    public static function schema(array $properties, array $required = []): array { return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false]; }
    private static function decode(string $json): array { try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; } catch (Throwable $e) { return []; } }
    private static function rpcResult(mixed $id, mixed $result): array { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; }
    private static function rpcError(mixed $id, int $code, string $message): array { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]; }
}
