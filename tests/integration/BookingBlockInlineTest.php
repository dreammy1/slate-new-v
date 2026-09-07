<?php
/**
 * D2 — the booking block renders the service catalogue inline.
 *
 * It was an <iframe src="/book?embed=1"> with a postMessage height dance, so the
 * document carried {service, minHeight} rather than content: nothing indexable,
 * nothing themeable, and a second renderer would get a frame instead of data.
 *
 * SCOPE is deliberately narrow, and these tests pin the boundary. Booking is a
 * four-step stateful wizard whose step state rides in the query string; hosting
 * that on a CMS page would mean the page absorbing every transition and POST,
 * for a slot picker nobody indexes. So the block inlines STEP ONE — the
 * catalogue, which is genuinely content — and each card links to /book, where
 * the wizard is untouched.
 *
 * The reachability assertion is the one that carries the phase discipline, for
 * the reason spelled out in FormBlockInlineTest: block MARKUP cannot diverge
 * between the render paths, because coreRegistry() delegates to the same
 * Renderer::renderBlock the legacy path calls. What can go wrong is the block
 * not being in the core registry when the snapshot is taken — Booking registers
 * either immediately or on content_register_blocks depending on boot order — in
 * which case the core path sees an unknown block, diverges, and the page is
 * silently served by the legacy renderer while looking perfectly correct.
 */

declare(strict_types=1);

/** Two active services in one category, removed afterwards. */
function _with_services(callable $fn): void
{
    $tid = current_tenant_id();
    Database::query(
        "INSERT INTO booking_services (tenant_id, name, slug, description, duration_min, price_cents, currency, is_active)
         VALUES (?, 'Deep Tissue Massage', '_d2_deep', 'Sixty minutes of it.', 60, 9000, 'USD', 1)",
        [$tid]
    );
    $idA = (int) Database::value("SELECT id FROM booking_services WHERE tenant_id = ? AND slug = '_d2_deep'", [$tid]);
    Database::query(
        "INSERT INTO booking_services (tenant_id, name, slug, description, duration_min, price_cents, currency, is_active)
         VALUES (?, 'Free Consultation', '_d2_consult', '', 15, 0, 'USD', 1)",
        [$tid]
    );
    $idB = (int) Database::value("SELECT id FROM booking_services WHERE tenant_id = ? AND slug = '_d2_consult'", [$tid]);

    try {
        $fn($idA, $idB);
    } finally {
        Database::query("DELETE FROM booking_services WHERE tenant_id = ? AND slug IN ('_d2_deep','_d2_consult')", [$tid]);
    }
}

unit('the booking block renders the catalogue inline, not an iframe', function (): void {
    if (!class_exists('BookingAPI')) { assert_true(true, 'booking inactive'); return; }

    _with_services(function (int $idA, int $idB): void {
        $html = BookingAPI::renderContentBlock([]);

        assert_true(!str_contains($html, '<iframe'), 'no iframe — the catalogue is in this document');
        assert_true(str_contains($html, 'Deep Tissue Massage'), 'service names are server-rendered');
        assert_true(str_contains($html, 'Free Consultation'), 'every active service is listed');
        assert_true(str_contains($html, 'Sixty minutes of it.'), 'descriptions are in the markup, so they are indexable');
        assert_true(str_contains($html, '60 min'), 'and the duration');
        assert_true(str_contains($html, 'Free'), 'a zero price reads as Free rather than 0.00');
        assert_true(str_contains($html, '/book?service=' . $idA), 'each card hands off to the wizard');
    });
});

unit('pinning one service renders a call to action, not the whole list', function (): void {
    if (!class_exists('BookingAPI')) { assert_true(true, 'booking inactive'); return; }

    _with_services(function (int $idA, int $idB): void {
        $html = BookingAPI::renderContentBlock(['service' => $idA]);

        assert_true(str_contains($html, 'Deep Tissue Massage'), 'the pinned service is shown');
        assert_true(!str_contains($html, 'Free Consultation'), 'and only that one');
        assert_true(str_contains($html, '/book?service=' . $idA), 'linking straight into its booking flow');
    });
});

unit('a page containing a booking block is still rendered by the CORE engine', function (): void {
    if (!class_exists('BookingAPI') || !class_exists('ContentCoreBridge')) {
        assert_true(true, 'booking or content-builder inactive'); return;
    }

    _with_services(function (int $idA, int $idB): void {
        ContentCoreBridge::resetBodyEngine();
        $html = ContentCoreBridge::renderLayoutForPublic([
            ['type' => 'paragraph', 'props' => ['text' => 'Book with us.']],
            ['type' => 'booking', 'props' => ['service' => '']],
        ]);

        // Engine first, deliberately. If the block is unregistered BOTH this and
        // the content check below fail, and the one you read first should name
        // the cause rather than the symptom.
        assert_eq(
            'core',
            ContentCoreBridge::lastBodyEngine(),
            'converting the block did not demote the page to the legacy renderer — '
            . '"legacy" would mean the block is missing from the core registry when '
            . 'its snapshot is taken, most likely through plugin boot order'
        );
        assert_true(str_contains($html, 'Deep Tissue Massage'), 'and the catalogue reached the page');
    });
});

unit('booking styles reach the head only when the page has a booking block', function (): void {
    if (!class_exists('BookingAPI')) { assert_true(true, 'booking inactive'); return; }

    // Through the real filter chain, so this asserts the hook is REGISTERED —
    // the half a direct method call cannot see.
    $without = (string) Hook::applyFilters('content_head_tags', '<title>x</title>', ['layout' => [
        ['type' => 'paragraph', 'props' => ['text' => 'no booking here']],
    ]]);
    assert_true(!str_contains($without, 'book-card-link'), 'a page with no booking block pays nothing');

    $with = (string) Hook::applyFilters('content_head_tags', '<title>x</title>', ['layout' => [
        ['type' => 'columns', 'props' => ['cols' => [
            ['blocks' => [['type' => 'booking', 'props' => []]]],
        ]]],
    ]]);
    assert_true(str_contains($with, 'book-card-link'), 'a booking block nested in a column still gets its stylesheet');
    assert_true(
        !str_contains($with, '<link rel="stylesheet"'),
        'inlined, not linked — a CDN in front of this install caches public.css by '
        . 'path and ignores ?v=, which is why the standalone widget inlines it too'
    );
});
