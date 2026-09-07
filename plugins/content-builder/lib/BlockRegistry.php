<?php
/**
 * BlockRegistry — holds the set of available block types.
 *
 * Built-in blocks ship as PHP templates in lib/blocks/. Other plugins
 * register their own blocks (with a 'render' callback) by hooking
 * 'content_register_blocks':
 *
 *   Hook::addAction('content_register_blocks', function ($registry) {
 *       $registry::register('contact-form', [
 *           'label'  => 'Contact Form',
 *           'group'  => 'Forms',
 *           'fields' => [ ['key'=>'formId','type'=>'select','label'=>'Form',
 *                          'options' => FormsAPI::optionList()] ],
 *           'render' => [FormsAPI::class, 'renderBlock'],
 *           'defaults' => ['formId' => 0],
 *       ]);
 *   });
 *
 * A block definition:
 *   label    string   human label in the palette
 *   group    string   palette grouping (optional, default 'Content')
 *   fields   array    field descriptors for the editor (see builder.js)
 *   defaults array    default props for a new block
 *   tpl      string   absolute path to a PHP template (built-in blocks)
 *   render   callable function(array $props): string (plugin blocks)
 */
class BlockRegistry {

    protected static array $blocks = [];

    public static function register(string $type, array $def): void {
        $def['group']    = $def['group']    ?? 'Content';
        $def['fields']   = $def['fields']   ?? [];
        $def['defaults'] = $def['defaults'] ?? [];
        self::$blocks[$type] = $def;
    }

