<?php
/**
 * D1 — the form block renders inline, and does so through the core engine.
 *
 * It was an <iframe src="/forms/<slug>?embed=1"> with a postMessage height
 * dance. That put a form on the page and satisfied nothing else the block model
 * exists for: the document carried {formSlug, minHeight} rather than content, so
 * the Content API returned a pointer to another page; --slate-* stopped at the
 * frame boundary, so a branded tenant got an unbranded form and Phase E could
 * never reach inside; and the markup was not server-rendered, so search engines
 * saw an empty box.
 *
 * The second test carries the Phase D discipline, though not for the reason it
 * first appears. Block MARKUP cannot diverge between the two render paths:
 * coreRegistry() wraps each block in a CallbackBlock that delegates to
 * Renderer::renderBlock — the same callback the legacy path calls — so both
 * produce identical HTML by construction. Rewriting this block's output could
 * not, by itself, trip parity.
 *
 * What it does guard is REACHABILITY, which is the actual risk here. The block
 * has to be in the registry coreRegistry() snapshots, and Forms registers it
 * either immediately or on content_register_blocks depending on plugin boot
 * order. Register too late and the core path sees an unknown block, emits a
 * comment where the form should be, diverges from legacy, and the page is
 * silently served by the legacy renderer instead. Props normalisation is the
 * other route: the core registry is built with no defaults ("verbatim props")
 * while the legacy path may apply the block's, so a document omitting formSlug
 * can render differently on each side.
 *
 * Both failures look identical from the HTML — the page renders, the form works,
 * and the cutover is off. Asserting the markup cannot see that. Asserting the
 * engine can.
 */

declare(strict_types=1);

/** A published form, removed afterwards. */
function _with_form(callable $fn): void
{
    $slug = '_d1_inline_fixture';
    Database::query(
        "INSERT INTO forms_definitions (tenant_id, slug, title, description, fields_json,
             submit_label, success_message, status)
         VALUES (?,?,?,?,?,?,?, 'published')
         ON DUPLICATE KEY UPDATE status = 'published'",
        [
            current_tenant_id(), $slug, 'Inline Fixture Form', 'A description.',
            json_encode([['type' => 'text', 'name' => 'full_name', 'label' => 'Your name']]),
            'Send it', 'Thanks, we got it.',
        ]
    );
    try {
        $fn($slug);
    } finally {
        Database::query('DELETE FROM forms_definitions WHERE tenant_id = ? AND slug = ?',
            [current_tenant_id(), $slug]);
    }
}

unit('the form block renders a real form inline, not an iframe', function (): void {
    if (!class_exists('FormsAPI')) { assert_true(true, 'forms plugin inactive'); return; }

    _with_form(function (string $slug): void {
        $html = FormsAPI::renderContentBlock(['formSlug' => $slug]);

        assert_true(!str_contains($html, '<iframe'), 'no iframe — the form is in this document');
        assert_true(str_contains($html, '<form'), 'a real form element is emitted');
        assert_true(str_contains($html, 'Your name'), 'the field labels are server-rendered');
        assert_true(str_contains($html, '/forms/' . $slug), 'it posts to the route that owns validation and storage');
        assert_true(str_contains($html, 'name="return_to"'), 'and carries the page to come back to');
        assert_true(
            !str_contains($html, '<h1'),
            'the block uses h2 — the host page already owns the h1'
        );
    });
});

unit('a page containing a form block is still rendered by the CORE engine', function (): void {
    if (!class_exists('FormsAPI') || !class_exists('ContentCoreBridge')) {
        assert_true(true, 'forms or content-builder inactive'); return;
    }

    _with_form(function (string $slug): void {
        ContentCoreBridge::resetBodyEngine();
        $html = ContentCoreBridge::renderLayoutForPublic([
            ['type' => 'paragraph', 'props' => ['text' => 'Intro.']],
            ['type' => 'form', 'props' => ['formSlug' => $slug]],
        ]);

        // Engine first: an unregistered block fails both assertions, and this is
        // the one that names why.
        assert_eq(
            'core',
            ContentCoreBridge::lastBodyEngine(),
            'converting the block did not demote the page to the legacy renderer. '
            . '"legacy" means the core path could not render this block the same way — '
            . 'most likely it is missing from the core registry through plugin boot '
            . 'order. The page would still look correct, which is why this is asserted '
            . 'and not inferred from the HTML'
        );
        assert_true(str_contains($html, '<form'), 'and the form reached the page');
    });
});

unit('form assets reach the head only when the page has a form block', function (): void {
    if (!class_exists('FormsAPI')) { assert_true(true, 'forms inactive'); return; }

    // Through the real filter, not a hand-built Plugin instance: this asserts the
    // hook is actually REGISTERED, which is the half a direct method call cannot
    // see — the same production-caller point as everywhere else in this phase.
    $without = (string) Hook::applyFilters('content_head_tags', '<title>x</title>', ['layout' => [
        ['type' => 'paragraph', 'props' => ['text' => 'no form here']],
    ]]);
    assert_true(!str_contains($without, 'public.css'), 'a page with no form block pays nothing');

    // Nested on purpose: container blocks disagree about which prop holds their
    // children, so the walk has to be generic rather than a list of known keys.
    $with = (string) Hook::applyFilters('content_head_tags', '<title>x</title>', ['layout' => [
        ['type' => 'columns', 'props' => ['cols' => [
            ['blocks' => [['type' => 'form', 'props' => ['formSlug' => 'x']]]],
        ]]],
    ]]);
    assert_true(str_contains($with, 'public.css'), 'a form nested in a column still gets its stylesheet');
    assert_true(str_contains($with, 'forms-logic.js'), 'and its logic script');
});

unit('return_to is a same-origin path or nothing', function (): void {
    if (!class_exists('FormsAPI')) { assert_true(true, 'forms inactive'); return; }

    $prior = $_SERVER['REQUEST_URI'] ?? null;
    try {
        // A redirect target taken from a request is an open redirect the moment
        // it can name another host, so anything that is not a single-slash-rooted
        // path is discarded rather than cleaned up.
        foreach (['//evil.example', 'https://evil.example/x', 'evil'] as $hostile) {
            $_SERVER['REQUEST_URI'] = $hostile;
            assert_eq('', FormsAPI::returnToPath(), "'{$hostile}' is refused outright");
        }

        $_SERVER['REQUEST_URI'] = '/slate/p/contact?utm=1';
        assert_eq('/slate/p/contact', FormsAPI::returnToPath(), 'a real path survives, without its query');
    } finally {
        if ($prior === null) { unset($_SERVER['REQUEST_URI']); } else { $_SERVER['REQUEST_URI'] = $prior; }
    }
});
