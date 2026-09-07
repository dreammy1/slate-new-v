<?php
/**
 * Security boundary tests for the content engine.
 *
 * Two things are being pinned here, both of them privilege boundaries the
 * plugin already intended and did not enforce:
 *
 *  1. A URL prop must never carry an executable scheme. e() escapes HTML, which
 *     does nothing to javascript: or data:text/html in an href.
 *  2. The 'html' block declares perm => content.publish. That declaration was
 *     never read by anything, so content.edit was enough to inject script.
 *
 * Both routes gave a content.edit user arbitrary JS in a visitor's — and an
 * admin's — browser, which is editor-to-admin escalation.
 */

declare(strict_types=1);

/** Render one block and return its HTML. */
function _cb_render(string $type, array $props): string
{
    return Renderer::renderBlock(['type' => $type, 'props' => $props]);
}

unit('executable URL schemes are stripped from block hrefs', function (): void {
    if (!class_exists('Renderer')) { assert_true(true, 'content-builder inactive'); return; }

    $hostile = [
        'javascript:alert(1)',
        'JaVaScRiPt:alert(1)',              // scheme match must be case-insensitive
        "java\tscript:alert(1)",            // browsers ignore control chars in schemes
        " javascript:alert(1)",             // and leading whitespace
        "jav\x00ascript:alert(1)",          // and NULs
        'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
        'vbscript:msgbox(1)',
    ];

    foreach ($hostile as $url) {
        foreach (['button' => 'href', 'cta' => 'btnHref', 'hero' => 'btnHref'] as $type => $prop) {
            $html = _cb_render($type, [$prop => $url, 'text' => 'x', 'btnText' => 'x']);
            $flat = strtolower(preg_replace('/[\x00-\x20]/', '', $html));

            assert_false(
                str_contains($flat, 'javascript:') || str_contains($flat, 'data:text/html')
                    || str_contains($flat, 'vbscript:'),
                "{$type}.{$prop} must not emit an executable scheme for " . var_export($url, true)
            );
        }
    }
});

unit('legitimate URLs survive sanitisation intact', function (): void {
    if (!class_exists('Renderer')) { assert_true(true, 'content-builder inactive'); return; }

    // Over-aggressive filtering would break real links, which is its own outage.
    $safe = [
        'https://example.com/a/b?c=1&d=2',
        'http://example.com',
        '/about',
        '../parent',
        '#section',
        '?page=2',
        'mailto:hi@example.com',
        'tel:+15551234567',
        '//cdn.example.com/x.png',   // protocol-relative
    ];

    foreach ($safe as $url) {
        $html = _cb_render('button', ['href' => $url, 'text' => 'Go']);
        assert_true(
            str_contains($html, 'href="' . e($url) . '"'),
            'must preserve legitimate URL ' . var_export($url, true) . ", got: {$html}"
        );
    }
});

unit('the html block is refused to callers without its declared permission', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }
    assert_true(
        method_exists('ContentBuilderAPI', 'sanitizeLayout'),
        'a server-side layout sanitiser must exist — the block picker is bypassable by direct POST'
    );

    $layout = [
        ['type' => 'heading', 'props' => ['text' => 'fine', 'level' => 2]],
        ['type' => 'html',    'props' => ['html' => '<script>alert(1)</script>']],
    ];

    // Without content.publish the privileged block must be dropped, not rendered.
    $filtered = ContentBuilderAPI::sanitizeLayout($layout, false);
    $types    = array_column($filtered, 'type');
    assert_true(in_array('heading', $types, true), 'ordinary blocks survive');
    assert_false(in_array('html', $types, true), 'the html block is dropped without content.publish');

    // With it, the block is allowed through — the gate is a permission, not a ban.
    $allowed = ContentBuilderAPI::sanitizeLayout($layout, true);
    assert_true(in_array('html', array_column($allowed, 'type'), true), 'html survives with permission');
});

