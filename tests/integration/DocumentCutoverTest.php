<?php
/**
 * Phase C exit — the public document frame is assembled by the core template
 * layer, and provably BY it.
 *
 * The body cutover landed earlier; the frame did not, so PageAssembler,
 * TemplateResolver and Template had no production caller. They were complete,
 * tested and unreached — the third instance of that shape after
 * RenderContext::withTheme() and content_resolve_media_key.
 *
 * The hard part to test is that last word. renderDocumentForPublic() is
 * parity-gated: on any divergence it serves Theme::renderPage instead. So the
 * served HTML is correct whether or not the core path ran, and every assertion
 * about the markup passes while the seam is dead. Asserting on output cannot
 * distinguish "assembled" from "fell back" — by construction.
 *
 * That is why `served` exists and why the assertion on it is load-bearing. It is
 * the same discipline as the media round trip: close the loop through the thing
 * whose absence is otherwise invisible.
 */

declare(strict_types=1);

/** A published page to serve, cleaned up afterwards. */
function _cutover_page(callable $fn): void
{
    $id = ContentBuilderAPI::savePost([
        'title'  => 'Cutover fixture',
        'type'   => 'page',
        'status' => 'published',
        'layout' => [['type' => 'paragraph', 'props' => ['text' => 'Body copy.']]],
    ]);
    try {
        $fn(ContentBuilderAPI::getPost($id));
    } finally {
        ContentBuilderAPI::deletePost($id);
    }
}

/** Build head tags the way router.php does, through the real filter chain. */
function _cutover_head(array $post): string
{
    return (string) Hook::applyFilters(
        'content_head_tags',
        '<title>' . e($post['title']) . '</title>',
        $post
    );
}

function _cutover_body(array $post): string
{
    return '<main class="cb-page">' . ContentCoreBridge::renderLayoutForPublic($post['layout']) . '</main>';
}

unit('the public document is assembled by the core template layer, not the fallback', function (): void {
    if (!class_exists('ContentCoreBridge') || !class_exists('ContentSiteTemplate')) {
        assert_true(true, 'content-builder inactive'); return;
    }

    _cutover_page(function (array $post): void {
        $head = _cutover_head($post);
        $body = _cutover_body($post);

        $doc = ContentCoreBridge::renderDocumentForPublic($post, $head, $body);

        // THE assertion. Everything below this line passes even when the seam is
        // dead, because parity fallback serves byte-identical HTML.
        assert_eq(
            'core',
            $doc['served'],
            'the document was assembled by PageAssembler. "legacy" means the core path '
            . 'did not run — either it diverged, it threw, or no template resolved — and '
            . 'the frame renderers are unreached again'
        );

        // Appearance neutrality: the whole premise of routing rather than restyling.
        assert_eq(
            Theme::renderPage($head, $body, $post),
            $doc['html'],
            'the assembled document is byte-identical to the legacy frame'
        );

        assert_true(str_starts_with($doc['html'], '<!doctype html>'), 'a full document came back');
        assert_true(str_contains($doc['html'], 'Body copy.'), 'the page body is in it');
    });
});

unit('the served document carries exactly one token block', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    // The specific hazard of routing through the wrong template: DocumentTemplate
    // emits its own TokenEmitter block while injectHeadTokens() has already put
    // one in the head tags, giving two <style id="slate-tokens"> elements with the
    // later silently winning. Duplicate ids are invalid, and on a branded tenant
    // the losing block is the themed one.
    _cutover_page(function (array $post): void {
        $doc = ContentCoreBridge::renderDocumentForPublic(
            $post, _cutover_head($post), _cutover_body($post)
        );
        assert_eq(
            1,
            substr_count($doc['html'], '<style id="slate-tokens">'),
            'exactly one token block in the served document'
        );
    });
});

unit('document_engine is an instant rollback', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $prior = ContentBuilderAPI::getSiteSetting('document_engine', 'core');
    try {
        ContentBuilderAPI::setSiteSetting('document_engine', 'legacy');

        _cutover_page(function (array $post): void {
            $head = _cutover_head($post);
            $body = _cutover_body($post);
            $doc  = ContentCoreBridge::renderDocumentForPublic($post, $head, $body);

            assert_eq('legacy', $doc['served'], 'the kill-switch takes the legacy path');
            assert_eq(Theme::renderPage($head, $body, $post), $doc['html'], 'and still serves the page');
        });
    } finally {
        ContentBuilderAPI::setSiteSetting('document_engine', (string) $prior);
    }
});
