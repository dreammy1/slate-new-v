<?php
/**
 * Register all sb-* Content Builder blocks.
 *
 * Every block is field-driven (no raw HTML) so users edit content via
 * simple form inputs; the active SBK theme styles the rendered markup.
 */

require_once __DIR__ . '/Icons.php';

class SBKBlocks {

    /** Shared "Style" tab fields for section blocks: heading size/weight + sub
     *  size. Spread into a block's `fields` (and defaults via styleDefaults). */
    public static function sizeFields(): array {
        return [
            ['key'=>'headingSize','type'=>'select','label'=>'Heading size','group'=>'style',
             'options'=>[['v'=>'','l'=>'Default'],['v'=>'s','l'=>'Small'],['v'=>'l','l'=>'Large'],['v'=>'xl','l'=>'Extra large']]],
            ['key'=>'headingWeight','type'=>'select','label'=>'Heading weight','group'=>'style',
             'options'=>[['v'=>'','l'=>'Default'],['v'=>'300','l'=>'Light'],['v'=>'400','l'=>'Regular'],['v'=>'500','l'=>'Medium'],['v'=>'600','l'=>'Semibold'],['v'=>'700','l'=>'Bold'],['v'=>'800','l'=>'Extra bold']]],
            ['key'=>'subSize','type'=>'select','label'=>'Subheading size','group'=>'style',
             'options'=>[['v'=>'','l'=>'Default'],['v'=>'s','l'=>'Small'],['v'=>'l','l'=>'Large']]],
        ];
    }
    public static function styleDefaults(): array {
        return ['headingSize'=>'','headingWeight'=>'','subSize'=>''];
    }
    /** Build the inline CSS-var style="" for a section heading from its props.
     *  $level 'h1' = big sub-page hero headings; 'h2' = section headings. */
    public static function styleAttr(array $p, string $level = 'h2'): string {
        $sizes = $level === 'h1'
            ? ['s'=>'clamp(28px,4.5vw,44px)','l'=>'clamp(40px,6.5vw,76px)','xl'=>'clamp(44px,7.5vw,92px)']
            : ['s'=>'clamp(22px,3vw,32px)','l'=>'clamp(32px,4.6vw,54px)','xl'=>'clamp(36px,5.4vw,64px)'];
        $sub = ['s'=>'15px','l'=>'19px'];
        $sizeVar   = $level === 'h1' ? '--sbk-h1-size'   : '--sbk-h2-size';
        $weightVar = $level === 'h1' ? '--sbk-h1-weight' : '--sbk-h2-weight';
        $sv = '';
        $s = (string)($p['headingSize'] ?? '');   if ($s !== '' && isset($sizes[$s])) $sv .= $sizeVar.':'.$sizes[$s].';';
        $w = preg_replace('/[^0-9]/','',(string)($p['headingWeight'] ?? '')); if ($w !== '') $sv .= $weightVar.':'.$w.';';
        $ss= (string)($p['subSize'] ?? '');        if ($ss !== '' && isset($sub[$ss])) $sv .= '--sbk-sub-size:'.$sub[$ss].';';
        return $sv !== '' ? ' style="'.e($sv).'"' : '';
    }

    /** Call from the plugin's boot(). Handles both boot orders. */
    public static function register(): void {
        $reg = function ($r) {
            self::registerAll($r);
        };
        if (class_exists('BlockRegistry')) {
            $reg('BlockRegistry');
        } else {
            Hook::addAction('content_register_blocks', $reg);
        }
    }

