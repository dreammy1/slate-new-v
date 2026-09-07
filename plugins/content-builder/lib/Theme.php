<?php
/**
 * Theme — renders the public-facing page shell (header, nav, content, footer).
 *
 * A built-in simple theme so pages look complete out of the box. Everything
 * is driven by site settings (ContentBuilderAPI::getSiteSetting) and the
 * menus assigned to the 'header' / 'footer' locations.
 *
 * To use your own theme later, you can bypass this entirely from
 * public/router.php — but this gives a clean default with zero setup.
 */
class Theme {

    /**
     * Render a complete HTML document.
     *
     * @param string $headTags  contents for <head> (title, meta — from SEO hook)
     * @param string $bodyHtml  the rendered page blocks
     * @param array|null $post   the current post (for context), or null on 404
     */
    public static function renderPage(string $headTags, string $bodyHtml, ?array $post = null): string {
        $siteName   = e(ContentBuilderAPI::getSiteSetting('site_name', 'My Site'));
        $logoUrl    = ContentBuilderAPI::mediaUrl((string)ContentBuilderAPI::getSiteSetting('logo_url', ''));
        $tagline    = ContentBuilderAPI::getSiteSetting('tagline', '');
        $showHeader = ContentBuilderAPI::getSiteSetting('show_header', '1') !== '0';
        $showFooter = ContentBuilderAPI::getSiteSetting('show_footer', '1') !== '0';
        $footerText = ContentBuilderAPI::getSiteSetting('footer_text', '');
        $headerStyle= ContentBuilderAPI::getSiteSetting('header_style', 'simple');
        $footerStyle= ContentBuilderAPI::getSiteSetting('footer_style', 'simple');
        $ctaText    = trim((string)ContentBuilderAPI::getSiteSetting('header_cta_text', ''));
        $ctaHref    = trim((string)ContentBuilderAPI::getSiteSetting('header_cta_href', ''));
        $homeUrl    = rtrim(SLATE_URL, '/') . '/p/home';

        $header = $showHeader ? self::renderHeader($siteName, $logoUrl, $tagline, $homeUrl, $headerStyle, $ctaText, $ctaHref) : '';
        $footer = $showFooter ? self::renderFooter($siteName, $footerText, $footerStyle, $tagline) : '';

        // Page width: per-page meta override, else global default.
        $width = '';
        if (is_array($post) && !empty($post['id'])) {
            $width = (string)ContentBuilderAPI::getMeta((int)$post['id'], 'cb_width', '');
        }
        if ($width === '') $width = (string)ContentBuilderAPI::getSiteSetting('layout_width', 'boxed');
        $width = in_array($width, ['full','boxed','canvas'], true) ? $width : 'boxed';
        $widthClass = 'cb-w-' . $width;

        // Allow plugins to inject just before </body> (analytics, etc.)
        $extraFooter = Hook::applyFilters('content_footer', '', $post);

        // Inline the theme CSS for zero extra requests + max reliability
        // (no dependency on /plugins/*/assets being publicly served).
        $themeCss = self::css();
        $tokenCss = Branding::cssVars();

        ob_start();
        ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $headTags ?>

    <style><?= $tokenCss . "\n" . $themeCss ?></style>
</head>
<body class="cb-public <?= e($widthClass) ?>">
    <?= $header ?>
    <?= $bodyHtml ?>
    <?= $footer ?>
    <?= $extraFooter ?>
</body>
</html>
<?php
        return ob_get_clean();
    }

    /** The theme CSS, read from the bundled file once and inlined. */
    protected static function css(): string {
        static $css = null;
        if ($css !== null) return $css;
        $file = ContentBuilderAPI::pluginDir() . '/assets/css/public.css';
        $css = is_file($file) ? (string)file_get_contents($file) : '';
        return $css;
    }

    /* ───────────────────────── Chrome libraries ─────────────────────────
     * The header & footer "template library": a catalogue of presets the user
     * picks from in Site settings. Each preset is a layout + a CSS variant
     * class (.cb-header-<key> / .cb-footer-<key>) defined in public.css. */

    public static function headerPresets(): array {
        return [
            'simple'   => ['label'=>'Simple',     'desc'=>'Brand left, navigation right.'],
            'split'    => ['label'=>'Split',      'desc'=>'Brand left, nav centered, button right.'],
            'centered' => ['label'=>'Centered',   'desc'=>'Brand and nav stacked, centered.'],
            'minimal'  => ['label'=>'Minimal',    'desc'=>'Small brand, thin border.'],
            'bordered' => ['label'=>'Bordered',   'desc'=>'Uppercase nav, strong divider.'],
            'accent'   => ['label'=>'Accent bar', 'desc'=>'Filled with your brand colour.'],
            'dark'     => ['label'=>'Dark',       'desc'=>'Charcoal bar with light text.'],
            'glass'    => ['label'=>'Glass',      'desc'=>'Sticky, translucent, blurred.'],
        ];
    }

    public static function footerPresets(): array {
        return [
            'simple'  => ['label'=>'Simple',  'desc'=>'Centered nav + copyright.'],
            'minimal' => ['label'=>'Minimal', 'desc'=>'Copyright only.'],
            'columns' => ['label'=>'Columns', 'desc'=>'Brand left, links right.'],
            'big'     => ['label'=>'Big',     'desc'=>'Tall brand block + links + copyright.'],
            'dark'    => ['label'=>'Dark',    'desc'=>'Charcoal footer with light text.'],
        ];
    }

