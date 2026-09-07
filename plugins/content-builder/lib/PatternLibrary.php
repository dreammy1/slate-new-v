<?php
/**
 * PatternLibrary — premade, pre-filled SECTIONS the user can drop into a page.
 *
 * A "pattern" is one (or a few) fully-configured blocks with realistic
 * placeholder content + image fields, grouped under a category. Unlike the
 * palette (which adds ONE empty block with bare defaults) and unlike
 * page templates (which only seed a brand-new empty page), a pattern can be
 * inserted into ANY page at any time — the editor appends its blocks to the
 * current layout. The user then just swaps the copy and the background image.
 *
 * Patterns are pure data and are built ENTIRELY from the built-in blocks
 * (hero, icon-grid, image-grid, cta, testimonial, heading, paragraph,
 * columns) — so they render and edit with zero extra block types.
 *
 * Shape:
 *   'key' => [
 *       'label'    => 'Hero — image left',
 *       'category' => 'Hero',
 *       'blocks'   => [ ['type'=>'hero','props'=>[ ... ]], ... ],
 *   ]
 *
 * The editor builds a small schematic thumbnail from the block types alone,
 * so no image assets are needed here.
 */
class PatternLibrary {

    /** @var array<string,array> patterns registered by other plugins */
    protected static array $extra = [];

    /**
     * Other plugins add their own sections by hooking
     * 'content_register_patterns' and calling this:
     *   PatternLibrary::register('pricing', [
     *       'label'=>'Pricing table','category'=>'Pricing',
     *       'blocks'=>[ ['type'=>'icon-grid','props'=>[...]] ],
     *   ]);
     */
    public static function register(string $key, array $def): void {
        $def['label']    = $def['label']    ?? $key;
        $def['category'] = $def['category'] ?? 'Custom';
        $def['blocks']   = $def['blocks']   ?? [];
        self::$extra[$key] = $def;
    }

    public static function all(): array {
        return array_merge(
            self::heroes(),
            self::features(),
            self::showcase(),
            self::social(),
            self::cta(),
            self::content(),
            self::$extra
        );
    }

    public static function get(string $key): ?array {
        return self::all()[$key] ?? null;
    }

    /** Distinct category list in display order. */
    public static function categories(): array {
        $seen = [];
        foreach (self::all() as $p) {
            $seen[$p['category']] = true;
        }
        return array_keys($seen);
    }

    /** Payload for the JS editor (no closures — pure data, JSON-safe). */
    public static function paletteJson(): string {
        $out = [];
        foreach (self::all() as $key => $p) {
            // Real rendered preview for the visual section picker (iframe srcdoc).
            $preview = '';
            if (!empty($p['blocks']) && class_exists('Theme') && class_exists('Renderer')) {
                $preview = Theme::previewDoc(Renderer::render($p['blocks']));
            }
            $out[$key] = [
                'label'    => $p['label'],
                'category' => $p['category'],
                'preview'  => $preview,
                'blocks'   => $p['blocks'],
            ];
        }
        return json_encode($out);
    }

    /* ─────────────────────────────── Hero ─────────────────────────────── */