    public static function registerDefaults(string $blockDir): void {
        $blockDir = rtrim($blockDir, '/');

        self::register('heading', [
            'label'  => 'Heading', 'group' => 'Content',
            'tpl'    => "$blockDir/heading.php",
            'fields' => [
                ['key'=>'text','type'=>'text','label'=>'Text'],
                ['key'=>'level','type'=>'select','label'=>'Level',
                 'options'=>[['v'=>'1','l'=>'H1'],['v'=>'2','l'=>'H2'],['v'=>'3','l'=>'H3'],['v'=>'4','l'=>'H4']]],
            ],
            'defaults' => ['text'=>'Heading', 'level'=>'2'],
        ]);

        self::register('paragraph', [
            'label'  => 'Paragraph', 'group' => 'Content',
            'tpl'    => "$blockDir/paragraph.php",
            'fields' => [['key'=>'text','type'=>'textarea','label'=>'Text']],
            'defaults' => ['text'=>'Write something…'],
        ]);

        self::register('image', [
            'label'  => 'Image', 'group' => 'Content',
            'tpl'    => "$blockDir/image.php",
            'fields' => [
                // `media` is the contracted form (ADR-0013 point 4); `src` is
                // read for existing documents and never newly written.
                ['key'=>'media','type'=>'media','label'=>'Image'],
                ['key'=>'src','type'=>'image','label'=>'Image URL (deprecated)'],
                ['key'=>'alt','type'=>'text','label'=>'Alt text'],
                ['key'=>'width','type'=>'select','label'=>'Width',
                 'options'=>[['v'=>'full','l'=>'Full'],['v'=>'wide','l'=>'Wide'],['v'=>'normal','l'=>'Normal']]],
            ],
            'defaults' => ['src'=>'', 'alt'=>'', 'width'=>'full'],
        ]);

        self::register('button', [
            'label'  => 'Button', 'group' => 'Content',
            'tpl'    => "$blockDir/button.php",
            'fields' => [
                ['key'=>'text','type'=>'text','label'=>'Label'],
                ['key'=>'href','type'=>'text','label'=>'Link URL'],
                ['key'=>'style','type'=>'select','label'=>'Style',
                 'options'=>[['v'=>'primary','l'=>'Primary'],['v'=>'secondary','l'=>'Secondary']]],
            ],
            'defaults' => ['text'=>'Click me', 'href'=>'#', 'style'=>'primary'],
        ]);

        self::register('columns', [
            'label'  => 'Columns / Grid', 'group' => 'Layout',
            'icon'   => 'columns',
            'tpl'    => "$blockDir/columns.php",
            'fields' => [
                ['key' => 'numCols', 'type' => 'select', 'label' => 'Columns',
                 'options' => [['v' => '1', 'l' => '1 Column'], ['v' => '2', 'l' => '2 Columns'], ['v' => '3', 'l' => '3 Columns'], ['v' => '4', 'l' => '4 Columns']]],
                ['key' => 'ratio', 'type' => 'select', 'label' => 'Width Ratio',
                 'options' => [
                     ['v' => '50-50', 'l' => '2 Col: 50 / 50 (Equal)'],
                     ['v' => '33-66', 'l' => '2 Col: 33 / 67 (Left narrow)'],
                     ['v' => '66-33', 'l' => '2 Col: 67 / 33 (Right narrow)'],
                     ['v' => '25-75', 'l' => '2 Col: 25 / 75 (Sidebar left)'],
                     ['v' => '75-25', 'l' => '2 Col: 75 / 25 (Sidebar right)'],
                     ['v' => '33-33-33', 'l' => '3 Col: 33 / 33 / 33 (Equal)'],
                     ['v' => '25-50-25', 'l' => '3 Col: 25 / 50 / 25 (Center wide)'],
                     ['v' => '50-25-25', 'l' => '3 Col: 50 / 25 / 25 (Left wide)'],
                     ['v' => '25-25-50', 'l' => '3 Col: 25 / 25 / 50 (Right wide)'],
                     ['v' => '25-25-25-25', 'l' => '4 Col: 25 / 25 / 25 / 25 (Equal)'],
                 ]],
                ['key' => 'gap', 'type' => 'number', 'label' => 'Column Gap (px)'],
                ['key' => 'align', 'type' => 'select', 'label' => 'Vertical Align',
                 'options' => [['v' => 'stretch', 'l' => 'Stretch'], ['v' => 'start', 'l' => 'Top'], ['v' => 'center', 'l' => 'Middle'], ['v' => 'end', 'l' => 'Bottom']]],
                ['key' => 'minHeight', 'type' => 'number', 'label' => 'Min Height (px)'],
                ['key' => 'stackOnMobile', 'type' => 'toggle', 'label' => 'Stack on mobile']
            ],
            'defaults' => [
                'numCols' => '2',
                'ratio'   => '50-50',
                'gap'     => 24,
                'align'   => 'stretch',
                'minHeight' => 0,
                'stackOnMobile' => true,
                'cols'    => [['blocks' => []], ['blocks' => []]]
            ],
        ]);

        self::register('html', [
            'label'  => 'Raw HTML', 'group' => 'Advanced',
            'tpl'    => "$blockDir/html.php",
            'fields' => [['key'=>'html','type'=>'textarea','label'=>'HTML (trusted roles only)']],
            'defaults' => ['html'=>'<!-- raw html -->'],
            'perm'   => 'content.publish',
        ]);

        self::register('react', [
            'label'  => 'React component', 'group' => 'Advanced',
            'tpl'    => "$blockDir/react.php",
            'fields' => [
                ['key'=>'component','type'=>'text','label'=>'Component name'],
                ['key'=>'componentProps','type'=>'textarea','label'=>'Props (JSON)'],
                ['key'=>'fallback','type'=>'textarea','label'=>'Server fallback (plain text)'],
            ],
            'defaults' => ['component'=>'', 'componentProps'=>[], 'fallback'=>''],
        ]);

        self::register('post-list', [
            'label'  => 'Post List', 'group' => 'Dynamic',
            'tpl'    => "$blockDir/post-list.php",
            'fields' => [
                ['key'=>'postType','type'=>'text','label'=>'Post type','placeholder'=>'post'],
                ['key'=>'taxonomy','type'=>'text','label'=>'Taxonomy (optional)','placeholder'=>'category'],
                ['key'=>'term','type'=>'text','label'=>'Term slug (optional)'],
                ['key'=>'limit','type'=>'text','label'=>'Limit','placeholder'=>'6'],
            ],
            'defaults' => ['postType'=>'post','taxonomy'=>'','term'=>'','limit'=>'6'],
        ]);

        /* ---------- Section blocks (richer, prebuilt layouts) ---------- */

        self::register('hero', [
            'label'  => 'Hero', 'group' => 'Sections',
            'tpl'    => "$blockDir/hero.php",
            'fields' => [
                ['key'=>'pad','type'=>'select','label'=>'Section spacing','options'=>[['v'=>'compact','l'=>'Compact'],['v'=>'normal','l'=>'Normal'],['v'=>'spacious','l'=>'Spacious']]],
                
                ['key'=>'layout','type'=>'select','label'=>'Layout',
                 'options'=>[['v'=>'split','l'=>'Split (image + text)'],['v'=>'banner','l'=>'Banner (text over image)']]],
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow (small label above heading)'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'subheading','type'=>'textarea','label'=>'Subheading'],
                ['key'=>'btnText','type'=>'text','label'=>'Button label'],
                ['key'=>'btnHref','type'=>'text','label'=>'Button link'],
                ['key'=>'btn2Text','type'=>'text','label'=>'Second button label (optional)'],
                ['key'=>'btn2Href','type'=>'text','label'=>'Second button link'],
                ['key'=>'image','type'=>'image','label'=>'Image'],
                ['key'=>'mediaSide','type'=>'select','label'=>'Image side (split only)',
                 'options'=>[['v'=>'left','l'=>'Left'],['v'=>'right','l'=>'Right']]],
                ['key'=>'overlay','type'=>'select','label'=>'Image darkness (banner only)',
                 'options'=>[['v'=>'light','l'=>'Light'],['v'=>'medium','l'=>'Medium'],['v'=>'dark','l'=>'Dark']]],
                ['key'=>'height','type'=>'select','label'=>'Height (banner only)',
                 'options'=>[['v'=>'normal','l'=>'Normal'],['v'=>'tall','l'=>'Tall'],['v'=>'short','l'=>'Short']]],
            ],
            'defaults' => ['pad'=>'normal','layout'=>'split','eyebrow'=>'','heading'=>'Your big headline',
                           'subheading'=>'A short supporting sentence that sells the idea.',
                           'btnText'=>'Get started','btnHref'=>'#','btn2Text'=>'','btn2Href'=>'',
                           'image'=>'','mediaSide'=>'left','overlay'=>'medium','height'=>'normal'],
        ]);

        self::register('icon-grid', [
            'label'  => 'Icon box grid', 'group' => 'Sections',
            'tpl'    => "$blockDir/icon-grid.php",
            'fields' => [
                ['key'=>'pad','type'=>'select','label'=>'Section spacing','options'=>[['v'=>'compact','l'=>'Compact'],['v'=>'normal','l'=>'Normal'],['v'=>'spacious','l'=>'Spacious']]],
                
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Section heading'],
                ['key'=>'bg','type'=>'select','label'=>'Background',
                 'options'=>[['v'=>'white','l'=>'White'],['v'=>'page','l'=>'Page'],['v'=>'tint','l'=>'Tint'],['v'=>'tint2','l'=>'Tint 2'],['v'=>'dark','l'=>'Dark']]],
                ['key'=>'cols','type'=>'select','label'=>'Columns',
                 'options'=>[['v'=>'3','l'=>'Three'],['v'=>'4','l'=>'Four'],['v'=>'2','l'=>'Two']]],
                ['key'=>'items','type'=>'repeater','label'=>'Boxes',
                 'item'=>[
                    ['key'=>'icon','type'=>'text','label'=>'Icon (star, heart, bolt, shield, leaf, gift, check, clock, box, truck)','placeholder'=>'star'],
                    ['key'=>'title','type'=>'text','label'=>'Title'],
                    ['key'=>'text','type'=>'textarea','label'=>'Text'],
                    ['key'=>'linkText','type'=>'text','label'=>'Link label (optional)'],
                    ['key'=>'linkHref','type'=>'text','label'=>'Link URL'],
                 ]],
            ],
            'defaults' => ['pad'=>'normal','eyebrow'=>'','heading'=>'What we offer','bg'=>'white','cols'=>'3','items'=>[
                ['icon'=>'star','title'=>'Quality','text'=>'Describe this feature.','linkText'=>'','linkHref'=>''],
                ['icon'=>'box','title'=>'Reliable','text'=>'Describe this feature.','linkText'=>'','linkHref'=>''],
                ['icon'=>'truck','title'=>'Fast','text'=>'Describe this feature.','linkText'=>'','linkHref'=>''],
            ]],
        ]);

        self::register('image-grid', [
            'label'  => 'Image box grid', 'group' => 'Sections',
            'tpl'    => "$blockDir/image-grid.php",
            'fields' => [
                ['key'=>'pad','type'=>'select','label'=>'Section spacing','options'=>[['v'=>'compact','l'=>'Compact'],['v'=>'normal','l'=>'Normal'],['v'=>'spacious','l'=>'Spacious']]],
                
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Section heading'],
                ['key'=>'bg','type'=>'select','label'=>'Background',
                 'options'=>[['v'=>'tint','l'=>'Tint'],['v'=>'page','l'=>'Page'],['v'=>'white','l'=>'White'],['v'=>'tint2','l'=>'Tint 2'],['v'=>'dark','l'=>'Dark']]],
                ['key'=>'cols','type'=>'select','label'=>'Columns',
                 'options'=>[['v'=>'3','l'=>'Three'],['v'=>'4','l'=>'Four'],['v'=>'2','l'=>'Two']]],
                ['key'=>'items','type'=>'repeater','label'=>'Cards',
                 'item'=>[
                    ['key'=>'image','type'=>'image','label'=>'Image'],
                    ['key'=>'title','type'=>'text','label'=>'Title'],
                    ['key'=>'text','type'=>'textarea','label'=>'Text'],
                    ['key'=>'href','type'=>'text','label'=>'Link (optional)'],
                 ]],
            ],
            'defaults' => ['pad'=>'normal','eyebrow'=>'','heading'=>'Gallery','bg'=>'tint','cols'=>'3','items'=>[
                ['image'=>'','title'=>'Item one','text'=>'Short caption.','href'=>''],
                ['image'=>'','title'=>'Item two','text'=>'Short caption.','href'=>''],
                ['image'=>'','title'=>'Item three','text'=>'Short caption.','href'=>''],
            ]],
        ]);

        self::register('cta', [
            'label'  => 'Call to action', 'group' => 'Sections',
            'tpl'    => "$blockDir/cta.php",
            'fields' => [
                ['key'=>'pad','type'=>'select','label'=>'Section spacing','options'=>[['v'=>'compact','l'=>'Compact'],['v'=>'normal','l'=>'Normal'],['v'=>'spacious','l'=>'Spacious']]],
                
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'text','type'=>'textarea','label'=>'Text'],
                ['key'=>'btnText','type'=>'text','label'=>'Button label'],
                ['key'=>'btnHref','type'=>'text','label'=>'Button link'],
                ['key'=>'btnColor','type'=>'color','label'=>'Button background color'],
                ['key'=>'btnTextColor','type'=>'color','label'=>'Button text color'],
            ],
            'defaults' => ['pad'=>'normal','heading'=>'Ready to start?','text'=>'Join us today.','btnText'=>'Sign up','btnHref'=>'#','btnColor'=>'','btnTextColor'=>''],
        ]);

