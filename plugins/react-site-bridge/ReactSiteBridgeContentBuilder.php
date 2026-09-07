<?php
/**
 * React Site Bridge — Content Builder editorial connector.
 *
 * Core React route documents remain the source of truth for protected page
 * layouts. This connector provides an intentionally narrow, published-only
 * Content Builder feed for future editorial articles and resource items.
 */

class ReactSiteBridgeContentBuilder {
    public const POST_TYPE = 'kaimana-editorial';

    public static function available(): bool {
        return PluginLoader::isActive('content-builder') && class_exists('ContentBuilderAPI');
    }

    public static function register(): void {
        if (!self::available()) return;
        self::ensurePostType();
        Hook::addAction('content_post_saved', [self::class, 'onContentPostSaved'], 10, 1);
    }

    public static function ensurePostType(): void {
        if (!self::available()) return;
        ContentBuilderAPI::registerPostType([
            'slug' => self::POST_TYPE,
            'label' => 'Kaimana Editorial',
            'singular' => 'Editorial item',
            'hierarchical' => false,
            'supports' => ['title', 'editor', 'excerpt'],
        ]);
    }

    public static function getSource(int $siteId): array {
        if (!ReactSiteBridgeAPI::getSite($siteId)) throw new InvalidArgumentException('React site not found for the active tenant.');
        $row = Database::row(
            'SELECT * FROM reactsitebridge_content_sources WHERE tenant_id = ? AND site_id = ? AND source_key = ?',
            [current_tenant_id(), $siteId, 'content-builder']
        );
        return $row ?: [
            'site_id' => $siteId,
            'source_key' => 'content-builder',
            'content_type' => self::POST_TYPE,
            'item_limit' => 12,
            'enabled' => 0,
        ];
    }

    public static function saveSource(int $siteId, bool $enabled, string $contentType, int $itemLimit): void {
        if (!ReactSiteBridgeAPI::getSite($siteId)) throw new InvalidArgumentException('React site not found for the active tenant.');
        if (!self::available()) throw new RuntimeException('Content Builder must be active before configuring an editorial source.');
        self::ensurePostType();
        $contentType = trim($contentType) === self::POST_TYPE ? self::POST_TYPE : self::POST_TYPE;
        $itemLimit = max(1, min(30, $itemLimit));
        Database::query(
            'INSERT INTO reactsitebridge_content_sources (tenant_id, site_id, source_key, content_type, item_limit, enabled, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content_type = VALUES(content_type), item_limit = VALUES(item_limit), enabled = VALUES(enabled), updated_by = VALUES(updated_by)',
            [current_tenant_id(), $siteId, 'content-builder', $contentType, $itemLimit, $enabled ? 1 : 0, (int)Auth::userId()]
        );
    }

    public static function manifestPayload(int $siteId): array {
        $source = self::getSource($siteId);
        $enabled = self::available() && !empty($source['enabled']);
        $items = [];
        if ($enabled) {
            self::ensurePostType();
            $posts = ContentBuilderAPI::listPosts((string)$source['content_type'], [
                'status' => 'published',
                'limit' => (int)$source['item_limit'],
                'orderby' => 'created_at',
            ]);
            foreach ($posts as $post) {
                $items[] = [
                    'id' => (int)$post['id'],
                    'title' => (string)($post['title'] ?? ''),
                    'slug' => (string)($post['slug'] ?? ''),
                    'excerpt' => (string)($post['excerpt'] ?? ''),
                    'published_at' => (string)($post['published_at'] ?? ''),
                    'blocks' => self::safeBlocks($post['layout'] ?? []),
                ];
            }
        }
        return [
            'enabled' => $enabled,
            'source' => 'content-builder',
            'content_type' => (string)$source['content_type'],
            'items' => $items,
        ];
    }

    public static function onContentPostSaved(int $postId): void {
        if (!self::available()) return;
        $post = ContentBuilderAPI::getPost($postId);
        if (!$post || (string)($post['type'] ?? '') !== self::POST_TYPE) return;
        $rows = Database::rows(
            'SELECT site_id FROM reactsitebridge_content_sources WHERE tenant_id = ? AND source_key = ? AND content_type = ? AND enabled = 1',
            [current_tenant_id(), 'content-builder', self::POST_TYPE]
        );
        foreach ($rows as $row) {
            ReactSiteBridgeAPI::publish((int)$row['site_id'], 'Content Builder editorial feed refreshed.');
        }
    }

    private static function safeBlocks($layout): array {
        if (!is_array($layout)) return [];
        $safe = [];
        foreach ($layout as $block) {
            if (!is_array($block) || !isset($block['type']) || !is_array($block['props'] ?? null)) continue;
            $type = (string)$block['type'];
            $props = $block['props'];
            if ($type === 'heading') {
                $safe[] = ['type' => 'heading', 'props' => ['text' => self::text($props['text'] ?? '', 300), 'level' => max(2, min(4, (int)($props['level'] ?? 2)))]];
            } elseif ($type === 'paragraph') {
                $safe[] = ['type' => 'paragraph', 'props' => ['text' => self::text($props['text'] ?? '', 5000)]];
            } elseif ($type === 'image') {
                $src = trim((string)($props['src'] ?? ''));
                if ($src !== '' && preg_match('#^(?:https?:)?//#i', $src)) {
                    $safe[] = ['type' => 'image', 'props' => ['src' => $src, 'alt' => self::text($props['alt'] ?? '', 500), 'width' => in_array(($props['width'] ?? ''), ['full', 'wide', 'normal'], true) ? $props['width'] : 'wide']];
                }
            } elseif ($type === 'button') {
                $href = trim((string)($props['href'] ?? ''));
                if ($href !== '' && (str_starts_with($href, '/') || preg_match('#^(?:https?:|mailto:|tel:)#i', $href))) {
                    $safe[] = ['type' => 'button', 'props' => ['text' => self::text($props['text'] ?? 'Learn more', 120), 'href' => $href]];
                }
            }
        }
        return $safe;
    }

    private static function text($value, int $limit): string {
        return mb_substr(trim((string)$value), 0, $limit);
    }
}