    public static function registerAll(string $registry): void {
        $blockDir = __DIR__ . '/blocks';
        $iconOpts = SBKIcons::options();

        /* ── sb-hero (homepage hero, full viewport) ─────────── */
        $registry::register('sb-hero', [
            'label' => 'SB · Hero (homepage)',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-hero.php",
            'fields' => [
                ['key'=>'align','type'=>'select','label'=>'Text alignment',
                 'options'=>[['v'=>'left','l'=>'Left'],['v'=>'center','l'=>'Center']]],
                ['key'=>'tall','type'=>'select','label'=>'Hero height',
                 'options'=>[['v'=>'1','l'=>'Full viewport'],['v'=>'0','l'=>'Compact']]],
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow chip (optional)'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'accentLine','type'=>'text','label'=>'Accent words (highlighted)'],
                ['key'=>'lede','type'=>'textarea','label'=>'Lede paragraph'],
                ['key'=>'image','type'=>'image','label'=>'Background image'],
                ['key'=>'btnText','type'=>'text','label'=>'Primary button label'],
                ['key'=>'btnHref','type'=>'text','label'=>'Primary button URL'],
                ['key'=>'btn2Text','type'=>'text','label'=>'Secondary button label'],
                ['key'=>'btn2Href','type'=>'text','label'=>'Secondary button URL'],
                ['key'=>'h1Size','type'=>'select','label'=>'Heading size','group'=>'style',
                 'options'=>[['v'=>'','l'=>'Default'],['v'=>'s','l'=>'Small'],['v'=>'l','l'=>'Large'],['v'=>'xl','l'=>'Extra large']]],
                ['key'=>'h1Weight','type'=>'select','label'=>'Heading weight','group'=>'style',
                 'options'=>[['v'=>'','l'=>'Default'],['v'=>'300','l'=>'Light'],['v'=>'400','l'=>'Regular'],['v'=>'500','l'=>'Medium'],['v'=>'600','l'=>'Semibold'],['v'=>'700','l'=>'Bold']]],
                ['key'=>'ledeSize','type'=>'select','label'=>'Lede size','group'=>'style',
                 'options'=>[['v'=>'','l'=>'Default'],['v'=>'s','l'=>'Small'],['v'=>'l','l'=>'Large']]],
                ['key'=>'btnShape','type'=>'select','label'=>'Button shape','group'=>'style',
                 'options'=>[['v'=>'','l'=>'Default'],['v'=>'rounded','l'=>'Rounded'],['v'=>'pill','l'=>'Pill'],['v'=>'square','l'=>'Square']]],
                ['key'=>'btnSize','type'=>'select','label'=>'Button size','group'=>'style',
                 'options'=>[['v'=>'','l'=>'Default'],['v'=>'s','l'=>'Small'],['v'=>'l','l'=>'Large']]],
                ['key'=>'features','type'=>'repeater','label'=>'Feature strip (bottom of hero)',
                 'item'=>[['key'=>'text','type'=>'text','label'=>'Phrase']]],
            ],
            'defaults' => [
                'align'=>'left','tall'=>'1',
                'eyebrow'=>'','heading'=>'Your headline goes',
                'accentLine'=>'here.','lede'=>'A supporting sentence that explains what you do.',
                'image'=>'','btnText'=>'Get started','btnHref'=>'#','btn2Text'=>'','btn2Href'=>'',
                'h1Size'=>'','h1Weight'=>'','ledeSize'=>'','btnShape'=>'','btnSize'=>'',
                'features'=>[],
            ],
        ]);

        /* ── sb-page-hero (sub-pages: breadcrumb + heading) ── */
        $registry::register('sb-page-hero', [
            'label' => 'SB · Page hero (sub-page)',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-page-hero.php",
            'fields' => [
                ['key'=>'crumb1','type'=>'text','label'=>'Breadcrumb (left)','placeholder'=>'Home'],
                ['key'=>'crumb1Url','type'=>'text','label'=>'Breadcrumb (left) URL'],
                ['key'=>'crumb2','type'=>'text','label'=>'Breadcrumb (current page)'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'lede','type'=>'textarea','label'=>'Lede paragraph (optional)'],
                ['key'=>'image','type'=>'image','label'=>'Background image'],
                ['key'=>'btnText','type'=>'text','label'=>'CTA button label (optional)'],
                ['key'=>'btnHref','type'=>'text','label'=>'CTA button URL'],
                ['key'=>'headingSize','type'=>'select','label'=>'Heading size','group'=>'style',
                 'options'=>[['v'=>'','l'=>'Default'],['v'=>'s','l'=>'Small'],['v'=>'l','l'=>'Large'],['v'=>'xl','l'=>'Extra large']]],
                ['key'=>'headingWeight','type'=>'select','label'=>'Heading weight','group'=>'style',
                 'options'=>[['v'=>'','l'=>'Default'],['v'=>'300','l'=>'Light'],['v'=>'400','l'=>'Regular'],['v'=>'500','l'=>'Medium'],['v'=>'600','l'=>'Semibold'],['v'=>'700','l'=>'Bold'],['v'=>'800','l'=>'Extra bold']]],
            ],
            'defaults' => [
                'crumb1'=>'Home','crumb1Url'=>'','crumb2'=>'Page',
                'heading'=>'Page heading','lede'=>'',
                'image'=>'','btnText'=>'','btnHref'=>'',
            ],
        ]);

        /* ── sb-feature-grid ────────────────────────────────── */
        $registry::register('sb-feature-grid', [
            'label' => 'SB · Feature grid',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-feature-grid.php",
            'fields' => [
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'accentLine','type'=>'text','label'=>'Accent words'],
                ['key'=>'sub','type'=>'textarea','label'=>'Subheading'],
                ['key'=>'cols','type'=>'select','label'=>'Columns',
                 'options'=>[['v'=>'2','l'=>'Two'],['v'=>'3','l'=>'Three'],['v'=>'4','l'=>'Four']]],
                ['key'=>'bg','type'=>'select','label'=>'Background',
                 'options'=>[['v'=>'page','l'=>'Page'],['v'=>'surface','l'=>'Tinted'],['v'=>'tint','l'=>'Tinted 2'],['v'=>'dark','l'=>'Dark']]],
                ['key'=>'items','type'=>'repeater','label'=>'Cards',
                 'item'=>[
                    ['key'=>'num','type'=>'text','label'=>'Small label / number (optional)'],
                    ['key'=>'icon','type'=>'select','label'=>'Icon','options'=>$iconOpts],
                    ['key'=>'title','type'=>'text','label'=>'Title'],
                    ['key'=>'text','type'=>'textarea','label'=>'Text'],
                    ['key'=>'linkText','type'=>'text','label'=>'Link label (optional)'],
                    ['key'=>'linkHref','type'=>'text','label'=>'Link URL'],
                 ]],
                ...self::sizeFields(),
            ],
            'defaults' => [
                'eyebrow'=>'What we do','heading'=>'Built to','accentLine'=>'deliver.',
                'sub'=>'A short sentence describing your three or four key services.',
                'cols'=>'3','bg'=>'page','items'=>[
                    ['num'=>'','icon'=>'shield','title'=>'Service one','text'=>'Describe this service in a sentence.','linkText'=>'','linkHref'=>''],
                    ['num'=>'','icon'=>'bolt','title'=>'Service two','text'=>'Describe this service in a sentence.','linkText'=>'','linkHref'=>''],
                    ['num'=>'','icon'=>'check','title'=>'Service three','text'=>'Describe this service in a sentence.','linkText'=>'','linkHref'=>''],
                ],
            ],
        ]);

