<?php
/**
 * Integration tests for the Phase 3A B5 content-builder bridge.
 *
 *   1. PARITY — the core PageRenderer is byte-identical to the legacy
 *      Renderer::render() for real stored pages and multi-block layouts.
 *   2. SNAPSHOTS — saving/publishing a post appends the right revision.
 *
 * Boots the full app (config.php → PluginLoader::boot()), so content-builder's
 * blocks are registered and ContentCoreBridge is loaded.
 */

declare(strict_types=1);

use Slate\Services\Content\RevisionStore;
use Slate\Tenancy\TenantContext;

// Preconditions: without the plugin loaded there is nothing to bridge/verify.
unit('bridge preconditions: plugin classes are loaded', function () {
    assert_true(class_exists('ContentBuilderAPI'), 'ContentBuilderAPI missing');
    assert_true(class_exists('Renderer'), 'legacy Renderer missing');
    assert_true(class_exists('BlockRegistry'), 'legacy BlockRegistry missing');
    assert_true(class_exists('ContentCoreBridge'), 'ContentCoreBridge missing');
    assert_true(count(BlockRegistry::all()) > 0, 'no blocks registered');
});

// ── 1. Parity ─────────────────────────────────────────────
unit('core render is byte-identical to legacy for a multi-block layout', function () {
    $layout = [
        ['type' => 'heading',   'props' => ['text' => 'Hello world', 'level' => '1']],
        ['type' => 'paragraph', 'props' => ['text' => 'Some body copy for the page.']],
        ['type' => 'cta',       'props' => ['pad' => 'normal', 'heading' => 'Go', 'text' => 'Do it', 'btnText' => 'Click', 'btnHref' => '#']],
    ];
    $core   = ContentCoreBridge::render($layout);
    $legacy = ContentCoreBridge::legacyRender($layout);
    assert_true($legacy !== '', 'legacy render should produce output');
    assert_eq($legacy, $core);
    assert_true(ContentCoreBridge::parityHolds($layout));
});

unit('core render preserves parity for a nested columns block', function () {
    $layout = [
        ['type' => 'columns', 'props' => ['cols' => [
            ['blocks' => [['type' => 'heading', 'props' => ['text' => 'L', 'level' => '3']]]],
            ['blocks' => [['type' => 'paragraph', 'props' => ['text' => 'R']]]],
        ]]],
    ];
    assert_true(ContentCoreBridge::parityHolds($layout), 'nested columns must render identically');
});

unit('core render is at parity with legacy for the seeded published pages', function () {
    $checked = 0;
    foreach (['home', 'demo'] as $slug) {
        $post = ContentBuilderAPI::getPostBySlug('page', $slug);
        if (!$post || ($post['status'] ?? '') !== 'published') {
            continue;
        }
        $checked++;
        assert_true(ContentCoreBridge::parityHolds($post['layout']),
            "core/legacy render diverged for page '{$slug}'");
    }
    assert_true($checked > 0, 'expected at least one seeded published page to verify against');
});

// ── 2. Revision snapshots on save / publish ───────────────
unit('saving then publishing a post appends working then published revisions', function () {
    $store = new RevisionStore(new TenantContext());
    $slug  = '__probe_bridge_' . substr(md5((string) getmypid()), 0, 8);
    $postId = 0;
    try {
        // Save as draft → a working revision should be snapshotted by the hook.
        $postId = ContentBuilderAPI::savePost([
            'type'   => 'page',
            'title'  => 'Probe bridge page',
            'slug'   => $slug,
            'status' => 'draft',
            'layout' => [['type' => 'heading', 'props' => ['text' => 'Draft', 'level' => '2']]],
        ]);
        assert_true($postId > 0);

        $working = $store->working(ContentCoreBridge::OWNER_TYPE, $postId);
        assert_true($working !== null, 'a working revision should exist after save');
        assert_eq('Draft', RevisionStore::documentOf($working)['sections'][0]['blocks'][0]['props']['text']);

        // Publish → a published revision should be appended.
        ContentBuilderAPI::publish($postId);
        $published = $store->published(ContentCoreBridge::OWNER_TYPE, $postId);
        assert_true($published !== null, 'a published revision should exist after publish');
        assert_true((int) $published['revision'] > (int) $working['revision'],
            'published revision is later in the sequence');
    } finally {
        if ($postId > 0) {
            ContentBuilderAPI::deletePost($postId);
            Database::query('DELETE FROM content_revisions WHERE owner_type = ? AND owner_id = ?',
                [ContentCoreBridge::OWNER_TYPE, $postId]);
        }
    }
});

// ── 3. Public render cutover (output provably unchanged) ──
unit('renderLayoutForPublic is byte-identical to the legacy body for every published page', function () {
    $checked = 0;
    foreach (['home', 'demo'] as $slug) {
        $post = ContentBuilderAPI::getPostBySlug('page', $slug);
        if (!$post || ($post['status'] ?? '') !== 'published') {
            continue;
        }
        $checked++;
        assert_eq(
            ContentBuilderAPI::renderLayout($post['layout']),
            ContentCoreBridge::renderLayoutForPublic($post['layout']),
            "public render changed output for '{$slug}'"
        );
    }
    assert_true($checked > 0, 'expected a seeded published page to verify');
});

