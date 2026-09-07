<?php
/**
 * Slate — single-page renderer (child process).
 *
 * Boots the app, forges an authenticated admin session, and includes ONE
 * admin page, streaming its markup to stdout followed by a JSON metadata
 * line. The parent (tests/render/run.php) reads that line and decides
 * pass/fail; this file only reports, it never asserts.
 *
 * Why a separate process per page: admin pages are scripts, not functions.
 * They `exit` on a redirect, they declare helpers at file scope, and a parse
 * error in one is fatal to whatever is running it. Rendering them in-process
 * would let the first `exit` end the whole suite and the first duplicate
 * helper name end it with a fatal that says nothing about the page under
 * test. One process per page buys real isolation for the cost of a fork.
 *
 * GET only. Every admin page guards its writes behind REQUEST_METHOD ===
 * 'POST', so a GET render is read-only against the live database — which is
 * what this runs against, there being no fixture database to point at.
 *
 * Usage: php tests/render/render_page.php <path> [query-string] [identity]
 *
 *   identity: admin (default) | customer | guest
 *
 * The query string may contain {class_id} / {recital_id} / {family_id}
 * placeholders, resolved against live data before the page runs. A page is
 * only meaningfully rendered by data that reaches its branches — ?view=class
 * with no id renders the not-found path and proves nothing about the class
 * template.
 */

declare(strict_types=1);

$root     = dirname(__DIR__, 2);
$rel      = (string) ($argv[1] ?? '');
$query    = (string) ($argv[2] ?? '');
$identity = (string) ($argv[3] ?? 'admin');
$page     = $root . '/' . ltrim($rel, '/');

/** Diagnostics (warnings, notices, deprecations) raised while rendering. */
$RENDER_DIAGS = [];
/** True once the include returns normally — false means the page called exit. */
$RENDER_COMPLETED = false;
/** Guards against the metadata line being emitted twice. */
$RENDER_REPORTED = false;

const RENDER_META_MARKER = '@@RENDER_META@@';

if (!is_file($page)) {
    echo "\n" . RENDER_META_MARKER . json_encode([
        'ok' => false, 'missing' => true, 'page' => $rel,
    ]) . "\n";
    exit(0);
}

// Anything the page raises is captured rather than printed: a warning echoed
// mid-markup would corrupt the very HTML the parent is about to inspect.
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use (&$RENDER_DIAGS): bool {
    $RENDER_DIAGS[] = [
        'level' => $no,
        'msg'   => $str,
        'file'  => $file,
        'line'  => $line,
    ];
    return true;
});

// Runs even when the page exits early or dies fatally, which is the whole
// point: a page that `exit`s after printing nothing must not look like a pass.
register_shutdown_function(static function () use (&$RENDER_DIAGS, &$RENDER_COMPLETED, &$RENDER_REPORTED, $rel): void {
    if ($RENDER_REPORTED) {
        return;
    }
    $RENDER_REPORTED = true;

    $last  = error_get_last();
    $fatal = null;
    if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $fatal = $last;
    }

    echo "\n" . RENDER_META_MARKER . json_encode([
        'ok'        => $fatal === null,
        'page'      => $rel,
        'completed' => $RENDER_COMPLETED,
        'fatal'     => $fatal,
        'diags'     => $RENDER_DIAGS,
    ]) . "\n";
});

// ── Request context ──────────────────────────────────────────
// Set before the bootstrap: config.php derives SLATE_URL from these.
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/slate/' . ltrim($rel, '/') . ($query !== '' ? '?' . $query : '');
$_SERVER['SCRIPT_NAME']     = '/slate/' . ltrim($rel, '/');
$_SERVER['QUERY_STRING']    = $query;
$_SERVER['HTTP_HOST']       = 'greenlightinduction.rakibhasaan.com';
$_SERVER['HTTPS']           = 'on';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'slate-render-harness';
$_GET = $_POST = [];

require $root . '/config.php';
require __DIR__ . '/../guard.php';
slate_require_test_database();

// ── Tenancy ──────────────────────────────────────────────────
// current_tenant_id() reads this global — not an env var, not a constant.
$tenantId = (int) (getenv('SLATE_RENDER_TENANT') ?: 1);
$GLOBALS['SLATE_TENANT_OVERRIDE'] = $tenantId;

/**
 * Stop early, reporting to the parent. Marks the run as reported so the
 * shutdown handler does not append a second, contradictory metadata line —
 * the parent reads the last marker in the stream, so an unsuppressed second
 * line silently wins over this one.
 */
$report = static function (array $meta) use (&$RENDER_REPORTED, $rel): void {
    $RENDER_REPORTED = true;
    echo "\n" . RENDER_META_MARKER . json_encode(['page' => $rel] + $meta) . "\n";
    exit(0);
};

