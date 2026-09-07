<?php
/**
 * D3 — the membership plans block.
 *
 * A NEW block rather than a conversion: membership never had one, so there is no
 * iframe to remove. It advertises plans as real document content — names,
 * prices, terms — indexable and themeable, where before a page could only link
 * to the member portal.
 *
 * The load-bearing constraint is what it must NOT do. Buying runs through
 * /member, which owns the login gate, the profile-completion gate, insurance
 * add-on rules and the Stripe hand-off. A public page block that POSTed a
 * purchase would step around every one of those. So the block links, and the
 * portal sells — and that is asserted, not merely intended, because it is the
 * kind of property a later "just add a buy button" change would quietly break.
 */

declare(strict_types=1);

/** Two sellable plans and one insurance add-on, removed afterwards. */
function _with_plans(callable $fn): void
{
    $tid = current_tenant_id();
    $mk = static function (string $name, string $type, int $cents, int $days) use ($tid): int {
        Database::query(
            "INSERT INTO membership_plans (tenant_id, name, description, plan_type, price_cents, currency, duration_days, is_active)
             VALUES (?,?,?,?,?, 'USD', ?, 1)",
            [$tid, $name, $name . ' description.', $type, $cents, $days]
        );
        return (int) Database::value(
            "SELECT id FROM membership_plans WHERE tenant_id = ? AND name = ? ORDER BY id DESC LIMIT 1",
            [$tid, $name]
        );
    };

    $annual = $mk('_d3 Annual Membership', 'membership', 4999, 365);
    $month  = $mk('_d3 Monthly Membership', 'membership', 999, 30);
    $ins    = $mk('_d3 Injury Cover', 'insurance', 1500, 365);

    try {
        $fn($annual, $month, $ins);
    } finally {
        Database::query("DELETE FROM membership_plans WHERE tenant_id = ? AND name LIKE '\\_d3 %'", [$tid]);
    }
}

unit('the membership block renders plan cards inline', function (): void {
    if (!class_exists('MembershipAPI')) { assert_true(true, 'membership inactive'); return; }

    _with_plans(function (int $annual, int $month, int $ins): void {
        $html = MembershipAPI::renderContentBlock([]);

        assert_true(!str_contains($html, '<iframe'), 'the plans are in this document');
        assert_true(str_contains($html, '_d3 Annual Membership'), 'plan names are server-rendered');
        assert_true(str_contains($html, '_d3 Annual Membership description.'), 'and their descriptions, so they are indexable');
        assert_true(str_contains($html, '365 days'), 'the term is shown');
        // &amp;, not &: the href is an HTML attribute and e() escapes it, which is
        // correct — asserting the raw ampersand would have been asserting a bug.
        assert_true(
            str_contains($html, '/member?view=plans&amp;plan=' . $annual),
            'each card links into the portal, with the ampersand escaped'
        );
    });
});

unit('prices come from integer minor units, exactly', function (): void {
    if (!class_exists('MembershipAPI')) { assert_true(true, 'membership inactive'); return; }

    // 4999 cents must render as 49.99 and 999 as 9.99. Asserted on the exact
    // string because a price that is a few cents wrong is the kind of defect
    // that survives review and reaches an invoice.
    _with_plans(function (int $annual, int $month, int $ins): void {
        $html = MembershipAPI::renderContentBlock([]);
        assert_true(str_contains($html, 'USD 49.99'), '4999 cents renders as USD 49.99');
        assert_true(str_contains($html, 'USD 9.99'), '999 cents renders as USD 9.99');
        assert_true(!str_contains($html, '49.990'), 'no float artefacts in the output');
    });
});

unit('the block never initiates a purchase', function (): void {
    if (!class_exists('MembershipAPI')) { assert_true(true, 'membership inactive'); return; }

    _with_plans(function (int $annual, int $month, int $ins): void {
        $html = MembershipAPI::renderContentBlock([]);

        // The portal owns buying, and with it the login gate, the onboarding
        // gate, insurance rules and the Stripe hand-off. A form here would route
        // around all of them.
        assert_true(!str_contains($html, '<form'), 'no form is emitted');
        assert_true(!str_contains($html, '_action'), 'and no purchase action');
        assert_true(!str_contains($html, 'method="post"'), 'nothing POSTs from page content');
    });
});

unit('insurance is not advertised as a standalone plan', function (): void {
    if (!class_exists('MembershipAPI')) { assert_true(true, 'membership inactive'); return; }

    // Insurance is an add-on bought alongside a membership. Listing it as its own
    // card would misrepresent what someone is buying.
    _with_plans(function (int $annual, int $month, int $ins): void {
        $html = MembershipAPI::renderContentBlock([]);
        assert_true(!str_contains($html, '_d3 Injury Cover'), 'the add-on is excluded from the catalogue');
    });
});

unit('pinning one plan renders only that plan', function (): void {
    if (!class_exists('MembershipAPI')) { assert_true(true, 'membership inactive'); return; }

    _with_plans(function (int $annual, int $month, int $ins): void {
        $html = MembershipAPI::renderContentBlock(['plan' => $annual]);
        assert_true(str_contains($html, '_d3 Annual Membership'), 'the pinned plan is shown');
        assert_true(!str_contains($html, '_d3 Monthly Membership'), 'and only that one');
    });
});

unit('a page containing a membership block is still rendered by the CORE engine', function (): void {
    if (!class_exists('MembershipAPI') || !class_exists('ContentCoreBridge')) {
        assert_true(true, 'membership or content-builder inactive'); return;
    }

    _with_plans(function (int $annual, int $month, int $ins): void {
        ContentCoreBridge::resetBodyEngine();
        $html = ContentCoreBridge::renderLayoutForPublic([
            ['type' => 'paragraph', 'props' => ['text' => 'Join us.']],
            ['type' => 'membership-plans', 'props' => ['plan' => '']],
        ]);

        // Engine first: an unregistered block fails both assertions, and this is
        // the one that names why.
        assert_eq(
            'core',
            ContentCoreBridge::lastBodyEngine(),
            'the new block is reachable on the core render path — "legacy" would mean '
            . 'it is missing from the core registry when the snapshot is taken, most '
            . 'likely through plugin boot order, and the page would still look correct'
        );
        assert_true(str_contains($html, '_d3 Annual Membership'), 'and the plans reached the page');
    });
});

unit('plan styles reach the head only when the page has the block', function (): void {
    if (!class_exists('MembershipAPI')) { assert_true(true, 'membership inactive'); return; }

    $without = (string) Hook::applyFilters('content_head_tags', '<title>x</title>', ['layout' => [
        ['type' => 'paragraph', 'props' => ['text' => 'no plans here']],
    ]]);
    assert_true(!str_contains($without, 'cb-mplans-css'), 'a page without the block pays nothing');

    $with = (string) Hook::applyFilters('content_head_tags', '<title>x</title>', ['layout' => [
        ['type' => 'columns', 'props' => ['cols' => [
            ['blocks' => [['type' => 'membership-plans', 'props' => []]]],
        ]]],
    ]]);
    assert_true(str_contains($with, 'cb-mplans-css'), 'a block nested in a column still gets its stylesheet');
    assert_true(str_contains($with, '--slate-color-accent'), 'and it consumes the target token vocabulary');
});
