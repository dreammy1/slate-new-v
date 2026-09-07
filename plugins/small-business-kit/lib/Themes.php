<?php
/**
 * Small Business Kit — Theme registry.
 *
 * Each theme = a set of CSS variables that re-style every sb-* block.
 * No layout changes; just colors, type, radii, button style. Stored
 * via SBKit::setActiveTheme(), read by SBKit::injectHead() and
 * inlined into every Content Builder page.
 */

class SBKThemes {

    /** Token defaults shared by every theme — themes override what they want. */
    private static function baseWeights(bool $editorial = false): array {
        return $editorial ? [
            '--sb-weight-display'  => '500',
            '--sb-weight-heading'  => '500',
            '--sb-weight-strong'   => '600',
            '--sb-weight-body'     => '400',
            '--sb-weight-nav'      => '500',
            '--sb-weight-btn'      => '600',
            '--sbk-nav-transform'   => 'none',
            '--sb-nav-tracking'    => '.01em',
            '--sb-h1-tracking'     => '-.015em',
        ] : [
            '--sb-weight-display'  => '800',
            '--sb-weight-heading'  => '750',
            '--sb-weight-strong'   => '700',
            '--sb-weight-body'     => '400',
            '--sb-weight-nav'      => '700',
            '--sb-weight-btn'      => '700',
            '--sbk-nav-transform'   => 'uppercase',
            '--sb-nav-tracking'    => '.08em',
            '--sb-h1-tracking'     => '-.025em',
        ];
    }

    /** Universal heading typeface — self-hosted Jost (geometric, editorial).
     *  Century Gothic is the closest common local fallback while it swaps. */
    const HEAD = "'Jost', 'Century Gothic', ui-rounded, 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif";