        self::register('testimonial', [
            'label'  => 'Testimonial', 'group' => 'Sections',
            'tpl'    => "$blockDir/testimonial.php",
            'fields' => [
                ['key'=>'pad','type'=>'select','label'=>'Section spacing','options'=>[['v'=>'compact','l'=>'Compact'],['v'=>'normal','l'=>'Normal'],['v'=>'spacious','l'=>'Spacious']]],
                
                ['key'=>'quote','type'=>'textarea','label'=>'Quote'],
                ['key'=>'author','type'=>'text','label'=>'Author'],
                ['key'=>'role','type'=>'text','label'=>'Role / company'],
                ['key'=>'avatar','type'=>'image','label'=>'Avatar (optional)'],
            ],
            'defaults' => ['pad'=>'normal','quote'=>'This product changed how we work.','author'=>'Jane Doe','role'=>'CEO, Example Co','avatar'=>''],
        ]);

        /* ---------- Restaurant: modern, fully field-driven sections ----------
         * Every text, price, image and list row is an editable field. Colours
         * and fonts come from the site theme tokens, so the whole page can be
         * recoloured from Site → Appearance without touching a block. CSS lives
         * in assets/css/public.css under the .rx-* namespace. */

        $tone2 = fn($a,$b,$la,$lb) => ['key'=>'tone','type'=>'select','label'=>'Section style',
            'options'=>[['v'=>$a,'l'=>$la],['v'=>$b,'l'=>$lb]]];

