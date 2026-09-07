<?php
/**
 * Slate — page-render tests.
 *
 * The unit and integration suites prove the APIs behind the admin pages. They
 * say nothing about whether a page still renders: a template can reference a
 * column a migration renamed, a nav entry can point at a view that was never
 * written, a helper can be called with the wrong arity, and every existing
 * test stays green. Those defects only show up when something loads the page.
 *
 * This suite loads the page. Each one is rendered in its own process (see
 * render_page.php) and judged on six things:
 *
 *   1. the process exits 0
 *   2. it reports back at all — no silent death
 *   3. no fatal or parse error
 *   4. the page ran to the end rather than exiting early (a redirect or a
 *      permission bounce would otherwise read as a pass with no markup)
 *   5. the markup closes — a truncated page means something died mid-render
 *   6. it raised no warnings, notices or deprecations
 *
 * The suite then renders three deliberately broken fixtures and asserts that
 * each one FAILS, on the specific check it is built to trip. Without that a
 * harness which had quietly stopped detecting anything would still report a
 * full row of passes, which is the same picture as a healthy application.
 *
 * Read-only: every page is rendered with GET, and admin pages guard their
 * writes behind REQUEST_METHOD === 'POST'.
 *
 * Usage: php tests/render/run.php   (exit 0 = all passed)
 */

declare(strict_types=1);

// Never over HTTP. These runners touch the database — the integration suite
// creates and deletes rows — and there is no reason for a web request to
// reach them. .htaccess denies the directory too; this is the backstop for
// when server config does not.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../unit/harness.php';

$root = dirname(__DIR__, 2);

/** Warning levels that should never survive into a rendered page. */
const RENDER_DIAG_LEVELS = [
    E_WARNING           => 'Warning',
    E_NOTICE            => 'Notice',
    E_DEPRECATED        => 'Deprecated',
    E_USER_WARNING      => 'User warning',
    E_USER_NOTICE       => 'User notice',
    E_USER_DEPRECATED   => 'User deprecated',
    E_STRICT            => 'Strict',
    E_RECOVERABLE_ERROR => 'Recoverable error',
];

/**
 * Pages under test. Files beginning with an underscore are partials, not
 * pages — they are included by the pages and have no standalone render.
 */
function render_pages(string $root): array
{
    $pages = [];
    foreach (glob($root . '/plugins/studio/admin/*.php') ?: [] as $abs) {
        if (str_starts_with(basename($abs), '_')) {
            continue;
        }
        $pages[] = 'plugins/studio/admin/' . basename($abs);
    }
    $pages[] = 'admin/sessions.php';
    sort($pages);
    return $pages;
}

/**
 * Public (parent-facing) views. The router is one file dispatching on ?view=,
 * so each row is a query string plus the identity it must be rendered as.
 * 'pay' and 'tickets' are absent on purpose: both are POST-only actions that
 * always end in a redirect, so there is no markup to judge.
 */
function render_public_views(): array
{
    return [
        ['catalog',  'view=catalog',                 'guest'],
        // Both catalog layouts, not just whichever the tenant has selected —
        // the unselected one is exactly the template that rots unnoticed.
        ['catalog (day picker)',  'view=catalog&tpl=list', 'guest'],
        ['catalog (weekly grid)', 'view=catalog&tpl=grid', 'guest'],
        ['prices',   'view=prices',                  'guest'],
        ['policies', 'view=policies',                'guest'],
        ['class',    'view=class&id={class_id}',     'guest'],
        ['portal',   'view=portal',                  'customer'],
        ['register', 'view=register&class={class_id}', 'customer'],
        ['recital',  'view=recital&id={recital_id}', 'customer'],
    ];
}

/** Render one page in a child process and return [markup, meta, exitCode]. */
function render_one(string $root, string $rel, string $query = '', string $identity = 'admin'): array
{
    // log_errors off so a render never appends to the production error_log;
    // display_errors off so diagnostics reach us as structured data via the
    // child's error handler rather than as text smeared through the markup.
    $cmd = sprintf(
        'php -d log_errors=0 -d display_errors=0 %s %s %s %s 2>&1',
        escapeshellarg($root . '/tests/render/render_page.php'),
        escapeshellarg($rel),
        escapeshellarg($query),
        escapeshellarg($identity)
    );

    $out  = (string) shell_exec($cmd . '; echo "__EXIT__$?"');
    $code = 0;
    if (preg_match('/__EXIT__(\d+)\s*$/', $out, $m)) {
        $code = (int) $m[1];
        $out  = (string) preg_replace('/__EXIT__\d+\s*$/', '', $out);
    }

    $marker = '@@RENDER_META@@';
    $at     = strrpos($out, $marker);
    if ($at === false) {
        return [$out, null, $code];
    }

    $markup = substr($out, 0, $at);
    $json   = trim(substr($out, $at + strlen($marker)));
    $meta   = json_decode($json, true);

    return [$markup, is_array($meta) ? $meta : null, $code];
}

