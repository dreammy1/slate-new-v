<?php
/**
 * Small Business Kit — Public API.
 *
 * Lightweight facade for theme switching and per-page applications.
 * Other plugins / admin UI call SBKitAPI:: never read settings directly.
 */

require_once __DIR__ . '/lib/Themes.php';

class SBKitAPI {

    public const SETTING_KEY = 'small-business-kit.active_theme';

    /**
     * Currently active theme slug (defaults to 'marine-pro').
     *
     * The content engine owns this setting now — see ContentBuilderAPI's "Site
     * theme" section. These two methods stay as a compat shim so existing
     * callers keep working, the same way media-library became a shim when the
     * media library was promoted to core.
     */
    public static function activeTheme(): string {
        if (class_exists('ContentBuilderAPI')) {
            $slug = ContentBuilderAPI::activeTheme();
            if ($slug !== '') return $slug;
        }
        // Engine absent (plugin deactivated): fall back to our own key.
        $val = (string)Database::setting(self::SETTING_KEY);
        return $val !== '' ? $val : 'marine-pro';
    }

    public static function setActiveTheme(string $slug): void {
        if (class_exists('ContentBuilderAPI') && ContentBuilderAPI::setActiveTheme($slug)) {
            return;
        }
        if (SBKThemes::get($slug)) {
            Database::setSetting(self::SETTING_KEY, $slug);
        }
    }

    public static function themes(): array {
        return SBKThemes::all();
    }

    /**
     * Apply a SBK page template to a Content Builder post.
     * Replaces the layout with the template's blocks, sets render mode
     * to 'builder' (so the user can edit field-by-field).
     */
    public static function applyTemplate(string $templateKey, int $postId, ?int $tenantId = null): bool {
        $tpls = SBKTemplates::all();
        $tpl = $tpls[$templateKey] ?? null;
        if (!$tpl) return false;
        $tenantId = $tenantId ?? current_tenant_id();
        $post = ContentBuilderAPI::getPost($postId, $tenantId);
        if (!$post) return false;
        ContentBuilderAPI::savePost([
            'id'     => $postId,
            'type'   => $post['type'],
            'title'  => $post['title'],
            'slug'   => $post['slug'],
            'status' => $post['status'] === 'trash' ? 'draft' : $post['status'],
            'layout' => $tpl['blocks'],
        ], $tenantId);
        ContentBuilderAPI::setMeta($postId, 'cb_render_mode', 'builder');
        ContentBuilderAPI::setMeta($postId, 'cb_width', 'full');
        return true;
    }

    /**
     * HTML to inject into <head> on every Content Builder page.
     *
     * Inlines everything (tokens + sb.css) — no extra HTTP requests, no
     * dependency on /plugins/* being publicly served. Cached per request.
     */
    public static function headInjection(?array $post = null): string {
        $slug = self::activeTheme();
        $tokens = SBKThemes::rootCss($slug);
        $css = self::inlineCss();

        // Self-hosted editorial display font (Jost variable WOFF2),
        // embedded as a base64 data URL so it ALWAYS loads — no separate
        // HTTP request, no dependency on Apache serving /plugins/* assets,
        // no Cloudflare/ad-blocker interference. ~35KB inline. Cached
        // per-request via the static in fontFaceBlock().
        $fontTags = self::fontFaceBlock($slug);

        $meta = self::metaTags($post);

        // Phase E 2c: the same theme values as --slate-*, scoped to SBK block
        // roots. Additive — sb.css still reads --sb-*, so nothing resolves
        // through these yet. Emitted AFTER sbk-tokens and before sbk-css so it
        // sits with the other declarations rather than among the rules.
        $slateScoped = SBKThemes::scopedSlateCss($slug);

        return $meta . $fontTags
             . "\n<style id=\"sbk-tokens\">{$tokens}</style>\n"
             . ($slateScoped !== '' ? "<style id=\"sbk-slate\">{$slateScoped}</style>\n" : '')
             . "<style id=\"sbk-css\">{$css}</style>";
    }