    protected static function heroes(): array {
        return [
            'hero-split-left' => [
                'label' => 'Hero — image left', 'category' => 'Hero',
                'blocks' => [['type'=>'hero','props'=>[
                    'pad'=>'spacious','layout'=>'split','mediaSide'=>'left','eyebrow'=>'Welcome',
                    'heading'=>'A headline that sells the idea',
                    'subheading'=>'One or two supporting sentences that explain the value and invite the visitor to act.',
                    'btnText'=>'Get started','btnHref'=>'#','btn2Text'=>'Learn more','btn2Href'=>'#',
                    'image'=>'','overlay'=>'medium','height'=>'normal',
                ]]],
            ],
            'hero-split-right' => [
                'label' => 'Hero — image right', 'category' => 'Hero',
                'blocks' => [['type'=>'hero','props'=>[
                    'pad'=>'spacious','layout'=>'split','mediaSide'=>'right','eyebrow'=>'Introducing',
                    'heading'=>'Built for the way you work',
                    'subheading'=>'Describe the single most important benefit here, then back it up with the button below.',
                    'btnText'=>'Start free','btnHref'=>'#','btn2Text'=>'','btn2Href'=>'',
                    'image'=>'','overlay'=>'medium','height'=>'normal',
                ]]],
            ],
            'hero-banner-image' => [
                'label' => 'Hero — full background image', 'category' => 'Hero',
                'blocks' => [['type'=>'hero','props'=>[
                    'pad'=>'spacious','layout'=>'banner','eyebrow'=>'',
                    'heading'=>'Big, bold and unmissable',
                    'subheading'=>'Text sits over a full-bleed background image with a darkening overlay for contrast.',
                    'btnText'=>'Get started','btnHref'=>'#','btn2Text'=>'See how','btn2Href'=>'#',
                    'image'=>'','overlay'=>'medium','height'=>'tall',
                ]]],
            ],
            'hero-banner-dark' => [
                'label' => 'Hero — dark overlay banner', 'category' => 'Hero',
                'blocks' => [['type'=>'hero','props'=>[
                    'pad'=>'spacious','layout'=>'banner','eyebrow'=>'New',
                    'heading'=>'Make a strong first impression',
                    'subheading'=>'A heavier overlay keeps white text crisp over busy or bright photography.',
                    'btnText'=>'Join now','btnHref'=>'#','btn2Text'=>'','btn2Href'=>'',
                    'image'=>'','overlay'=>'dark','height'=>'tall',
                ]]],
            ],
            'hero-centered' => [
                'label' => 'Hero — centered, no image', 'category' => 'Hero',
                'blocks' => [['type'=>'hero','props'=>[
                    'pad'=>'spacious','layout'=>'banner','eyebrow'=>'Welcome',
                    'heading'=>'A clean, centered headline',
                    'subheading'=>'No image needed — the section uses your theme background and centers everything.',
                    'btnText'=>'Get started','btnHref'=>'#','btn2Text'=>'Contact us','btn2Href'=>'#',
                    'image'=>'','overlay'=>'medium','height'=>'normal',
                ]]],
            ],
            'page-header-band' => [
                'label' => 'Page header band (slim)', 'category' => 'Hero',
                'blocks' => [['type'=>'hero','props'=>[
                    'pad'=>'normal','layout'=>'banner','eyebrow'=>'',
                    'heading'=>'Page title',
                    'subheading'=>'A short slim banner to head an interior page.',
                    'btnText'=>'','btnHref'=>'','btn2Text'=>'','btn2Href'=>'',
                    'image'=>'','overlay'=>'medium','height'=>'short',
                ]]],
            ],
        ];
    }

    /* ───────────────────────────── Features ───────────────────────────── */