// Public studio views are rendered as real entry points, so the harness must
// provision the same active-plugin state that a browser request would have.
if (class_exists('PluginLoader') && !PluginLoader::isActive('studio')) {
    $activation = PluginLoader::activate('studio');
    if (empty($activation['ok'])) {
        $report(['ok' => false, 'harness_error' => 'activating studio plugin: ' . ($activation['error'] ?? 'unknown error')]);
    }
}

/**
 * First scalar from a query, as [value, error]. The two failure modes are
 * kept apart on purpose: no rows is a fact about this tenant's data and a
 * fair skip, while a query that throws is a bug in this harness. Collapsing
 * them into null is how a misspelled table name turns into a permanent green
 * skip that nobody reads.
 */
$firstId = static function (string $sql, array $args): array {
    try {
        $row = Database::row($sql, $args);
        return [$row ? (int) reset($row) : null, null];
    } catch (\Throwable $e) {
        return [null, $e->getMessage()];
    }
};

// ── Query string ─────────────────────────────────────────────
// Placeholders resolve to real ids so the page under test takes its populated
// branch rather than its not-found one.
if ($query !== '') {
    $tokens = [
        '{class_id}'   => ["SELECT id FROM studio_class_series WHERE tenant_id = ? AND is_active = 1 ORDER BY id LIMIT 1", [$tenantId]],
        '{recital_id}' => ["SELECT id FROM studio_recitals WHERE tenant_id = ? ORDER BY id DESC LIMIT 1", [$tenantId]],
        '{family_id}'  => ["SELECT id FROM studio_families WHERE tenant_id = ? ORDER BY id LIMIT 1", [$tenantId]],
    ];
    foreach ($tokens as $token => [$sql, $args]) {
        if (!str_contains($query, $token)) {
            continue;
        }
        [$value, $error] = $firstId($sql, $args);
        if ($error !== null) {
            $report(['ok' => false, 'harness_error' => "resolving $token: $error"]);
        }
        if ($value === null) {
            $report(['ok' => false, 'skipped' => "no live data for $token"]);
        }
        $query = str_replace($token, (string) $value, $query);
    }
    parse_str($query, $_GET);
}

// ── Identity ─────────────────────────────────────────────────
Auth::startSession();
$_SESSION = [];

if ($identity === 'admin') {
    // A real superadmin row where one exists, so pages that join on the acting
    // user find something; a synthetic row otherwise. role_id 1 short-circuits
    // Auth::can(), so either way every page is reachable.
    $admin = null;
    try {
        $admin = Database::row(
            "SELECT id, name, email, role_id, tenant_id FROM users WHERE role_id = 1 AND tenant_id = ? ORDER BY id LIMIT 1",
            [$tenantId]
        ) ?: Database::row("SELECT id, name, email, role_id, tenant_id FROM users WHERE role_id = 1 ORDER BY id LIMIT 1");
    } catch (\Throwable $e) {
        // Fall through to the synthetic identity below.
    }

    $_SESSION['slate_user'] = $admin ? [
        'id'        => (int) $admin['id'],
        'email'     => (string) $admin['email'],
        'name'      => (string) $admin['name'],
        'role_id'   => (int) $admin['role_id'],
        'tenant_id' => (int) $admin['tenant_id'],
    ] : [
        'id'        => 0,
        'email'     => 'render-harness@localhost',
        'name'      => 'Render Harness',
        'role_id'   => 1,
        'tenant_id' => $tenantId,
    ];
    try {
        $renderUserId = (int)($_SESSION['slate_user']['id'] ?? 0);
        if ($renderUserId > 0) {
            (new \Slate\Services\Auth\SessionRepository(new \Slate\Tenancy\TenantContext()))->register(
                $renderUserId, session_id(), 'Render harness', '127.0.0.1', 'SlateRender/1'
            );
        }
    } catch (\Throwable $e) {
        $report(['ok' => false, 'harness_error' => 'registering admin session: ' . $e->getMessage()]);
    }
} elseif ($identity === 'customer') {
    // A parent who actually heads a studio family: the portal is a view onto
    // that family's enrolments, so signing in as an unrelated contact renders
    // the empty state and tests nothing.
    $parent = null;
    try {
        $parent = Database::row(
            "SELECT c.id, c.name, c.email
               FROM studio_families f
               JOIN customers c ON c.id = f.primary_parent_id
              WHERE f.tenant_id = ?
              ORDER BY f.id LIMIT 1",
            [$tenantId]
        );
    } catch (\Throwable $e) {
        $report(['ok' => false, 'harness_error' => 'resolving parent identity: ' . $e->getMessage()]);
    }

    if ($parent === null) {
        $report(['ok' => false, 'skipped' => 'no studio family with a primary parent on this tenant']);
    }

    $_SESSION['slate_customer'] = [
        'id'        => (int) $parent['id'],
        'email'     => (string) ($parent['email'] ?? ''),
        'name'      => (string) ($parent['name'] ?? ''),
        'tenant_id' => $tenantId,
    ];
}
// 'guest' deliberately leaves the session empty.

// ── Render ───────────────────────────────────────────────────
require $page;

$RENDER_COMPLETED = true;