    /**
     * @font-face block for the self-hosted display face.
     *
     * LINKS the WOFF2 by default; inlines it as base64 only as a fallback.
     * It used to always inline, to avoid "a separate HTTP request, a
     * dependency on Apache serving /plugins/* assets, CDN/ad-blocker
     * interference". Those were reasonable worries, but the arithmetic goes
     * the other way:
     *
     *   file on disk        26,576 bytes
     *   inlined as base64   35,464 bytes   (+33%, base64 is 4 bytes per 3)
     *
     * So inlining costs ~8.9KB MORE on a first visit, and then re-sends the
     * whole font on every subsequent page view — where a linked file is
     * served from cache for nothing. Plugin assets here carry
     * `cache-control: public, max-age=604800` and return `cf-cache-status:
     * HIT`, and this exact font URL was verified to serve 200 font/woff2, so
     * the "might not be served" premise does not hold on this install.
     *
     * The cache-invalidation bug that motivated inlining elsewhere — a CDN
     * caching by path and ignoring ?v= — cannot bite here: font files do not
     * change, so there is nothing to invalidate. No version query is used.
     *
     * `crossorigin` on the preload is required even same-origin: fonts are
     * fetched in CORS mode by CSS, and a preload whose mode does not match is
     * ignored, downloading the font twice instead of once.
     *
     * Kill-switch: site setting `small-business-kit.inline_font` = '1' restores
     * the old always-inline behaviour, matching the rollback pattern used by
     * render_engine / document_engine / token_bridge.
     *
     * NAMESPACED, like this plugin's other setting (small-business-kit.
     * active_theme). It shipped as a bare `sbk_inline_font`, which was an
     * inconsistency: content-builder's switches read through getSiteSetting()
     * and are stored as `content-builder.<key>`, so an operator who flips one
     * switch by SQL and copies the pattern for this one writes a row nothing
     * reads. That failure is silent — the UPDATE reports success — and it
     * happens while reaching for a kill-switch, which is the worst possible
     * moment. Renaming now is free: it defaults to off and nothing has set it.
     */
    private static function fontFaceBlock(string $themeSlug = ''): string {
        static $cache = null;
        if ($cache !== null) return $cache;

        $woffPath = __DIR__ . '/assets/fonts/jost-latin.woff2';
        if (!is_file($woffPath)) return ($cache = '');

        $forceInline = false;
        try {
            $forceInline = class_exists('Database')
                && (string) \Database::setting('small-business-kit.inline_font') === '1';
        } catch (\Throwable $e) {
            $forceInline = false;   // settings unavailable → link it
        }

        if (!$forceInline && function_exists('plugin_url')) {
            $url = (string) plugin_url('small-business-kit', 'assets/fonts/jost-latin.woff2');
            if ($url !== '') {
                return $cache =
                      "\n<link rel=\"preload\" href=\"" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                    . "\" as=\"font\" type=\"font/woff2\" crossorigin>"
                    . "\n<style id=\"sbk-fontface\">"
                    . "@font-face{font-family:'Jost';font-style:normal;font-weight:400 700;font-display:swap;"
                    . "src:url(" . $url . ") format('woff2');"
                    . "}</style>";
            }
        }

        $bin = @file_get_contents($woffPath);
        if ($bin === false || $bin === '') return ($cache = '');
        $b64 = base64_encode($bin);
        $dataUrl = "data:font/woff2;base64,{$b64}";

        // Variable WOFF2 (weight 400–700 axis). format('woff2') only — all
        // modern browsers support variable fonts this way. ~35KB inline.
        $cache = "\n<style id=\"sbk-fontface\">"
               . "@font-face{font-family:'Jost';font-style:normal;font-weight:400 700;font-display:swap;"
               . "src:url({$dataUrl}) format('woff2');"
               . "}</style>";
        return $cache;
    }

