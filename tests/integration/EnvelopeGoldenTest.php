<?php
/**
 * T4 — the document envelope, themed and unthemed.
 *
 * DocumentRenderTest pins the BODY. This pins the <head>, which is where the
 * --slate-* token block is emitted and therefore where two hazards live that a
 * body-only golden cannot see:
 *
 *   1. Whether the tenant Theme reaches the render at all. Before the seam in
 *      CoreBridge::siteTheme(), RenderContext::withTheme() had no production
 *      caller, so DocumentTemplate read $ctx->theme, found null, and rendered
 *      unskinned every time — unreached rather than broken, and invisible.
 *
 *   2. Whether `color-scheme: light dark` is declared. CoreBridge emits the block
 *      WITHOUT it on purpose, so it stays inert on pages that do not consume the
 *      tokens yet. DocumentTemplate took TokenEmitter's default of true. Routing
 *      existing pages through the template would have started handing form
 *      controls and scrollbars to the OS preference on tenants that never opted
 *      into dark — a visual change, on a cutover whose whole premise is
 *      appearance neutrality.
 *
 * Regenerate with php tests/bin/render-goldens.php and read the diff.
 */

declare(strict_types=1);

/**
 * Local, uniquely named. DocumentRenderTest defines an equivalent helper, but
 * depending on it would make this file's correctness rest on glob() order —
 * fine today because 'D' sorts before 'E', and silently broken the moment a
 * file is renamed. Not a dependency worth having for four lines.
 */
function _slate_envelope_strip_base(string $html): string
{
    $base = rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/');
    if ($base !== '') {
        $html = str_replace(['="' . $base . '/', "('" . $base . '/'], ['="/', "('/"], $html);
    }
    $origin = rtrim((string) parse_url(SLATE_URL, PHP_URL_SCHEME), ':/') . '://'
            . (string) parse_url(SLATE_URL, PHP_URL_HOST)
            . ((int) parse_url(SLATE_URL, PHP_URL_PORT) > 0 ? ':' . (int) parse_url(SLATE_URL, PHP_URL_PORT) : '');
    return $origin !== '://' ? str_replace($origin, 'http://localhost', $html) : $html;
}

unit('the document envelope renders to its checked-in HTML, themed and unthemed', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $dir = dirname(__DIR__) . '/fixtures/documents';
    require_once $dir . '/envelope_cases.php';

    // Defined by DocumentRenderTest.php, which the runner loads first. Asserted
    // rather than assumed so a load-order change fails legibly, not fatally.
    assert_true(function_exists('_strip_install_base'), 'the base-stripping helper is loaded');

    $source = slate_envelope_source();
    foreach (slate_envelope_cases() as $case => $ctx) {
        $goldenPath = $dir . '/expected/envelope/' . $case . '.html';
        assert_true(
            is_file($goldenPath),
            "no envelope golden for '{$case}' — run: php tests/bin/render-goldens.php"
        );

        $actual = _slate_envelope_strip_base(slate_render_envelope($source, $ctx));
        assert_eq(
            (string) file_get_contents($goldenPath),
            $actual,
            "envelope '{$case}' renders differently than its golden. If intended, "
            . "regenerate with php tests/bin/render-goldens.php and review the diff."
        );
    }
});

/**
 * The golden above would catch a regression, but only as "these bytes differ".
 * These name the two properties, so a failure says WHICH guarantee broke — the
 * difference between a diff someone squints at and a diff someone understands.
 */
unit('the envelope is inert unthemed and branded themed', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $dir = dirname(__DIR__) . '/fixtures/documents';
    require_once $dir . '/envelope_cases.php';

    $cases  = slate_envelope_cases();
    $source = slate_envelope_source();

    $unthemed = slate_render_envelope($source, $cases['unthemed']);
    $themed   = slate_render_envelope($source, $cases['themed']);

    // Match the DECLARATION, not the substring: `@media (prefers-color-scheme:
    // dark)` is present in every token block, themed or not, so asserting on
    // 'color-scheme' alone fails against correct output.
    assert_true(
        !str_contains($unthemed, 'color-scheme:light dark'),
        'an unthemed envelope declares no color-scheme — it must not hand the '
        . 'page to the OS preference on a tenant that never opted into dark'
    );
    assert_true(
        !str_contains($unthemed, SLATE_ENVELOPE_TEST_ACCENT),
        'an unthemed envelope carries no accent override'
    );
    assert_true(
        str_contains($themed, 'color-scheme:light dark;'),
        'a themed envelope opts into color-scheme explicitly'
    );
    assert_true(
        str_contains($themed, '--slate-color-accent:' . SLATE_ENVELOPE_TEST_ACCENT . ';'),
        'a themed envelope carries its context Theme into the head'
    );
});

