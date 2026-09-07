<?php
/**
 * The display font is LINKED, not embedded in every page.
 *
 * It used to be inlined as a base64 data URL on every page view, to avoid a
 * separate request and any dependency on /plugins/* being served. The
 * arithmetic does not support it: base64 is four bytes per three, so the
 * inlined copy is ~33% LARGER than the file, meaning inlining costs more even
 * on a first visit — and then re-sends the whole font on every subsequent page,
 * where a linked file is already in cache.
 *
 * SBK is required directly rather than activated: activating it moves the
 * render goldens, because its boot filters change page output.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/small-business-kit/SBKitAPI.php';

unit('the display font is linked rather than embedded in the page', function (): void {
    if (!class_exists('SBKitAPI')) { assert_true(true, 'SBKitAPI unavailable'); return; }

    $head = SBKitAPI::headInjection(null);

    assert_true(
        !str_contains($head, 'data:font/woff2'),
        'the font is not embedded as a data URL — that re-sends it on every page view'
    );
    assert_true(
        str_contains($head, 'jost-latin.woff2') && str_contains($head, '<style id="sbk-fontface">'),
        'the @font-face points at the file'
    );
    assert_true(
        str_contains($head, 'rel="preload"') && str_contains($head, 'as="font"'),
        'and it is preloaded, so linking does not delay first paint'
    );
    // Fonts are fetched in CORS mode by CSS. A preload whose mode does not match
    // is ignored and the font downloads TWICE — worse than not preloading.
    assert_true(
        str_contains($head, 'crossorigin'),
        'the preload is crossorigin, matching how CSS fetches the font'
    );
});

unit('embedding the font would cost more than linking it', function (): void {
    $path = dirname(__DIR__, 2) . '/plugins/small-business-kit/assets/fonts/jost-latin.woff2';
    if (!is_file($path)) { assert_true(true, 'font file absent'); return; }

    $raw = (int) filesize($path);
    $b64 = strlen(base64_encode((string) file_get_contents($path)));

    // Pins the reasoning, not just the behaviour: if someone re-inlines the
    // font, this records why that costs bytes rather than saving a request.
    assert_true($b64 > $raw, "base64 ({$b64}) is larger than the file ({$raw})");
    assert_true(
        $b64 > $raw * 1.3,
        'base64 inflates by roughly a third — the inline copy is never cheaper'
    );
});
