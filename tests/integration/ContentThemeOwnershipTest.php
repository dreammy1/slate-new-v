<?php
/**
 * Integration tests for Phase A — the content engine owning the active theme.
 *
 * Phase A moved "which theme is this site using" out of small-business-kit and
 * into the content engine. Plugins still own their themes and publish them on
 * the `content_register_themes` filter; the engine owns the choice and renders
 * the single picker inside Site Settings.
 *
 * The load-bearing property is the DUAL-WRITE. The engine writes its own key
 * AND the key small-business-kit used before, so rolling this change back — by
 * reverting the code, or by deactivating content-builder — leaves the old
 * reader looking at the theme the user actually picked, not a stale one. These
 * tests pin that in both directions, because a rollback that silently restyles
 * a live site is exactly the failure this design exists to prevent.
 *
 * small-business-kit is NOT activated in CI (booting it would inject header and
 * footer filters and move the render goldens). SBKitAPI.php only defines
 * classes, so the delegation shim is tested by including it directly and
 * publishing SBKThemes on the filter — real coverage, no render side effects.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/small-business-kit/SBKitAPI.php';

const CBT_ENGINE_KEY = 'content-builder.active_theme';
const CBT_LEGACY_KEY = 'small-business-kit.active_theme';

/**
 * Probe themes, plus the real small-business-kit set so the delegation shim is
 * exercised against the slugs it ships with. Registered once for the file.
 */
Hook::addFilter('content_register_themes', function (array $themes): array {
    $probe = [
        'probe-alpha' => ['label' => 'Probe Alpha', 'blurb' => 'test only', 'tokens' => ['--sb-accent' => '#111111']],
        'probe-beta'  => ['label' => 'Probe Beta',  'blurb' => 'test only', 'tokens' => ['--sb-accent' => '#222222']],
    ];
    return $themes + $probe + SBKThemes::all();
});

/** Capture the real values so the suite leaves the database as it found it. */
$cbtOriginalEngine = Database::setting(CBT_ENGINE_KEY);
$cbtOriginalLegacy = Database::setting(CBT_LEGACY_KEY);

// ── Registration ──────────────────────────────────────────
unit('phase A preconditions: engine and theme registry are loaded', function () {
    assert_true(class_exists('ContentBuilderAPI'), 'ContentBuilderAPI missing');
    assert_true(class_exists('SBKitAPI'), 'SBKitAPI missing');
    assert_true(class_exists('SBKThemes'), 'SBKThemes missing');
});

unit('themes registered on the filter are visible to the engine', function () {
    $all = ContentBuilderAPI::themes();
    assert_true(isset($all['probe-alpha']), 'probe-alpha not registered');
    assert_eq('Probe Alpha', $all['probe-alpha']['label']);
    assert_true(ContentBuilderAPI::getTheme('probe-beta') !== null, 'getTheme should find probe-beta');
    assert_true(ContentBuilderAPI::getTheme('no-such-theme') === null, 'getTheme should miss unknown slugs');
});

// ── The dual-write ────────────────────────────────────────
unit('setActiveTheme writes BOTH the engine key and the legacy key', function () {
    assert_true(ContentBuilderAPI::setActiveTheme('probe-alpha'), 'setActiveTheme should accept a registered slug');

    // Assert the stored rows, not the accessor: the accessor would happily
    // report the right answer off a single key and hide a missing dual-write.
    assert_eq('probe-alpha', (string) Database::setting(CBT_ENGINE_KEY), 'engine key not written');
    assert_eq('probe-alpha', (string) Database::setting(CBT_LEGACY_KEY), 'legacy key not written');
});

unit('an unregistered slug is rejected and stores nothing', function () {
    ContentBuilderAPI::setActiveTheme('probe-beta');           // known-good starting point
    assert_eq('probe-beta', (string) Database::setting(CBT_ENGINE_KEY));

    assert_false(ContentBuilderAPI::setActiveTheme('../../etc/passwd'), 'garbage slug must be refused');
    assert_false(ContentBuilderAPI::setActiveTheme(''), 'empty slug must be refused');

    // Both keys still hold the last good value — a refused write is inert.
    assert_eq('probe-beta', (string) Database::setting(CBT_ENGINE_KEY), 'engine key was clobbered');
    assert_eq('probe-beta', (string) Database::setting(CBT_LEGACY_KEY), 'legacy key was clobbered');
});