unit('savePost() grandfathers privileged blocks through the real save path', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    // The unit test below calls sanitizeLayout() directly and passes $existing in
    // by hand. That is not how production reaches it, and the difference hid a
    // real defect: savePost() read $id before assigning it, so the prior layout
    // was never loaded and grandfathering silently never ran. The sanitiser was
    // correct; the wiring to it was not. This exercises the wiring.
    //
    // Auth::can() is false on CLI, so this runs the unprivileged path by default
    // — which is exactly the path that must not destroy existing content.
    $tid  = slate_test_tenant(931400);
    $slug = 'grandfather-probe';

    $wipe = function () use ($tid) {
        Database::query('DELETE FROM contentbuilder_post_meta WHERE post_id IN
            (SELECT id FROM contentbuilder_posts WHERE tenant_id = ?)', [$tid]);
        Database::query('DELETE FROM contentbuilder_posts WHERE tenant_id = ?', [$tid]);
    };
    $wipe();

    try {
        $adminAuthored = [
            ['type' => 'html',    'props' => ['html' => '<div id="portal">admin built this</div>']],
            ['type' => 'heading', 'props' => ['text' => 'Intro', 'level' => 2]],
        ];

        // Seeded directly, standing in for a page an admin published earlier.
        $id = (int) Database::insert('contentbuilder_posts', [
            'tenant_id' => $tid, 'type' => 'page', 'title' => 'Grandfather probe',
            'slug' => $slug, 'status' => 'published',
            'layout' => json_encode($adminAuthored),
        ]);

        // An editor re-saves the page with only the heading reworded.
        $edited    = $adminAuthored;
        $edited[1] = ['type' => 'heading', 'props' => ['text' => 'Intro, reworded', 'level' => 2]];

        ContentBuilderAPI::savePost([
            'id' => $id, 'type' => 'page', 'title' => 'Grandfather probe',
            'slug' => $slug, 'status' => 'published', 'layout' => $edited,
        ], $tid);

        $after = ContentBuilderAPI::getPost($id, $tid)['layout'] ?? [];
        $types = array_column($after, 'type');

        assert_true(in_array('html', $types, true), 'the admin-authored html block survives an editor save');
        assert_eq(2, count($after), 'both blocks are still present');
        assert_eq('Intro, reworded', $after[1]['props']['text'] ?? '', 'the permitted edit still applied');

        // A newly introduced privileged block is still refused on the same path.
        $smuggled   = $edited;
        $smuggled[] = ['type' => 'html', 'props' => ['html' => '<script>alert(1)</script>']];
        ContentBuilderAPI::savePost([
            'id' => $id, 'type' => 'page', 'title' => 'Grandfather probe',
            'slug' => $slug, 'status' => 'published', 'layout' => $smuggled,
        ], $tid);

        $after2 = ContentBuilderAPI::getPost($id, $tid)['layout'] ?? [];
        assert_eq(2, count($after2), 'a newly added html block is dropped by savePost');
        assert_false(
            str_contains(json_encode($after2), 'alert(1)'),
            'smuggled script never reaches storage'
        );
    } finally {
        $wipe();
    }
});

unit('an unprivileged save preserves privileged blocks that were already there', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    // Blocking a content.edit user from ADDING an html block is the point.
    // Letting that same user silently DELETE one an admin already published is
    // data loss wearing a security fix's clothes — a real page on this install
    // (kaimana-portal) is built almost entirely from html blocks.
    $existing = [
        ['type' => 'html',    'props' => ['html' => '<div class="portal">admin built this</div>']],
        ['type' => 'heading', 'props' => ['text' => 'Intro', 'level' => 2]],
    ];

    // Editor edits the heading and resubmits, carrying the html block along.
    $incoming = [
        ['type' => 'html',    'props' => ['html' => '<div class="portal">admin built this</div>']],
        ['type' => 'heading', 'props' => ['text' => 'Intro, reworded', 'level' => 2]],
    ];

    $kept = ContentBuilderAPI::sanitizeLayout($incoming, false, $existing);
    assert_eq(2, count($kept), 'the untouched html block survives an unprivileged save');
    assert_eq('html', $kept[0]['type'] ?? '', 'and keeps its position');
    assert_eq('Intro, reworded', $kept[1]['props']['text'] ?? '', 'the permitted edit still applies');

    // But a NEW privileged block, not present before, is still refused.
    $smuggled = $incoming;
    $smuggled[] = ['type' => 'html', 'props' => ['html' => '<script>alert(1)</script>']];
    $filtered = ContentBuilderAPI::sanitizeLayout($smuggled, false, $existing);
    assert_eq(2, count($filtered), 'a newly introduced html block is dropped');
    foreach ($filtered as $b) {
        assert_false(
            str_contains(json_encode($b), 'alert(1)'),
            'smuggled script must not survive'
        );
    }
});

