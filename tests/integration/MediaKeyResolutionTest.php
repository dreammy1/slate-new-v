<?php
/**
 * T3 — media referenced by logical key, resolved at render time (ADR-0013 §4).
 *
 * The point of a key is portability: a URL is correct only for the host and path
 * that produced it, so a document carrying URLs renders correctly in one place
 * and wrongly in another. A key stays correct because resolution happens where
 * the rendering does.
 *
 * The engine owns the contract, not the mapping. Whoever holds the key -> media
 * relationship answers `content_resolve_media_key`, which keeps the content
 * engine independent of any one plugin's storage.
 */

declare(strict_types=1);

/** Register a mapping for the duration of one test. */
function _with_media_map(array $map, callable $fn): void
{
    $resolver = static function ($current, $key) use ($map) {
        return $map[$key] ?? $current;
    };
    Hook::addFilter('content_resolve_media_key', $resolver, 10, 2);
    try {
        $fn();
    } finally {
        // The harness runs every test in one process, so a mapping left behind
        // would leak into later assertions.
        if (method_exists('Hook', 'removeFilter')) {
            Hook::removeFilter('content_resolve_media_key', $resolver, 10);
        }
    }
}

unit('a logical media key resolves to a URL through the filter', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    _with_media_map(['hero.primary' => '/uploads/branding/hero.jpg'], function (): void {
        $m = ContentBuilderAPI::resolveMedia(
            ['key' => 'hero.primary', 'alt' => 'Studio floor', 'focal' => [0.5, 0.35]]
        );

        assert_true(str_ends_with($m['url'], '/uploads/branding/hero.jpg'), 'the key resolved to its URL');
        assert_eq('Studio floor', $m['alt'], 'alt from the block is used');
        assert_eq('hero.primary', $m['key'], 'the key is reported back');
        assert_true(is_array($m['focal']), 'the focal point survives');
    });
});

unit('an unresolvable key yields no image rather than a broken one', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    // Nothing answers the filter. A missing image is better than an <img> whose
    // src is a key, which would render as a broken-image icon on a live page.
    $m = ContentBuilderAPI::resolveMedia(['key' => 'nothing.maps.this']);
    assert_eq('', $m['url'], 'no URL is invented');
    assert_eq('nothing.maps.this', $m['key'], 'the key is still reported for diagnosis');
});

unit('the deprecated raw-URL form is still read', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    // Every image block written before this contract carries a raw src. They must
    // keep rendering — deprecated means "not newly written", not "broken".
    $m = ContentBuilderAPI::resolveMedia(null, '/uploads/legacy/old-hero.jpg', 'Legacy alt');
    assert_true(str_ends_with($m['url'], '/uploads/legacy/old-hero.jpg'), 'the raw URL still resolves');
    assert_eq('Legacy alt', $m['alt'], 'its alt is preserved');
    assert_eq('', $m['key'], 'and it reports no key');
});

unit('the mixed-form media fixture renders both blocks', function (): void {
    // T3's stated Definition of Done. The fixture deliberately holds one keyed
    // block and one deprecated raw-URL block, because a real migration produces
    // documents containing both and neither may be dropped.
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $json = (string) file_get_contents(dirname(__DIR__) . '/fixtures/documents/media-keys.json');

    _with_media_map(['hero.primary' => '/uploads/branding/hero.jpg'], function () use ($json): void {
        $html = ContentCoreBridge::render($json);

        assert_true(str_contains($html, '/uploads/branding/hero.jpg'), 'the keyed image resolved and rendered');
        assert_true(str_contains($html, '/uploads/legacy/old-hero.jpg'), 'the deprecated form rendered alongside it');
        assert_eq(2, substr_count($html, '<img '), 'both images are present, neither dropped');

        // The focal point is emitted so CSS can use it once something crops.
        assert_true(str_contains($html, 'object-position'), 'the focal point reaches the markup');

        // A key must never leak into an src — that would render as a broken image.
        assert_false(str_contains($html, 'src="hero.primary"'), 'a key is never used as a URL');
    });
});

unit('a resolved media URL still passes through URL sanitisation', function (): void {
    // A mapping is data like any other. If a compromised or careless mapping
    // returned an executable scheme, resolution must not be a way around the
    // sanitiser that block templates apply.
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    _with_media_map(['evil.key' => 'javascript:alert(1)'], function (): void {
        $html = ContentCoreBridge::render(json_encode([
            'schema' => 1, 'type' => 'page', 'template' => '',
            'sections' => [[
                'id' => 's1', 'layout' => ['kind' => 'default'],
                'blocks' => [['type' => 'image', 'props' => ['media' => ['key' => 'evil.key'], 'alt' => 'x']]],
            ]],
            'seo' => [],
        ]));

        assert_false(str_contains($html, 'javascript:'), 'an executable scheme never reaches src');
    });
});