    /** Meta description + favicon + OG tags + hero preload. */
    private static function metaTags(?array $post): string {
        $desc = '';
        if ($post) {
            $desc = trim((string)($post['excerpt'] ?? ''));
            if ($desc === '') {
                foreach (($post['layout'] ?? []) as $b) {
                    $type = $b['type'] ?? '';
                    if (in_array($type, ['sb-hero','sb-page-hero','paragraph','sb-split'], true)) {
                        $candidate = $b['props']['lede'] ?? $b['props']['body'] ?? $b['props']['text'] ?? '';
                        $candidate = trim((string)$candidate);
                        if ($candidate !== '') { $desc = $candidate; break; }
                    }
                }
            }
        }
        if ($desc === '') {
            $desc = trim((string)ContentBuilderAPI::getSiteSetting('tagline', ''));
        }
        $desc = mb_substr(strip_tags($desc), 0, 160);

        $out = '';

        // ── Favicon (Slate core stores it at brand_favicon_path; fall back
        //    to the site logo so something always renders).
        $favicon = self::brandUrl((string)Database::setting('brand_favicon_path'));
        if ($favicon === '') {
            $favicon = self::brandUrl((string)Database::setting('brand_logo_path'));
        }
        if ($favicon === '') {
            $favicon = ContentBuilderAPI::mediaUrl((string)ContentBuilderAPI::getSiteSetting('logo_url', ''));
        }
        if ($favicon !== '') {
            $type = self::imageMime($favicon);
            $typeAttr = $type ? ' type="' . htmlspecialchars($type, ENT_QUOTES) . '"' : '';
            $out .= "\n<link rel=\"icon\"" . $typeAttr . " href=\"" . htmlspecialchars($favicon, ENT_QUOTES) . "\">";
            $out .= "\n<link rel=\"apple-touch-icon\" href=\"" . htmlspecialchars($favicon, ENT_QUOTES) . "\">";
        }

        // ── Meta description
        if ($desc !== '') {
            $out .= "\n<meta name=\"description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES) . "\">";
            $out .= "\n<meta property=\"og:description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES) . "\">";
            $out .= "\n<meta name=\"twitter:description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES) . "\">";
        }

        // ── OG title + type + site + URL
        $siteName = trim((string)ContentBuilderAPI::getSiteSetting('site_name', ''));
        if ($siteName !== '') {
            $out .= "\n<meta property=\"og:site_name\" content=\"" . htmlspecialchars($siteName, ENT_QUOTES) . "\">";
        }
        if ($post) {
            $title = trim((string)($post['title'] ?? ''));
            if ($title !== '') {
                $out .= "\n<meta property=\"og:title\" content=\"" . htmlspecialchars($title, ENT_QUOTES) . "\">";
                $out .= "\n<meta name=\"twitter:title\" content=\"" . htmlspecialchars($title, ENT_QUOTES) . "\">";
            }
            // Prefer the short URL (/slate/<slug>) over CB's default /p/<slug>.
            $type = (string)($post['type'] ?? 'page');
            $slug = (string)($post['slug'] ?? '');
            $url = ($type === 'page' && $slug !== '')
                ? rtrim(SLATE_URL, '/') . '/' . rawurlencode($slug)
                : ContentBuilderAPI::permalink($post);
            $out .= "\n<meta property=\"og:url\" content=\"" . htmlspecialchars($url, ENT_QUOTES) . "\">";
            $out .= "\n<link rel=\"canonical\" href=\"" . htmlspecialchars($url, ENT_QUOTES) . "\">";
        }
        $out .= "\n<meta property=\"og:type\" content=\"website\">";
        $out .= "\n<meta name=\"twitter:card\" content=\"summary_large_image\">";

        // ── OG image: page hero → explicit SBK share image → Slate's
        //    login/landing image → favicon. Per-page heroes win, but every
        //    page always has *something* for social share previews.
        $heroSrc  = self::findHeroImage($post);
        $shareImg = self::brandUrl((string)Database::setting('small-business-kit.og_image'));
        $loginImg = self::brandUrl((string)Database::setting('brand_login_image_path'));
        $ogImage  = $heroSrc !== ''  ? $heroSrc
                  : ($shareImg !== '' ? $shareImg
                  : ($loginImg !== '' ? $loginImg
                  : ($favicon !== ''  ? $favicon : '')));
        if ($ogImage !== '') {
            // Make sure it's absolute — social crawlers won't follow root-relative.
            $abs = self::absoluteUrl($ogImage);
            $out .= "\n<meta property=\"og:image\" content=\"" . htmlspecialchars($abs, ENT_QUOTES) . "\">";
            $out .= "\n<meta name=\"twitter:image\" content=\"" . htmlspecialchars($abs, ENT_QUOTES) . "\">";
        }

        // ── Preload the hero image (LCP win)
        if ($heroSrc !== '') {
            $out .= "\n<link rel=\"preload\" as=\"image\" href=\"" . htmlspecialchars($heroSrc, ENT_QUOTES) . "\" fetchpriority=\"high\">";
        }

        return $out;
    }

    /** Convert a Slate brand path (often root-relative) to a usable URL. */
    private static function brandUrl(string $path): string {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^(?:https?:)?//#i', $path)) return $path;
        return rtrim(SLATE_URL, '/') . '/' . ltrim($path, '/');
    }

    /** Turn a media URL into an absolute URL (with scheme + host). */
    private static function absoluteUrl(string $url): string {
        if (preg_match('#^(?:https?:)?//#i', $url)) return $url;
        $base = SLATE_URL;
        $host = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);
        if ($url[0] === '/') {
            // Already root-relative — host + path
            return $host . $url;
        }
        return rtrim($base, '/') . '/' . $url;
    }

    private static function imageMime(string $url): string {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        return [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'gif'  => 'image/gif',
        ][$ext] ?? '';
    }

    private static function findHeroImage(?array $post): string {
        if (!$post) return '';
        foreach (($post['layout'] ?? []) as $b) {
            $type = $b['type'] ?? '';
            if (in_array($type, ['sb-hero','sb-page-hero'], true)) {
                $img = (string)($b['props']['image'] ?? '');
                if ($img !== '') return ContentBuilderAPI::mediaUrl($img);
            }
        }
        return '';
    }

    private static function inlineCss(): string {
        $f = __DIR__ . '/assets/css/sb.css';
        return is_file($f) ? (string)file_get_contents($f) : '';
    }
}
