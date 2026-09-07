<?php
/**
 * Shop image migration tool.
 *
 * Scans shop_products.image_url and shop_products.gallery_urls for
 * external HTTP/HTTPS URLs and downloads them into /uploads/shop/products/,
 * rewriting the database to point at the local copy.
 *
 * Why: some CSV imports (e.g. WooCommerce exports from a previous shop)
 * leave image_url as a full URL pointing at the source site. When the
 * source site goes down, or starts returning 403 on hotlinked images,
 * the storefront breaks. Run this once after a CSV import to localize.
 *
 * Usage (from the Slate root):
 *
 *   php plugins/shop/bin/migrate-images.php           # dry-run, no writes
 *   php plugins/shop/bin/migrate-images.php --commit  # actually download + update
 *   php plugins/shop/bin/migrate-images.php --commit --limit=10  # do 10 then stop
 *
 * Idempotent: re-running only processes URLs that are still external.
 * Failures (404, timeout, oversized) are logged and the URL is left
 * alone so the next run can retry.
 *
 * Safe to run while the site is live. Each row is updated in its own
 * transaction; partial runs leave consistent state.
 */

// Locate Slate root — walk up until we find config.php
$root = __DIR__;
while ($root !== '/' && !file_exists($root . '/config.php')) {
    $root = dirname($root);
}
if (!file_exists($root . '/config.php')) {
    fwrite(STDERR, "Could not locate Slate root (no config.php found).\n");
    exit(1);
}
require $root . '/config.php';

// Parse args
$commit = false;
$limit  = PHP_INT_MAX;
$verbose = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--commit') $commit = true;
    elseif ($arg === '--verbose' || $arg === '-v') $verbose = true;
    elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
    elseif ($arg === '-h' || $arg === '--help') {
        echo "Usage: php migrate-images.php [--commit] [--limit=N] [--verbose]\n";
        exit(0);
    }
}

$mode = $commit ? 'COMMIT' : 'DRY-RUN';
echo "Shop image migration tool — $mode mode\n";
echo str_repeat('-', 60) . "\n";

$tid = current_tenant_id();

// Find all products with at least one external URL
$rows = Database::rows(
    "SELECT id, name, image_url, gallery_urls
       FROM shop_products
      WHERE tenant_id = ?
        AND (image_url LIKE 'http://%' OR image_url LIKE 'https://%'
          OR gallery_urls LIKE '%http://%' OR gallery_urls LIKE '%https://%')
      ORDER BY id ASC",
    [$tid]
);

echo "Products with external URLs: " . count($rows) . "\n";
if (!$rows) {
    echo "Nothing to do.\n";
    exit(0);
}

$processed = 0;
$mainDownloaded = 0;
$galleryDownloaded = 0;
$mainFailed = 0;
$galleryFailed = 0;
$errors = [];

// Target dir for downloads (same place upload form writes to)
$targetDir = $root . '/uploads/shop/products';
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0775, true);
}

/**
 * Download a URL into /uploads/shop/products/<random>.<ext> and
 * return the public path ("/uploads/shop/products/abc.jpg") on success
 * or null on failure.
 */
