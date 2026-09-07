<?php
/**
 * booking-plus public endpoints must go away when the plugin is deactivated.
 *
 * message.php had no activation check, so deactivating booking-plus did not
 * turn it off. It kept resolving manage tokens, kept running
 * BookingPlusAPI::ensureSchema(), and kept writing client_message rows into a
 * plugin the operator believed was off. booking/public/pay-intent.php:36 has
 * had the check all along — this was a one-line divergence between siblings,
 * which is the shape CORE-1 exists to end.
 *
 * WHY THIS IS TESTABLE CHEAPLY, when it looks like it should not be
 *
 * Pinning it appears to need the plugin deactivated mid-run, which would change
 * what the other suites in this shared process see. It does not: booking-plus
 * is ALREADY inactive in the test database — ci.yml activates content-builder,
 * forms, booking and membership, and not this one — so the refusal path is the
 * default state and needs no toggling at all. The first case asserts that
 * precondition rather than assuming it, so if CI's plugin set ever changes this
 * test says so instead of quietly passing for the wrong reason.
 *
 * The second case does need the plugin active, and gets it in a CHILD process
 * (tests/fixtures/public-page-probe.php) with the plugins row restored
 * afterwards. The parent's boot is never touched. That case is what stops the
 * first from passing against a guard that refuses unconditionally — a 503 is
 * only evidence of a working guard if something else gets through.
 */

declare(strict_types=1);

/** Run a public page in a child process. Returns [status, body]. */
function bppg_probe(string $page, string $query = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' 2>/dev/null';

    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];

    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** Is a plugin marked active in the database this run is pointed at? */
function bppg_is_active(string $slug): bool
{
    return (string) Database::value(
        'SELECT status FROM plugins WHERE slug = ?', [$slug]
    ) === 'active';
}

/**
 * Mark booking-plus active for the duration; hand back the exact undo.
 *
 * The row does not exist at all in a CI-provisioned database, so restoring
 * means DELETEing it rather than writing a status back — absent and 'inactive'
 * are different states, and leaving an 'inactive' row behind would change what
 * a later run of this very test sees.
 */
function bppg_activate_booking_plus(): callable
{
    $prior = Database::row('SELECT status FROM plugins WHERE slug = ?', ['booking-plus']);
    $ver   = (string) (json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/booking-plus/plugin.json'), true
    )['version'] ?? '0.0.0');

    Database::query(
        "INSERT INTO plugins (slug, name, version, status, manifest_json)
         VALUES ('booking-plus', 'Booking Plus', ?, 'active', ?)
         ON DUPLICATE KEY UPDATE status = 'active'",
        [$ver, json_encode(['slug' => 'booking-plus', 'version' => $ver])]
    );

    return static function () use ($prior): void {
        if ($prior === null) {
            Database::delete('plugins', 'slug = ?', ['booking-plus']);
        } else {
            Database::query('UPDATE plugins SET status = ? WHERE slug = ?',
                [(string) $prior['status'], 'booking-plus']);
        }
    };
}

unit('booking-plus message.php refuses with 503 while the plugin is deactivated', function (): void {
    // Stated, not assumed. If CI starts activating booking-plus this fails
    // here with a clear reason rather than further down for an opaque one.
    assert_true(
        !bppg_is_active('booking-plus'),
        'precondition: booking-plus is inactive in the test database'
    );

    [$status, $body] = bppg_probe(
        'plugins/booking-plus/public/message.php',
        't=' . str_repeat('a', 32)
    );

    // 503 specifically, matching pay-intent.php rather than a new code.
    assert_eq(503, $status, 'a deactivated plugin serves 503');

    // The token was never resolved and no form was offered — the guard runs
    // before the lookup, not after it.
    assert_true(
        !str_contains($body, '<textarea'),
        'no message form is rendered'
    );
});

unit('booking-plus message.php serves once the plugin is active', function (): void {
    $restore = bppg_activate_booking_plus();

    try {
        [$status, ] = bppg_probe(
            'plugins/booking-plus/public/message.php',
            't=' . str_repeat('a', 32)
        );

        // 404 — the token is bogus, which is the point: the request got past
        // the activation guard and as far as the lookup. Without this case a
        // guard hard-wired to refuse would satisfy the test above.
        assert_eq(404, $status, 'an active plugin gets past the guard to the token lookup');
    } finally {
        $restore();
    }
});