unit('render_engine kill-switch forces the legacy path', function () {
    $layout = [['type' => 'heading', 'props' => ['text' => 'Hi', 'level' => '2']]];
    $original = ContentBuilderAPI::getSiteSetting('render_engine', 'core');
    try {
        ContentBuilderAPI::setSiteSetting('render_engine', 'legacy');
        assert_eq(
            ContentCoreBridge::legacyRender($layout),
            ContentCoreBridge::renderLayoutForPublic($layout),
            'kill-switch must return the legacy render'
        );
    } finally {
        ContentBuilderAPI::setSiteSetting('render_engine', (string) $original);
    }
});

// ── 4. --slate-* token injection into the page head ───────
unit('content_head_tags injects the slate token block, inertly (no color-scheme)', function () {
    $out = ContentCoreBridge::injectHeadTokens('<title>x</title>', null);
    assert_true(str_contains($out, '<title>x</title>'), 'preserves prior head tags');
    assert_true(str_contains($out, '<style id="slate-tokens">'), 'token block injected');
    assert_true(str_contains($out, '--slate-color-accent'), 'vocabulary present');
    assert_false(str_contains($out, 'color-scheme:light dark;'), 'injection is non-visual (no color-scheme declaration)');
});

unit('inject_slate_tokens kill-switch disables head injection', function () {
    $original = ContentBuilderAPI::getSiteSetting('inject_slate_tokens', 'on');
    try {
        ContentBuilderAPI::setSiteSetting('inject_slate_tokens', 'off');
        $out = ContentCoreBridge::injectHeadTokens('<title>x</title>', null);
        assert_eq('<title>x</title>', $out, 'no injection when disabled');
    } finally {
        ContentBuilderAPI::setSiteSetting('inject_slate_tokens', (string) $original);
    }
});

// ── 5. Token-consolidation bridge (slice 1: accent) ──
unit('token bridge is dormant when disabled (no alias block emitted)', function () {
    $original = ContentBuilderAPI::getSiteSetting('token_bridge', 'off');
    try {
        ContentBuilderAPI::setSiteSetting('token_bridge', 'off');   // hermetic, not ambient
        assert_eq('', ContentCoreBridge::bridgeCss());
        $out = ContentCoreBridge::injectHeadTokens('<title>x</title>', null);
        assert_false(str_contains($out, 'slate-token-bridge'), 'no bridge unless enabled');
    } finally {
        ContentBuilderAPI::setSiteSetting('token_bridge', (string) $original);
    }
});

unit('token bridge, when enabled, aliases legacy accent to the --slate- source', function () {
    $original = ContentBuilderAPI::getSiteSetting('token_bridge', 'off');
    try {
        ContentBuilderAPI::setSiteSetting('token_bridge', 'on');
        $css = ContentCoreBridge::bridgeCss();
        assert_true(str_contains($css, '--accent:var(--slate-color-accent);'), 'accent aliased');
        assert_true(str_contains($css, '--on-accent:var(--slate-color-on-accent);'), 'on-accent aliased');

        // In the head, the alias comes AFTER the --slate-* token block that defines the source.
        $out = ContentCoreBridge::injectHeadTokens('', null);
        $tokenPos  = strpos($out, 'id="slate-tokens"');
        $bridgePos = strpos($out, 'id="slate-token-bridge"');
        assert_true($tokenPos !== false && $bridgePos !== false, 'both blocks present');
        assert_true($bridgePos > $tokenPos, 'bridge emitted after the token source');
    } finally {
        ContentBuilderAPI::setSiteSetting('token_bridge', (string) $original);
    }
});

unit('admin head injection is dormant off, and emits token+bridge (in order) when on', function () {
    $original = ContentBuilderAPI::getSiteSetting('token_bridge', 'off');
    try {
        ContentBuilderAPI::setSiteSetting('token_bridge', 'off');
        ob_start(); ContentCoreBridge::injectAdminHead(); $off = ob_get_clean();
        assert_eq('', $off, 'no admin injection while dormant');

        ContentBuilderAPI::setSiteSetting('token_bridge', 'on');
        ob_start(); ContentCoreBridge::injectAdminHead(); $on = ob_get_clean();
        $tokenPos  = strpos($on, 'id="slate-tokens"');
        $bridgePos = strpos($on, 'id="slate-token-bridge"');
        assert_true($tokenPos !== false, 'token block emitted in admin head');
        assert_true($bridgePos !== false && $bridgePos > $tokenPos, 'bridge alias after the token source');
        assert_false(str_contains($on, 'color-scheme:light dark;'), 'admin injection stays non-visual (no color-scheme)');
    } finally {
        ContentBuilderAPI::setSiteSetting('token_bridge', (string) $original);
    }
});
