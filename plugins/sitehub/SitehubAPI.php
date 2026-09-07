<?php
/**
 * SiteHub public API + PortKit REST client.
 *
 * Other plugins:  \SitehubAPI::sites();
 * All site I/O goes through PortKit's /wp-json/portkit/v1/ endpoints with a
 * per-site bearer token (stored encrypted). Tenant-scoped throughout.
 */
class SitehubAPI {

    /* ---------------- registry ---------------- */

    public static function sites(): array {
        return Database::rows(
            "SELECT * FROM sitehub_sites WHERE tenant_id = ? ORDER BY name ASC",
            [current_tenant_id()]
        );
    }

    public static function get(int $id): ?array {
        return Database::row(
            "SELECT * FROM sitehub_sites WHERE id = ? AND tenant_id = ?",
            [$id, current_tenant_id()]
        ) ?: null;
    }

    public static function add(string $name, string $url, string $token): int {
        $url = rtrim(trim($url), '/');
        return Database::insert('sitehub_sites', [
            'tenant_id' => current_tenant_id(),
            'name'      => mb_substr(trim($name), 0, 190),
            'url'       => mb_substr($url, 0, 255),
            'token'     => slate_encrypt_secret(trim($token)),
            'status'    => 'unknown',
        ]);
    }

    public static function remove(int $id): int {
        return Database::delete(
            'sitehub_sites', 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]
        );
    }

    /* ---------------- HTTP client ---------------- */

    private static function base(array $site): string {
        return rtrim($site['url'], '/') . '/wp-json/portkit/v1/';
    }

    /**
     * Call a PortKit endpoint. Returns:
     *   ['ok'=>bool, 'code'=>int, 'data'=>array|null, 'error'=>string]
     */
    public static function http(array $site, string $method, string $path, ?array $body = null, int $timeout = 25): array {
        $token = slate_decrypt_secret($site['token']);
        $url   = self::base($site) . ltrim($path, '/');
        // Also carry the token as a query param so auth works even when the host
        // strips the Authorization header (LiteSpeed/FastCGI) or a security
        // plugin intercepts Bearer auth.
        $url  .= (strpos($url, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token);
        $out   = ['ok' => false, 'code' => 0, 'data' => null, 'error' => ''];

        if (!function_exists('curl_init')) {
            $out['error'] = 'cURL is not available on this server.';
            return $out;
        }
        $ch = curl_init($url);
        // Authenticate via the ?token= query param only. Sending an
        // 'Authorization: Bearer' header makes some hosts/security layers reject
        // the request before it reaches the plugin ('not authorized').
        $headers = [
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SiteHub/1.1 (fleet-control; +https://rakibhasaan.com)',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $out['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $out['error'] = curl_error($ch) ?: 'Request failed.';
            curl_close($ch);
            return $out;
        }
        curl_close($ch);

        $data = json_decode((string)$raw, true);
        $out['data'] = is_array($data) ? $data : null;
        if ($out['code'] >= 200 && $out['code'] < 300 && is_array($data)) {
            $out['ok'] = true;
        } elseif (!is_array($data)) {
            // Non-JSON body: almost always an upstream block (Cloudflare/WAF
            // challenge, or a security plugin returning an HTML page).
            $out['error'] = 'Non-JSON response (HTTP ' . $out['code'] . ') \u2014 likely blocked by Cloudflare/WAF. Allow /wp-json/portkit/v1/* or allowlist this server IP.';
        } else {
            $out['error'] = isset($data['message']) ? (string)$data['message'] : ('HTTP ' . $out['code']);
        }
        return $out;
    }

    private static function log(int $siteId, string $action, bool $ok, string $detail = ''): void {
        Database::insert('sitehub_runs', [
            'tenant_id' => current_tenant_id(),
            'site_id'   => $siteId,
            'action'    => mb_substr($action, 0, 40),
            'ok'        => $ok ? 1 : 0,
            'detail'    => mb_substr($detail, 0, 1000),
        ]);
    }

    /* ---------------- operations ---------------- */

    /** Health check: GET /ping, update status/version/last_seen. */
    public static function ping(int $id): array {
        $site = self::get($id);
        if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'GET', 'ping', null, 12);
        $fields = ['last_seen' => slate_db_now()];
        if ($r['ok'] && !empty($r['data']['ok'])) {
            $fields['status']     = 'online';
            $fields['version']    = (string)($r['data']['version'] ?? '');
            $fields['last_error'] = null;
        } else {
            $fields['status']     = 'offline';
            $fields['last_error'] = mb_substr($r['error'] ?: 'No response', 0, 255);
        }
        Database::update('sitehub_sites', $fields, 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
        self::log($id, 'ping', $r['ok'], $r['error']);
        return $r;
    }

    /** GET /doctor/scan and cache the summary on the site row. */
    public static function scan(int $id, bool $fresh = false): array {
        $site = self::get($id);
        if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'GET', 'doctor/scan' . ($fresh ? '?fresh=1' : ''), null, 40);
        if ($r['ok'] && is_array($r['data'])) {
            Database::update('sitehub_sites',
                ['last_scan' => json_encode($r['data']), 'status' => 'online', 'version' => (string)($r['data']['version'] ?? $site['version']), 'last_seen' => slate_db_now(), 'last_error' => null],
                'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
        } else {
            Database::update('sitehub_sites',
                ['last_error' => mb_substr($r['error'] ?: 'Scan failed', 0, 255)],
                'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
        }
        self::log($id, 'scan', $r['ok'], $r['error']);
        return $r;
    }

    public static function reclaimColor(int $id, string $hex, string $global): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'doctor/reclaim-color', ['hex' => $hex, 'global' => $global], 60);
        self::log($id, 'reclaim_color', $r['ok'], $r['error'] ?: ($hex . ' -> ' . $global));
        return $r;
    }

    public static function replaceColor(int $id, string $from, string $to): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'doctor/replace-color', ['from' => $from, 'to' => $to], 60);
        self::log($id, 'replace_color', $r['ok'], $r['error'] ?: ($from . ' -> ' . $to));
        return $r;
    }

    public static function setRadius(int $id, int $px): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'doctor/set-radius', ['px' => $px], 60);
        self::log($id, 'set_radius', $r['ok'], $r['error'] ?: ($px . 'px'));
        return $r;
    }

    /* ---------------- Fleet control (RK Suite extended endpoints) ---------------- */

    /** List RK Suite modules + enabled state. */
    public static function modules(int $id): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'GET', 'modules', null, 20);
        self::log($id, 'modules', $r['ok'], $r['error']);
        return $r;
    }

    /** Enable/disable a module on a site. */
    public static function moduleToggle(int $id, string $slug, bool $enable): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'modules/toggle', ['slug' => $slug, 'enable' => $enable], 30);
        self::log($id, 'module_toggle', $r['ok'], $r['error'] ?: ($slug . '=' . ($enable ? 'on' : 'off')));
        return $r;
    }

    /** Health/system report (versions, updates, maintenance, https). */
    public static function system(int $id): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'GET', 'system', null, 25);
        self::log($id, 'system', $r['ok'], $r['error']);
        return $r;
    }

    public static function cacheClear(int $id): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'cache/clear', [], 40);
        self::log($id, 'cache_clear', $r['ok'], $r['error']);
        return $r;
    }

    public static function maintenance(int $id, bool $on): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'maintenance', ['on' => $on], 20);
        self::log($id, 'maintenance', $r['ok'], $r['error'] ?: ($on ? 'on' : 'off'));
        return $r;
    }

    public static function flushPermalinks(int $id): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'flush-permalinks', [], 30);
        self::log($id, 'flush_permalinks', $r['ok'], $r['error']);
        return $r;
    }

    /** List rollback snapshots on a site. */
    public static function snapshots(int $id): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'GET', 'snapshots', null, 25);
        self::log($id, 'snapshots', $r['ok'], $r['error']);
        return $r;
    }

    /** Roll a site back to a snapshot token. */
    public static function rollback(int $id, string $token): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'rollback', ['snapshot' => $token], 120);
        self::log($id, 'rollback', $r['ok'], $r['error'] ?: $token);
        return $r;
    }

    /** Take an on-demand snapshot before a fleet action. */
    public static function snapshotCreate(int $id, string $label = 'SiteHub snapshot'): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'snapshot', ['label' => $label], 120);
        self::log($id, 'snapshot', $r['ok'], $r['error']);
        return $r;
    }

    /** Per-site SEO health summary (for the fleet SEO dashboard). */
    public static function seoHealth(int $id): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'GET', 'seo/health', null, 30);
        self::log($id, 'seo_health', $r['ok'], $r['error']);
        return $r;
    }

    /** Push a bundle (raw zip bytes) into a site. */
    public static function push(int $id, string $zipBytes, array $opts = []): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'receive', ['bundle' => base64_encode($zipBytes), 'opts' => $opts], 180);
        self::log($id, 'push', $r['ok'], $r['error']);
        return $r;
    }

    /** Pull a site as a bundle and store it locally. Returns saved file info. */
    public static function pull(int $id): array {
        $site = self::get($id); if (!$site) return ['ok' => false, 'error' => 'Unknown site.'];
        $r = self::http($site, 'POST', 'pull', ['include_media' => false], 180);
        if (!$r['ok'] || empty($r['data']['bundle'])) {
            self::log($id, 'pull', false, $r['error']);
            return ['ok' => false, 'error' => $r['error'] ?: 'Empty response.'];
        }
        $bytes = base64_decode($r['data']['bundle']);
        $dir   = self::backupDir();
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name  = 'site' . $id . '-' . date('Ymd-His') . '.zip';
        file_put_contents($dir . $name, $bytes);
        Database::insert('sitehub_backups', [
            'tenant_id' => current_tenant_id(),
            'site_id'   => $id,
            'filename'  => $name,
            'size'      => strlen($bytes),
        ]);
        self::log($id, 'pull', true, $name);
        return ['ok' => true, 'file' => $name, 'size' => strlen($bytes)];
    }

    public static function backups(): array {
        return Database::rows(
            "SELECT b.*, s.name AS site_name FROM sitehub_backups b
               LEFT JOIN sitehub_sites s ON s.id = b.site_id
              WHERE b.tenant_id = ? ORDER BY b.created_at DESC LIMIT 100",
            [current_tenant_id()]
        );
    }

    public static function backupDir(): string {
        $root = defined('SLATE_ROOT') ? SLATE_ROOT : dirname(__DIR__, 2);
        return $root . '/uploads/sitehub/';
    }

    /* ---------------- aggregation ---------------- */

    /** Roll up cached scans across all sites for the fleet summary. */
    public static function fleetSummary(): array {
        $sum = ['sites' => 0, 'online' => 0, 'hardcoded_colors' => 0, 'links_empty' => 0,
                'images_no_alt' => 0, 'pages_no_h1' => 0, 'legacy_pages' => 0];
        foreach (self::sites() as $s) {
            $sum['sites']++;
            if ($s['status'] === 'online') $sum['online']++;
            if (empty($s['last_scan'])) continue;
            $scan = json_decode($s['last_scan'], true);
            if (!is_array($scan)) continue;
            $t = $scan['totals'] ?? [];
            $sum['hardcoded_colors'] += (int)($t['hardcoded_colors'] ?? 0);
            $sum['links_empty']      += (int)($t['links_empty'] ?? 0);
            $sum['images_no_alt']    += (int)($t['images_no_alt'] ?? 0);
            $sum['pages_no_h1']      += (int)($t['pages_no_h1'] ?? 0);
            $sum['legacy_pages']     += (int)($scan['legacy_pages'] ?? 0);
        }
        return $sum;
    }
}
