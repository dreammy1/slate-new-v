<?php
/**
 * The gate for sb.css's 211 token consumers.
 *
 * sb.css is inlined into any page carrying SBK blocks (<style id="sbk-css">),
 * so unlike builder.css a rendered gate is possible — and unlike the envelope
 * lane, this golden actually contains the consumers, which is the property that
 * lets it fail.
 *
 * SBK is loaded require-don't-activate. Activating it would change what every
 * other golden renders, which is exactly the mistake 2b existed to correct.
 */

declare(strict_types=1);

unit('the SBK document renders to its checked-in HTML', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $dir = dirname(__DIR__) . '/fixtures/documents';
    require_once $dir . '/site_cases.php';
    require_once $dir . '/sbk_cases.php';

    $golden = $dir . '/expected/sbk/branded.html';
    if (!is_file($golden)) { assert_true(true, 'SBK golden absent — sources unavailable'); return; }

    $actual = _slate_envelope_strip_base(slate_render_sbk_document(slate_sbk_source()));
    assert_eq(
        (string) file_get_contents($golden),
        $actual,
        'the SBK document renders differently than its golden. If intended, regenerate '
        . 'with php tests/bin/render-goldens.php and read the diff — this is the file '
        . 'that shows an --sb-* rename changing what a visitor sees.'
    );

    // The golden alone cannot say WHICH engine produced those bytes.
    // renderDocumentForPublic() serves the legacy frame whenever the core path
    // diverges, so a batch that broke parity would fail above with a large,
    // mysterious diff instead of naming the cause. Asserted separately so the
    // failure reads "engine demoted to legacy" rather than "these bytes differ".
    assert_eq(
        'core',
        slate_last_document_engine(),
        'the SBK document was assembled by the core engine — "legacy" means parity '
        . 'tripped and the fallback served it, which is defence-in-depth working '
        . 'but is not what a green batch should look like'
    );
});

unit('the SBK golden contains the consumers and both un-scoped blocks', function (): void {
    $golden = dirname(__DIR__) . '/fixtures/documents/expected/sbk/branded.html';
    if (!is_file($golden)) { assert_true(true, 'SBK golden absent'); return; }
    $g = (string) file_get_contents($golden);

    // The property that makes this gate able to fail. If sb.css ever stops being
    // inlined, the golden keeps passing while guarding nothing — which is how
    // the envelope lane looked correct for a fortnight.
    $consumers = substr_count($g, 'var(--sb-') + substr_count($g, 'var(--slate-');
    assert_true(
        $consumers > 150,
        "the golden inlines the stylesheet that consumes the tokens (found {$consumers})"
    );

    // sb-cta-band and sb-page-hero are the two blocks whose roots do not carry
    // .sb yet. The normalisation that adds it is appearance-sensitive, so they
    // must be under this gate BEFORE they are touched.
    assert_true(str_contains($g, 'sb-ctaband'), 'sb-cta-band is rendered, so its normalisation is gated');
    assert_true(str_contains($g, 'sb-page-hero'), 'sb-page-hero is rendered, so its normalisation is gated');

    // The bold preset's awkward weight — the value a rounding regression would
    // silently change.
    assert_true(str_contains($g, '750'), 'the 750 weight is carried, not rounded');
});