    protected static function features(): array {
        return [
            'features-3' => [
                'label' => 'Features — 3 columns', 'category' => 'Features',
                'blocks' => [['type'=>'icon-grid','props'=>[
                    'pad'=>'normal','eyebrow'=>'Why us','heading'=>'Everything you need','bg'=>'white','cols'=>'3',
                    'items'=>[
                        ['icon'=>'bolt','title'=>'Fast','text'=>'Describe the first benefit in a sentence.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'shield','title'=>'Reliable','text'=>'Describe the second benefit in a sentence.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'heart','title'=>'Loved','text'=>'Describe the third benefit in a sentence.','linkText'=>'','linkHref'=>''],
                    ],
                ]]],
            ],
            'features-4' => [
                'label' => 'Features — 4 columns (tint)', 'category' => 'Features',
                'blocks' => [['type'=>'icon-grid','props'=>[
                    'pad'=>'normal','eyebrow'=>'Features','heading'=>'Built to do more','bg'=>'tint','cols'=>'4',
                    'items'=>[
                        ['icon'=>'star','title'=>'Quality','text'=>'Short supporting line.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'bolt','title'=>'Speed','text'=>'Short supporting line.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'shield','title'=>'Secure','text'=>'Short supporting line.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'clock','title'=>'On time','text'=>'Short supporting line.','linkText'=>'','linkHref'=>''],
                    ],
                ]]],
            ],
            'features-dark' => [
                'label' => 'Features — 3 columns (dark)', 'category' => 'Features',
                'blocks' => [['type'=>'icon-grid','props'=>[
                    'pad'=>'spacious','eyebrow'=>'Highlights','heading'=>'Stand out from the rest','bg'=>'dark','cols'=>'3',
                    'items'=>[
                        ['icon'=>'check','title'=>'Proven','text'=>'A bright section that pops on the page.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'leaf','title'=>'Sustainable','text'=>'A bright section that pops on the page.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'gift','title'=>'Rewarding','text'=>'A bright section that pops on the page.','linkText'=>'','linkHref'=>''],
                    ],
                ]]],
            ],
        ];
    }

    /* ───────────────────────────── Showcase ───────────────────────────── */

    protected static function showcase(): array {
        return [
            'gallery-3' => [
                'label' => 'Image cards — 3 up', 'category' => 'Showcase',
                'blocks' => [['type'=>'image-grid','props'=>[
                    'pad'=>'normal','eyebrow'=>'','heading'=>'Our work','bg'=>'tint','cols'=>'3',
                    'items'=>[
                        ['image'=>'','title'=>'Project one','text'=>'A short caption for this card.','href'=>''],
                        ['image'=>'','title'=>'Project two','text'=>'A short caption for this card.','href'=>''],
                        ['image'=>'','title'=>'Project three','text'=>'A short caption for this card.','href'=>''],
                    ],
                ]]],
            ],
            'gallery-4' => [
                'label' => 'Image cards — 4 up', 'category' => 'Showcase',
                'blocks' => [['type'=>'image-grid','props'=>[
                    'pad'=>'normal','eyebrow'=>'Gallery','heading'=>'A closer look','bg'=>'white','cols'=>'4',
                    'items'=>[
                        ['image'=>'','title'=>'Item one','text'=>'Caption.','href'=>''],
                        ['image'=>'','title'=>'Item two','text'=>'Caption.','href'=>''],
                        ['image'=>'','title'=>'Item three','text'=>'Caption.','href'=>''],
                        ['image'=>'','title'=>'Item four','text'=>'Caption.','href'=>''],
                    ],
                ]]],
            ],
            'split-feature' => [
                'label' => 'Feature — image beside text', 'category' => 'Showcase',
                'blocks' => [['type'=>'hero','props'=>[
                    'pad'=>'normal','layout'=>'split','mediaSide'=>'right','eyebrow'=>'How it works',
                    'heading'=>'Explain one feature in depth',
                    'subheading'=>'Use this section to walk through a single capability with a supporting image on the side.',
                    'btnText'=>'Learn more','btnHref'=>'#','btn2Text'=>'','btn2Href'=>'',
                    'image'=>'','overlay'=>'medium','height'=>'normal',
                ]]],
            ],
        ];
    }

    /* ─────────────────────────── Social proof ─────────────────────────── */

    protected static function social(): array {
        return [
            'testimonial' => [
                'label' => 'Testimonial quote', 'category' => 'Social proof',
                'blocks' => [['type'=>'testimonial','props'=>[
                    'pad'=>'normal','quote'=>'This is the single best decision we made this year.',
                    'author'=>'Jane Doe','role'=>'CEO, Example Co','avatar'=>'',
                ]]],
            ],
            'stats' => [
                'label' => 'Stat highlights — 3 up', 'category' => 'Social proof',
                'blocks' => [['type'=>'icon-grid','props'=>[
                    'pad'=>'normal','eyebrow'=>'By the numbers','heading'=>'Results that speak','bg'=>'tint2','cols'=>'3',
                    'items'=>[
                        ['icon'=>'star','title'=>'10,000+','text'=>'Happy customers','linkText'=>'','linkHref'=>''],
                        ['icon'=>'bolt','title'=>'99.9%','text'=>'Uptime guarantee','linkText'=>'','linkHref'=>''],
                        ['icon'=>'heart','title'=>'4.9/5','text'=>'Average rating','linkText'=>'','linkHref'=>''],
                    ],
                ]]],
            ],
        ];
    }

    /* ───────────────────────────── Call to action ─────────────────────── */

    protected static function cta(): array {
        return [
            'cta-band' => [
                'label' => 'Call-to-action band', 'category' => 'Call to action',
                'blocks' => [['type'=>'cta','props'=>[
                    'pad'=>'normal','eyebrow'=>'','heading'=>'Ready to get started?',
                    'text'=>'Join thousands who already made the switch.','btnText'=>'Sign up','btnHref'=>'#',
                ]]],
            ],
            'cta-closing' => [
                'label' => 'Closing CTA (eyebrow + band)', 'category' => 'Call to action',
                'blocks' => [['type'=>'cta','props'=>[
                    'pad'=>'spacious','eyebrow'=>'One last thing','heading'=>'Let’s build something together',
                    'text'=>'Tell us what you need and we’ll take it from there.','btnText'=>'Contact us','btnHref'=>'/p/contact',
                ]]],
            ],
        ];
    }

    /* ───────────────────────────── Content ────────────────────────────── */

    protected static function content(): array {
        return [
            'intro' => [
                'label' => 'Heading + intro paragraph', 'category' => 'Content',
                'blocks' => [
                    ['type'=>'heading','props'=>['text'=>'A section heading','level'=>'2']],
                    ['type'=>'paragraph','props'=>['text'=>'Introduce the section with a short paragraph that gives the reader context before the detail that follows.']],
                ],
            ],
            'two-col-text' => [
                'label' => 'Two columns of text', 'category' => 'Content',
                'blocks' => [['type'=>'columns','props'=>['cols'=>[
                    ['blocks'=>[
                        ['type'=>'heading','props'=>['text'=>'First column','level'=>'3']],
                        ['type'=>'paragraph','props'=>['text'=>'Describe one idea here. Columns stack on small screens.']],
                    ]],
                    ['blocks'=>[
                        ['type'=>'heading','props'=>['text'=>'Second column','level'=>'3']],
                        ['type'=>'paragraph','props'=>['text'=>'Describe a related idea here to balance the layout.']],
                    ]],
                ]]]],
            ],
        ];
    }
}
