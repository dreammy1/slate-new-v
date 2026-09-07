<?php
/**
 * React Site Bridge — public PHP contract.
 *
 * Slate plugins and admin pages call this facade instead of reading or writing
 * reactsitebridge_* tables directly. Every operation scopes to the active
 * TenantContext through current_tenant_id().
 */

class ReactSiteBridgeAPI {
    public const SCHEMA_V = '4';
    private static bool $schemaChecked = false;

    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            if (Database::setting('react-site-bridge.schema_v') === self::SCHEMA_V) return;
            $file = __DIR__ . '/install.sql';
            $sql = is_file($file) ? trim((string)file_get_contents($file)) : '';
            if ($sql === '') return;
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
                $statement = trim($statement);
                if ($statement === '') continue;
                Database::query($statement);
            }
            Database::setSetting('react-site-bridge.schema_v', self::SCHEMA_V);
        } catch (Throwable $e) {
            if (function_exists('slate_log')) slate_log('ReactSiteBridge schema failed: ' . $e->getMessage(), 'warning');
        }
    }

    public static function listSites(): array {
        self::ensureSchema();
        return Database::rows(
            'SELECT s.*, r.version AS active_version, r.published_at AS active_published_at
               FROM reactsitebridge_sites s
          LEFT JOIN reactsitebridge_revisions r ON r.id = s.active_revision_id AND r.tenant_id = s.tenant_id
              WHERE s.tenant_id = ? ORDER BY s.updated_at DESC, s.id DESC',
            [current_tenant_id()]
        );
    }

    public static function getSite(int $siteId): ?array {
        self::ensureSchema();
        return Database::row(
            'SELECT * FROM reactsitebridge_sites WHERE id = ? AND tenant_id = ?',
            [$siteId, current_tenant_id()]
        ) ?: null;
    }

    /**
     * Resolve a published site by its public id (CORE-1).
     *
     * The public id appears in the URL of every published site, so it is an
     * identifier, not a secret. It was previously matched without a tenant
     * filter while its two siblings (getSite at :48, and the site-key lookup
     * below) both scoped — so any tenant's host would serve any other
     * tenant's published site, no credential required.
     *
     * Scoping is not a parameter here. A caller that legitimately needs a
     * cross-tenant view must say so somewhere more visible than an argument.
     */
    public static function getSiteByPublicId(string $publicId): ?array {
        self::ensureSchema();
        $publicId = trim($publicId);
        if (!preg_match('/^[a-f0-9]{24,64}$/', $publicId)) {
            return null;
        }
        return Database::row(
            "SELECT * FROM reactsitebridge_sites
              WHERE public_id = ? AND " . slate_tenant_clause() . " AND status = 'published'",
            [$publicId, slate_tenant_id()]
        ) ?: null;
    }

    public static function createSite(string $name, string $siteKey, string $publicOrigin = '', string $previewOrigin = ''): int {
        self::ensureSchema();
        $name = mb_substr(trim($name), 0, 190);
        $siteKey = self::normalizeSiteKey($siteKey);
        if ($name === '' || $siteKey === '') throw new InvalidArgumentException('A site name and lowercase site key are required.');
        return Database::insert('reactsitebridge_sites', [
            'tenant_id'      => current_tenant_id(),
            'site_key'       => $siteKey,
            'public_id'      => self::newPublicId(),
            'name'           => $name,
            'status'         => 'draft',
            'public_origin'  => self::normalizeOrigin($publicOrigin),
            'preview_origin' => self::normalizeOrigin($previewOrigin),
            'created_by'     => (int)Auth::userId(),
        ]);
    }

    public static function saveSite(int $siteId, string $name, string $publicOrigin = '', string $previewOrigin = ''): void {
        $site = self::requireSite($siteId);
        $name = mb_substr(trim($name), 0, 190);
        if ($name === '') throw new InvalidArgumentException('A site name is required.');
        Database::update('reactsitebridge_sites', [
            'name'           => $name,
            'public_origin'  => self::normalizeOrigin($publicOrigin),
            'preview_origin' => self::normalizeOrigin($previewOrigin),
        ], 'id = ? AND tenant_id = ?', [(int)$site['id'], current_tenant_id()]);
    }

    public static function listDocuments(int $siteId): array {
        self::requireSite($siteId);
        $rows = Database::rows(
            'SELECT * FROM reactsitebridge_documents WHERE tenant_id = ? AND site_id = ? ORDER BY route ASC, locale ASC',
            [current_tenant_id(), $siteId]
        );
        foreach ($rows as &$row) $row['document'] = self::decodeJson((string)$row['document_json']);
        return $rows;
    }

    public static function getDocument(int $siteId, int $documentId): ?array {
        self::requireSite($siteId);
        $row = Database::row(
            'SELECT * FROM reactsitebridge_documents WHERE id = ? AND site_id = ? AND tenant_id = ?',
            [$documentId, $siteId, current_tenant_id()]
        );
        if (!$row) return null;
        $row['document'] = self::decodeJson((string)$row['document_json']);
        return $row;
    }

    public static function saveDocument(int $siteId, int $documentId, string $route, string $schemaKey, string $documentJson, string $locale = 'en'): int {
        self::requireSite($siteId);
        $route = self::normalizeRoute($route);
        $locale = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) ? $locale : 'en';
        $schemaKey = mb_substr(trim($schemaKey), 0, 120);
        $document = self::decodeJson($documentJson, true);
        if (!is_array($document)) throw new InvalidArgumentException('Document JSON must decode to an object or array.');
        $encoded = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $row = [
            'tenant_id'    => current_tenant_id(),
            'site_id'      => $siteId,
            'route'        => $route,
            'locale'       => $locale,
            'schema_key'   => $schemaKey,
            'document_json'=> $encoded,
            'updated_by'   => (int)Auth::userId(),
        ];
        if ($documentId > 0) {
            $existing = self::getDocument($siteId, $documentId);
            if (!$existing) throw new InvalidArgumentException('Document not found.');
            Database::update('reactsitebridge_documents', $row, 'id = ? AND tenant_id = ?', [$documentId, current_tenant_id()]);
            return $documentId;
        }
        return Database::insert('reactsitebridge_documents', $row);
    }

    public static function deleteDocument(int $siteId, int $documentId): void {
        self::requireSite($siteId);
        Database::delete('reactsitebridge_documents', 'id = ? AND site_id = ? AND tenant_id = ?', [$documentId, $siteId, current_tenant_id()]);
    }

    public static function listMediaMappings(int $siteId): array {
        self::requireSite($siteId);
        $rows = Database::rows(
            'SELECT * FROM reactsitebridge_media WHERE tenant_id = ? AND site_id = ? ORDER BY logical_key ASC',
            [current_tenant_id(), $siteId]
        );
        foreach ($rows as &$row) $row['media'] = !empty($row['media_id']) && class_exists('Media') ? Media::get((int)$row['media_id']) : null;
        return $rows;
    }

    public static function saveMediaMapping(int $siteId, int $mappingId, string $logicalKey, int $mediaId, string $altText = '', $focalX = null, $focalY = null): int {
        self::requireSite($siteId);
        $logicalKey = self::normalizeLogicalKey($logicalKey);
        if ($logicalKey === '') throw new InvalidArgumentException('A logical media key is required.');
        if (!class_exists('Media')) throw new RuntimeException('Slate Media is unavailable.');
        $keyOwner = Database::row('SELECT id FROM reactsitebridge_media WHERE tenant_id = ? AND site_id = ? AND logical_key = ?', [current_tenant_id(), $siteId, $logicalKey]);
        if ($keyOwner && (int)$keyOwner['id'] !== $mappingId) throw new InvalidArgumentException('This logical media key is already mapped. Use a new key or edit the existing mapping.');
        $media = Media::get($mediaId);
        if (!$media) throw new InvalidArgumentException('Select an image from the current tenant media library.');
        if ((string)($media['kind'] ?? '') !== 'image') throw new InvalidArgumentException('Select an image from the current tenant media library.');
        $existing = $mappingId > 0 ? Database::row('SELECT id, media_id, logical_key FROM reactsitebridge_media WHERE id = ? AND tenant_id = ? AND site_id = ?', [$mappingId, current_tenant_id(), $siteId]) : null;
        if ($mappingId > 0 && !$existing) throw new InvalidArgumentException('Media mapping not found.');
        $row = [
            'tenant_id'   => current_tenant_id(),
            'site_id'     => $siteId,
            'logical_key' => $logicalKey,
            'media_id'    => $mediaId,
            'alt_text'    => mb_substr(trim($altText), 0, 500),
            'focal_x'     => self::focal($focalX),
            'focal_y'     => self::focal($focalY),
            'updated_by'  => (int)Auth::userId(),
        ];
        if ($mappingId > 0) {
            Database::update('reactsitebridge_media', $row, 'id = ? AND tenant_id = ? AND site_id = ?', [$mappingId, current_tenant_id(), $siteId]);
        } else {
            $mappingId = Database::insert('reactsitebridge_media', $row);
        }
        $context = ['context' => 'react-site-bridge', 'object_type' => 'site', 'object_id' => $siteId, 'field' => $logicalKey];
        if ($existing && (int)$existing['media_id'] !== $mediaId) {
            Media::detach((int)$existing['media_id'], ['context' => 'react-site-bridge', 'object_type' => 'site', 'object_id' => $siteId, 'field' => (string)$existing['logical_key']]);
        }
        Media::attach($mediaId, $context);
        return $mappingId;
    }

    public static function deleteMediaMapping(int $siteId, int $mappingId): void {
        self::requireSite($siteId);
        $existing = Database::row('SELECT media_id, logical_key FROM reactsitebridge_media WHERE id = ? AND site_id = ? AND tenant_id = ?', [$mappingId, $siteId, current_tenant_id()]);
        Database::delete('reactsitebridge_media', 'id = ? AND site_id = ? AND tenant_id = ?', [$mappingId, $siteId, current_tenant_id()]);
        if ($existing && class_exists('Media')) {
            Media::detach((int)$existing['media_id'], ['context' => 'react-site-bridge', 'object_type' => 'site', 'object_id' => $siteId, 'field' => (string)$existing['logical_key']]);
        }
    }

    /** Upload an approved raster photograph through Slate Media, then map it as a bridge draft. */
    public static function uploadApprovedPhotographyAndMap(int $siteId, string $field, string $logicalKey, string $altText = '', $focalX = null, $focalY = null): array {
        self::requireSite($siteId);
        if (!class_exists('Media')) throw new RuntimeException('Slate Media is unavailable.');
        $logicalKey = self::normalizeLogicalKey($logicalKey);
        if ($logicalKey === '') throw new InvalidArgumentException('A logical media key is required.');
        $existing = Database::row('SELECT id FROM reactsitebridge_media WHERE tenant_id = ? AND site_id = ? AND logical_key = ?', [current_tenant_id(), $siteId, $logicalKey]);
        if ($existing) throw new InvalidArgumentException('This logical media key is already mapped. Choose a new key before uploading.');
        $result = Media::upload($field, [
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'allowed_exts'  => ['jpg', 'jpeg', 'png', 'webp'],
            'max_bytes'     => 15 * 1024 * 1024,
        ]);
        if (empty($result['id'])) throw new InvalidArgumentException((string)($result['error'] ?? 'The approved photography file could not be uploaded.'));
        $mediaId = (int)$result['id'];
        $media = Media::get($mediaId);
        if (!$media || (string)($media['kind'] ?? '') !== 'image') throw new RuntimeException('Slate Media did not register a usable image.');
        $mappingId = self::saveMediaMapping($siteId, 0, $logicalKey, $mediaId, $altText, $focalX, $focalY);
        return ['media' => $media, 'mapping_id' => $mappingId];
    }

    public static function buildManifest(int $siteId): array {
        $site = self::requireSite($siteId);
        $documents = [];
        foreach (self::listDocuments($siteId) as $document) {
            $documents[$document['route']] = [
                'schema' => $document['schema_key'],
                'locale' => $document['locale'],
                'data'   => $document['document'],
            ];
        }
        $media = [];
        foreach (self::listMediaMappings($siteId) as $mapping) {
            $asset = $mapping['media'];
            if (!$asset) continue;
            $path = (string)($asset['path'] ?? '');
            $media[$mapping['logical_key']] = [
                'id'      => (int)$mapping['media_id'],
                'url'     => self::publicMediaUrl($path),
                'alt'     => (string)$mapping['alt_text'],
                'focalX'  => $mapping['focal_x'] !== null ? (float)$mapping['focal_x'] : null,
                'focalY'  => $mapping['focal_y'] !== null ? (float)$mapping['focal_y'] : null,
                'focal'   => ['x' => $mapping['focal_x'] !== null ? (float)$mapping['focal_x'] : null, 'y' => $mapping['focal_y'] !== null ? (float)$mapping['focal_y'] : null],
                'mime'    => (string)($asset['mime'] ?? ''),
                'width'   => !empty($asset['width']) ? (int)$asset['width'] : null,
                'height'  => !empty($asset['height']) ? (int)$asset['height'] : null,
            ];
        }
        return [
            'schema_version' => 1,
            'site' => [
                'id'        => (int)$site['id'],
                'key'       => (string)$site['site_key'],
                'public_id' => (string)$site['public_id'],
                'name'      => (string)$site['name'],
            ],
            'documents'    => $documents,
            'media'        => $media,
            'editorial'    => class_exists('ReactSiteBridgeContentBuilder') ? ReactSiteBridgeContentBuilder::manifestPayload($siteId) : ['enabled' => false, 'items' => []],
            'generated_at' => gmdate('c'),
        ];
    }

    public static function publish(int $siteId, string $note = ''): array {
        $site = self::requireSite($siteId);
        $manifest = self::buildManifest($siteId);
        $version = (int)Database::value(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM reactsitebridge_revisions WHERE tenant_id = ? AND site_id = ?',
            [current_tenant_id(), $siteId]
        );
        $revisionId = Database::insert('reactsitebridge_revisions', [
            'tenant_id'    => current_tenant_id(),
            'site_id'      => $siteId,
            'version'      => $version,
            'manifest_json'=> json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'note'         => mb_substr(trim($note), 0, 500),
            'published_by' => (int)Auth::userId(),
        ]);
        Database::update('reactsitebridge_sites', [
            'status' => 'published',
            'active_revision_id' => $revisionId,
        ], 'id = ? AND tenant_id = ?', [$siteId, current_tenant_id()]);
        Hook::doAction('react_site_bridge_published', $siteId, $version, $revisionId);
        return ['revision_id' => $revisionId, 'version' => $version, 'manifest' => $manifest];
    }

    /**
     * Return only the visual-editor payload from recent published revisions.
     * The result remains tenant and site scoped, and deliberately omits unrelated
     * manifest documents and media metadata from the editor history UI.
     */
    public static function listVisualRevisions(int $siteId, int $limit = 16): array {
        self::requireSite($siteId);
        $limit = max(1, min(50, $limit));
        $rows = Database::rows(
            'SELECT id, version, note, published_at, published_by, manifest_json
               FROM reactsitebridge_revisions
              WHERE tenant_id = ? AND site_id = ?
              ORDER BY version DESC, id DESC
              LIMIT ' . $limit,
            [current_tenant_id(), $siteId]
        );
        foreach ($rows as &$row) {
            $manifest = self::decodeJson((string)($row['manifest_json'] ?? ''));
            $visual = is_array($manifest)
                ? ($manifest['documents']['/__visual-editor__']['data'] ?? null)
                : null;
            $row['visual_document'] = is_array($visual) ? $visual : null;
            unset($row['manifest_json']);
        }
        return $rows;
    }

    public static function activeManifestByPublicId(string $publicId): ?array {
        $site = self::getSiteByPublicId($publicId);
        if (!$site || empty($site['active_revision_id'])) return null;
        $row = Database::row('SELECT * FROM reactsitebridge_revisions WHERE id = ?', [(int)$site['active_revision_id']]);
        if (!$row) return null;
        return ['site' => $site, 'revision' => $row, 'manifest' => self::decodeJson((string)$row['manifest_json'])];
    }

    public static function exportSeed(int $siteId): array {
        $site = self::requireSite($siteId);
        return [
            'package_type' => 'react-site-bridge-seed',
            'schema_version' => 1,
            'site' => ['key' => $site['site_key'], 'name' => $site['name']],
            'documents' => array_map(static fn($d) => ['route' => $d['route'], 'locale' => $d['locale'], 'schema' => $d['schema_key'], 'data' => $d['document']], self::listDocuments($siteId)),
            'media' => array_map(static fn($m) => ['key' => $m['logical_key'], 'media_id' => (int)$m['media_id'], 'alt' => $m['alt_text'], 'focal_x' => $m['focal_x'], 'focal_y' => $m['focal_y']], self::listMediaMappings($siteId)),
        ];
    }

    /**
     * Import a prepared .zip site package in one guided operation.
     *
     * The archive is never extracted to disk. Every asset is streamed through a
     * strict allow-list into the tenant Media Library, while package documents
     * become normal editable React Site Bridge records. A failed import remains
     * unpublished, so it cannot change any public manifest.
     */
    public static function importPackage(array $upload, bool $publish = true, bool $replaceExisting = false): array {
        self::ensureSchema();
        if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP ZipArchive extension is required to import a site package.');
        if (!class_exists('Media') || !class_exists('Uploads')) throw new RuntimeException('Slate Media and Uploads must be available before importing a site package.');
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new InvalidArgumentException('Upload a valid React Site Package ZIP file.');
        }
        if ((int)($upload['size'] ?? 0) > 55 * 1024 * 1024) throw new InvalidArgumentException('The site package exceeds the 55 MB import limit.');

        $zip = new ZipArchive();
        if ($zip->open((string)$upload['tmp_name']) !== true) throw new InvalidArgumentException('The uploaded archive could not be opened.');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 500) throw new InvalidArgumentException('The package must contain between 1 and 500 files.');
            $raw = $zip->getFromName('site-package.json');
            $package = is_string($raw) ? self::decodeJson($raw, true) : null;
            if (!is_array($package) || ($package['package_type'] ?? '') !== 'react-site-bridge-package' || (int)($package['schema_version'] ?? 0) !== 1) {
                throw new InvalidArgumentException('This is not a supported React Site Bridge package.');
            }

            $siteData = is_array($package['site'] ?? null) ? $package['site'] : [];
            $name = (string)($siteData['name'] ?? '');
            $siteKey = self::normalizeSiteKey((string)($siteData['key'] ?? ''));
            if ($name === '' || $siteKey === '') throw new InvalidArgumentException('The package must include a valid site name and lowercase site key.');
            $existing = Database::value('SELECT id FROM reactsitebridge_sites WHERE tenant_id = ? AND site_key = ?', [current_tenant_id(), $siteKey]);
            if ($existing && !$replaceExisting) {
                throw new InvalidArgumentException('A React site with this site key already exists in the active tenant. Open it to manage it, delete it, or re-import this package with “replace existing site” selected.');
            }

            $documents = is_array($package['documents'] ?? null) ? $package['documents'] : [];
            $media = is_array($package['media'] ?? null) ? $package['media'] : [];
            if (count($documents) > 100 || count($media) > 100) throw new InvalidArgumentException('The package exceeds the document or media item limit.');
            self::validatePackageDocuments($documents);
            self::validatePackageMedia($zip, $media);

            if ($existing) {
                $siteId = (int)$existing;
                self::replaceSiteForImport(
                    $siteId,
                    $name,
                    (string)($siteData['public_origin'] ?? ''),
                    (string)($siteData['preview_origin'] ?? '')
                );
            } else {
                $siteId = self::createSite(
                    $name,
                    $siteKey,
                    (string)($siteData['public_origin'] ?? ''),
                    (string)($siteData['preview_origin'] ?? '')
                );
            }

            foreach ($documents as $document) {
                self::saveDocument(
                    $siteId,
                    0,
                    (string)($document['route'] ?? '/'),
                    (string)($document['schema'] ?? 'page.v1'),
                    json_encode($document['data'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    (string)($document['locale'] ?? 'en')
                );
            }

            foreach ($media as $item) {
                $mediaId = self::streamPackageAsset($zip, (string)$item['file']);
                self::saveMediaMapping(
                    $siteId,
                    0,
                    (string)$item['key'],
                    $mediaId,
                    (string)($item['alt'] ?? ''),
                    $item['focal_x'] ?? null,
                    $item['focal_y'] ?? null
                );
                Media::attach($mediaId, ['context' => 'react-site-bridge', 'object_type' => 'site', 'object_id' => $siteId, 'field' => (string)$item['key']]);
            }

            $site = self::requireSite($siteId);
            $result = ['site_id' => $siteId, 'site_key' => $site['site_key'], 'public_id' => $site['public_id'], 'published' => false, 'replaced' => (bool)$existing];
            if ($publish) {
                $revision = self::publish($siteId, 'Initial import from prepared site package');
                $result['published'] = true;
                $result['version'] = $revision['version'];
            }
            Hook::doAction('react_site_bridge_package_imported', $siteId, $result);
            return $result;
        } finally {
            $zip->close();
        }
    }

    /** Remove a site and its bridge records. Tenant Media items remain available in Media Library. */
    public static function deleteSite(int $siteId): void {
        $site = self::requireSite($siteId);
        $release = self::hostedRelease((int)$site['id']);
        if ($release) {
            self::removeHostedReleaseDirectory((string)$release['storage_path']);
            Database::delete('reactsitebridge_hosted_releases', 'site_id = ? AND tenant_id = ?', [(int)$site['id'], current_tenant_id()]);
        }
        self::clearSiteContent((int)$site['id']);
        Database::delete('reactsitebridge_sites', 'id = ? AND tenant_id = ?', [(int)$site['id'], current_tenant_id()]);
        Hook::doAction('react_site_bridge_site_deleted', (int)$site['id'], (string)$site['site_key']);
    }

    /** Return the active direct-hosted release for the current tenant's site. */
    public static function hostedRelease(int $siteId): ?array {
        self::requireSite($siteId);
        return Database::row(
            'SELECT * FROM reactsitebridge_hosted_releases WHERE site_id = ? AND tenant_id = ?',
            [$siteId, current_tenant_id()]
        ) ?: null;
    }

    /** List published hosted-release site keys eligible for clean Slate routes. */
    public static function hostedRouteKeys(): array {
        self::ensureSchema();
        $rows = Database::rows(
            "SELECT s.site_key
               FROM reactsitebridge_sites s
               INNER JOIN reactsitebridge_hosted_releases r ON r.site_id = s.id AND r.tenant_id = s.tenant_id
              WHERE s.tenant_id = ? AND s.status = 'published'
           ORDER BY s.site_key ASC",
            [current_tenant_id()]
        );
        $keys = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string)($row['site_key'] ?? '')));
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $key)) $keys[] = $key;
        }
        return array_values(array_unique($keys));
    }

    /** Return a clean Slate-domain URL when a direct release exists. */
    public static function hostedUrl(int $siteId): ?string {
        $site = self::requireSite($siteId);
        if (!self::hostedRelease($siteId)) return null;
        $siteKey = strtolower(trim((string)$site['site_key']));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $siteKey)) return null;
        return rtrim((string)SLATE_URL, '/') . '/' . rawurlencode($siteKey) . '/';
    }

    /** Remove a direct-hosted release while retaining the site, documents, media, and manifests. */
    public static function removeHostedRelease(int $siteId): void {
        self::requireSite($siteId);
        $release = self::hostedRelease($siteId);
        if (!$release) return;
        self::removeHostedReleaseDirectory((string)$release['storage_path']);
        Database::delete('reactsitebridge_hosted_releases', 'id = ? AND tenant_id = ?', [(int)$release['id'], current_tenant_id()]);
    }

    /**
     * Install a verified static React release in Slate-owned uploads. The archive
     * is constrained to a declared allow-list and no uploaded PHP is ever served.
     */
    public static function importHostedRelease(int $siteId, array $upload): array {
        self::requireSite($siteId);
        if (!class_exists('ZipArchive') || !class_exists('Uploads')) throw new RuntimeException('Slate requires ZipArchive and Uploads to host a React release.');
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new InvalidArgumentException('Upload a valid Slate React Release ZIP file.');
        }
        if ((int)($upload['size'] ?? 0) > 90 * 1024 * 1024) throw new InvalidArgumentException('The hosted release exceeds the 90 MB upload limit.');

        $zip = new ZipArchive();
        if ($zip->open((string)$upload['tmp_name']) !== true) throw new InvalidArgumentException('The release archive could not be opened.');
        $storedPath = '';
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > 400) throw new InvalidArgumentException('The hosted release must contain between 2 and 400 files.');
            $raw = $zip->getFromName('hosted-release.json');
            $package = is_string($raw) ? self::decodeJson($raw, true) : null;
            if (!is_array($package) || ($package['package_type'] ?? '') !== 'react-site-bridge-hosted-release' || (int)($package['schema_version'] ?? 0) !== 1) {
                throw new InvalidArgumentException('This is not a supported Slate React Release package.');
            }
            $files = is_array($package['files'] ?? null) ? $package['files'] : [];
            if (!$files || count($files) > 350 || !in_array('index.html', $files, true)) throw new InvalidArgumentException('The release manifest must include index.html and a bounded file list.');

            $uniqueFiles = [];
            $totalBytes = 0;
            foreach ($files as $file) {
                $file = (string)$file;
                if (!self::validHostedFile($file) || isset($uniqueFiles[$file])) throw new InvalidArgumentException('The release contains an invalid or duplicate file path.');
                $stat = $zip->statName($file);
                $size = (int)($stat['size'] ?? -1);
                if (!$stat || $size < 0 || $size > 30 * 1024 * 1024) throw new InvalidArgumentException('A release file is missing or exceeds the 30 MB per-file limit.');
                $totalBytes += $size;
                if ($totalBytes > 120 * 1024 * 1024) throw new InvalidArgumentException('The hosted release exceeds the 120 MB expanded-size limit.');
                $uniqueFiles[$file] = $size;
            }

            $site = self::requireSite($siteId);
            $folder = 'react-site-bridge/releases/' . (string)$site['public_id'] . '-' . bin2hex(random_bytes(8));
            $absoluteDir = Uploads::publicUploadDir($folder);
            $storedPath = '/uploads/' . $folder;
            foreach ($uniqueFiles as $file => $size) self::copyHostedReleaseFile($zip, $file, $size, $absoluteDir);

            $previous = self::hostedRelease($siteId);
            $row = [
                'tenant_id' => current_tenant_id(),
                'site_id' => $siteId,
                'storage_path' => $storedPath,
                'entrypoint' => 'index.html',
                'file_count' => count($uniqueFiles),
                'size_bytes' => $totalBytes,
                'uploaded_by' => (int)Auth::userId(),
            ];
            if ($previous) Database::update('reactsitebridge_hosted_releases', $row, 'id = ? AND tenant_id = ?', [(int)$previous['id'], current_tenant_id()]);
            else Database::insert('reactsitebridge_hosted_releases', $row);
            if ($previous && (string)$previous['storage_path'] !== $storedPath) self::removeHostedReleaseDirectory((string)$previous['storage_path']);
            Hook::doAction('react_site_bridge_release_hosted', $siteId, $storedPath);
            return self::hostedRelease($siteId) ?: $row;
        } catch (Throwable $e) {
            if ($storedPath !== '') self::removeHostedReleaseDirectory($storedPath);
            throw $e;
        } finally {
            $zip->close();
        }
    }

    /** Serve a current direct-hosted release with a strict path boundary. */
    public static function serveHostedRelease(string $publicId, string $requestPath): void {
        self::ensureSchema();
        $site = self::getSiteByPublicId($publicId);
        if (!$site) { self::hostedNotFound(); return; }
        self::serveHostedSite($site, $requestPath);
    }

    /** Serve a hosted release through its tenant-scoped, human-readable site key. */
    public static function serveHostedReleaseBySiteKey(string $siteKey, string $requestPath): void {
        self::ensureSchema();
        $siteKey = strtolower(trim($siteKey));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $siteKey)) { self::hostedNotFound(); return; }
        $site = Database::row(
            "SELECT * FROM reactsitebridge_sites WHERE tenant_id = ? AND site_key = ? AND status = 'published'",
            [current_tenant_id(), $siteKey]
        );
        if (!$site) { self::hostedNotFound(); return; }
        self::serveHostedSite($site, $requestPath);
    }

    /** Shared strict static-serving implementation for technical and clean release URLs. */
    private static function serveHostedSite(array $site, string $requestPath): void {
        $release = Database::row('SELECT * FROM reactsitebridge_hosted_releases WHERE site_id = ? AND tenant_id = ?', [(int)$site['id'], (int)$site['tenant_id']]);
        if (!$release) { self::hostedNotFound(); return; }
        $requestPath = rawurldecode(trim($requestPath, '/'));
        // Coastal Precision implementation note: direct React releases are
        // single-page applications. Permit clean client routes such as
        // /electric and /energy/services to fall back to index.html, while
        // retaining the strict allowlist for real static files.
        if (str_contains($requestPath, '..') || str_contains($requestPath, "\0")) { self::hostedNotFound(); return; }
        $isStaticAsset = $requestPath !== '' && self::validHostedFile($requestPath);
        if ($requestPath !== '' && !$isStaticAsset && pathinfo($requestPath, PATHINFO_EXTENSION) !== '') { self::hostedNotFound(); return; }
        $relative = $requestPath === '' ? (string)$release['entrypoint'] : $requestPath;
        $base = self::hostedReleaseAbsoluteDirectory((string)$release['storage_path']);
        if (!$base) { self::hostedNotFound(); return; }
        $candidate = $base . '/' . $relative;
        if (!is_file($candidate)) {
            if ($isStaticAsset || pathinfo($relative, PATHINFO_EXTENSION) !== '') { self::hostedNotFound(); return; }
            $candidate = $base . '/' . (string)$release['entrypoint'];
        }
        if (!is_file($candidate)) { self::hostedNotFound(); return; }
        $mime = self::hostedMime((string)pathinfo($candidate, PATHINFO_EXTENSION));
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . (str_contains($relative, '/assets/') ? 'public, max-age=31536000, immutable' : 'no-cache'));
        header('Content-Length: ' . (string)filesize($candidate));
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') readfile($candidate);
    }

    /** Reset an existing site before a confirmed package replacement while retaining its public identifier. */
    private static function replaceSiteForImport(int $siteId, string $name, string $publicOrigin, string $previewOrigin): void {
        $site = self::requireSite($siteId);
        self::clearSiteContent((int)$site['id']);
        Database::update('reactsitebridge_sites', [
            'name'               => mb_substr(trim($name), 0, 190),
            'status'             => 'draft',
            'active_revision_id' => null,
            'public_origin'      => self::normalizeOrigin($publicOrigin),
            'preview_origin'     => self::normalizeOrigin($previewOrigin),
        ], 'id = ? AND tenant_id = ?', [(int)$site['id'], current_tenant_id()]);
    }

    /** Delete bridge-owned records only; Media Library assets remain available for deliberate cleanup. */
    private static function clearSiteContent(int $siteId): void {
        $tenantId = current_tenant_id();
        Database::delete('reactsitebridge_documents', 'site_id = ? AND tenant_id = ?', [$siteId, $tenantId]);
        Database::delete('reactsitebridge_media', 'site_id = ? AND tenant_id = ?', [$siteId, $tenantId]);
        Database::delete('reactsitebridge_revisions', 'site_id = ? AND tenant_id = ?', [$siteId, $tenantId]);
    }

    private static function requireSite(int $siteId): array {
        $site = self::getSite($siteId);
        if (!$site) throw new InvalidArgumentException('React site not found for the active tenant.');
        return $site;
    }

    private static function validatePackageDocuments(array $documents): void {
        $routes = [];
        foreach ($documents as $document) {
            if (!is_array($document) || !array_key_exists('data', $document)) throw new InvalidArgumentException('Each package document needs route, schema, and data fields.');
            $route = self::normalizeRoute((string)($document['route'] ?? ''));
            $locale = (string)($document['locale'] ?? 'en');
            $key = $route . '|' . $locale;
            if (isset($routes[$key])) throw new InvalidArgumentException('The package contains a duplicate route and locale.');
            $routes[$key] = true;
            if (!is_array($document['data'])) throw new InvalidArgumentException('Every package document data field must be a JSON object or array.');
            if (strlen((string)json_encode($document['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > 500000) throw new InvalidArgumentException('A package document exceeds the 500 KB limit.');
        }
    }

    private static function validatePackageMedia(ZipArchive $zip, array $items): void {
        $seen = [];
        $totalBytes = 0;
        foreach ($items as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Each media entry must be an object.');
            $key = self::normalizeLogicalKey((string)($item['key'] ?? ''));
            $file = (string)($item['file'] ?? '');
            if ($key === '' || !preg_match('#^media/[a-zA-Z0-9][a-zA-Z0-9._-]*$#', $file)) throw new InvalidArgumentException('Media keys and package file paths are invalid.');
            if (isset($seen[$key])) throw new InvalidArgumentException('The package contains duplicate media keys.');
            $seen[$key] = true;
            $stat = $zip->statName($file);
            $size = (int)($stat['size'] ?? -1);
            if (!$stat || $size < 1 || $size > 10 * 1024 * 1024) throw new InvalidArgumentException('Each package media file must be between 1 byte and 10 MB.');
            $totalBytes += $size;
            if ($totalBytes > 45 * 1024 * 1024) throw new InvalidArgumentException('Package media exceeds the 45 MB import limit.');
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, Media::allowedExts(), true)) throw new InvalidArgumentException('A package media file has an unsupported extension.');
        }
    }

    private static function streamPackageAsset(ZipArchive $zip, string $entry): int {
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) throw new RuntimeException('A package media file could not be read.');
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        $folder = 'media/react-site-bridge/' . gmdate('Y/m');
        $directory = Uploads::publicUploadDir($folder);
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;
        $absolutePath = $directory . '/' . $filename;
        $target = @fopen($absolutePath, 'xb');
        if (!is_resource($target)) { fclose($stream); throw new RuntimeException('A package media file could not be stored.'); }
        $bytes = stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);
        if ($bytes === false || $bytes < 1) { @unlink($absolutePath); throw new RuntimeException('A package media file was empty.'); }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';
        if (!in_array($mime, Media::allowedMimes(), true)) { @unlink($absolutePath); throw new InvalidArgumentException('A package media file did not pass MIME validation.'); }
        if ($ext === 'svg' && !Media::sanitizeSvgFile($absolutePath)) { @unlink($absolutePath); throw new InvalidArgumentException('A package SVG could not be sanitized.'); }

        $width = null;
        $height = null;
        if (Media::kindForMime($mime, $ext) === 'image') {
            $dimensions = @getimagesize($absolutePath);
            if (is_array($dimensions)) { $width = $dimensions[0] ?? null; $height = $dimensions[1] ?? null; }
        }
        @chmod($absolutePath, 0644);
        $path = '/uploads/' . $folder . '/' . $filename;
        $mediaId = Media::register($path, ['mime' => $mime, 'size_bytes' => (int)$bytes, 'width' => $width, 'height' => $height, 'original_name' => basename($entry)]);
        if ($mediaId <= 0) { @unlink($absolutePath); throw new RuntimeException('A package media file could not be registered in Slate Media.'); }
        return $mediaId;
    }

    private static function newPublicId(): string { return bin2hex(random_bytes(18)); }
    private static function normalizeSiteKey(string $value): string { return trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower($value)), '-'); }
    private static function normalizeLogicalKey(string $value): string { return trim((string)preg_replace('/[^a-z0-9._-]+/', '-', strtolower($value)), '-'); }
    private static function validHostedFile(string $file): bool {
        if ($file === 'index.html') return true;
        if (!preg_match('#^(?:assets|media)/[A-Za-z0-9][A-Za-z0-9._/-]*$#', $file)) return false;
        if (str_contains($file, '..') || str_contains($file, '//')) return false;
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        return in_array($ext, ['css', 'js', 'mjs', 'map', 'json', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'woff', 'woff2', 'ttf', 'ico', 'webmanifest'], true);
    }
    private static function copyHostedReleaseFile(ZipArchive $zip, string $file, int $expectedBytes, string $absoluteDir): void {
        $stream = $zip->getStream($file);
        if (!is_resource($stream)) throw new RuntimeException('A hosted release file could not be read.');
        $target = $absoluteDir . '/' . $file;
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) { fclose($stream); throw new RuntimeException('A hosted release folder could not be created.'); }
        $out = @fopen($target, 'xb');
        if (!is_resource($out)) { fclose($stream); throw new RuntimeException('A hosted release file could not be written.'); }
        $written = stream_copy_to_stream($stream, $out);
        fclose($out); fclose($stream);
        if ($written === false || (int)$written !== $expectedBytes) { @unlink($target); throw new RuntimeException('A hosted release file could not be written completely.'); }
        @chmod($target, 0644);
    }
    private static function hostedReleaseAbsoluteDirectory(string $storedPath): ?string {
        if (!preg_match('#^/uploads/react-site-bridge/releases/[a-f0-9-]+$#', $storedPath)) return null;
        $absolute = realpath(SLATE_ROOT . '/' . ltrim($storedPath, '/'));
        $root = realpath(SLATE_ROOT . '/uploads/react-site-bridge/releases');
        return $absolute && $root && str_starts_with($absolute, $root . '/') ? $absolute : null;
    }
    private static function removeHostedReleaseDirectory(string $storedPath): void {
        $absolute = self::hostedReleaseAbsoluteDirectory($storedPath);
        if (!$absolute) return;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($absolute);
    }
    private static function hostedMime(string $extension): string {
        return match (strtolower($extension)) {
            'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js', 'mjs' => 'text/javascript; charset=utf-8', 'json', 'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'ico' => 'image/x-icon', 'webmanifest' => 'application/manifest+json; charset=utf-8', default => 'application/octet-stream',
        };
    }
    private static function hostedNotFound(): void { http_response_code(404); echo 'Hosted React site not found.'; }
    private static function normalizeRoute(string $route): string {
        $route = '/' . trim($route);
        $route = preg_replace('#/+#', '/', $route) ?: '/';
        if (str_contains($route, '..')) throw new InvalidArgumentException('Route cannot contain traversal segments.');
        return $route === '/' ? '/' : rtrim($route, '/');
    }
    /**
     * Store a safe frontend base URL. This intentionally permits a path such
     * as /slate/kaimana for direct Slate-hosted releases, while rejecting
     * credentials, query strings, fragments, traversal, and malformed paths.
     */
    private static function normalizeOrigin(string $origin): ?string {
        $origin = trim($origin);
        if ($origin === '') return null;
        $parts = parse_url($origin);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user'], $parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Frontend base URL must be an http(s) URL with a host and optional safe path.');
        }
        $base = strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']) . (!empty($parts['port']) ? ':' . (int)$parts['port'] : '');
        $path = (string)($parts['path'] ?? '');
        if ($path === '' || $path === '/') return $base;
        if (!preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+$#', $path) || str_contains($path, '..') || str_contains($path, '//')) {
            throw new InvalidArgumentException('Frontend base URL path contains unsupported characters.');
        }
        return $base . '/' . trim($path, '/');
    }

    /** Extract the browser Origin portion of a stored frontend base URL for CORS. */
    public static function frontendCorsOrigin(string $frontendBase): string {
        $parts = parse_url(trim($frontendBase));
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) return '';
        return strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']) . (!empty($parts['port']) ? ':' . (int)$parts['port'] : '');
    }
    private static function focal($value): ?float {
        if ($value === '' || $value === null) return null;
        $value = (float)$value;
        if ($value < 0 || $value > 1) throw new InvalidArgumentException('Focal values must be between 0 and 1.');
        return $value;
    }
    private static function decodeJson(string $json, bool $throw = false): ?array {
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { if ($throw) throw new InvalidArgumentException('Invalid JSON document.'); return null; }
        if (!is_array($value)) { if ($throw) throw new InvalidArgumentException('JSON must be an object or array.'); return null; }
        return $value;
    }
    private static function publicMediaUrl(string $path): string {
        if (preg_match('#^(?:https?:)?//#i', $path)) return $path;
        return rtrim((string)SLATE_URL, '/') . '/' . ltrim($path, '/');
    }
}
