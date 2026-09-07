<?php
/**
 * Slate Multi Language Visual Translation — plugin bootstrap.
 *
 * - Harvesting is ON-DEMAND ONLY: text is captured by clicking "Scan site"
 *   in the admin grid (MLT_Crawler → MLT_Harvester), never passively on
 *   real visitor page views. This keeps every request on the live site
 *   free of scan overhead and means the grid only ever fills with content
 *   an admin explicitly asked to pull in.
 * - Still replaces source text with published translations when a
 *   non-default locale is active, via an output buffer on every request
 *   (this is the actual live-translation feature, distinct from scanning).
 * - Registers the admin grid page, and a language-switcher widget that
 *   plugins/themes render via `mlt_switcher_html()` or the `site_footer`
 *   hook (falls back to auto-inject if the current page fires no such hook).
 */

require_once __DIR__ . '/includes/Tokenizer.php';
require_once __DIR__ . '/includes/LangRepo.php';
require_once __DIR__ . '/includes/StringRepo.php';
require_once __DIR__ . '/includes/Harvester.php';
require_once __DIR__ . '/includes/Replacer.php';
require_once __DIR__ . '/includes/Crawler.php';

class MultilangTranslate extends Plugin {

    private static bool $schemaChecked = false;

    public function boot(): void {
        self::ensureSchema();
        $tid = function_exists('current_tenant_id') ? current_tenant_id() : 1;
        MLT_LangRepo::ensureDefault($tid);

        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('i18n_supported_languages', [$this, 'addSupportedLanguages']);

        // Language switcher: explicit hook points if the theme/shell fires them,
        // plus an auto-append fallback via the output buffer itself. CSS/JS
        // are bundled inline with the widget markup in switcherHtml() rather
        // than enqueued separately — see the long comment there for why
        // (short version: not every page template renders queued plugin
        // assets, e.g. customer/login.php doesn't, so relying on
        // enqueueStyle/enqueueScript alone left the widget unstyled and
        // non-interactive on some pages; and enqueuing them ALSO would risk
        // switcher.js loading twice on pages that do support it, which
        // would double-attach its click handler and make the toggle
        // silently no-op).
        Hook::addAction('site_footer', [$this, 'renderSwitcher']);
        Hook::addAction('admin_footer', [$this, 'renderSwitcher']);
        Hook::addAction('customer_footer', [$this, 'renderSwitcher']);

        // The customer portal's shared topbar has its own dedicated slot for
        // page-level controls (customer_portal_bar_actions) — render the
        // switcher inline there instead of letting the floating widget land
        // on top of it. This is Slate's ONE language switcher: individual
        // plugins (Membership used to run its own FR/EN links here) must not
        // add a second one — see addPortalBarSwitcher() below.
        Hook::addFilter('customer_portal_bar_actions', [$this, 'addPortalBarSwitcher']);

        // Skip our own AJAX/export endpoints and asset requests.
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/plugins/multilang-translate/assets/') !== false) return;
        if (!empty($_SERVER['HTTP_X_MLT_SCAN'])) return; // being fetched BY the crawler itself

        ob_start(function (string $html) use ($tid) {
            return $this->obCallback($tid, $html);
        });
    }

    /**
     * Runs once per request, on the full rendered page body.
     *
     * Deliberately does NOT harvest here — harvesting only ever happens via
     * MLT_Crawler::run(), triggered by the admin "Scan site" button. This
     * method's only job on a normal visitor request is to swap in published
     * translations (and keep the switcher visible).
     */
    private function obCallback(int $tid, string $html): string {
        if ($html === '' ) return $html;

        $locale = MLT_I18nBridge::activeLocale($tid);
        $default = MLT_I18nBridge::defaultLocale($tid);
        if ($locale === $default) {
            // Still inject the switcher even if no explicit footer hook fired.
            return $this->autoInjectSwitcher($html, $tid);
        }

        try {
            $html = MLT_Replacer::replace($tid, $html, $locale);
        } catch (\Throwable $e) {
            slate_log('MLT replace failed: ' . $e->getMessage(), 'error');
        }
        return $this->autoInjectSwitcher($html, $tid);
    }

    /** If the page never fired site_footer/admin_footer/customer_footer, append before </body>. */
    private function autoInjectSwitcher(string $html, int $tid): string {
        if (stripos($html, '</body>') === false) return $html;
        $widget = $this->switcherHtml();
        if ($widget === '') return $html;
        return preg_replace('/<\/body>/i', $widget . '</body>', $html, 1);
    }