        self::register('rx-hero', [
            'label'  => 'Restaurant · Hero', 'group' => 'Restaurant',
            'tpl'    => "$blockDir/rx-hero.php",
            'fields' => [
                $tone2('dark','light','Dark','Light'),
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow (small label)'],
                ['key'=>'heading','type'=>'text','label'=>'Heading — line 1'],
                ['key'=>'accentLine','type'=>'text','label'=>'Heading — accent line (coloured)'],
                ['key'=>'headingEnd','type'=>'text','label'=>'Heading — line 3 (optional)'],
                ['key'=>'lead','type'=>'textarea','label'=>'Intro paragraph'],
                ['key'=>'btnText','type'=>'text','label'=>'Primary button label'],
                ['key'=>'btnHref','type'=>'text','label'=>'Primary button link'],
                ['key'=>'btn2Text','type'=>'text','label'=>'Secondary button label'],
                ['key'=>'btn2Href','type'=>'text','label'=>'Secondary button link'],
                ['key'=>'rating','type'=>'text','label'=>'Rating / trust line'],
                ['key'=>'image','type'=>'image','label'=>'Background photo (optional)'],
                ['key'=>'cardBadge','type'=>'text','label'=>'Feature card — badge'],
                ['key'=>'cardTitle','type'=>'text','label'=>'Feature card — dish name'],
                ['key'=>'cardNote','type'=>'text','label'=>'Feature card — note'],
                ['key'=>'cardPrice','type'=>'text','label'=>'Feature card — price'],
            ],
            'defaults' => ['tone'=>'dark','eyebrow'=>'Texas-style smokehouse · Est. 2009',
                'heading'=>'Low & slow.','accentLine'=>'Smoked 14 hours','headingEnd'=>'over post oak.',
                'lead'=>'Brisket with a black-pepper bark, ribs that pull clean, and sides made from scratch every morning.',
                'btnText'=>'View the menu','btnHref'=>'#menu','btn2Text'=>'Find us','btn2Href'=>'#visit',
                'rating'=>'4.9 / 5 · 2,400+ reviews · Voted #1 BBQ in the city','image'=>'',
                'cardBadge'=>'🔥 Pit-fired daily from 6am','cardTitle'=>'The Brisket Plate',
                'cardNote'=>'½ lb brisket · 2 sides · pickles','cardPrice'=>'$24'],
        ]);

