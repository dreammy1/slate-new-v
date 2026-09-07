<?php
/**
 * Phase E step 1 — the landing proof.
 *
 * Step 1 adds tokens and nothing consumes them, so it cannot change a rendered
 * byte. The risk it must rule out is the NEXT step's: that a theme's real value
 * has no home in the new vocabulary and gets rounded onto the nearest scale
 * step, which is a silent weight or spacing shift on a live tenant.
 *
 * An earlier draft of this extension proposed weights 300/600/800 and tracking
 * tight/normal/wide (-0.02 / 0 / 0.08). Reading the themes killed it: 750 is a
 * real weight in the bold preset and is not a scale step, three of the four
 * tracking values miss the proposed set, and 300 is used by nothing. The fix was
 * not a better scale — it was recognising that a theme SETS a role token to a
 * literal, so primitives are defaults and nothing is snapped to anything.
 *
 * This test is that argument, executable: every weight and tracking value in
 * every SBK theme is carried through as a literal, unchanged.
 *
 * SBK is required rather than activated, because activating it moves the render
 * goldens.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/small-business-kit/lib/Themes.php';

use Slate\Presentation\Theme\ArrayTheme;
use Slate\Presentation\Tokens\DesignTokens;
use Slate\Presentation\Tokens\TokenEmitter;

/** The --sb-* role => --slate-* role mapping this phase migrates along. */
function _e1_role_map(): array
{
    return [
        '--sb-weight-body'    => 'slate-font-weight-body',
        '--sb-weight-heading' => 'slate-font-weight-heading',
        '--sb-weight-display' => 'slate-font-weight-display',
        '--sb-weight-nav'     => 'slate-font-weight-nav',
        '--sb-weight-btn'     => 'slate-font-weight-button',
        '--sb-weight-strong'  => 'slate-font-weight-strong',
        '--sb-h1-tracking'    => 'slate-tracking-heading',
        '--sb-nav-tracking'   => 'slate-tracking-nav',
    ];
}

unit('every token step 1 adds exists, and only those', function (): void {
    $all = array_merge(DesignTokens::primitives(), DesignTokens::semantics());

    foreach ([
        'slate-font-weight-semibold', 'slate-font-weight-extrabold', 'slate-font-heading',
        'slate-font-weight-body', 'slate-font-weight-heading', 'slate-font-weight-display',
        'slate-font-weight-nav', 'slate-font-weight-button', 'slate-font-weight-strong',
        'slate-tracking-heading', 'slate-tracking-nav',
    ] as $t) {
        assert_true(isset($all[$t]), "step 1 declares {$t}");
    }

    // 300 was in the draft and is used by no theme. An unused primitive is a
    // token a later phase has to justify or delete.
    assert_true(!isset($all['slate-font-weight-light']), 'the unused 300 weight was not added');
});

unit('THE LANDING PROOF: every SBK theme value survives as a literal', function (): void {
    if (!class_exists('SBKThemes')) { assert_true(true, 'SBK unavailable'); return; }

    $map    = _e1_role_map();
    $checked = 0;

    foreach (SBKThemes::all() as $slug => $theme) {
        $tokens = $theme['tokens'] ?? [];

        // Carry this theme's real values across onto the --slate-* role tokens,
        // exactly as step 2 will, and emit them.
        $overrides = [];
        foreach ($map as $sb => $slate) {
            if (isset($tokens[$sb])) { $overrides[$slate] = (string) $tokens[$sb]; }
        }
        if ($overrides === []) { continue; }

        $css = TokenEmitter::css($overrides, false, false);

        foreach ($overrides as $slate => $value) {
            $checked++;
            assert_true(
                str_contains($css, "--{$slate}:{$value};"),
                "{$slug}: {$slate} emits '{$value}' verbatim — a value that does not "
                . 'appear exactly has been rounded onto a scale step, which is a '
                . 'silent typographic change on a live tenant'
            );
        }
    }

    assert_true($checked > 0, 'the proof actually ran against real theme values');
});

unit('the values that broke the draft are carried, not rounded', function (): void {
    if (!class_exists('SBKThemes')) { assert_true(true, 'SBK unavailable'); return; }

    // Named explicitly so the regression is legible: these four are the ones the
    // originally proposed primitive scale would have changed.
    $awkward = ['750', '-.015em', '-.025em', '.01em'];
    $seen    = [];
    foreach (SBKThemes::all() as $theme) {
        foreach (_e1_role_map() as $sb => $slate) {
            $v = (string) ($theme['tokens'][$sb] ?? '');
            if (in_array($v, $awkward, true)) { $seen[$v] = $slate; }
        }
    }

    foreach ($seen as $value => $slate) {
        $css = TokenEmitter::css([$slate => $value], false, false);
        assert_true(
            str_contains($css, "--{$slate}:{$value};"),
            "'{$value}' survives on {$slate} — it is not a step on any scale, and "
            . 'the whole reason role tokens carry theme literals is so it need not be'
        );
    }
    assert_true($seen !== [], 'the awkward values are still present in the themes');
});