    public function addAdminNav(array $items): array {
        $items[] = [
            'slug'  => 'mlt-translate',
            'label' => __('translations', 'Translations'),
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'globe',
            'perm'  => 'mlt.view',
            'order' => 700,
            'group' => 'content',
        ];
        return $items;
    }

    public function addSupportedLanguages(array $langs): array {
        $tid = function_exists('current_tenant_id') ? current_tenant_id() : 1;
        foreach (MLT_LangRepo::enabled($tid) as $l) $langs[$l['code']] = $l['name'];
        return $langs;
    }

    /**
     * Front-of-site switcher, called directly (echoes) if a theme ever
     * fires site_footer/admin_footer/customer_footer.
     */
    public function renderSwitcher(): void {
        echo $this->switcherHtml('floating');
    }

    /**
     * Inline switcher for the customer portal's shared topbar
     * (customer_portal_bar_actions filter — see includes/portal_shell.php).
     * Reuses switcherHtml()'s single-render-per-request guard, so whichever
     * of this or the floating widget renders first on a given request wins
     * and the other is a no-op — never both on the same page.
     */
    public function addPortalBarSwitcher(array $actions): array {
        $html = $this->switcherHtml('bar');
        if ($html !== '') $actions[] = $html;
        return $actions;
    }

    /**
     * Returns the switcher widget markup, or '' if disabled / already
     * rendered this request / nothing to switch between.
     *
     * Deliberately returns a string instead of echoing-then-ob_get_clean():
     * this is called from inside obCallback(), which itself runs as the
     * *display handler* of the page's own output buffer. PHP does not allow
     * starting a second output buffer from within another buffer's display
     * handler ("Cannot use output buffering in output buffering display
     * handlers") — that combination fatals on every single request. Building
     * the markup as a plain return value avoids the nested buffer entirely.
     *
     * The CSS and JS are bundled INLINE with the markup, not left to
     * enqueueStyle()/enqueueScript() alone. Reason: those only produce a
     * <link>/<script> tag if the surrounding page template calls
     * PluginLoader::renderQueuedStyles()/renderQueuedScripts() — and not
     * every page does. Confirmed one that doesn't: customer/login.php ships
     * as a fully standalone template with no such call and no site_footer/
     * customer_footer hook either, so it only ever got the raw, unstyled,
     * un-scripted widget markup from the auto-inject fallback below — the
     * exact "design is broken, button doesn't work" symptom. Bundling the
     * CSS/JS with the markup itself means the widget is self-contained and
     * works on literally any page, regardless of what that page's template
     * does or doesn't call. enqueueStyle()/enqueueScript() are still called
     * in boot() too, for the (cacheable, separate-file) benefit on pages
     * that DO support it — loading both is harmless, just slightly
     * redundant, and every selector/function here is written to be safe if
     * it runs twice on the same page.
     */
    public function switcherHtml(string $context = 'floating'): string {
        if (defined('MLT_SWITCHER_RENDERED')) return '';
        if (!$this->setting('switcher_enabled', true)) return '';

        // &portal=1 marks a page Slate itself is framing inside its own
        // customer-portal chrome (e.g. the booking widget iframed by
        // customer/book.php) — that outer page already has a switcher in
        // its topbar, so the framed copy showing its own floating one on
        // top was a double switcher on the same visible screen. This does
        // NOT affect a third-party site (Wix, WordPress, ...) embedding
        // /book?embed=1 on its own — there is no outer Slate chrome there,
        // so the widget's own switcher is the only control available and
        // keeps showing exactly as before.
        if (!empty($_GET['portal'])) return '';

        $tid = function_exists('current_tenant_id') ? current_tenant_id() : 1;
        $langs = MLT_LangRepo::enabled($tid);
        if (count($langs) < 2) return ''; // nothing to switch between

        define('MLT_SWITCHER_RENDERED', true);

        if ($context === 'bar') {
            // Compact inline pair of codes, styled by the portal's own
            // .mapp-lang CSS (assets/css/portal-app.css) — the exact slot
            // Membership's own bespoke switcher used to occupy, so this
            // drops in with no new styling needed.
            return $this->barSwitcherHtml($langs, MLT_I18nBridge::activeLocale($tid));
        }

        $widget = require __DIR__ . '/assets/switcher-render.php';
        if (!is_string($widget) || $widget === '') return '';

        $css = @file_get_contents($this->dir('assets/css/switcher.css'));
        $js = @file_get_contents($this->dir('assets/js/switcher.js'));
        $bundle = '';
        if ($css) $bundle .= '<style id="mlt-switcher-inline-css">' . $css . '</style>';
        $bundle .= $widget;
        if ($js) $bundle .= '<script id="mlt-switcher-inline-js">' . $js . '</script>';
        return $bundle;
    }

