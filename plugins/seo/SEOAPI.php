<?php
/**
 * SEO — Public API.
 *
 * Other plugins/themes can call SEOAPI::renderHeadTags($post) to get the
 * SEO <head> markup, or read/write site-wide defaults.
 */

class SEOAPI {

    /* ---- Site-wide settings ---- */

    public static function getSetting(string $key, $default = '', ?int $tenantId = null) {
        $tenantId = $tenantId ?? current_tenant_id();
        $val = Database::value(
            "SELECT setting_val FROM seo_settings WHERE tenant_id = ? AND setting_key = ?",
            [$tenantId, $key]);
        return $val === null ? $default : $val;
    }

    public static function setSetting(string $key, ?string $val, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();
        Database::query(
            "INSERT INTO seo_settings (tenant_id, setting_key, setting_val)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)",
            [$tenantId, $key, $val]);
    }

    public static function allSettings(?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $rows = Database::rows(
            "SELECT setting_key, setting_val FROM seo_settings WHERE tenant_id = ?",
            [$tenantId]);
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_val'];
        return $out;
    }

    /**
     * Build the SEO <head> markup for a given post.
     * Falls back to the post title and site-wide defaults.
     */
    public static function renderHeadTags(array $post, string $prepend = ''): string {
        $postId = (int)($post['id'] ?? 0);

        $meta = (class_exists('ContentBuilderAPI') && $postId)
            ? ContentBuilderAPI::getAllMeta($postId)
            : [];

        $siteName   = self::getSetting('site_name', '');
        $suffix     = self::getSetting('title_suffix', '');
        $defaultDesc= self::getSetting('default_description', '');

        $title = trim($meta['seo_title'] ?? '') ?: ($post['title'] ?? 'Untitled');
        if ($suffix && stripos($title, $suffix) === false) {
            $title .= ' ' . $suffix;
        }
        $desc  = trim($meta['seo_description'] ?? '') ?: $defaultDesc;
        $canon = trim($meta['seo_canonical'] ?? '');
        $ogimg = trim($meta['seo_og_image'] ?? '');
        $noindex = !empty($meta['seo_noindex']);

        $out = $prepend;
        // Replace any default <title> the resolver prepended.
        $out = preg_replace('#<title>.*?</title>#i', '', $out);

        $out .= '<title>' . e($title) . '</title>' . "\n";
        if ($desc !== '') {
            $out .= '<meta name="description" content="' . e($desc) . '">' . "\n";
        }
        if ($noindex) {
            $out .= '<meta name="robots" content="noindex,nofollow">' . "\n";
        }
        if ($canon !== '') {
            $out .= '<link rel="canonical" href="' . e($canon) . '">' . "\n";
        }

        // Open Graph
        $out .= '<meta property="og:type" content="article">' . "\n";
        $out .= '<meta property="og:title" content="' . e($title) . '">' . "\n";
        if ($desc !== '') {
            $out .= '<meta property="og:description" content="' . e($desc) . '">' . "\n";
        }
        if ($siteName !== '') {
            $out .= '<meta property="og:site_name" content="' . e($siteName) . '">' . "\n";
        }
        if ($ogimg !== '') {
            $out .= '<meta property="og:image" content="' . e($ogimg) . '">' . "\n";
            $out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
        } else {
            $out .= '<meta name="twitter:card" content="summary">' . "\n";
        }

        return $out;
    }
}