        self::register('rx-marquee', [
            'label'  => 'Restaurant · Scrolling strip', 'group' => 'Restaurant',
            'tpl'    => "$blockDir/rx-marquee.php",
            'fields' => [
                $tone2('surface','dark','Tinted','Dark'),
                ['key'=>'items','type'=>'repeater','label'=>'Phrases',
                 'item'=>[['key'=>'text','type'=>'text','label'=>'Phrase']]],
            ],
            'defaults' => ['tone'=>'surface','items'=>[
                ['text'=>'Post Oak Smoked'],['text'=>'House-Made Sauces'],['text'=>'USDA Prime Brisket'],
                ['text'=>'Scratch Sides'],['text'=>'Craft Beer on Tap'],['text'=>'Catering Available'],
            ]],
        ]);

        self::register('rx-story', [
            'label'  => 'Restaurant · Story + stats', 'group' => 'Restaurant',
            'tpl'    => "$blockDir/rx-story.php",
            'fields' => [
                $tone2('light','dark','Light','Dark'),
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'body','type'=>'textarea','label'=>'Body (one or more paragraphs)'],
                ['key'=>'statBig','type'=>'text','label'=>'Stat number (e.g. 14h)'],
                ['key'=>'statLabel','type'=>'text','label'=>'Stat label'],
                ['key'=>'image','type'=>'image','label'=>'Photo (optional — replaces the stat medallion)'],
                ['key'=>'items','type'=>'repeater','label'=>'Highlights',
                 'item'=>[
                    ['key'=>'icon','type'=>'text','label'=>'Icon (emoji)','placeholder'=>'🪵'],
                    ['key'=>'title','type'=>'text','label'=>'Title'],
                    ['key'=>'text','type'=>'text','label'=>'Short text'],
                 ]],
            ],
            'defaults' => ['tone'=>'light','eyebrow'=>'Our story','heading'=>'One pit. One obsession. Zero shortcuts.',
                'body'=>"Chef Gregory built his first offset smoker in a backyard in 2009. Fifteen years later we still run on the same principle: real wood, real time, and meat we'd be proud to serve our own family.\n\nNo gas assist, no liquid smoke, no rushing.",
                'statBig'=>'14h','statLabel'=>'In the pit','image'=>'','items'=>[
                ['icon'=>'🪵','title'=>'Real wood fire','text'=>'Post oak & hickory only'],
                ['icon'=>'🌅','title'=>'Smoked overnight','text'=>'Pits lit at 4am daily'],
                ['icon'=>'🥩','title'=>'Prime cuts','text'=>'USDA Prime, hand-trimmed'],
                ['icon'=>'🍃','title'=>'Scratch kitchen','text'=>'Sides & sauces in-house'],
            ]],
        ]);

        self::register('rx-menu', [
            'label'  => 'Restaurant · Menu grid', 'group' => 'Restaurant',
            'tpl'    => "$blockDir/rx-menu.php",
            'fields' => [
                $tone2('surface','dark','Tinted','Dark'),
                ['key'=>'cols','type'=>'select','label'=>'Columns','options'=>[['v'=>'3','l'=>'Three'],['v'=>'2','l'=>'Two']]],
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'intro','type'=>'text','label'=>'Intro line'],
                ['key'=>'items','type'=>'repeater','label'=>'Dishes',
                 'item'=>[
                    ['key'=>'tag','type'=>'text','label'=>'Tag (e.g. Best seller)'],
                    ['key'=>'name','type'=>'text','label'=>'Dish name'],
                    ['key'=>'price','type'=>'text','label'=>'Price'],
                    ['key'=>'text','type'=>'textarea','label'=>'Description'],
                    ['key'=>'image','type'=>'image','label'=>'Photo (optional)'],
                 ]],
            ],
            'defaults' => ['tone'=>'surface','cols'=>'3','eyebrow'=>'The board','heading'=>'Signature plates',
                'intro'=>"Sold by the pound or as a plate with two scratch-made sides. When it's gone, it's gone.",
                'items'=>[
                ['tag'=>'Best seller','name'=>'Brisket','price'=>'$24','text'=>'Black-pepper bark, smoke ring for days, pencil-thick slices.','image'=>''],
                ['tag'=>'Fan favorite','name'=>'Pork Ribs','price'=>'$22','text'=>'St. Louis cut, glazed and pull-clean tender.','image'=>''],
                ['tag'=>'House','name'=>'Pulled Pork','price'=>'$18','text'=>'14-hour shoulder, hand-pulled, vinegar mop.','image'=>''],
                ['tag'=>'Spicy','name'=>'Hot Links','price'=>'$16','text'=>'Coarse-ground beef & pork sausage with a jalapeño kick.','image'=>''],
                ['tag'=>'Weekends','name'=>'Beef Rib','price'=>'$32','text'=>'The dino rib. One bone, one pound, weekends only.','image'=>''],
                ['tag'=>'Feeds 4','name'=>'The Pitmaster','price'=>'$78','text'=>'Brisket, ribs, links, pork & four sides.','image'=>''],
            ]],
        ]);

        self::register('rx-gallery', [
            'label'  => 'Restaurant · Gallery', 'group' => 'Restaurant',
            'tpl'    => "$blockDir/rx-gallery.php",
            'fields' => [
                $tone2('light','dark','Light','Dark'),
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'items','type'=>'repeater','label'=>'Tiles',
                 'item'=>[
                    ['key'=>'image','type'=>'image','label'=>'Photo'],
                    ['key'=>'label','type'=>'text','label'=>'Caption'],
                    ['key'=>'size','type'=>'select','label'=>'Size',
                     'options'=>[['v'=>'normal','l'=>'Normal'],['v'=>'wide','l'=>'Wide'],['v'=>'tall','l'=>'Tall']]],
                 ]],
            ],
            'defaults' => ['tone'=>'light','eyebrow'=>'From the pit','heading'=>'Smoke, fire & everything in between',
                'items'=>[
                ['image'=>'','label'=>'The offset pit','size'=>'tall'],
                ['image'=>'','label'=>'Fresh-sliced brisket','size'=>'wide'],
                ['image'=>'','label'=>'Bark close-up','size'=>'normal'],
                ['image'=>'','label'=>'Mac & cheese','size'=>'normal'],
                ['image'=>'','label'=>'Rack of ribs','size'=>'wide'],
                ['image'=>'','label'=>'The dining room','size'=>'tall'],
                ['image'=>'','label'=>'House sauces','size'=>'normal'],
            ]],
        ]);

        self::register('rx-reviews', [
            'label'  => 'Restaurant · Reviews', 'group' => 'Restaurant',
            'tpl'    => "$blockDir/rx-reviews.php",
            'fields' => [
                $tone2('surface','dark','Tinted','Dark'),
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'items','type'=>'repeater','label'=>'Reviews',
                 'item'=>[
                    ['key'=>'rating','type'=>'select','label'=>'Stars',
                     'options'=>[['v'=>'5','l'=>'★★★★★'],['v'=>'4','l'=>'★★★★'],['v'=>'3','l'=>'★★★']]],
                    ['key'=>'quote','type'=>'textarea','label'=>'Quote'],
                    ['key'=>'name','type'=>'text','label'=>'Name'],
                    ['key'=>'meta','type'=>'text','label'=>'Sub-label'],
                    ['key'=>'avatar','type'=>'image','label'=>'Avatar (optional)'],
                 ]],
            ],
            'defaults' => ['tone'=>'surface','eyebrow'=>'Word of mouth','heading'=>'People drive in for this',
                'items'=>[
                ['rating'=>'5','quote'=>"The brisket is the best I've had outside of Texas. We waited 40 minutes and would do it again tomorrow.",'name'=>'Marcus T.','meta'=>'Local guide · 312 reviews','avatar'=>''],
                ['rating'=>'5','quote'=>"Those beef ribs are a religious experience. Get there before noon on Saturday or they'll be sold out.",'name'=>'Aisha R.','meta'=>'Verified diner','avatar'=>''],
                ['rating'=>'5','quote'=>'We catered our wedding with Chef Gregory’s and guests are still talking about it a year later.','name'=>'Jordan & Lee','meta'=>'Catering client','avatar'=>''],
            ]],
        ]);

        self::register('rx-visit', [
            'label'  => 'Restaurant · Visit + hours', 'group' => 'Restaurant',
            'tpl'    => "$blockDir/rx-visit.php",
            'fields' => [
                $tone2('surface','dark','Tinted','Dark'),
                ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow'],
                ['key'=>'heading','type'=>'text','label'=>'Heading'],
                ['key'=>'text','type'=>'textarea','label'=>'Text'],
                ['key'=>'btnText','type'=>'text','label'=>'Primary button label'],
                ['key'=>'btnHref','type'=>'text','label'=>'Primary button link'],
                ['key'=>'btn2Text','type'=>'text','label'=>'Secondary button label'],
                ['key'=>'btn2Href','type'=>'text','label'=>'Secondary button link'],
                ['key'=>'address','type'=>'text','label'=>'Address'],
                ['key'=>'phone','type'=>'text','label'=>'Phone'],
                ['key'=>'items','type'=>'repeater','label'=>'Opening hours',
                 'item'=>[
                    ['key'=>'label','type'=>'text','label'=>'Day(s)'],
                    ['key'=>'value','type'=>'text','label'=>'Hours'],
                    ['key'=>'highlight','type'=>'select','label'=>'Highlight as “today”',
                     'options'=>[['v'=>'','l'=>'No'],['v'=>'1','l'=>'Yes']]],
                 ]],
            ],
            'defaults' => ['tone'=>'surface','eyebrow'=>'Come hungry','heading'=>'Find us & book a table',
                'text'=>"Walk-ins always welcome, but tables for 6+ fill fast on weekends. Reserve ahead and we'll have the pickles waiting.",
                'btnText'=>'Reserve a table','btnHref'=>'#','btn2Text'=>'Order pickup','btn2Href'=>'#',
                'address'=>'1842 Smokehouse Lane · Downtown','phone'=>'(555) 014-2090','items'=>[
                ['label'=>'Today · Wednesday','value'=>'11am – 9pm · Open','highlight'=>'1'],
                ['label'=>'Mon – Thu','value'=>'11am – 9pm','highlight'=>''],
                ['label'=>'Fri – Sat','value'=>'11am – 11pm','highlight'=>''],
                ['label'=>'Sunday','value'=>'11am – 8pm','highlight'=>''],
                ['label'=>'Pit lights','value'=>'4:00am daily','highlight'=>''],
            ]],
        ]);

        self::register('spacer', [
            'label'    => 'Spacer',
            'group'    => 'Layout',
            'tpl'      => "$blockDir/spacer.php",
            'fields'   => [
                ['key' => 'height', 'type' => 'number', 'label' => 'Height (px)'],
            ],
            'defaults' => ['height' => 48],
        ]);

        self::register('divider', [
            'label'    => 'Divider',
            'group'    => 'Layout',
            'tpl'      => "$blockDir/divider.php",
            'fields'   => [
                ['key' => 'style', 'type' => 'select', 'label' => 'Line style',
                 'options' => [['v' => 'solid', 'l' => 'Solid'], ['v' => 'dashed', 'l' => 'Dashed'], ['v' => 'dotted', 'l' => 'Dotted']]],
                ['key' => 'color', 'type' => 'color', 'label' => 'Color'],
                ['key' => 'space', 'type' => 'number', 'label' => 'Spacing (px)'],
            ],
            'defaults' => ['style' => 'solid', 'color' => '#e5e7eb', 'space' => 24],
        ]);

        self::register('container', [
            'label'    => 'Flex Container',
            'group'    => 'Layout',
            'icon'     => 'layout',
            'tpl'      => "$blockDir/container.php",
            'fields'   => [
                ['key' => 'direction', 'type' => 'select', 'label' => 'Direction',
                 'options' => [
                     ['v' => 'row', 'l' => 'Row (Horizontal ➔)'],
                     ['v' => 'column', 'l' => 'Column (Vertical ⬇)'],
                     ['v' => 'row-reverse', 'l' => 'Row Reverse (⬅)'],
                     ['v' => 'column-reverse', 'l' => 'Column Reverse (⬆)'],
                 ]],
                ['key' => 'justify', 'type' => 'select', 'label' => 'Justify Content',
                 'options' => [
                     ['v' => 'flex-start', 'l' => 'Start (Left / Top)'],
                     ['v' => 'center', 'l' => 'Center'],
                     ['v' => 'flex-end', 'l' => 'End (Right / Bottom)'],
                     ['v' => 'space-between', 'l' => 'Space Between'],
                     ['v' => 'space-around', 'l' => 'Space Around'],
                     ['v' => 'space-evenly', 'l' => 'Space Evenly'],
                 ]],
                ['key' => 'align', 'type' => 'select', 'label' => 'Align Items',
                 'options' => [
                     ['v' => 'stretch', 'l' => 'Stretch'],
                     ['v' => 'center', 'l' => 'Center'],
                     ['v' => 'flex-start', 'l' => 'Start'],
                     ['v' => 'flex-end', 'l' => 'End'],
                 ]],
                ['key' => 'gap',      'type' => 'number',  'label' => 'Gap (px)'],
                ['key' => 'wrap',     'type' => 'toggle',  'label' => 'Wrap items'],
                ['key' => 'fullwidth','type' => 'toggle',  'label' => 'Full width'],
                ['key' => 'minHeight','type' => 'number',  'label' => 'Min Height (px)'],
                ['key' => 'stackOnMobile', 'type' => 'toggle', 'label' => 'Stack on mobile'],
            ],
            'defaults' => [
                'direction' => 'row',
                'justify'   => 'flex-start',
                'align'     => 'stretch',
                'gap'       => 16,
                'wrap'      => false,
                'fullwidth' => true,
                'stackOnMobile' => true,
                'minHeight' => 0,
                'children'  => [],
            ],
        ]);
    }

    public static function all(): array {
        return self::$blocks;
    }

    public static function get(string $type): ?array {
        return self::$blocks[$type] ?? null;
    }

    /** Palette payload for the JS editor (no callbacks — not serializable). */
    public static function paletteJson(): string {
        $out = [];
        foreach (self::$blocks as $type => $def) {
            $out[$type] = [
                'label'    => $def['label'],
                'group'    => $def['group'],
                'fields'   => $def['fields'],
                'defaults' => $def['defaults'],
                // 'columns' nests a grid of column slots, each with its own block
                // list; 'container' nests a single flat list of child blocks.
                // Falsy (null) means the block has no nested block list at all.
                'nest'     => $type === 'columns' ? 'columns' : ($type === 'container' ? 'children' : null),
            ];
        }
        return json_encode($out);
    }
}