    /** Inline switcher markup for a page's own topbar (see switcherHtml('bar')). */
    private function barSwitcherHtml(array $langs, string $active): string {
        $baseUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $qs = $_GET ?? [];
        unset($qs['lang']);
        $links = '';
        foreach ($langs as $l) {
            $qsCopy = $qs;
            $qsCopy['lang'] = $l['code'];
            $links .= '<a href="' . e($baseUrl . '?' . http_build_query($qsCopy)) . '"'
                    . ' class="' . ($l['code'] === $active ? 'on' : '') . '">'
                    . e(strtoupper($l['code'])) . '</a>';
        }
        return '<span class="mapp-lang">' . $links . '</span>';
    }

    /**
     * Split a schema file into executable statements.
     *
     * Comments are stripped BEFORE splitting on `;`, because a semicolon
     * inside a comment is not a statement terminator. install.sql:49 read:
     *
     *     -- multilangtranslate_strings; matched strings are silently skipped.
     *
     * A plain explode(';') cut that in half. The tail — prose, then the next
     * CREATE TABLE — still contained the words "CREATE TABLE", so it passed
     * the filter and was handed to the database as SQL. It logged a 1064
     * syntax error on every request for four days.
     *
     * Only whole-line comments are removed. A `--` mid-line could sit inside a
     * string literal, and mangling data to tidy up a comment would be a worse
     * bug than the one being fixed.
     *
     * Public so it can be tested without a database; there is no other reason.
     *
     * @return string[] trimmed statements, empties dropped
     */
    public static function splitSchema(string $sql): array {
        $sql = preg_replace('/^[ \t]*--[^\n]*$/m', '', $sql) ?? $sql;   // line comments
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;          // block comments
        $out = [];
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') $out[] = $stmt;
        }
        return $out;
    }

    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;

        try {
            $pdo = Database::get();
            $sql = (string) file_get_contents(__DIR__ . '/install.sql');
        } catch (\Throwable $e) {
            slate_log('MLT ensureSchema failed to start: ' . $e->getMessage(), 'error');
            return;
        }

        foreach (self::splitSchema($sql) as $stmt) {
            if (stripos($stmt, 'CREATE TABLE') === false) continue;
            // Per-statement, so one bad statement cannot abandon the rest. The
            // try used to wrap the whole loop: the failure above aborted every
            // remaining statement, and it survived only because the broken one
            // happened to be last in the file. Anything added after it would
            // never have been created.
            try {
                $pdo->exec($stmt);
            } catch (\Throwable $e) {
                slate_log('MLT ensureSchema statement failed: ' . $e->getMessage(), 'error');
            }
        }
    }
}

/**
 * Small bridge so the plugin doesn't hard-depend on core I18n internals
 * beyond currentLocale()/DEFAULT_LANGUAGES, and always resolves against our
 * own enabled-languages table (a visitor can be on a locale core I18n
 * doesn't know about, since we register it via i18n_supported_languages).
 */
class MLT_I18nBridge {
    public static function defaultLocale(int $tid): string {
        $row = Database::row("SELECT code FROM multilangtranslate_languages WHERE tenant_id = ? AND is_default = 1", [$tid]);
        return $row['code'] ?? 'en';
    }

    /**
     * Slate has exactly one current-locale mechanism: core I18n's
     * ?lang= / $_SESSION['slate_lang'] (see src/Services/I18n/I18n.php).
     * This used to keep its own, entirely separate ?mlt_lang=/
     * $_SESSION['mlt_lang'] pair — meaning a visitor could switch MLT's
     * live-translation replacement to French while every __()-based string
     * elsewhere on the same page (Membership's own dashboard content,
     * admin chrome, etc.) stayed on whatever locale core I18n was set to,
     * and vice versa. Delegating here makes MLT and core I18n move
     * together on the one switcher click that changes either.
     */
    public static function activeLocale(int $tid): string {
        $enabled = array_column(MLT_LangRepo::enabled($tid), 'code');
        if (class_exists('I18n')) {
            $loc = I18n::currentLocale();
            if (in_array($loc, $enabled, true)) return $loc;
        }
        return self::defaultLocale($tid);
    }
}
