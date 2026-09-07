<?php
/**
 * Slate — resolve `media:<id>` logical keys to stored media (ADR-0013 point 4).
 *
 * The content engine owns the CONTRACT for media references and deliberately not
 * the mapping: `ContentBuilderAPI::resolveMedia()` asks
 * `content_resolve_media_key` and takes whatever answers. Until now NOTHING
 * answered it outside the test suite, which registers its own mapping. So the
 * keyed form was fully implemented on the read side and completely unusable on
 * a live site: every `media:{key:…}` block resolved to an empty URL, and the
 * image block returned early. Writing keys from the builder before this existed
 * would have shipped "no image" for every picked asset — the failure the key was
 * introduced to prevent, relocated rather than fixed.
 *
 * The key addresses `medialibrary_files.id`, not the path. A path is exactly the
 * kind of thing that moves — a file is re-uploaded, a CDN prefix changes, the
 * install moves under a sub-path — and a reference that moves with it is the
 * URL-in-the-document problem again under a new name. The id does not move.
 *
 * This lives in content-builder rather than in core Media because the filter is
 * the content engine's, and core must not know about it: layering allows the
 * plugin to depend on core, not the reverse.
 */

declare(strict_types=1);

final class ContentMediaKeyResolver
{
    /** Scheme prefix. Namespaced so other owners can answer other schemes. */
    public const SCHEME = 'media:';

    public static function register(): void
    {
        Hook::addFilter('content_resolve_media_key', [self::class, 'resolve'], 10, 2);
    }

    /** The key for a media-library id — the one place the format is written. */
    public static function keyFor(int $mediaId): string
    {
        return self::SCHEME . $mediaId;
    }

    /** The id inside a key, or null if this is not one of ours. */
    public static function idFromKey(string $key): ?int
    {
        if (!str_starts_with($key, self::SCHEME)) {
            return null;
        }
        $raw = substr($key, strlen(self::SCHEME));
        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * Answer the filter for `media:` keys only.
     *
     * Returns $current untouched for any other scheme, so this composes with
     * other resolvers instead of claiming every key. An id that maps to nothing
     * also returns $current: a key that cannot be resolved must stay
     * unresolved, so resolveMedia() reports an empty URL and the block renders
     * nothing. Inventing a URL would put a key in an <img src> and show a broken
     * image on a live page.
     *
     * The stored PATH is returned, not a full URL — resolveMedia() runs it
     * through mediaUrl(), which applies the install base. Returning an absolute
     * URL here would double-apply it on a sub-path install.
     */
    public static function resolve($current, $key)
    {
        $id = self::idFromKey(is_string($key) ? $key : '');
        if ($id === null || !class_exists('Media')) {
            return $current;
        }

        $row  = \Media::get($id);
        $path = is_array($row) ? trim((string) ($row['path'] ?? '')) : '';

        return $path !== '' ? ['url' => $path] : $current;
    }
}