    /** Wrap arbitrary block/chrome HTML in a self-contained document (theme
     * tokens + public CSS inlined) for use as an <iframe srcdoc> live preview. */
    public static function previewDoc(string $inner): string {
        $tok = Branding::cssVars();
        $href = e(rtrim(SLATE_URL, '/') . '/plugins/content-builder/assets/css/public.css');
        return '<!doctype html><html><head><meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1">'
             . '<link rel="stylesheet" href="' . $href . '">'
             . '<style>' . $tok . ' html,body{margin:0}body{background:var(--slate-color-canvas)}</style>'
             . '</head><body class="cb-public">' . $inner . '</body></html>';
    }

    /** Sample-data header/footer for the visual picker (used inside an iframe). */
    public static function previewHeader(string $style): string {
        $menu = ['items'=>[['label'=>'Menu','url'=>'#'],['label'=>'Story','url'=>'#'],['label'=>'Gallery','url'=>'#'],['label'=>'Visit','url'=>'#']]];
        return self::renderHeader('Chef Gregory’s', '', 'Smokehouse', '#', $style, 'Reserve', '#', $menu);
    }
    public static function previewFooter(string $style): string {
        $menu = ['items'=>[['label'=>'Menu','url'=>'#'],['label'=>'Hours','url'=>'#'],['label'=>'Catering','url'=>'#'],['label'=>'Contact','url'=>'#']]];
        return self::renderFooter('Chef Gregory’s', '© 2026 Chef Gregory’s BBQ', $style, 'Real wood, real time — since 2009.', $menu);
    }

    protected static function renderHeader(string $siteName, string $logoUrl, string $tagline, string $homeUrl, string $style = 'simple', string $ctaText = '', string $ctaHref = '', ?array $menuOverride = null): string {
        $menu = $menuOverride ?? ContentBuilderAPI::getMenuByLocation('header');
        $styleClass = 'cb-header-' . preg_replace('/[^a-z]/', '', $style);
        ob_start();
        ?>
        <header class="cb-site-header <?= e($styleClass) ?>">
            <div class="cb-site-header-inner">
                <a class="cb-brand" href="<?= e(slate_safe_url($homeUrl)) ?>">
                    <?php if ($logoUrl !== ''): ?>
                        <img class="cb-brand-logo" src="<?= e(slate_safe_url($logoUrl)) ?>" alt="<?= e($siteName) ?>"
                             onerror="this.style.display='none';this.insertAdjacentHTML('afterend','<span class=\'cb-brand-name\'><?= addslashes($siteName) ?></span>');">
                    <?php else: ?>
                        <span class="cb-brand-name"><?= $siteName ?></span>
                        <?php if ($tagline !== ''): ?>
                            <span class="cb-brand-tagline"><?= e($tagline) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </a>
                <div class="cb-header-end">
                    <?php if ($menu && !empty($menu['items'])): ?>
                        <nav class="cb-nav" aria-label="Primary">
                            <?php foreach ($menu['items'] as $item):
                                $label = (string)($item['label'] ?? '');
                                $url   = (string)($item['url'] ?? '#');
                                if ($label === '') continue; ?>
                                <a class="cb-nav-link" href="<?= e(slate_safe_url($url)) ?>"><?= e($label) ?></a>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                    <?php if ($ctaText !== ''): ?>
                        <a class="cb-btn cb-btn-primary cb-header-cta" href="<?= e(slate_safe_url($ctaHref)) ?>"><?= e($ctaText) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <?php
        return ob_get_clean();
    }

    protected static function renderFooter(string $siteName, string $footerText, string $style = 'simple', string $tagline = '', ?array $menuOverride = null): string {
        $menu = $menuOverride ?? ContentBuilderAPI::getMenuByLocation('footer');
        $styleClass = 'cb-footer-' . preg_replace('/[^a-z]/', '', $style);
        $year = date('Y');
        // $siteName arrives already HTML-escaped from renderPage(); $footerText is raw.
        $copy = $footerText !== '' ? e($footerText) : '&copy; ' . $year . ' ' . $siteName;

        $nav = '';
        if ($menu && !empty($menu['items'])) {
            $nav .= '<nav class="cb-footer-nav" aria-label="Footer">';
            foreach ($menu['items'] as $item) {
                $label = (string)($item['label'] ?? '');
                $url   = (string)($item['url'] ?? '#');
                if ($label === '') continue;
                $nav .= '<a class="cb-footer-link" href="' . e($url) . '">' . e($label) . '</a>';
            }
            $nav .= '</nav>';
        }

        ob_start();
        if ($style === 'big'):
        ?>
        <footer class="cb-site-footer cb-footer-big">
            <div class="cb-site-footer-inner">
                <div class="cb-footer-top">
                    <div class="cb-footer-brandcol">
                        <span class="cb-footer-brandname"><?= $siteName ?></span>
                        <?php if ($tagline !== ''): ?><span class="cb-footer-tagline"><?= e($tagline) ?></span><?php endif; ?>
                    </div>
                    <?= $nav ?>
                </div>
                <p class="cb-footer-copy"><?= $copy ?></p>
            </div>
        </footer>
        <?php elseif ($style === 'columns'): ?>
        <footer class="cb-site-footer cb-footer-columns">
            <div class="cb-site-footer-inner">
                <div class="cb-footer-brandcol">
                    <span class="cb-footer-brandname"><?= $siteName ?></span>
                    <?php if ($tagline !== ''): ?><span class="cb-footer-tagline"><?= e($tagline) ?></span><?php endif; ?>
                </div>
                <div class="cb-footer-linkcol">
                    <?= $nav ?>
                    <p class="cb-footer-copy"><?= $copy ?></p>
                </div>
            </div>
        </footer>
        <?php else: ?>
        <footer class="cb-site-footer <?= e($styleClass) ?>">
            <div class="cb-site-footer-inner">
                <?= $nav ?>
                <p class="cb-footer-copy"><?= $copy ?></p>
            </div>
        </footer>
        <?php endif;
        return ob_get_clean();
    }
}