    public static function all(): array {
        $bold      = self::baseWeights(false);
        $editorial = self::baseWeights(true);
        $head      = self::HEAD;
        return [
            'marine-pro' => [
                'label' => 'Marine Pro',
                'blurb' => 'Cool cyan + deep navy. Trustworthy, professional.',
                'tokens' => array_merge($bold, [
                    '--sb-accent'      => '#00c6ff',
                    '--sb-accent-2'    => '#0099cc',
                    '--sb-ink'         => '#0b1c2c',
                    '--sb-ink-2'       => '#1a2f44',
                    '--sb-muted'       => '#5a6b7d',
                    '--sb-line'        => 'rgba(11,28,44,.08)',
                    '--sb-surface'     => '#f6f9fc',
                    '--sb-surface-2'   => '#eef4fa',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '18px',
                    '--sb-radius-lg'   => '24px',
                    '--sb-btn-radius'  => '999px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '-apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
                ]),
            ],
            'marine-editorial' => [
                'label' => 'Marine Editorial',
                'blurb' => 'Same cyan + navy palette, with clean geometric display type (Jost) and lighter weights.',
                'tokens' => array_merge($editorial, [
                    '--sb-accent'      => '#00c6ff',
                    '--sb-accent-2'    => '#0099cc',
                    '--sb-ink'         => '#0b1c2c',
                    '--sb-ink-2'       => '#1a2f44',
                    '--sb-muted'       => '#5a6b7d',
                    '--sb-line'        => 'rgba(11,28,44,.10)',
                    '--sb-surface'     => '#f7f9fb',
                    '--sb-surface-2'   => '#edf2f7',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '10px',
                    '--sb-radius-lg'   => '14px',
                    '--sb-btn-radius'  => '6px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                ]),
            ],
            'modern-minimal' => [
                'label' => 'Modern Minimal',
                'blurb' => 'Crisp black + warm off-white. Editorial, sharp.',
                'tokens' => array_merge($editorial, [
                    '--sb-accent'      => '#111827',
                    '--sb-accent-2'    => '#000000',
                    '--sb-ink'         => '#0a0a0a',
                    '--sb-ink-2'       => '#1f2937',
                    '--sb-muted'       => '#6b7280',
                    '--sb-line'        => 'rgba(0,0,0,.08)',
                    '--sb-surface'     => '#f7f4ee',
                    '--sb-surface-2'   => '#efeae0',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '8px',
                    '--sb-radius-lg'   => '12px',
                    '--sb-btn-radius'  => '6px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                ]),
            ],
            'warm-service' => [
                'label' => 'Warm Service',
                'blurb' => 'Terracotta + cream. Friendly, hospitality-leaning.',
                'tokens' => array_merge($editorial, [
                    '--sb-accent'      => '#d3582a',
                    '--sb-accent-2'    => '#a8431e',
                    '--sb-ink'         => '#2a1a12',
                    '--sb-ink-2'       => '#3d2a20',
                    '--sb-muted'       => '#7a665c',
                    '--sb-line'        => 'rgba(42,26,18,.10)',
                    '--sb-surface'     => '#faf3ec',
                    '--sb-surface-2'   => '#f3e6d4',
                    '--sb-page'        => '#fffbf6',
                    '--sb-radius'      => '14px',
                    '--sb-radius-lg'   => '20px',
                    '--sb-btn-radius'  => '999px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '-apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, sans-serif',
                ]),
            ],
            'editorial-bold' => [
                'label' => 'Editorial Bold',
                'blurb' => 'Deep green + acid yellow accent. Confident, magazine-like.',
                'tokens' => array_merge($bold, [
                    '--sb-accent'      => '#cdfa45',
                    '--sb-accent-2'    => '#b6e02e',
                    '--sb-ink'         => '#0d3024',
                    '--sb-ink-2'       => '#164232',
                    '--sb-muted'       => '#5e7468',
                    '--sb-line'        => 'rgba(13,48,36,.10)',
                    '--sb-surface'     => '#f4f6ee',
                    '--sb-surface-2'   => '#e6ead6',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '4px',
                    '--sb-radius-lg'   => '6px',
                    '--sb-btn-radius'  => '4px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '-apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, sans-serif',
                ]),
            ],
        ];
    }

    /** Optional Google-Fonts URL for the active theme (or empty). */
    public static function fontLink(string $slug): string {
        $t = self::get($slug);
        return $t['fontLink'] ?? '';
    }

    public static function get(string $slug): ?array {
        return self::all()[$slug] ?? null;
    }

    /** CSS variable block ":root { --sb-*: ...; }" for the given theme. */
    /**
     * The selector every SBK block root matches.
     *
     * A union rather than a single class because the block roots are not
     * uniform: seven carry `sb` (sb-hero carries both `sb-hero` and `sb`), while
     * sb-cta-band emits `sb-ctaband` and sb-page-hero emits `sb-page-hero`.
     *
     * Normalising those two to carry `.sb` was considered and rejected: it is
     * not appearance-neutral. Five rule groups in sb.css key on `.sb` — root
     * padding, root colour/font, `.sb h1–h4`, `.sb h2` metrics and `.sb *` —
     * and both blocks contain headings, so adding the class restyles them. The
     * document goldens could not have caught it either: they compare source
     * bytes, and whether a selector MATCHES is a browser-side computation they
     * never run.
     *
     * A hardcoded list is the hazard that bit sanitizeNested() and
     * layoutHasBlock(). The difference here is that this one is guarded:
     * SbkScopeCoverageTest enumerates every block template's root classes and
     * fails if any is not covered, so a new block with a new root breaks CI
     * instead of silently missing its tokens.
     */
    public const SLATE_SCOPE = '.sb, .sb-ctaband, .sb-page-hero';

    /**
     * The theme's values, expressed as --slate-* tokens, scoped to SBK blocks.
     *
     * Phase E 2c. Additive: nothing reads these until sb.css's consumers are
     * renamed, so emitting them changes no rendered pixel.
     *
     * SCOPED, not :root, and that is the whole point. The two vocabularies coexist
     * on one page today — sb.css exists partly to beat `.cb-public h1,h2,h3
     * { color: <the content-builder ink token> }` — so the winner is decided by
     * specificity. Feeding SBK's values at :root would repaint every non-SBK
     * element on any page containing an SBK block. Scoping reproduces the
     * current per-element winner through ordinary custom-property inheritance:
     * descendants of an SBK block resolve the SBK value, everything else keeps
     * resolving the :root content-builder value. It also no longer depends on
     * which stylesheet is inlined first, which the !important dance in sb.css
     * currently does.
     *
     * All twenty-two mapped tokens are emitted. --sb-accent-2 and --sb-ink-2
     * joined once accent-secondary / text-secondary were declared; without a
     * scoped home they would have had nowhere to resolve when the sb.css batch
     * renamed their consumers.
     */
    public static function scopedSlateCss(string $slug): string {
        $theme = self::get($slug) ?? self::get('marine-pro');
        $t     = $theme['tokens'] ?? [];

        $map = [
            '--sb-ink'            => 'slate-color-text',
            '--sb-muted'          => 'slate-color-text-muted',
            '--sb-line'           => 'slate-color-border',
            '--sb-page'           => 'slate-color-canvas',
            '--sb-surface'        => 'slate-color-surface',
            '--sb-surface-2'      => 'slate-color-surface-sunken',
            '--sb-accent'         => 'slate-color-accent',
            '--sb-radius'         => 'slate-radius-md',
            '--sb-radius-lg'      => 'slate-radius-lg',
            '--sb-btn-radius'     => 'slate-radius-control',
            '--sb-font-body'      => 'slate-font-sans',
            '--sb-font-head'      => 'slate-font-heading',
            '--sb-weight-body'    => 'slate-font-weight-body',
            '--sb-weight-heading' => 'slate-font-weight-heading',
            '--sb-weight-display' => 'slate-font-weight-display',
            '--sb-weight-nav'     => 'slate-font-weight-nav',
            '--sb-weight-btn'     => 'slate-font-weight-button',
            '--sb-weight-strong'  => 'slate-font-weight-strong',
            '--sb-accent-2'       => 'slate-color-accent-secondary',
            '--sb-ink-2'          => 'slate-color-text-secondary',
            '--sb-h1-tracking'    => 'slate-tracking-heading',
            '--sb-nav-tracking'   => 'slate-tracking-nav',
        ];

        $rows = [];
        foreach ($map as $sb => $slate) {
            if (isset($t[$sb]) && (string) $t[$sb] !== '') {
                $rows[] = "  --{$slate}: {$t[$sb]};";
            }
        }
        if ($rows === []) return '';

        return self::SLATE_SCOPE . " {\n" . implode("\n", $rows) . "\n}";
    }

    public static function rootCss(string $slug): string {
        $theme = self::get($slug) ?? self::get('marine-pro');
        $rows = [];
        foreach ($theme['tokens'] as $k => $v) {
            $rows[] = "  $k: $v;";
        }
        return ":root {\n" . implode("\n", $rows) . "\n}";
    }

    /** Picker options for select fields: [{v,l}, ...]. */
    public static function pickerOptions(): array {
        $out = [];
        foreach (self::all() as $slug => $def) {
            $out[] = ['v' => $slug, 'l' => $def['label']];
        }
        return $out;
    }
}