// ── Rollback ──────────────────────────────────────────────
unit('ROLLBACK: the pre-Phase-A reader sees the theme picked in the new screen', function () {
    // Pick a theme the way Site Settings does now.
    assert_true(ContentBuilderAPI::setActiveTheme('probe-alpha'));

    // Now read it exactly as small-business-kit did BEFORE Phase A: its own key,
    // straight from settings, with no engine involved. This is the code that
    // would be running again after `git revert` of the Phase A commit.
    $legacyRead = (string) Database::setting(SBKitAPI::SETTING_KEY);
    $legacyRead = $legacyRead !== '' ? $legacyRead : 'marine-pro';

    assert_eq('probe-alpha', $legacyRead, 'rolling back would silently restyle the site');
});

unit('ROLLFORWARD: an install that never opened the new screen keeps its theme', function () {
    // Simulate an upgrade: only the old key is populated. Blank the engine key
    // first and assert it really is blank — an exit code proves nothing here.
    Database::setSetting(CBT_ENGINE_KEY, '');
    Database::setSetting(CBT_LEGACY_KEY, 'probe-beta');
    assert_eq('', (string) Database::setting(CBT_ENGINE_KEY), 'engine key was not actually cleared');
    assert_eq('probe-beta', (string) Database::setting(CBT_LEGACY_KEY), 'legacy key was not actually seeded');

    assert_eq('probe-beta', ContentBuilderAPI::activeTheme(), 'engine must fall back to the legacy key');
});

unit('a stale slug that no longer exists falls back instead of rendering themeless', function () {
    Database::setSetting(CBT_ENGINE_KEY, 'theme-deleted-by-a-plugin-uninstall');
    Database::setSetting(CBT_LEGACY_KEY, '');

    $active = ContentBuilderAPI::activeTheme();
    assert_true($active !== '', 'a site must never end up with no theme');
    assert_true(ContentBuilderAPI::getTheme($active) !== null, 'fallback must be a registered theme');
});

// ── The delegation shim ───────────────────────────────────
unit('SBKitAPI::activeTheme delegates to the engine (one source of truth)', function () {
    assert_true(ContentBuilderAPI::setActiveTheme('probe-alpha'));
    assert_eq('probe-alpha', SBKitAPI::activeTheme(), 'shim must report the engine value');

    assert_true(ContentBuilderAPI::setActiveTheme('probe-beta'));
    assert_eq('probe-beta', SBKitAPI::activeTheme(), 'shim must follow the engine, not a cached value');
});

unit('SBKitAPI::setActiveTheme routes writes through the engine', function () {
    SBKitAPI::setActiveTheme('probe-alpha');

    assert_eq('probe-alpha', (string) Database::setting(CBT_ENGINE_KEY), 'write did not reach the engine key');
    assert_eq('probe-alpha', (string) Database::setting(CBT_LEGACY_KEY), 'write did not reach the legacy key');
    assert_eq('probe-alpha', ContentBuilderAPI::activeTheme());
});

unit('the shim and the engine cannot disagree for a real SBK theme', function () {
    $sbk = SBKThemes::all();
    assert_true($sbk !== [], 'small-business-kit should ship themes');

    $slug = (string) array_key_first($sbk);
    assert_true(ContentBuilderAPI::setActiveTheme($slug), "engine should accept the real slug '{$slug}'");
    assert_eq($slug, SBKitAPI::activeTheme());
    assert_eq(ContentBuilderAPI::activeTheme(), SBKitAPI::activeTheme());
});

// ── Restore ───────────────────────────────────────────────
// There is no deleteSetting(); '' is what both readers treat as unset, so a key
// that did not exist is restored to the empty string rather than removed.
unit('cleanup: restore the theme settings this file changed', function () use ($cbtOriginalEngine, $cbtOriginalLegacy) {
    Database::setSetting(CBT_ENGINE_KEY, $cbtOriginalEngine ?? '');
    Database::setSetting(CBT_LEGACY_KEY, $cbtOriginalLegacy ?? '');

    assert_eq((string) ($cbtOriginalEngine ?? ''), (string) Database::setting(CBT_ENGINE_KEY));
    assert_eq((string) ($cbtOriginalLegacy ?? ''), (string) Database::setting(CBT_LEGACY_KEY));
});