/**
 * The two tests above build their contexts by hand, so they pin DocumentTemplate
 * but say nothing about whether production ever HANDS it a themed context. That
 * is the actual seam, and it was the actual defect: withTheme() had no caller
 * outside tests, so DocumentTemplate always read null.
 *
 * Asserted separately because it is invisible in rendered output — a context
 * built without a Theme produces byte-identical body HTML.
 */
unit('the default render context carries the tenant Theme', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $ctx = ContentCoreBridge::defaultContext();

    assert_true(
        $ctx->theme instanceof \Slate\Presentation\Theme\Theme,
        'the default context carries a Theme, not null — null is what made the '
        . 'envelope render unskinned in production while the head was branded'
    );

    // And it is the tenant's Theme, not an arbitrary one: same stored brand
    // inputs, same resolver, same tokens.
    //
    // Phase E 2a widened this contract. It used to be "resolves from the stored
    // brand ACCENT" — three tokens — and this assertion caught the change, which
    // is what it was for. The context now carries the WHOLE brand, because
    // --cb-* is tenant-fed for a dozen values and a rename to --slate-* is only
    // value-preserving once --slate-* carries them too. The accent still comes
    // from the core setting rather than Branding's own, since those two are
    // known to drift.
    // One accent, in both directions: the core setting wins when set, and when it
    // is not set the slate tokens follow Branding's accent rather than dropping
    // to the default blue. Feeding only one direction left the two divergent on
    // every install that never set a core brand colour.
    $accent = class_exists('Database') ? (string) Database::setting('brand_accent_color') : '';
    $brand  = class_exists('Branding') ? Branding::resolve() : [];
    $brand['accent'] = $accent !== '' ? $accent : (string) ($brand['accent'] ?? '');
    $expected = \Slate\Presentation\Theme\TenantThemeResolver::fromBrand($brand);

    assert_eq(
        $expected->tokens(),
        $ctx->theme->tokens(),
        'the default context resolves its Theme from the whole stored brand, with a '
        . 'single unified accent'
    );

    // The widening is the point, so assert it rather than only the equality:
    // a context carrying just the three accent tokens would satisfy the check
    // above if fromBrand() ever regressed to accent-only.
    assert_true(
        isset($ctx->theme->tokens()['slate-color-text']),
        'the tenant text colour reaches --slate-color-text — without it, renaming '
        . 'a var(--cb-ink) consumer changes the colour on every branded site'
    );
});

/**
 * The branded fixture has to be able to FAIL, or it is the blind gate again with
 * more lines.
 *
 * Phase E step 2 renames consumers from tenant-fed --cb-* to --slate-*. That is
 * only value-preserving once --slate-* carries tenant branding; until then a
 * rename changes the colour on every branded site. The unthemed and themed cases
 * cannot see it — themed overrides the accent alone, and text, surface, canvas
 * and radius sit at their defaults in both — so a batch that moved them would
 * produce a zero-byte diff and pass review.
 *
 * This asserts the branded case really does differ from the defaults on every
 * token class step 2 migrates, so a later edit cannot quietly reduce it to the
 * unthemed case and leave the gate looking green.
 */
unit('the branded fixture differs from slate defaults on every migrated token class', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $dir = dirname(__DIR__) . '/fixtures/documents';
    require_once $dir . '/envelope_cases.php';

    $cases = slate_envelope_cases();
    assert_true(isset($cases['branded']), 'the branded case exists');

    $branded  = _slate_envelope_strip_base(slate_render_envelope(slate_envelope_source(), $cases['branded']));
    $unthemed = _slate_envelope_strip_base(slate_render_envelope(slate_envelope_source(), $cases['unthemed']));

    assert_true($branded !== $unthemed, 'branded output is not identical to unthemed');

    // One representative per class. Colour alone would leave radius and
    // typography unguarded, and those are token classes step 2 migrates too.
    // Anchored to the DECLARATION, not the bare value: '2px' also occurs inside
    // --slate-shadow-1's `0 1px 2px`, so a bare-value check reported the
    // unthemed fixture as varying radius when it does not. A discriminator that
    // matches something else is not a discriminator.
    $classes = [
        'text colour'    => '--slate-color-text:#2B1B12',
        'surface colour' => '--slate-color-surface:#FFFDF7',
        'canvas colour'  => '--slate-color-canvas:#F3EAD8',
        'radius'         => '--slate-radius-md:2px',
        'font weight'    => '--slate-font-weight-heading:750',
        'tracking'       => '--slate-tracking-heading:.04em',
    ];
    foreach ($classes as $label => $value) {
        assert_true(
            str_contains($branded, $value),
            "the branded fixture varies {$label} ({$value}) — a class left at its "
            . 'default is a class step 2 could change without any golden moving'
        );
        assert_true(
            !str_contains($unthemed, $value),
            "and the unthemed fixture does not, so the two genuinely differ on {$label}"
        );
    }
});