/** Render a diagnostic as one readable line. */
function render_diag_line(array $d, string $root): string
{
    $label = RENDER_DIAG_LEVELS[$d['level']] ?? ('level ' . $d['level']);
    $file  = str_replace($root . '/', '', (string) ($d['file'] ?? ''));
    return $label . ': ' . $d['msg'] . ($file !== '' ? " ({$file}:{$d['line']})" : '');
}

/**
 * Judge one render. Returns null when the page is healthy, or a human-readable
 * reason when it is not. Shared by the real pages and the negative controls so
 * the controls prove the same code path the pages are judged by.
 */
function render_verdict(string $markup, ?array $meta, int $code, string $root): ?string
{
    if ($meta === null) {
        return 'no metadata returned (exit ' . $code . '); output was: ' . substr(trim($markup), 0, 400);
    }
    if (!empty($meta['missing'])) {
        return 'page file not found';
    }
    if (!empty($meta['harness_error'])) {
        return 'harness error: ' . $meta['harness_error'];
    }

    if (!empty($meta['fatal'])) {
        $f    = $meta['fatal'];
        $file = str_replace($root . '/', '', (string) ($f['file'] ?? ''));
        return "fatal: {$f['message']} ({$file}:{$f['line']})";
    }

    if ($code !== 0) {
        return 'exit code ' . $code;
    }

    if (empty($meta['completed'])) {
        return 'page exited before completing — a redirect or permission bounce, not a render ('
            . strlen(trim($markup)) . ' bytes of output)';
    }

    $diags = $meta['diags'] ?? [];
    if ($diags !== []) {
        $lines = array_map(static fn (array $d): string => render_diag_line($d, $root), $diags);
        return count($diags) . ' diagnostic(s): ' . implode(' | ', array_slice($lines, 0, 4));
    }

    if (!str_contains($markup, '</html>')) {
        return 'markup does not close (' . strlen(trim($markup)) . ' bytes) — died mid-render';
    }

    return null;
}

echo "# Slate page-render tests\n";

$pages = render_pages($root);
if ($pages === []) {
    echo "FAIL - no pages discovered\n1..1\n";
    exit(1);
}

foreach ($pages as $rel) {
    [$markup, $meta, $code] = render_one($root, $rel);
    $verdict = render_verdict($markup, $meta, $code, $root);

    unit("renders: $rel", static function () use ($verdict): void {
        if ($verdict !== null) {
            throw new \Exception($verdict);
        }
    });
}

// ── Public (parent-facing) views ─────────────────────────────
// A view whose placeholder has no live data to fill it is reported as a skip
// rather than a pass: there is a real difference between "this renders" and
// "there was nothing on this tenant to render it with", and collapsing the
// two is how an untested page comes to look tested.
$router = 'plugins/studio/public/router.php';

foreach (render_public_views() as [$name, $query, $identity]) {
    [$markup, $meta, $code] = render_one($root, $router, $query, $identity);

    if ($meta !== null && !empty($meta['skipped'])) {
        echo "skip - public view: $name  ({$meta['skipped']})\n";
        continue;
    }

    $verdict = render_verdict($markup, $meta, $code, $root);

    unit("renders public view: $name  [$identity]", static function () use ($verdict): void {
        if ($verdict !== null) {
            throw new \Exception($verdict);
        }
    });
}

// ── Negative controls ────────────────────────────────────────
// Each fixture is built to trip exactly one check. Asserting on the reason,
// not merely on failure, keeps a fixture from passing the suite for the wrong
// cause — an early-exit fixture that started dying fatally would otherwise
// still look like proof that early exits are caught.
$controls = [
    'fixtures/fatal.php'      => 'fatal:',
    'fixtures/early_exit.php' => 'exited before completing',
    'fixtures/warning.php'    => 'diagnostic(s):',
];

foreach ($controls as $file => $expect) {
    $rel = 'tests/render/' . $file;
    [$markup, $meta, $code] = render_one($root, $rel);
    $verdict = render_verdict($markup, $meta, $code, $root);

    unit("detects broken page: $file", static function () use ($verdict, $expect): void {
        if ($verdict === null) {
            throw new \Exception('harness reported a broken fixture as healthy');
        }
        assert_true(
            str_contains($verdict, $expect),
            'expected a verdict containing "' . $expect . '", got: ' . $verdict
        );
    });
}

exit(unit_summary());
