<?php
/**
 * Every SBK block root must be covered by SBKThemes::SLATE_SCOPE.
 *
 * 2c scopes the theme's --slate-* values to a union of selectors, because the
 * block roots are not uniform: seven carry `sb`, sb-cta-band emits `sb-ctaband`
 * and sb-page-hero emits `sb-page-hero`.
 *
 * A hardcoded list is the hazard that already bit sanitizeNested() and
 * layoutHasBlock() in this codebase — both walked a fixed set of places and both
 * were wrong. The difference is that this list is GUARDED. Add a block with a
 * new root class and this test fails, instead of that block silently resolving
 * content-builder's tokens instead of its theme's.
 *
 * Normalising the two odd roots to carry `.sb` was the obvious alternative and
 * was rejected: five rule groups in sb.css key on `.sb`, both blocks contain
 * headings, so the class would restyle them — and the document goldens could not
 * have caught it, because they compare source bytes and never compute whether a
 * selector matches.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/small-business-kit/lib/Themes.php';

/** Static class tokens on each block template's root element. */
function _sbk_block_roots(): array
{
    $out = [];
    foreach (glob(dirname(__DIR__, 2) . '/plugins/small-business-kit/lib/blocks/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        if (!preg_match('/<(?:section|div|header|footer|article)[^>]*\bclass="([^"]*)"/', $src, $m)) {
            continue;
        }
        // Drop interpolated fragments (a class built from a PHP short-echo tag)
        // — only static tokens can be matched by a stylesheet selector.
        //
        // The example is deliberately described rather than written out: a
        // literal PHP close-tag inside a // comment ENDS php mode, which is how
        // the first version of this file failed to parse.
        $classes = [];
        foreach (preg_split('/\s+/', $m[1]) as $c) {
            if ($c !== '' && !str_contains($c, '<?')) { $classes[] = $c; }
        }
        $out[basename($f, '.php')] = $classes;
    }
    return $out;
}

unit('every SBK block root is covered by the scoped token selector', function (): void {
    if (!class_exists('SBKThemes')) { assert_true(true, 'SBK unavailable'); return; }

    // The classes the scope selector actually targets, parsed from the constant
    // rather than restated — so the test cannot drift from the implementation.
    $covered = [];
    foreach (explode(',', SBKThemes::SLATE_SCOPE) as $sel) {
        $covered[] = ltrim(trim($sel), '.');
    }

    $roots = _sbk_block_roots();
    assert_true($roots !== [], 'block templates were found and parsed');

    foreach ($roots as $block => $classes) {
        $hit = array_intersect($classes, $covered);
        assert_true(
            $hit !== [],
            "{$block}'s root (" . implode(' ', $classes) . ') is covered by '
            . SBKThemes::SLATE_SCOPE . " — an uncovered block resolves content-builder's "
            . 'tokens instead of its own theme, which is a silent wrong-colour bug'
        );
    }
});

unit('the two odd roots are covered without carrying .sb', function (): void {
    if (!class_exists('SBKThemes')) { assert_true(true, 'SBK unavailable'); return; }

    // Named explicitly: these are the blocks that forced the union. If someone
    // later "tidies" the scope back to a bare `.sb`, this says why not.
    $roots = _sbk_block_roots();
    foreach (['sb-cta-band' => 'sb-ctaband', 'sb-page-hero' => 'sb-page-hero'] as $block => $cls) {
        assert_true(
            in_array($cls, $roots[$block] ?? [], true),
            "{$block} still roots on .{$cls}"
        );
        assert_true(
            !in_array('sb', $roots[$block] ?? [], true),
            "{$block} does NOT carry .sb — adding it would apply .sb root padding, "
            . 'root colour/font and .sb h1-h4 metrics to a block that has headings'
        );
        assert_true(
            str_contains(SBKThemes::SLATE_SCOPE, '.' . $cls),
            "and the scope selector covers .{$cls} explicitly"
        );
    }
});
