<?php
/**
 * Dynamic blocks in the content API — ADR-0013 addendum.
 *
 * post-list is not a pure function of its props: it queries published posts. The
 * PHP renderer can do that; the React renderer must not, or "one document, two
 * renderers" becomes "two renderers that agree when they happen to share a
 * database". So the API resolves it server-side and carries the result beside
 * the document.
 *
 * NOTE ON WHY THERE IS NO post-list FIXTURE: a golden for it would be rendered
 * by PHP against whatever rows the database holds, which is precisely the
 * DB-dependence T1 removed from the render suite. The block is covered here
 * instead, against rows this test creates and removes.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchema;

/** A document containing one post-list block at a known position. */
function _post_list_document(array $props = []): array
{
    return DocumentSchema::normalize([
        'schema' => 1, 'type' => 'page', 'template' => '',
        'sections' => [[
            'id' => 's1', 'layout' => ['kind' => 'default'],
            'blocks' => [
                ['type' => 'heading',   'props' => ['text' => 'Latest', 'level' => 2]],
                ['type' => 'post-list', 'props' => $props + ['postType' => 'post', 'limit' => '6']],
            ],
        ]],
        'seo' => [],
    ], 'page');
}

/** Create posts of a type, run $fn, remove them. */
function _with_posts(array $specs, callable $fn): void
{
    $ids = [];
    try {
        foreach ($specs as $spec) {
            $ids[] = ContentBuilderAPI::savePost($spec + [
                'type' => 'post', 'status' => 'published', 'layout' => [],
            ]);
        }
        $fn($ids);
    } finally {
        foreach ($ids as $id) ContentBuilderAPI::deletePost($id);
    }
}

unit('a dynamic block is resolved server-side and keyed by position', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    _with_posts([
        ['title' => 'Dynamic probe one', 'slug' => 'dyn-probe-one', 'excerpt' => 'First.'],
        ['title' => 'Dynamic probe two', 'slug' => 'dyn-probe-two'],
    ], function (): void {
        $resolved = ContentBuilderAPI::resolveDynamicBlocks(_post_list_document());

        // "<sectionId>.<blockIndex>" — the post-list is the second block of s1.
        assert_true(isset($resolved['s1.1']), 'resolved by position; got keys: ' . implode(',', array_keys($resolved)));
        assert_false(isset($resolved['s1.0']), 'the static heading is not resolved');

        $items = $resolved['s1.1']['items'] ?? [];
        assert_true(count($items) >= 2, 'both probe posts resolved');

        $slugs = array_column($items, 'slug');
        assert_true(in_array('dyn-probe-one', $slugs, true), 'the seeded post is present');

        $first = $items[array_search('dyn-probe-one', $slugs, true)];
        assert_true(($first['url'] ?? '') !== '', 'each item carries a resolved URL');
        assert_eq('First.', $first['excerpt'] ?? '', 'and its excerpt');
    });
});

unit('a document with no dynamic block resolves to nothing', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    $static = DocumentSchema::normalize([
        'schema' => 1, 'type' => 'page', 'template' => '',
        'sections' => [['id' => 's1', 'layout' => [], 'blocks' => [
            ['type' => 'heading', 'props' => ['text' => 'Static', 'level' => 2]],
        ]]],
        'seo' => [],
    ], 'page');

    assert_eq([], ContentBuilderAPI::resolveDynamicBlocks($static), 'nothing to resolve, nothing emitted');
});

unit('resolution never surfaces a draft the renderer would refuse', function (): void {
    // The API must not become a way to read what GET /api/content/{route}
    // already declines to serve. post-list resolves with status => published.
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    $draft = ContentBuilderAPI::savePost([
        'type' => 'post', 'title' => 'Unpublished probe', 'slug' => 'dyn-draft-probe',
        'status' => 'draft', 'layout' => [],
    ]);
    try {
        $items = ContentBuilderAPI::resolveDynamicBlocks(_post_list_document())['s1.1']['items'] ?? [];
        assert_false(
            in_array('dyn-draft-probe', array_column($items, 'slug'), true),
            'a draft must not appear in resolved items'
        );
    } finally {
        ContentBuilderAPI::deletePost($draft);
    }
});

unit('the envelope carries resolved data as a fifth member', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    $page = ContentBuilderAPI::savePost([
        'type' => 'page', 'title' => 'Dynamic page', 'slug' => 'dyn-envelope-probe',
        'status' => 'published', 'layout' => _post_list_document(),
    ]);
    try {
        $env = ContentBuilderAPI::contentEnvelope('dyn-envelope-probe');
        assert_true($env !== null, 'the page resolves');

        $keys = array_keys($env);
        sort($keys);
        assert_eq(['address', 'document', 'meta', 'resolved', 'theme'], $keys, 'five members now');

        // Additive: the document is still verbatim, with no resolved data
        // smuggled into the block's props (ADR-0013 §3).
        assert_false(
            str_contains(json_encode($env['document']), '"items"'),
            'resolved items never leak into the document'
        );
    } finally {
        ContentBuilderAPI::deletePost($page);
    }
});

unit('the resolved key matches the position the renderer will compute', function (): void {
    // The PHP key and the JS lookup path must agree exactly, or a dynamic block
    // silently renders its empty state on the React side — a failure that looks
    // like "no posts" rather than like a bug.
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    $doc = DocumentSchema::normalize([
        'schema' => 1, 'type' => 'page', 'template' => '',
        'sections' => [
            ['id' => 's1', 'layout' => [], 'blocks' => [['type' => 'heading', 'props' => ['text' => 'a']]]],
            ['id' => 's2', 'layout' => [], 'blocks' => [
                ['type' => 'paragraph', 'props' => ['text' => 'b']],
                ['type' => 'post-list', 'props' => ['postType' => 'post']],
            ]],
        ],
        'seo' => [],
    ], 'page');

    $keys = array_keys(ContentBuilderAPI::resolveDynamicBlocks($doc));
    assert_eq(['s2.1'], $keys, 'second section, second block — section id and index, not a global counter');
});
