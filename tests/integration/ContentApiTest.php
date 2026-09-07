<?php
/**
 * T2 — the content API envelope (ADR-0013 §3).
 *
 * The envelope is what a React consumer sees, so its shape is as much a contract
 * as the document's. Two properties matter most and are asserted literally:
 *
 *   - the document is passed through VERBATIM, so both renderers provably read
 *     the same bytes rather than two serialisations that drift;
 *   - addressing is ECHOED, so a consumer never parses identity back out of the
 *     URL it requested.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchema;

/** Publish a fixture at a slug, run $fn, then remove it. */
function _with_published_page(string $slug, string $fixture, callable $fn): void
{
    $layout = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/fixtures/documents/' . $fixture . '.json'),
        true
    );

    $id = ContentBuilderAPI::savePost([
        'type' => 'page', 'title' => 'API fixture', 'slug' => $slug,
        'status' => 'published', 'layout' => $layout,
    ]);

    try {
        $fn($id);
    } finally {
        ContentBuilderAPI::deletePost($id);
    }
}

unit('the content envelope has exactly the five contracted members', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    _with_published_page('api-envelope-probe', 'envelope-basic', function (): void {
        $env = ContentBuilderAPI::contentEnvelope('api-envelope-probe');
        assert_true($env !== null, 'a published route returns an envelope');

        $keys = array_keys($env);
        sort($keys);
        // Five since the ADR-0013 addendum added `resolved` for dynamic blocks.
        // The member is additive: a consumer written against the original four
        // still renders, because a dynamic block with no resolved entry falls
        // back to its empty state.
        assert_eq(['address', 'document', 'meta', 'resolved', 'theme'], $keys, 'envelope members are frozen');
    });
});

unit('the envelope echoes addressing so a consumer never parses the URL', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    _with_published_page('api-address-probe', 'envelope-basic', function (): void {
        $env = ContentBuilderAPI::contentEnvelope('api-address-probe', 'en');
        $addr = $env['address'] ?? [];

        assert_eq('/api-address-probe', $addr['route'] ?? '', 'the route is echoed');
        assert_eq('api-address-probe', $addr['slug'] ?? '', 'and its slug');
        assert_eq('en', $addr['locale'] ?? '', 'the locale travels with it');
        assert_eq('page', $addr['type'] ?? '', 'as does the type');
        assert_eq('published', $addr['status'] ?? '', 'and the status');

        // ADR-0013 §2: addressing lives on the row and is echoed here — it must
        // never have been written into the document itself.
        assert_false(
            str_contains(json_encode($env['document']), '"route"'),
            'addressing never leaked into the stored document'
        );
    });
});

unit('the document travels verbatim, not re-serialised', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    _with_published_page('api-verbatim-probe', 'envelope-basic', function ($id): void {
        $env = ContentBuilderAPI::contentEnvelope('api-verbatim-probe');
        $post = ContentBuilderAPI::getPost($id);

        // The API must hand over exactly what the HTML renderer reads. Anything
        // else and the two renderers are working from different bytes.
        assert_eq(
            json_encode(DocumentSchema::normalize($post['layout'], 'page')),
            json_encode($env['document']),
            'the API document is the same normalised envelope the renderer uses'
        );
    });
});

unit('the envelope states the install base so a remote consumer can resolve URLs', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    _with_published_page('api-base-probe', 'media-keys', function (): void {
        $env = ContentBuilderAPI::contentEnvelope('api-base-probe');

        assert_true(array_key_exists('base', $env['meta']), 'meta carries the base path');
        assert_eq(
            rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/'),
            $env['meta']['base'],
            'and it matches where this install actually lives'
        );
        assert_eq(DocumentSchema::VERSION, $env['meta']['schema'] ?? null, 'and the schema version');
    });
});

unit('an unpublished or missing route yields no envelope', function (): void {
    if (!class_exists('ContentBuilderAPI')) { assert_true(true, 'content-builder inactive'); return; }

    assert_true(
        ContentBuilderAPI::contentEnvelope('no-such-route-anywhere') === null,
        'a missing route returns null so the caller chooses the status code'
    );

    // A draft must not be readable through the API. The public renderer already
    // refuses drafts; an API that did not would be a disclosure channel around it.
    $id = ContentBuilderAPI::savePost([
        'type' => 'page', 'title' => 'Draft', 'slug' => 'api-draft-probe',
        'status' => 'draft', 'layout' => [],
    ]);
    try {
        assert_true(
            ContentBuilderAPI::contentEnvelope('api-draft-probe') === null,
            'a draft is not served through the content API'
        );
    } finally {
        ContentBuilderAPI::deletePost($id);
    }
});
