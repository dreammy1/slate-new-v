<?php
/**
 * T4 — a picked image survives the whole loop: builder shape → save → reload →
 * resolver → rendered markup.
 *
 * MediaKeyResolutionTest already covers resolveMedia(), but it registers its own
 * mapping through _with_media_map(). That proves the CONTRACT works given a
 * resolver; it cannot prove one exists. Nothing answered
 * `content_resolve_media_key` in production until ContentMediaKeyResolver, so
 * every keyed block resolved to an empty URL and the image block returned early.
 *
 * That is the failure this test exists to make loud. A builder that writes keys
 * the resolver cannot map does not fix the portability problem, it relocates it
 * — and it fails as "no image", which looks like an authoring mistake rather
 * than a broken reference. So this closes the loop through the REAL resolver:
 * if the key does not map, the assertion on the resolved URL fails here, in CI,
 * instead of on a live page.
 */

declare(strict_types=1);

unit('a keyed image round-trips from builder shape to rendered src', function (): void {
    if (!class_exists('ContentBuilderAPI') || !class_exists('Media')
        || !class_exists('ContentMediaKeyResolver')) {
        assert_true(true, 'content-builder or media unavailable');
        return;
    }

    $path    = '/uploads/media/_roundtrip_fixture.webp';
    $mediaId = Media::register($path, ['mime' => 'image/webp']);
    assert_true($mediaId > 0, 'the fixture asset registered in the media library');

    $postId = 0;
    try {
        // 1. Exactly the shape builder.js writes when the picker returns an item.
        $key    = ContentMediaKeyResolver::keyFor($mediaId);
        $layout = [[
            'type'  => 'image',
            'props' => ['media' => ['key' => $key, 'alt' => 'Studio floor', 'focal' => [0.5, 0.35]]],
        ]];

        // 2. Save and reload. The document must come back carrying the KEY —
        //    not a URL the save path helpfully resolved for us, which would put
        //    the install's hostname back into stored content.
        $postId = ContentBuilderAPI::savePost([
            'title' => 'Media key round trip', 'type' => 'page', 'layout' => $layout,
        ]);
        assert_true($postId > 0, 'the document saved');

        $stored = ContentBuilderAPI::getPost($postId);
        $props  = $stored['layout'][0]['props'] ?? [];

        assert_eq($key, (string) ($props['media']['key'] ?? ''), 'the reloaded document carries the key');
        assert_eq('Studio floor', (string) ($props['media']['alt'] ?? ''), 'alt survives the round trip');
        assert_true(
            is_array($props['media']['focal'] ?? null) && count($props['media']['focal']) === 2,
            'the focal point survives the round trip'
        );
        assert_true(
            (string) ($props['src'] ?? '') === '',
            'no raw URL was written — the deprecated form must not come back through the writer'
        );

        // 3. The PRODUCTION resolver maps that exact key. No test filter is
        //    registered here on purpose: this is the step whose absence would
        //    otherwise surface as a missing image on a live page.
        $m = ContentBuilderAPI::resolveMedia($props['media']);
        assert_true(
            $m['url'] !== '',
            "the production resolver maps '{$key}' to a URL — if this fails, the builder is "
            . 'writing keys nothing can resolve, which ships as "no image"'
        );
        assert_eq(ContentBuilderAPI::mediaUrl($path), $m['url'], 'it resolves to the registered asset');

        // 4. Render. The URL must reach the markup, and the key must not.
        $html = ContentCoreBridge::render((string) json_encode($layout));

        assert_true(
            str_contains($html, 'src="' . htmlspecialchars($m['url'], ENT_QUOTES, 'UTF-8') . '"'),
            'the rendered <img src> is the resolved URL the picker started from'
        );
        assert_true(
            !str_contains($html, $key),
            'the key never reaches the markup — a key in an <img src> renders as a broken image'
        );
        assert_true(str_contains($html, 'alt="Studio floor"'), 'the alt reaches the markup');
    } finally {
        if ($postId > 0) { ContentBuilderAPI::deletePost($postId); }
        Media::unregister($path);
    }
});

unit('an unmappable key renders nothing rather than a broken image', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    // The id does not exist, so the resolver declines and resolveMedia reports
    // no URL. The block returns early: a missing figure, never an <img> whose
    // src is a key. This is the behaviour the round-trip test above would catch
    // if the resolver were removed — asserted here so the two failure modes stay
    // distinguishable.
    $layout = [['type' => 'image', 'props' => ['media' => ['key' => 'media:2147483600', 'alt' => 'Nothing']]]];
    $html   = ContentCoreBridge::render((string) json_encode($layout));

    assert_true(!str_contains($html, '<img'), 'no <img> is emitted for an unresolvable key');
    assert_true(!str_contains($html, 'media:2147483600'), 'the key does not leak into the markup');
});

/**
 * Regression for a defect this round trip exposed rather than introduced.
 *
 * savePost() defaulted status with `$data['status'] ?? 'draft'` inside the
 * in_array() CONDITION while the ternary returned the raw `$data['status']`.
 * With status absent the condition passed on the defaulted string and the branch
 * wrote the missing key — NULL — into a NOT NULL column, so every caller that
 * omitted a status failed with an integrity-constraint violation. Kept as a
 * named test because the round trip above would only ever report it as "the
 * document saved" failing, which does not say why.
 */
unit('savePost defaults an absent status to draft instead of writing null', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    $id = 0;
    try {
        $id = ContentBuilderAPI::savePost(['title' => 'Status default', 'type' => 'page', 'layout' => []]);
        assert_true($id > 0, 'a document with no explicit status saves');
        assert_eq('draft', (string) (ContentBuilderAPI::getPost($id)['status'] ?? ''), 'it lands as draft');
    } finally {
        if ($id > 0) { ContentBuilderAPI::deletePost($id); }
    }
});
