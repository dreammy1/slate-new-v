<?php
/**
 * The gate for Phase E step 2.
 *
 * Step 2 renames token consumers. The envelope goldens cannot see that: they
 * render DocumentTemplate, which emits the token block and inlines none of the
 * stylesheets that read it. Demonstrated, not assumed — rewriting 14
 * `var(--cb-ink)` sites in public.css produced no golden diff and a green suite.
 *
 * This golden renders through Theme::renderPage, which inlines
 * Branding::cssVars() and public.css, so every `var(--cb-*)` consumer is in the
 * compared bytes. A rename that changes what a tenant sees moves this file.
 */

declare(strict_types=1);

unit('the branded site document renders to its checked-in HTML', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $dir = dirname(__DIR__) . '/fixtures/documents';
    require_once $dir . '/site_cases.php';

    $golden = $dir . '/expected/site/branded.html';
    assert_true(is_file($golden), 'no site golden — run: php tests/bin/render-goldens.php');

    $actual = _slate_envelope_strip_base(slate_render_site_document(slate_site_source()));
    assert_eq(
        (string) file_get_contents($golden),
        $actual,
        'the branded site document renders differently than its golden. If intended, '
        . 'regenerate with php tests/bin/render-goldens.php and read the diff — this '
        . 'is the file that shows a token rename changing what a tenant sees.'
    );

    // The golden alone cannot say WHICH engine produced those bytes.
    // renderDocumentForPublic() serves the legacy frame whenever the core path
    // diverges, so a batch that broke parity would fail above with a large,
    // mysterious diff instead of naming the cause. Asserted separately so the
    // failure reads "engine demoted to legacy" rather than "these bytes differ".
    assert_eq(
        'core',
        slate_last_document_engine(),
        'the branded site document was assembled by the core engine — "legacy" means parity '
        . 'tripped and the fallback served it, which is defence-in-depth working '
        . 'but is not what a green batch should look like'
    );
});

unit('the site golden actually contains the token consumers', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    // The property that makes this gate able to fail at all. If a future change
    // stops inlining the stylesheet, the golden keeps passing while guarding
    // nothing — which is precisely how the envelope lane looked correct.
    $golden = (string) file_get_contents(dirname(__DIR__) . '/fixtures/documents/expected/site/branded.html');

    // Counted across BOTH vocabularies on purpose. Phase E is migrating --cb-*
    // to --slate-* one file at a time, so asserting on a specific --cb-* token
    // would fail the moment its batch lands — and the property being guarded is
    // not "cb tokens exist", it is "the consuming stylesheet is in these bytes".
    // An assertion that has to be edited by every batch is one someone will
    // eventually edit by deleting.
    $consumers = substr_count($golden, 'var(--cb-') + substr_count($golden, 'var(--slate-');
    assert_true(
        $consumers > 50,
        "the golden inlines the stylesheet that consumes the design tokens "
        . "(found {$consumers}) — without the consumers in the compared bytes, "
        . 'renaming one is invisible and this gate guards nothing'
    );
    assert_true(
        str_contains($golden, '.cb-public'),
        'and it is really the public stylesheet, not just a token block'
    );
});