unit('deleting a post never touches another tenant\'s rows', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    $mine    = slate_test_tenant(930100);
    $theirs  = slate_test_tenant(930200);

    $cleanup = function () use ($mine, $theirs): void {
        foreach ([$mine, $theirs] as $t) {
            Database::query('DELETE FROM contentbuilder_post_meta WHERE post_id IN
                (SELECT id FROM contentbuilder_posts WHERE tenant_id = ?)', [$t]);
            Database::query('DELETE FROM contentbuilder_posts WHERE tenant_id = ?', [$t]);
        }
    };
    $cleanup();

    try {
        $theirPost = (int) Database::insert('contentbuilder_posts', [
            'tenant_id' => $theirs, 'type' => 'page', 'title' => 'Theirs',
            'slug' => 'theirs-' . $theirs, 'status' => 'published',
        ]);
        Database::query(
            'INSERT INTO contentbuilder_post_meta (post_id, meta_key, meta_val) VALUES (?,?,?)',
            [$theirPost, 'keep_me', 'yes']
        );

        // Acting as $mine, try to delete a post id that belongs to $theirs.
        ContentBuilderAPI::deletePost($theirPost, $mine);

        $postStill = (int) Database::value('SELECT COUNT(*) FROM contentbuilder_posts WHERE id = ?', [$theirPost]);
        $metaStill = (int) Database::value('SELECT COUNT(*) FROM contentbuilder_post_meta WHERE post_id = ?', [$theirPost]);

        assert_eq(1, $postStill, "another tenant's post must survive");
        assert_eq(1, $metaStill, "another tenant's post_meta must survive — the delete must fail closed");
    } finally {
        $cleanup();
    }
});

unit('a privileged block cannot ride inside a container past the permission gate', function (): void {
    // sanitizeLayout() used to recurse into a hardcoded list of nesting keys —
    // children, blocks, props.blocks. The `columns` block nests under
    // props.cols[].blocks, which was not on that list, so an html block hidden
    // in a column survived an unprivileged save with its <script> intact. That
    // is the editor-to-admin escalation #6 closed, reopened by a shape nobody
    // had enumerated.
    //
    // The walk is now generic: any list of blocks anywhere below a block is
    // sanitised, whatever key holds it. These are the shapes that exist today
    // plus a doubly-nested one, because the failure was never about a
    // particular key — it was about maintaining a list of them at all.
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    $smuggled = ['type' => 'html', 'props' => ['html' => '<script>alert(1)</script>']];

    $shapes = [
        'props.cols[].blocks' => [['type' => 'columns', 'props' => ['cols' => [['blocks' => [$smuggled]]]]]],
        'props.blocks'        => [['type' => 'columns', 'props' => ['blocks' => [$smuggled]]]],
        'children'            => [['type' => 'columns', 'children' => [$smuggled]]],
        'two levels deep'     => [['type' => 'columns', 'props' => ['cols' => [['blocks' => [
                                    ['type' => 'columns', 'props' => ['cols' => [['blocks' => [$smuggled]]]]],
                                 ]]]]]],
    ];

    foreach ($shapes as $label => $layout) {
        $json = json_encode(ContentBuilderAPI::sanitizeLayout($layout, false));
        assert_false(
            str_contains($json, 'alert(1)'),
            "a privileged block nested inside a container ({$label}) reached storage"
        );
    }

    // The gate is a permission, not a ban: an author who holds content.publish
    // keeps their nested raw HTML.
    $allowed = json_encode(ContentBuilderAPI::sanitizeLayout($shapes['props.cols[].blocks'], true));
    assert_true(str_contains($allowed, 'alert(1)'), 'a permitted author is not blocked');
});