        /* ── sb-split ───────────────────────────────────────── */
        $registry::register('sb-split', [
            'label' => 'SB · Split (image + text)',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-split.php",
            'fields' => [
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'accentLine','type'=>'text','label'=>'Accent words'],
                ['key'=>'body','type'=>'textarea','label'=>'Body (blank line for paragraph breaks)'],
                ['key'=>'image','type'=>'image','label'=>'Image'],
                ['key'=>'mediaSide','type'=>'select','label'=>'Image side',
                 'options'=>[['v'=>'left','l'=>'Left'],['v'=>'right','l'=>'Right']]],
                ['key'=>'btnText','type'=>'text','label'=>'Button label (optional)'],
                ['key'=>'btnHref','type'=>'text','label'=>'Button URL'],
                ['key'=>'btnStyle','type'=>'select','label'=>'Button style',
                 'options'=>[['v'=>'dark','l'=>'Dark'],['v'=>'primary','l'=>'Primary'],['v'=>'outline','l'=>'Outline']]],
                ['key'=>'bg','type'=>'select','label'=>'Background',
                 'options'=>[['v'=>'page','l'=>'Page'],['v'=>'surface','l'=>'Tinted'],['v'=>'tint','l'=>'Tinted 2'],['v'=>'dark','l'=>'Dark']]],
                ...self::sizeFields(),
            ],
            'defaults' => [
                'eyebrow'=>'About us','heading'=>'Our','accentLine'=>'story.',
                'body'=>"Tell your story in two or three short paragraphs.\n\nFocus on why you do what you do, and what makes you different.",
                'image'=>'','mediaSide'=>'left','btnText'=>'Learn more','btnHref'=>'#','btnStyle'=>'dark','bg'=>'surface',
            ],
        ]);

        /* ── sb-quote-grid ──────────────────────────────────── */
        $registry::register('sb-quote-grid', [
            'label' => 'SB · Testimonials + stats',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-quote-grid.php",
            'fields' => [
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'accentLine','type'=>'text','label'=>'Accent words'],
                ['key'=>'sub','type'=>'textarea','label'=>'Subheading'],
                ['key'=>'cols','type'=>'select','label'=>'Columns',
                 'options'=>[['v'=>'2','l'=>'Two'],['v'=>'3','l'=>'Three']]],
                ['key'=>'bg','type'=>'select','label'=>'Background',
                 'options'=>[['v'=>'page','l'=>'Page'],['v'=>'surface','l'=>'Tinted'],['v'=>'tint','l'=>'Tinted 2']]],
                ['key'=>'stats','type'=>'repeater','label'=>'Stats strip (optional)',
                 'item'=>[
                    ['key'=>'big','type'=>'text','label'=>'Big text / number'],
                    ['key'=>'label','type'=>'text','label'=>'Label below'],
                    ['key'=>'isText','type'=>'select','label'=>'Render as text?',
                     'options'=>[['v'=>'0','l'=>'Number (big)'],['v'=>'1','l'=>'Text (smaller)']]],
                 ]],
                ['key'=>'items','type'=>'repeater','label'=>'Testimonials',
                 'item'=>[
                    ['key'=>'stars','type'=>'select','label'=>'Stars',
                     'options'=>[['v'=>'5','l'=>'★★★★★'],['v'=>'4','l'=>'★★★★'],['v'=>'3','l'=>'★★★']]],
                    ['key'=>'quote','type'=>'textarea','label'=>'Quote'],
                    ['key'=>'name','type'=>'text','label'=>'Name'],
                    ['key'=>'meta','type'=>'text','label'=>'Sub-label (location, vessel, etc.)'],
                 ]],
                ...self::sizeFields(),
            ],
            'defaults' => [
                'eyebrow'=>'Word of mouth','heading'=>'What clients','accentLine'=>'say.','sub'=>'',
                'cols'=>'3','bg'=>'page','stats'=>[
                    ['big'=>'30+','label'=>'Years experience','isText'=>'0'],
                    ['big'=>'4,000+','label'=>'Projects done','isText'=>'0'],
                    ['big'=>'5★','label'=>'Average rating','isText'=>'0'],
                    ['big'=>'100%','label'=>'Recommend','isText'=>'0'],
                ],
                'items'=>[
                    ['stars'=>'5','quote'=>'Add a real client quote here.','name'=>'Client Name','meta'=>'Project / company'],
                    ['stars'=>'5','quote'=>'Add a real client quote here.','name'=>'Client Name','meta'=>'Project / company'],
                    ['stars'=>'5','quote'=>'Add a real client quote here.','name'=>'Client Name','meta'=>'Project / company'],
                ],
            ],
        ]);

        /* ── sb-cta-band ────────────────────────────────────── */
        $registry::register('sb-cta-band', [
            'label' => 'SB · CTA band (dark)',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-cta-band.php",
            'fields' => [
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'text','type'=>'textarea','label'=>'Sub-text'],
                ['key'=>'btnText','type'=>'text','label'=>'Button label'],
                ['key'=>'btnHref','type'=>'text','label'=>'Button URL'],
                ['key'=>'btnColor','type'=>'color','label'=>'Button background color'],
                ['key'=>'btnTextColor','type'=>'color','label'=>'Button text color'],
            ],
            'defaults' => [
                'heading'=>'Ready to get started?',
                'text'=>'Tell visitors what to do next, in one short sentence.',
                'btnText'=>'Get started','btnHref'=>'#','btnColor'=>'','btnTextColor'=>'',
            ],
        ]);

        /* ── sb-contact-grid ────────────────────────────────── */
        $registry::register('sb-contact-grid', [
            'label' => 'SB · Contact grid',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-contact-grid.php",
            'fields' => [
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'accentLine','type'=>'text','label'=>'Accent words'],
                ['key'=>'bg','type'=>'select','label'=>'Background',
                 'options'=>[['v'=>'page','l'=>'Page'],['v'=>'surface','l'=>'Tinted'],['v'=>'tint','l'=>'Tinted 2'],['v'=>'dark','l'=>'Dark']]],
                ['key'=>'items','type'=>'repeater','label'=>'Contact cards',
                 'item'=>[
                    ['key'=>'icon','type'=>'select','label'=>'Icon','options'=>$iconOpts],
                    ['key'=>'label','type'=>'text','label'=>'Label (e.g. Phone)'],
                    ['key'=>'value','type'=>'textarea','label'=>'Value (supports line breaks)'],
                    ['key'=>'href','type'=>'text','label'=>'Link URL (tel: / mailto: / etc.)'],
                 ]],
                ...self::sizeFields(),
            ],
            'defaults' => [
                'eyebrow'=>'How to reach us','heading'=>'Three ways to','accentLine'=>'get in touch.',
                'bg'=>'page','items'=>[
                    ['icon'=>'phone','label'=>'Phone','value'=>'(555) 123-4567','href'=>'tel:5551234567'],
                    ['icon'=>'mail','label'=>'Email','value'=>'hello@example.com','href'=>'mailto:hello@example.com'],
                    ['icon'=>'pin','label'=>'Address','value'=>"123 Main St.\nCity, ST 12345",'href'=>''],
                ],
            ],
        ]);

        /* Form picker options (empty if Forms plugin isn't active). */
        $formOpts = class_exists('FormsAPI') ? FormsAPI::pickerOptions() : [];

        /* ── sb-survey-tabs (tabbed dual-form embed) ────────── */
        $registry::register('sb-survey-tabs', [
            'label' => 'SB · Survey form tabs',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-survey-tabs.php",
            'fields' => [
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'accentLine','type'=>'text','label'=>'Accent words'],
                ['key'=>'sub','type'=>'textarea','label'=>'Subheading'],
                ['key'=>'bg','type'=>'select','label'=>'Background',
                 'options'=>[['v'=>'page','l'=>'Page'],['v'=>'surface','l'=>'Tinted'],['v'=>'tint','l'=>'Tinted 2'],['v'=>'dark','l'=>'Dark']]],
                ['key'=>'tab1Label','type'=>'text','label'=>'Tab 1 — label'],
                ['key'=>'tab1Desc','type'=>'text','label'=>'Tab 1 — short description'],
                ['key'=>'tab1Icon','type'=>'select','label'=>'Tab 1 — icon','options'=>$iconOpts],
                ['key'=>'tab1Slug','type'=>'select','label'=>'Tab 1 — form','options'=>$formOpts],
                ['key'=>'tab2Label','type'=>'text','label'=>'Tab 2 — label'],
                ['key'=>'tab2Desc','type'=>'text','label'=>'Tab 2 — short description'],
                ['key'=>'tab2Icon','type'=>'select','label'=>'Tab 2 — icon','options'=>$iconOpts],
                ['key'=>'tab2Slug','type'=>'select','label'=>'Tab 2 — form','options'=>$formOpts],
                ['key'=>'minHeight','type'=>'text','label'=>'Initial form height (px)','placeholder'=>'640'],
            ],
            'defaults' => [
                'eyebrow'=>'Vessel type','heading'=>'Two ways','accentLine'=>'in.',
                'sub'=>'Pick the form that matches your boat — both collect the same core info (vessel, location, date, purpose).',
                'bg'=>'surface',
                'tab1Label'=>'Powerboat survey','tab1Desc'=>'Runabouts, cruisers, sportfish, motoryachts and houseboats.','tab1Icon'=>'boat','tab1Slug'=>'powerboat-survey-order',
                'tab2Label'=>'Sailboat survey','tab2Desc'=>'Keelboats, multihulls and bluewater cruisers.','tab2Icon'=>'sail','tab2Slug'=>'sailboat-survey-order',
                'minHeight'=>'640',
            ],
        ]);

        /* ── sb-contact-panel (form + info + service area) ──── */
        $registry::register('sb-contact-panel', [
            'label' => 'SB · Contact panel (form + map)',
            'group' => 'Small Business',
            'tpl'   => "$blockDir/sb-contact-panel.php",
            'fields' => [
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'accentLine','type'=>'text','label'=>'Accent words'],
                ['key'=>'sub','type'=>'textarea','label'=>'Subheading'],
                ['key'=>'formSlug','type'=>'select','label'=>'Contact form','options'=>$formOpts],
                ['key'=>'minHeight','type'=>'text','label'=>'Initial form height (px)','placeholder'=>'560'],
                ['key'=>'phone','type'=>'text','label'=>'Phone'],
                ['key'=>'phoneHref','type'=>'text','label'=>'Phone link (tel:)'],
                ['key'=>'intlPhone','type'=>'text','label'=>'Outside-U.S. phone'],
                ['key'=>'intlPhoneHref','type'=>'text','label'=>'Outside-U.S. link (tel:)'],
                ['key'=>'addrName','type'=>'text','label'=>'Mailing name'],
                ['key'=>'addrLines','type'=>'textarea','label'=>'Mailing address (line breaks)'],
                ['key'=>'serviceHeading','type'=>'text','label'=>'Service-area heading'],
                ['key'=>'serviceNote','type'=>'textarea','label'=>'Service-area note'],
                ['key'=>'mapImage','type'=>'image','label'=>'Service-area map image (optional)'],
            ],
            'defaults' => [
                'eyebrow'=>'Get in touch','heading'=>'Contact','accentLine'=>'us.',
                'sub'=>'Have questions or need to schedule a survey? We’re here to help.',
                'formSlug'=>'contact','minHeight'=>'560',
                'phone'=>'','phoneHref'=>'','intlPhone'=>'','intlPhoneHref'=>'',
                'addrName'=>'','addrLines'=>'',
                'serviceHeading'=>'Our Service Area','serviceNote'=>'','mapImage'=>'',
            ],
        ]);
    }
}
