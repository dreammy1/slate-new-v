<?php
/**
 * SBK Page templates — composable starter kits for small-business sites.
 *
 * Each template is a list of sb-* blocks with sensible defaults. Apply
 * one via SBKitAPI::applyTemplate('sb-home', $postId).
 *
 * These are pure data — no callbacks — so they're trivially extendable.
 */

class SBKTemplates {

    public static function all(): array {
        return [

            /* ────────────────────────────── HOME ────────────────────────────── */
            'sb-home' => [
                'label'    => 'SB · Service business home',
                'category' => 'Small Business',
                'blocks'   => [
                    ['type'=>'sb-hero','props'=>[
                        'align'=>'center',
                        'eyebrow'=>'Your eyebrow chip · Since 2010',
                        'heading'=>'A confident headline you can',
                        'accentLine'=>'stand on.',
                        'lede'=>'One short paragraph explaining who you serve and what you do, in plain language.',
                        'image'=>'','btnText'=>'Get started','btnHref'=>'#',
                        'btn2Text'=>'Learn more','btn2Href'=>'#',
                    ]],
                    ['type'=>'sb-feature-grid','props'=>[
                        'eyebrow'=>'What we do',
                        'heading'=>'Three things we','accentLine'=>'do well.',
                        'sub'=>'A short sub-line explaining the cluster of services.',
                        'cols'=>'3','bg'=>'page','items'=>[
                            ['num'=>'','icon'=>'shield','title'=>'Service one','text'=>'Describe this service.','linkText'=>'Learn more','linkHref'=>'#'],
                            ['num'=>'','icon'=>'bolt','title'=>'Service two','text'=>'Describe this service.','linkText'=>'Learn more','linkHref'=>'#'],
                            ['num'=>'','icon'=>'check','title'=>'Service three','text'=>'Describe this service.','linkText'=>'Learn more','linkHref'=>'#'],
                        ],
                    ]],
                    ['type'=>'sb-feature-grid','props'=>[
                        'eyebrow'=>'Credentials',
                        'heading'=>'Why we are','accentLine'=>'different.',
                        'sub'=>'Six trust signals — accreditations, affiliations, awards.',
                        'cols'=>'3','bg'=>'dark','items'=>[
                            ['icon'=>'star','title'=>'Credential one','text'=>'One sentence each.'],
                            ['icon'=>'check','title'=>'Credential two','text'=>'One sentence each.'],
                            ['icon'=>'shield','title'=>'Credential three','text'=>'One sentence each.'],
                            ['icon'=>'wave','title'=>'Credential four','text'=>'One sentence each.'],
                            ['icon'=>'bolt','title'=>'Credential five','text'=>'One sentence each.'],
                            ['icon'=>'globe','title'=>'Credential six','text'=>'One sentence each.'],
                        ],
                    ]],
                    ['type'=>'sb-split','props'=>[
                        'eyebrow'=>'Our promise',
                        'heading'=>'A short line about',
                        'accentLine'=>'what makes you different.',
                        'body'=>"Two short paragraphs telling the story. Why you started, what you believe.\n\nWhat the customer can expect from working with you.",
                        'image'=>'','mediaSide'=>'right',
                        'btnText'=>'See our services','btnHref'=>'#','btnStyle'=>'dark','bg'=>'surface',
                    ]],
                    ['type'=>'sb-quote-grid','props'=>[
                        'eyebrow'=>'Word of mouth',
                        'heading'=>'In their','accentLine'=>'own words.','sub'=>'',
                        'cols'=>'3','bg'=>'page',
                        'stats'=>[],
                        'items'=>[
                            ['stars'=>'5','quote'=>'Replace with a real quote.','name'=>'Client one','meta'=>'Project'],
                            ['stars'=>'5','quote'=>'Replace with a real quote.','name'=>'Client two','meta'=>'Project'],
                            ['stars'=>'5','quote'=>'Replace with a real quote.','name'=>'Client three','meta'=>'Project'],
                        ],
                    ]],
                    ['type'=>'sb-cta-band','props'=>[
                        'heading'=>'Ready to get started?',
                        'text'=>'Tell visitors what to do next, in one short sentence.',
                        'btnText'=>'Get in touch','btnHref'=>'#',
                    ]],
                ],
            ],

            /* ──────────────────────────── CONTACT ──────────────────────────── */
            'sb-contact' => [
                'label'    => 'SB · Contact',
                'category' => 'Small Business',
                'blocks'   => [
                    ['type'=>'sb-hero','props'=>[
                        'align'=>'center',
                        'eyebrow'=>'Get in touch',
                        'heading'=>'Contact','accentLine'=>'us.',
                        'lede'=>'Call us, write to us, or send a new assignment — we reply within the business day.',
                        'image'=>'','btnText'=>'','btnHref'=>'','btn2Text'=>'','btn2Href'=>'',
                    ]],
                    ['type'=>'sb-contact-grid','props'=>[
                        'eyebrow'=>'How to reach us',
                        'heading'=>'Three ways to','accentLine'=>'get in touch.',
                        'bg'=>'page','items'=>[
                            ['icon'=>'phone','label'=>'Phone','value'=>'(555) 123-4567','href'=>'tel:5551234567'],
                            ['icon'=>'mail','label'=>'Email','value'=>'hello@example.com','href'=>'mailto:hello@example.com'],
                            ['icon'=>'pin','label'=>'Address','value'=>"123 Main St.\nCity, ST 12345",'href'=>''],
                        ],
                    ]],
                    ['type'=>'sb-split','props'=>[
                        'eyebrow'=>'Service area',
                        'heading'=>'Where we','accentLine'=>'work.',
                        'body'=>"Tell visitors where you operate, in plain language.",
                        'image'=>'','mediaSide'=>'right',
                        'btnText'=>'','btnHref'=>'','btnStyle'=>'dark','bg'=>'surface',
                    ]],
                    ['type'=>'sb-cta-band','props'=>[
                        'heading'=>'Prefer to start online?',
                        'text'=>'Use the order form and we will get back to you fast.',
                        'btnText'=>'Start an order','btnHref'=>'#',
                    ]],
                ],
            ],
        ];
    }
}