function downloadOne(string $url, string $targetDir, bool &$lastError = null): ?string {
    $lastError = null;

    // SSRF guard: these URLs come from imported CSVs (untrusted), so refuse
    // non-http(s) schemes and hosts that resolve to private/reserved IPs
    // (cloud metadata, internal services). Redirects stay capped at 5 and
    // are restricted to http(s) below.
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
    $host   = (string)(parse_url($url, PHP_URL_HOST) ?: '');
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
        $lastError = 'refused: not a http(s) URL';
        return null;
    }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    if (!$ips) { $lastError = 'refused: host did not resolve'; return null; }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $lastError = 'refused: host resolves to a private/reserved address';
            return null;
        }
    }

    // Cap response size at 10 MB — same as the upload form
    $maxBytes = 10 * 1024 * 1024;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'SlateShop-ImageMigration/1.0',
        // Refuse oversized responses defensively
        CURLOPT_BUFFERSIZE     => 8192,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function($dl, $dlnow, $ul, $ulnow) use ($maxBytes) {
            // Returning non-zero from progress aborts the transfer
            return ($dlnow > $maxBytes) ? 1 : 0;
        },
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        $lastError = "HTTP $code" . ($err ? " ($err)" : '');
        return null;
    }
    if (strlen($body) > $maxBytes) {
        $lastError = 'response too large';
        return null;
    }

    // Determine extension from Content-Type, fall back to URL path,
    // fall back to .bin (which fails the MIME whitelist below).
    $ext = null;
    $ctMime = strtolower(trim(explode(';', $contentType)[0]));
    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (isset($extByMime[$ctMime])) {
        $ext = $extByMime[$ctMime];
    } else {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $urlExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($urlExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $ext = ($urlExt === 'jpeg') ? 'jpg' : $urlExt;
        }
    }
    if ($ext === null) {
        $lastError = "unrecognized content-type ($ctMime)";
        return null;
    }

    // Final MIME-from-bytes check, same as Uploads::handle does
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $sniffedMime = $finfo->buffer($body) ?: 'application/octet-stream';
    if (!in_array($sniffedMime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        $lastError = "fetched content is not an image (sniffed $sniffedMime)";
        return null;
    }

    $newName = bin2hex(random_bytes(12)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $newName;
    if (@file_put_contents($targetPath, $body) === false) {
        $lastError = 'could not write file to disk';
        return null;
    }
    @chmod($targetPath, 0644);
    return '/uploads/shop/products/' . $newName;
}

foreach ($rows as $r) {
    if ($processed >= $limit) {
        echo "Reached --limit=$limit, stopping.\n";
        break;
    }
    $processed++;
    $id   = (int)$r['id'];
    $name = $r['name'];

    echo "\n[$id] " . substr((string)$name, 0, 60) . "\n";

    // --- Main image ---
    $newMain = $r['image_url'];
    if (preg_match('#^https?://#', (string)$r['image_url'])) {
        $url = $r['image_url'];
        if ($verbose) echo "  main: $url\n";
        if ($commit) {
            $localPath = downloadOne($url, $targetDir, $err);
            if ($localPath !== null) {
                $newMain = $localPath;
                $mainDownloaded++;
                echo "  ✓ main → $localPath\n";
            } else {
                $mainFailed++;
                $errors[] = "[$id] main image: $err — $url";
                echo "  ✗ main failed: $err\n";
            }
        } else {
            echo "  (would download main image)\n";
        }
    }

    // --- Gallery images ---
    $galleryRaw = (string)$r['gallery_urls'];
    $gallery = [];
    if ($galleryRaw !== '') {
        $decoded = json_decode($galleryRaw, true);
        if (is_array($decoded)) $gallery = $decoded;
    }

    $newGallery = $gallery;
    foreach ($gallery as $idx => $g) {
        if (!preg_match('#^https?://#', (string)$g)) continue;
        if ($verbose) echo "  gallery[$idx]: $g\n";
        if ($commit) {
            $localPath = downloadOne($g, $targetDir, $err);
            if ($localPath !== null) {
                $newGallery[$idx] = $localPath;
                $galleryDownloaded++;
                echo "  ✓ gallery[$idx] → $localPath\n";
            } else {
                $galleryFailed++;
                $errors[] = "[$id] gallery[$idx]: $err — $g";
                echo "  ✗ gallery[$idx] failed: $err\n";
            }
        } else {
            echo "  (would download gallery image)\n";
        }
    }

    // --- Persist (only in commit mode, and only if anything changed) ---
    if ($commit) {
        $changes = [];
        if ($newMain !== $r['image_url']) $changes['image_url'] = $newMain;

        // Only write gallery_urls if it changed. Compare JSON encodings.
        $newGalleryJson = $newGallery ? json_encode(array_values($newGallery)) : '';
        if ($newGalleryJson !== $galleryRaw) $changes['gallery_urls'] = $newGalleryJson;

        if (!empty($changes)) {
            Database::update('shop_products', $changes, 'id = ? AND tenant_id = ?', [$id, $tid]);
        }
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "Done. Processed: $processed product(s).\n";
if ($commit) {
    echo "  Main images downloaded:     $mainDownloaded\n";
    echo "  Gallery images downloaded:  $galleryDownloaded\n";
    if ($mainFailed || $galleryFailed) {
        echo "  Failed (main):    $mainFailed\n";
        echo "  Failed (gallery): $galleryFailed\n";
        echo "\nErrors:\n";
        foreach ($errors as $e) echo "  - $e\n";
        echo "\nRe-run the migration to retry failed URLs.\n";
    }
} else {
    echo "(dry-run — no changes written. Re-run with --commit to apply.)\n";
}
