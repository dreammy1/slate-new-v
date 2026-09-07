<?php
/**
 * Shop — Products admin (WooCommerce-style editor, CSV I/O, variants).
 *
 * Routes:
 *   GET  ?              → list (with bulk actions, search, category filter)
 *   GET  ?new           → new product editor
 *   GET  ?edit=N        → edit product editor
 *   GET  ?export        → CSV download
 *   GET  ?import        → CSV import form
 *   POST _action=create|update|delete|bulk|import_csv|save_variant|delete_variant
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('shop.manage_products');

$pageTitle  = __('shop_products', 'Products');
$currentNav = 'shop-products';

$tid      = current_tenant_id();
$currency = Database::setting('shop.currency') ?: 'USD';
$flash    = null;
$editing  = null;
$mode     = 'list';

/**
 * Render an image URL for the admin (variant/product/gallery thumbnails).
 * Accepts either a stored relative path ("/uploads/shop/...") or an
 * absolute http(s) URL. Returns the version safe to put into <img src>.
 */
if (!function_exists('shop_admin_image_url')) {
    function shop_admin_image_url(?string $stored): string {
        if (!$stored) return '';
        if (filter_var($stored, FILTER_VALIDATE_URL)) return $stored;
        return SLATE_URL . '/' . ltrim($stored, '/');
    }
}

// ─── CSV EXPORT (handled before any output) ──────────────────
if (isset($_GET['export'])) {
    $rows = Database::rows(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
           FROM shop_products p
      LEFT JOIN shop_categories c ON c.id = p.category_id
          WHERE p.tenant_id = ?
          ORDER BY p.id ASC",
        [$tid]
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Type', 'SKU', 'Name', 'Slug', 'Published', 'Featured',
        'Short description', 'Description',
        'Regular price', 'Sale price',
        'In stock?', 'Stock', 'Manage stock',
        'Weight', 'Categories', 'Tags', 'Images',
    ]);
    // Neutralise CSV/formula injection: a cell starting with = + - @ (or a
    // tab/CR) is treated as a formula by Excel/Sheets. Prefix with a quote.
    $csvSafe = static function ($v): string {
        $s = (string)$v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    };
    foreach ($rows as $r) {
        fputcsv($out, array_map($csvSafe, [
            $r['id'],
            'simple',
            $r['sku'],
            $r['name'],
            $r['slug'],
            $r['status'] === 'active' ? '1' : '0',
            $r['featured'] ?? '0',
            $r['short_desc'],
            $r['description'],
            number_format((float)$r['price'], 2, '.', ''),
            $r['sale_price'] !== null ? number_format((float)$r['sale_price'], 2, '.', '') : '',
            ((int)$r['stock_qty'] > 0 || !$r['manage_stock']) ? '1' : '0',
            $r['stock_qty'],
            $r['manage_stock'] ? '1' : '0',
            $r['weight'] !== null ? $r['weight'] : '',
            $r['category_name'] ?? $r['category'] ?? '',
            $r['tags'] ?? '',
            $r['image_url'] ?? '',
        ]));
    }
    fclose($out);
    AuditLog::record('shop.products_exported', 'csv', ['count' => count($rows)]);
    exit;
}

// ─── POST HANDLERS ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $id = (int)($_POST['product_id'] ?? 0);
            $data = collectProductData($_POST, $_FILES);

            // Collect pending variants from the New Product page. These
            // are the rows from the dynamic repeater UI. We sanitise
            // here (cast types, trim strings, drop rows with no
            // attribute+value) so that validation failure can re-render
            // them and so the eventual INSERT is straightforward.
            //
            // For UPDATE actions (existing products) we ignore this —
            // those products manage variants through the dedicated
            // per-variant forms below the editor.
            $pendingVariants = [];
            if ($action === 'create' && !empty($_POST['pending_variants']) && is_array($_POST['pending_variants'])) {
                foreach ($_POST['pending_variants'] as $pv) {
                    if (!is_array($pv)) continue;
                    $attr  = trim((string)($pv['attribute'] ?? ''));
                    $value = trim((string)($pv['value'] ?? ''));
                    // Skip blank rows silently — users may leave the
                    // default empty row in place.
                    if ($attr === '' && $value === '') continue;
                    $pendingVariants[] = [
                        'attribute'  => mb_substr($attr, 0, 50),
                        'value'      => mb_substr($value, 0, 100),
                        'sku'        => mb_substr(trim((string)($pv['sku'] ?? '')), 0, 100),
                        'price_diff' => (float)($pv['price_diff'] ?? 0),
                        'stock_qty'  => max(0, (int)($pv['stock_qty'] ?? 0)),
                    ];
                }
            }

            // Validate the product data AND any pending variants so a
            // single error path handles both. We allow blank rows
            // (already skipped above) but reject rows where only ONE
            // of attribute/value is filled — that's almost always a
            // typo the user wants flagged, not silently dropped.
            $err = validateProduct($data, $id);
            if ($err === null && $action === 'create' && !empty($_POST['pending_variants'])) {
                foreach ($_POST['pending_variants'] as $pv) {
                    if (!is_array($pv)) continue;
                    $attr  = trim((string)($pv['attribute'] ?? ''));
                    $value = trim((string)($pv['value'] ?? ''));
                    if (($attr === '') !== ($value === '')) {
                        // exactly one of the two is set
                        $err = __('variant_attr_value_pair_required',
                            'Each variant row needs BOTH an attribute and a value (or leave both blank).');
                        break;
                    }
                }
            }

            if ($err) {
                $flash = ['type' => 'error', 'msg' => $err];
                $mode = 'edit';
                $editing = array_merge(['id' => $id], $data);
                // Thread the user's pending-variant rows through to the
                // re-rendered form so they don't have to re-type them.
                // The editor template reads _pending_variants explicitly.
                if (!empty($pendingVariants)) {
                    $editing['_pending_variants'] = $pendingVariants;
                }
            } else {
                if ($action === 'create') {
                    // Wrap product create + variant inserts in one
                    // transaction so a bad variant doesn't leave an
                    // orphaned product behind. We follow the same
                    // pattern as ShopAPI::createOrder() — guard against
                    // nested-transaction PDOException.
                    $pdo = Database::get();
                    $startedTx = false;
                    $newId = 0;
                    try {
                        if (!$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                            $startedTx = true;
                        }
                        $newId = Database::insert('shop_products', array_merge(
                            ['tenant_id' => $tid],
                            productDbRow($data)
                        ));
                        foreach ($pendingVariants as $pv) {
                            Database::insert('shop_product_variants', array_merge(
                                ['product_id' => $newId],
                                $pv
                            ));
                        }
                        if ($startedTx) $pdo->commit();
                    } catch (\Throwable $e) {
                        if ($startedTx && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        slate_log('Shop product create failed: ' . $e->getMessage(), 'error');
                        // Duplicate (product_id, attribute, value) constraint —
                        // surface a friendly message instead of a raw SQL error.
                        $msg = ($e instanceof \PDOException && $e->getCode() === '23000')
                            ? __('variant_duplicate_in_create',
                                'One of the variant rows duplicates an attribute+value combination. Remove the duplicate row and try again.')
                            : __('product_create_failed',
                                'Could not save the product. The error has been logged.')
                              . ' (' . $e->getMessage() . ')';
                        $flash = ['type' => 'error', 'msg' => $msg];
                        $mode = 'edit';
                        $editing = array_merge(['id' => 0], $data);
                        if (!empty($pendingVariants)) {
                            $editing['_pending_variants'] = $pendingVariants;
                        }
                    }
                    if ($newId > 0) {
                        AuditLog::record('shop.product_created', "product#$newId", [
                            'name'           => $data['name'],
                            'variant_count'  => count($pendingVariants),
                        ]);
                        $msg = sprintf(
                            __('product_created', 'Product "%s" created.'),
                            $data['name']
                        );
                        if (!empty($pendingVariants)) {
                            $msg .= ' ' . sprintf(
                                __('with_n_variants', '%d variant%s added.'),
                                count($pendingVariants),
                                count($pendingVariants) === 1 ? '' : 's'
                            );
                        }
                        $flash = ['type' => 'success', 'msg' => $msg];
                        header('Location: ' . SLATE_URL . '/plugins/shop/admin/products.php?edit=' . $newId
                             . '&created=1');
                        exit;
                    }
                } else {
                    Database::update('shop_products', productDbRow($data),
                        'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('shop.product_updated', "product#$id");
                    $flash = ['type' => 'success', 'msg' => __('product_updated', 'Product saved.')];
                    $mode = 'edit';
                    $editing = Database::row("SELECT * FROM shop_products WHERE id = ? AND tenant_id = ?",
                        [$id, $tid]);
                }
            }
        }

        elseif ($action === 'delete') {
            $id = (int)($_POST['product_id'] ?? 0);
            if ($id > 0) {
                $row = Database::row("SELECT name FROM shop_products WHERE id = ? AND tenant_id = ?",
                    [$id, $tid]);
                Database::delete('shop_product_variants', 'product_id = ?', [$id]);
                Database::delete('shop_products', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('shop.product_deleted', "product#$id", ['name' => $row['name'] ?? '']);
                $flash = ['type' => 'success', 'msg' => __('product_deleted', 'Product deleted.')];
            }
        }

        elseif ($action === 'bulk') {
            $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
            $op  = $_POST['bulk_action'] ?? '';
            if (!empty($ids) && $op !== '') {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                if ($op === 'publish') {
                    Database::query(
                        "UPDATE shop_products SET status='active' WHERE tenant_id = ? AND id IN ($placeholders)",
                        array_merge([$tid], $ids));
                } elseif ($op === 'draft') {
                    Database::query(
                        "UPDATE shop_products SET status='draft' WHERE tenant_id = ? AND id IN ($placeholders)",
                        array_merge([$tid], $ids));
                } elseif ($op === 'archive') {
                    Database::query(
                        "UPDATE shop_products SET status='archived' WHERE tenant_id = ? AND id IN ($placeholders)",
                        array_merge([$tid], $ids));
                } elseif ($op === 'delete') {
                    // Scope the variant purge to products this tenant owns,
                    // so a forged id can't delete another tenant's variants.
                    Database::query(
                        "DELETE FROM shop_product_variants
                          WHERE product_id IN (
                              SELECT id FROM shop_products
                               WHERE tenant_id = ? AND id IN ($placeholders))",
                        array_merge([$tid], $ids));
                    Database::query(
                        "DELETE FROM shop_products WHERE tenant_id = ? AND id IN ($placeholders)",
                        array_merge([$tid], $ids));
                }
                AuditLog::record('shop.products_bulk_' . $op, '', ['count' => count($ids)]);
                $flash = ['type' => 'success', 'msg' => sprintf(
                    __('bulk_applied', '%s applied to %d product(s).'), ucfirst($op), count($ids))];
            }
        }

        elseif ($action === 'import_csv') {
            // ── Hardening the CSV intake ──
            //
            // Before this fix the importer trusted $_FILES['csv_file']
            // entirely, reading the tmp file with no MIME / extension /
            // size validation. That meant an admin (or an attacker who
            // had compromised an admin account) could feed multi-GB
            // "CSVs" of arbitrary content into file_get_contents(),
            // exhausting memory.
            //
            // We don't route this through Uploads::handle() because that
            // would also MOVE the file into /uploads/ — we just want to
            // parse and discard. So we replicate the same checks inline:
            //   - upload succeeded (UPLOAD_ERR_OK)
            //   - is_uploaded_file (came through the SAPI, not forged)
            //   - size <= 2 MB
            //   - extension is .csv
            //   - real MIME (via finfo) is a CSV-ish type
            $file = $_FILES['csv_file'] ?? null;
            $csvErr = null;

            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                // Map PHP's upload error codes to messages the admin can act on.
                $code = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                $csvErr = match ($code) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
                        => __('csv_too_large', 'CSV file exceeds the server upload size limit.'),
                    UPLOAD_ERR_PARTIAL
                        => __('csv_partial', 'CSV upload was interrupted. Please try again.'),
                    UPLOAD_ERR_NO_FILE
                        => __('csv_no_file', 'Please select a CSV file.'),
                    default
                        => __('csv_upload_failed', 'CSV upload failed. Please try again.'),
                };
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                // Refuse any path that didn't come through the PHP SAPI
                // upload handler — defends against a forged $_FILES entry.
                slate_log('Shop CSV import: is_uploaded_file failed for ' . $file['tmp_name'], 'warning');
                $csvErr = __('csv_invalid_upload', 'Invalid upload.');
            } elseif ((int)$file['size'] > 2 * 1024 * 1024) {
                $csvErr = __('csv_too_large', 'CSV file is too large. Maximum size is 2 MB.');
            } else {
                $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    $csvErr = __('csv_bad_ext', 'Only .csv files are accepted.');
                } else {
                    // Real MIME from file bytes — don't trust $_FILES['type']
                    // (the browser-provided MIME is attacker-controlled).
                    $mime = 'application/octet-stream';
                    if (class_exists('finfo')) {
                        try {
                            $fi = new finfo(FILEINFO_MIME_TYPE);
                            $sniffed = $fi->file($file['tmp_name']);
                            if (is_string($sniffed) && $sniffed !== '') $mime = $sniffed;
                        } catch (\Throwable $e) { /* leave default */ }
                    }
                    // CSVs sniff as a small handful of types depending on
                    // the producer and the file's contents. Excel sometimes
                    // saves them as application/vnd.ms-excel; finfo on a
                    // pure-ASCII CSV very often returns text/plain.
                    $okMimes = [
                        'text/csv', 'text/plain', 'application/csv',
                        'application/vnd.ms-excel',
                    ];
                    if (!in_array($mime, $okMimes, true)) {
                        $csvErr = sprintf(
                            __('csv_bad_mime', 'File does not look like a CSV (detected: %s).'),
                            $mime
                        );
                    }
                }
            }

            if ($csvErr !== null) {
                $flash = ['type' => 'error', 'msg' => $csvErr];
            } else {
                $result = importProductsCsv($file['tmp_name']);
                if ($result['ok']) {
                    AuditLog::record('shop.products_imported', 'csv', [
                        'created' => $result['created'],
                        'updated' => $result['updated'],
                        'skipped' => $result['skipped'] ?? 0,
                        'errors'  => count($result['errors']),
                    ]);
                    $skipped = $result['skipped'] ?? 0;
                    $variantsImp = $result['variants_imported'] ?? 0;
                    $msg = sprintf(__('csv_import_ok',
                        'Imported: %d created, %d updated, %d skipped (variations/grouped), %d errors.'),
                        $result['created'], $result['updated'], $skipped, count($result['errors']));
                    if ($variantsImp > 0) {
                        $msg .= ' ' . sprintf(__('csv_variants_added',
                            '%d variant%s imported.'),
                            $variantsImp, $variantsImp === 1 ? '' : 's');
                    }
                    if (!empty($result['errors'])) {
                        $msg .= ' ' . __('csv_check_below', 'See errors below.');
                    }
                    $flash = ['type' => 'success', 'msg' => $msg];
                    $GLOBALS['_csv_errors'] = $result['errors'];
                } else {
                    $flash = ['type' => 'error', 'msg' => $result['error']];
                }
            }
            $mode = 'import';
        }

        elseif ($action === 'save_variant') {
            $productId   = (int)($_POST['product_id'] ?? 0);
            $vid         = (int)($_POST['variant_id'] ?? 0);
            $attribute   = trim((string)($_POST['v_attribute'] ?? ''));
            $value       = trim((string)($_POST['v_value'] ?? ''));
            $sku         = trim((string)($_POST['v_sku'] ?? ''));
            $priceDiff   = (float)($_POST['v_price_diff'] ?? 0);
            $stockQty    = (int)($_POST['v_stock_qty'] ?? 0);
            $description = trim((string)($_POST['v_description'] ?? ''));

            // Per-variant image: same upload logic as the parent product —
            // upload a new file if one was provided, otherwise check for
            // a picker-selected path, otherwise keep the existing path
            // (passed back in v_existing_image_url so the form doesn't
            // lose it on a description-only edit).
            $existingImage = trim((string)($_POST['v_existing_image_url'] ?? ''));
            $pickedImage   = trim((string)($_POST['v_picked_image_url']   ?? ''));
            $imageUrl      = $existingImage;
            if (!empty($_FILES['v_image']) && $_FILES['v_image']['error'] === UPLOAD_ERR_OK) {
                try {
                    $up = Uploads::handle('v_image', 'shop/products/variants', [
                        'allowed_mimes' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
                        'max_bytes'     => 5 * 1024 * 1024,
                    ]);
                    if ($up['ok']) {
                        $imageUrl = $up['path'];
                        if (class_exists('MediaLibrary')) {
                            $info = @getimagesize(SLATE_ROOT . $up['path']);
                            MediaLibrary::register($up['path'], [
                                'mime'          => $up['mime']     ?? '',
                                'size_bytes'    => $up['size']     ?? 0,
                                'width'         => is_array($info) ? ($info[0] ?? null) : null,
                                'height'        => is_array($info) ? ($info[1] ?? null) : null,
                                'original_name' => $up['original'] ?? '',
                            ]);
                        }
                    } else {
                        slate_log('Variant image upload failed: ' . ($up['error'] ?? '?'), 'warning');
                    }
                } catch (\Throwable $e) {
                    slate_log('Variant image upload exception: ' . $e->getMessage(), 'error');
                }
            } elseif ($pickedImage !== '' && class_exists('MediaLibrary')
                   && MediaLibrary::isManagedPath($pickedImage)) {
                $imageUrl = $pickedImage;
            }
            // Explicit clear flag (checkbox on the editor)
            if (!empty($_POST['v_clear_image'])) {
                $imageUrl = '';
            }

            // Verify the parent product belongs to this tenant before any
            // variant write — `shop_product_variants` has no tenant_id of
            // its own, so ownership is enforced through the product.
            $ownsProduct = $productId > 0 && Database::value(
                "SELECT id FROM shop_products WHERE id = ? AND tenant_id = ?", [$productId, $tid]);

            if ($productId <= 0 || $attribute === '' || $value === '') {
                $flash = ['type' => 'error',
                    'msg' => __('variant_required', 'Variant attribute and value are required.')];
            } elseif (!$ownsProduct) {
                $flash = ['type' => 'error', 'msg' => __('product_not_found', 'Product not found.')];
            } else {
                $row = [
                    'attribute'   => $attribute,
                    'value'       => $value,
                    'sku'         => $sku,
                    'price_diff'  => $priceDiff,
                    'stock_qty'   => $stockQty,
                    'image_url'   => $imageUrl !== '' ? $imageUrl : null,
                    'description' => $description !== '' ? $description : null,
                ];
                try {
                    if ($vid > 0) {
                        // Scope by product_id (already verified tenant-owned)
                        // so a variant id from another product can't be hijacked.
                        Database::update('shop_product_variants', $row,
                            'id = ? AND product_id = ?', [$vid, $productId]);
                    } else {
                        $row['product_id'] = $productId;
                        Database::insert('shop_product_variants', $row);
                    }
                    $flash = ['type' => 'success', 'msg' => __('variant_saved', 'Variant saved.')];
                } catch (\PDOException $e) {
                    // 23000 = integrity constraint violation. The unique key
                    // is (product_id, attribute, value) — surface a friendly
                    // message instead of 500-ing.
                    if ($e->getCode() === '23000') {
                        $flash = ['type' => 'error',
                            'msg' => sprintf(
                                __('variant_dup', 'A variant with attribute "%s" and value "%s" already exists for this product.'),
                                $attribute, $value
                            )];
                    } else {
                        slate_log('Variant save failed: ' . $e->getMessage(), 'error');
                        $flash = ['type' => 'error',
                            'msg' => __('variant_save_failed', 'Could not save variant. See server log for details.')];
                    }
                }
            }
            // Stay on edit page
            $mode = 'edit';
            $editing = Database::row("SELECT * FROM shop_products WHERE id = ? AND tenant_id = ?",
                [$productId, $tid]);
        }

        elseif ($action === 'delete_variant') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $vid       = (int)($_POST['variant_id'] ?? 0);
            // Only delete when the parent product belongs to this tenant,
            // and scope the delete to that product.
            $ownsProduct = $productId > 0 && Database::value(
                "SELECT id FROM shop_products WHERE id = ? AND tenant_id = ?", [$productId, $tid]);
            if ($vid > 0 && $ownsProduct) {
                Database::delete('shop_product_variants', 'id = ? AND product_id = ?', [$vid, $productId]);
                $flash = ['type' => 'success', 'msg' => __('variant_deleted', 'Variant removed.')];
            }
            $mode = 'edit';
            $editing = Database::row("SELECT * FROM shop_products WHERE id = ? AND tenant_id = ?",
                [$productId, $tid]);
        }
    }
}

// ─── MODE SELECTION (GET) ────────────────────────────────────
if ($mode === 'list') {
    if (isset($_GET['new'])) {
        $mode = 'edit';
        $editing = ['id' => 0, 'name' => '', 'sku' => '', 'gtin' => '', 'slug' => '',
                    'description' => '', 'short_desc' => '',
                    'price' => '', 'sale_price' => '', 'stock_qty' => 0,
                    'manage_stock' => 1, 'weight' => '',
                    'dimensions_l' => '', 'dimensions_w' => '', 'dimensions_h' => '',
                    'category_id' => 0,
                    'tags' => '', 'image_url' => '', 'gallery_urls' => '',
                    'status' => 'draft', 'featured' => 0];
    } elseif (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $row = Database::row("SELECT * FROM shop_products WHERE id = ? AND tenant_id = ?",
            [$id, $tid]);
        if ($row) {
            $editing = $row;
            $mode = 'edit';
        }
    } elseif (isset($_GET['import'])) {
        $mode = 'import';
    }
}

// ─── HELPERS ─────────────────────────────────────────────────
function collectProductData(array $post, array $files): array {
    // Product names are plaintext by contract; any HTML in them
    // displays as literal "<b>FOO</b>" text on storefront/cart since
    // those pages defensively HTML-escape names. Strip tags here and
    // in importProductsCsv() so both entry paths converge on plaintext.
    $rawName = (string)($post['name'] ?? '');
    $cleanName = html_entity_decode(strip_tags($rawName),
                                    ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cleanName = trim(preg_replace('/\s+/u', ' ', $cleanName));

    return [
        'name'         => $cleanName,
        'sku'          => trim((string)($post['sku'] ?? '')),
        'gtin'         => trim((string)($post['gtin'] ?? '')),
        'slug'         => trim((string)($post['slug'] ?? '')),
        'description'  => trim((string)($post['description'] ?? '')),
        'short_desc'   => trim((string)($post['short_desc'] ?? '')),
        'price'        => (float)($post['price'] ?? 0),
        'sale_price'   => ($post['sale_price'] ?? '') !== '' ? (float)$post['sale_price'] : null,
        'stock_qty'    => (int)($post['stock_qty'] ?? 0),
        'manage_stock' => isset($post['manage_stock']) ? 1 : 0,
        'weight'       => ($post['weight'] ?? '') !== '' ? (float)$post['weight'] : null,
        'dimensions_l' => ($post['dimensions_l'] ?? '') !== '' ? (float)$post['dimensions_l'] : null,
        'dimensions_w' => ($post['dimensions_w'] ?? '') !== '' ? (float)$post['dimensions_w'] : null,
        'dimensions_h' => ($post['dimensions_h'] ?? '') !== '' ? (float)$post['dimensions_h'] : null,
        'category_id'  => (int)($post['category_id'] ?? 0) ?: null,
        'tags'         => trim((string)($post['tags'] ?? '')),
        'image_url'    => handleImageUpload($files, $post),
        'gallery_urls' => handleGalleryUpload($files, $post),
        'status'       => in_array($post['status'] ?? '', ['active', 'draft', 'archived'], true)
                          ? $post['status'] : 'draft',
        'featured'     => isset($post['featured']) ? 1 : 0,
    ];
}

function handleImageUpload(array $files, array $post): string {
    // Keep existing URL by default. The form may also carry a
    // 'picked_image_url' value from the media-library picker — if the
    // admin clicked "Pick from media library" instead of (or after)
    // choosing a file upload. Priority is:
    //   1. A new file upload via $_FILES (replaces everything)
    //   2. A picker-selected path (replaces existing)
    //   3. The existing path passed through as a hidden field
    $existing = trim((string)($post['existing_image_url'] ?? ''));
    $picked   = trim((string)($post['picked_image_url']   ?? ''));

    if (!empty($files['image']) && $files['image']['error'] === UPLOAD_ERR_OK) {
        try {
            $result = Uploads::handle('image', 'shop/products', [
                'allowed_mimes' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
                'max_bytes'    => 5 * 1024 * 1024,
            ]);
            if ($result['ok']) {
                // Also register in the media library cache if installed,
                // so the just-uploaded file appears in the picker
                // immediately on next open.
                if (class_exists('MediaLibrary')) {
                    $info = @getimagesize(SLATE_ROOT . $result['path']);
                    MediaLibrary::register($result['path'], [
                        'mime'          => $result['mime']     ?? '',
                        'size_bytes'    => $result['size']     ?? 0,
                        'width'         => is_array($info) ? ($info[0] ?? null) : null,
                        'height'        => is_array($info) ? ($info[1] ?? null) : null,
                        'original_name' => $result['original'] ?? '',
                    ]);
                }
                return $result['path'];
            }
        } catch (\Throwable $e) { /* fall through */ }
    }

    // Picker selection. Validate that the path is one the media library
    // would manage — refuses to accept arbitrary attacker-supplied
    // paths like /etc/passwd or ../../../config.php.
    if ($picked !== '' && class_exists('MediaLibrary') && MediaLibrary::isManagedPath($picked)) {
        return $picked;
    }

    return $existing;
}

/**
 * Handle multi-file gallery upload. Returns a JSON-encoded array of stored
 * paths.
 *
 * Inputs from the editor form:
 *   gallery_existing[]  — paths the editor is keeping (rendered as thumbs)
 *   gallery_remove[]    — paths to drop (checkbox per thumb)
 *   gallery_files[]     — NEW files being uploaded
 *
 * The result is { kept ∪ new } \ removed, stored as JSON in shop_products.gallery_urls.
 */
function handleGalleryUpload(array $files, array $post): string {
    $kept = [];

    // 1. Start with existing items the form is preserving.
    //    The media-library picker (when run in append mode) writes
    //    hidden inputs with the same gallery_existing[] name, so picked
    //    paths come through this branch automatically. We validate
    //    each path looks reasonable to defend against forged values.
    if (!empty($post['gallery_existing']) && is_array($post['gallery_existing'])) {
        $remove = array_flip(array_map('strval',
            (array)($post['gallery_remove'] ?? [])));
        foreach ($post['gallery_existing'] as $path) {
            $p = trim((string)$path);
            if ($p === '' || isset($remove[$p])) continue;
            // Anything outside /uploads/ is suspicious — drop it.
            // (Internal paths start with /uploads/, full URLs start
            // with http(s)://, both are fine; an attacker forging a
            // gallery_existing[] entry with /etc/passwd would NOT
            // start with either.)
            if (!str_starts_with($p, '/uploads/') && !preg_match('#^https?://#i', $p)) continue;
            $kept[] = $p;
        }
    }

    // 2. Handle the multi-file upload. PHP rearranges $_FILES for multi-files
    // into per-field arrays — we have to walk them by index.
    if (!empty($files['gallery_files']) && is_array($files['gallery_files']['name'] ?? null)) {
        $count = count($files['gallery_files']['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($files['gallery_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            // Build a synthetic single-file entry for Uploads::handle()
            $_FILES['__gallery_one'] = [
                'name'     => $files['gallery_files']['name'][$i],
                'type'     => $files['gallery_files']['type'][$i],
                'tmp_name' => $files['gallery_files']['tmp_name'][$i],
                'error'    => $files['gallery_files']['error'][$i],
                'size'     => $files['gallery_files']['size'][$i],
            ];
            try {
                $r = Uploads::handle('__gallery_one', 'shop/products/gallery', [
                    'allowed_mimes' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
                    'max_bytes'    => 5 * 1024 * 1024,
                ]);
                if (!empty($r['ok'])) {
                    $kept[] = $r['path'];
                    // Register in media-library cache if active.
                    if (class_exists('MediaLibrary')) {
                        $info = @getimagesize(SLATE_ROOT . $r['path']);
                        MediaLibrary::register($r['path'], [
                            'mime'          => $r['mime']     ?? '',
                            'size_bytes'    => $r['size']     ?? 0,
                            'width'         => is_array($info) ? ($info[0] ?? null) : null,
                            'height'        => is_array($info) ? ($info[1] ?? null) : null,
                            'original_name' => $r['original'] ?? '',
                        ]);
                    }
                }
            } catch (\Throwable $e) { /* skip bad files */ }
            unset($_FILES['__gallery_one']);
        }
    }

    return $kept ? json_encode(array_values($kept)) : '';
}

function productDbRow(array $data): array {
    // Derive slug if empty
    if (empty($data['slug']) && !empty($data['name'])) {
        $data['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['name']));
        $data['slug'] = trim($data['slug'], '-');
    }
    $row = [
        'name'         => $data['name'],
        'sku'          => $data['sku'],
        'gtin'         => $data['gtin'] ?? '',
        'slug'         => $data['slug'],
        'description'  => $data['description'],
        'short_desc'   => $data['short_desc'],
        'price'        => $data['price'],
        'sale_price'   => $data['sale_price'],
        'stock_qty'    => $data['stock_qty'],
        'manage_stock' => $data['manage_stock'],
        'weight'       => $data['weight'],
        'dimensions_l' => $data['dimensions_l'] ?? null,
        'dimensions_w' => $data['dimensions_w'] ?? null,
        'dimensions_h' => $data['dimensions_h'] ?? null,
        'category_id'  => $data['category_id'],
        'tags'         => $data['tags'],
        'image_url'    => $data['image_url'],
        'gallery_urls' => $data['gallery_urls'] ?? '',
        'status'       => $data['status'],
        'featured'     => $data['featured'],
    ];

    // Let plugins inject additional column values into the row. Each
    // callback receives the current $row plus the raw $data array (in
    // case it needs to derive its column from non-column fields like
    // submitted POST). Returned array replaces $row. Any keys for
    // columns that don't exist in shop_products will be stripped by
    // the column-filter step below, so plugins don't need to guard
    // against schema drift themselves.
    if (class_exists('Hook')) {
        $filtered = Hook::applyFilters('shop_product_db_row', $row, $data);
        if (is_array($filtered)) $row = $filtered;
    }

    // Drop columns that don't exist in the shop_products table (handles
    // the rare case where the migration's ALTER TABLE step was skipped
    // for permissions or other reasons). We re-fetch the column list
    // each call rather than caching, because the migration may complete
    // between page loads and we want saves to start using the new
    // columns immediately.
    static $existingCols = null;
    if ($existingCols === null) {
        try {
            $rows = Database::rows(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_products'");
            $existingCols = array_flip(array_column($rows, 'COLUMN_NAME'));
        } catch (\Throwable $e) {
            $existingCols = []; // worst case: empty -> filtering below removes all
        }
    }
    if (!empty($existingCols)) {
        foreach (array_keys($row) as $col) {
            if (!isset($existingCols[$col])) unset($row[$col]);
        }
    }
    return $row;
}

function validateProduct(array $d, int $excludeId): ?string {
    if ($d['name'] === '') return __('product_name_required', 'Product name is required.');
    if ($d['price'] < 0)   return __('product_price_invalid', 'Price must be ≥ 0.');
    if ($d['sale_price'] !== null && $d['sale_price'] < 0) {
        return __('sale_price_invalid', 'Sale price must be ≥ 0.');
    }
    // Slug uniqueness if provided
    if (!empty($d['slug'])) {
        $tid = current_tenant_id();
        $dup = Database::row(
            "SELECT id FROM shop_products WHERE tenant_id = ? AND slug = ? AND id != ?",
            [$tid, $d['slug'], $excludeId]
        );
        if ($dup) return __('slug_in_use', 'Another product has that slug.');
    }
    return null;
}

/**
 * Parse variation attribute/value pairs out of a single CSV row.
 *
 * Supports two formats:
 *
 *   1. WooCommerce native:
 *        "Attribute 1 name" = "Size"
 *        "Attribute 1 value(s)" = "Small | Medium | Large"
 *      (numbered 1..N for multiple attributes)
 *
 *   2. Scrape-style "extra_attributes" Python-dict literal:
 *        "{'Size': 'Small, Medium, Large', 'Color': 'Red, Blue'}"
 *      Single-quoted keys and values, comma-separated values inside.
 *
 * Returns a flat list of [{attribute, value}, ...] — one entry per
 * attribute-value combination. Multi-axis variations would need a full
 * cartesian product across attributes, which this importer doesn't
 * yet generate; callers get the values for the FIRST attribute only
 * when multiple are present, to keep the variant table sane.
 */
function parseRowVariations(array $r): array {
    // ── WC format ──
    // Look for any "Attribute N name" column with a matching value column
    $wcAttrs = [];
    foreach ($r as $col => $val) {
        if (!preg_match('/^attribute\s+(\d+)\s+name$/i', (string)$col, $m)) continue;
        $n = (int)$m[1];
        $attrName = trim((string)$val);
        if ($attrName === '') continue;
        // Find the matching value column
        $valuesRaw = '';
        foreach ($r as $vcol => $vval) {
            if (preg_match("/^attribute\\s+$n\\s+value\\(s\\)?$/i", (string)$vcol)) {
                $valuesRaw = trim((string)$vval);
                break;
            }
        }
        if ($valuesRaw === '') continue;
        $values = array_filter(array_map('trim', preg_split('/\s*\|\s*/', $valuesRaw)));
        if (!empty($values)) {
            $wcAttrs[$attrName] = $values;
        }
    }
    if (!empty($wcAttrs)) {
        // Use the first attribute only (single-axis)
        $firstAttr = array_key_first($wcAttrs);
        $out = [];
        foreach ($wcAttrs[$firstAttr] as $v) {
            $out[] = ['attribute' => $firstAttr, 'value' => $v];
        }
        return $out;
    }

    // ── extra_attributes Python-dict format ──
    $raw = '';
    foreach ($r as $col => $val) {
        if (preg_match('/^extra\s*[_ ]?attributes?$/i', (string)$col)) {
            $raw = trim((string)$val);
            break;
        }
    }
    if ($raw === '' || $raw === '{}') return [];

    // Extract 'key': 'value' pairs. Values can contain commas (they're the
    // delimiter for the variant values within), parens, and escaped quotes
    // ("FIRE!" → ""FIRE!""). We use a permissive regex:
    //   'KEY': 'VALUE'
    // where KEY has no single quotes and VALUE may have escaped doubled
    // quotes.
    $pairs = [];
    if (preg_match_all("/'([^']+)':\\s*'((?:[^']|'')*)'/", $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key   = trim($m[1]);
            $value = str_replace("''", "'", $m[2]);
            $pairs[$key] = $value;
        }
    }
    if (empty($pairs)) return [];

    // Find an attribute that LOOKS like a variation list — multiple values
    // separated by commas. Skip keys like "Weight" or "Dimensions" which
    // are scalar. Heuristic: any value with a comma is treated as a
    // variation list.
    foreach ($pairs as $attr => $valueStr) {
        // Skip the well-known scalar attributes we already capture elsewhere
        if (preg_match('/^(weight|dimensions?|size|brand)$/i', $attr)) continue;
        if (strpos($valueStr, ',') === false) continue;
        $values = array_filter(array_map('trim', explode(',', $valueStr)));
        $values = array_values(array_unique($values));
        if (count($values) < 2) continue;
        $out = [];
        foreach ($values as $v) {
            // Skip empty or junk values
            if ($v === '' || mb_strlen($v) > 100) continue;
            $out[] = ['attribute' => $attr, 'value' => $v];
        }
        if (!empty($out)) return $out;
    }
    return [];
}

function importProductsCsv(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) return ['ok' => false, 'error' => 'Cannot read uploaded file.'];

    // Encoding detection. WooCommerce exports are UTF-8 with BOM. Scrape-style
    // exports from older WordPress/PHP installs are often Windows-1252 (cp1252)
    // — they look like UTF-8 to the eye but have single-byte characters like
    // \x85 (ellipsis), \x91/\x92 (curly quotes), \x96 (en dash). Storing those
    // in a utf8mb4 column produces malformed text that breaks regex, HTML
    // entity encoding, and substring operations downstream.
    //
    // We test for UTF-8 validity and, if invalid, convert from Windows-1252.
    // BOM is stripped after conversion.
    if (!mb_check_encoding($raw, 'UTF-8')) {
        // Try Windows-1252 first (most common), fall back to ISO-8859-1
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($converted === false || !mb_check_encoding($converted, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }
        if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
            $raw = $converted;
        }
    }
    // Strip UTF-8 BOM if present
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    // Parse via str_getcsv per line. We can't use a stream because we already
    // have the converted text in memory; build an array of rows by splitting
    // on real line breaks (respecting RFC 4180 quoted-field newlines).
    $rows = [];
    $f = fopen('php://memory', 'r+');
    fwrite($f, $raw);
    rewind($f);

    $headers = fgetcsv($f);
    if (!$headers) {
        fclose($f);
        return ['ok' => false, 'error' => 'CSV is empty (no header row found).'];
    }

    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

    // Pre-flight: if the file has nothing but a header, tell the user.
    // We need to peek without consuming — but fgetcsv has no peek, so
    // we'll just let the loop run and catch zero-results at the end.

    $tid = current_tenant_id();
    $created = 0; $updated = 0; $skipped = 0; $errors = []; $variantsImported = 0;
    $rowNum = 1;

    // Helper to read a value from $r by trying multiple column-name aliases.
    // WooCommerce exports vary by version and locale.
    // Helper: case-insensitive column lookup that accepts both space and
    // underscore separators in the alias list and matches them against
    // either form in the CSV header. WooCommerce exports use "Regular price";
    // many other exporters use "regular_price". We normalize both sides to
    // a canonical "regular price" form before comparing.
    $norm = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[\s_]+/', ' ', $s);
        return $s;
    };
    $get = function(array $r, array $aliases, $default = '') use ($norm) {
        $normMap = [];
        foreach ($r as $k => $v) $normMap[$norm((string)$k)] = $v;
        foreach ($aliases as $a) {
            $na = $norm($a);
            if (isset($normMap[$na]) && $normMap[$na] !== '') return $normMap[$na];
        }
        return $default;
    };

    // Parse a price-like string into a float. Tolerant of currency symbols
    // ($, £, €, ¥, ₹), thousands separators, and trailing whitespace —
    // common in scrape-style exports. Defined at function scope so both
    // pass 1 (products) and pass 2 (variations) can use it.
    $parsePrice = function($v): ?float {
        if ($v === null || $v === '') return null;
        $s = (string)$v;
        $s = preg_replace('/[^\d.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.') return null;
        return is_numeric($s) ? (float)$s : null;
    };

    // ── Pre-pass: classify each row as product, variation, or skip ──
    //
    // Different WC exporter plugins use different conventions:
    //
    //   - WC native importer:    "Type" column = simple|variable|variation
    //   - Product CSV Import     post_title / post_parent fields; variations
    //     Suite (popular WP      go in a separate CSV linked via post_parent
    //     export plugin):        (numeric ID) or "Parent" (parent's title text)
    //
    // We detect both. A row is a VARIATION if any of:
    //   - "Type" column equals "variation"
    //   - "post_parent" column has a non-zero integer (links to parent ID)
    //   - "Parent" column has text AND row has meta:attribute_* fields
    //
    // Other special types (grouped, external) get skipped entirely.
    //
    // Parents go in pass 1, variations in pass 2 so we can resolve their
    // parent_id reliably (the parent must exist before we can link).

    $allRows = [];
    while (($row = fgetcsv($f)) !== false) {
        $rowNum++;
        $nonEmpty = array_filter($row, fn($v) => trim((string)$v) !== '');
        if (empty($nonEmpty)) { continue; }
        $r = array_combine(
            array_slice($headers, 0, count($row)),
            array_slice($row, 0, count($headers))
        );
        $allRows[] = ['n' => $rowNum, 'r' => $r];
    }
    fclose($f);

    // Classify
    $productRows  = [];
    $variantRows  = [];
    foreach ($allRows as $entry) {
        $r = $entry['r'];
        $type = strtolower(trim((string)($r['type'] ?? '')));
        if (in_array($type, ['grouped', 'external'], true)) {
            $skipped++;
            continue;
        }
        $isVariation = false;
        if ($type === 'variation') {
            $isVariation = true;
        } else {
            // Check the "Product CSV Import Suite" format
            $pp = trim((string)($r['post_parent'] ?? ''));
            if ($pp !== '' && $pp !== '0' && ctype_digit(ltrim($pp, '-'))) {
                $isVariation = true;
            } elseif (trim((string)($r['parent'] ?? '')) !== '') {
                // "Parent" text column + any meta:attribute_* field signals
                // a variation row (header has been lowercased by this point)
                foreach ($r as $k => $v) {
                    if (preg_match('/^meta:attribute[_-]/i', (string)$k)) {
                        $isVariation = true;
                        break;
                    }
                }
            }
        }
        if ($isVariation) {
            $variantRows[] = $entry;
        } else {
            $productRows[] = $entry;
        }
    }

    // Map from parent identifier → product_id (built in pass 1, consumed in
    // pass 2). Keys: the WC post_id (string of digits) AND the post_name/slug
    // AND the post_title text. Variation rows can reference any of these.
    $parentIdMap = [];

    // ── PASS 1: parent products ──
    foreach ($productRows as $entry) {
        $rowNum = $entry['n'];
        $r      = $entry['r'];

        $name = trim((string)$get($r, ['name', 'title', 'post_title']));
        // Strip any HTML tags from the name. CSVs from sources like
        // WooCommerce exports sometimes have <b>, <strong>, <em>, etc.
        // baked into the product title for visual emphasis. The cart
        // and product pages HTML-escape names (defensively, to prevent
        // XSS), so unstripped tags display as literal "<b>FOO</b>"
        // text. Names are plaintext by contract; descriptions are
        // where rich text belongs.
        if ($name !== '') {
            // strip_tags removes HTML markup; html_entity_decode then
            // un-escapes any leftover entities (e.g. &amp; → &) so the
            // name reads cleanly.
            $name = html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Collapse runs of whitespace (incl. NBSP from word-wrap)
            // to single spaces, then trim.
            $name = trim(preg_replace('/\s+/u', ' ', $name));
        }
        if ($name === '') {
            $errors[] = "Row $rowNum: missing name, skipped.";
            continue;
        }

        $sku   = trim((string)$get($r, ['sku']));
        $slug  = trim((string)$get($r, ['slug', 'permalink', 'post_name']))
              ?: trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
        if ($slug === '') {
            // Pure-non-alphanumeric name — fall back to row position
            $slug = 'product-' . $rowNum;
        }

        $priceVal = $get($r, ['regular price', 'price', 'regular_price'], '');
        $price = $parsePrice($priceVal) ?? 0.0;

        $saleVal = $get($r, ['sale price', 'sale_price'], '');
        $salePrice = $parsePrice($saleVal);

        // Status: WooCommerce uses Published=1 for active, 0 for draft, -1 for trash.
        // Product CSV Import Suite uses post_status with values like
        // "publish", "draft", "private", "trash". We map them.
        // Scrape-style exports may have no Published column at all, just a
        // stock_status text field — in that case we infer "In Stock" rows as
        // active so the imported products actually appear on the storefront.
        $pub = (string)$get($r, ['published', 'post_status'], '');
        if ($pub !== '') {
            $lc = strtolower($pub);
            if ($pub === '1' || $lc === 'yes' || $lc === 'true' || $lc === 'publish' || $lc === 'published') {
                $status = 'active';
            } elseif ($pub === '-1' || $lc === 'trash' || $lc === 'archived') {
                $status = 'archived';
            } else {
                $status = 'draft';
            }
        } else {
            // No Published column. Default to active so customers see the products
            // immediately; the admin can switch any to draft afterward in bulk.
            $status = 'active';
        }

        // Featured: WooCommerce export uses "Is featured?", custom exports use "Featured".
        $feat = (string)$get($r, ['is featured?', 'featured'], '0');
        $featured = ($feat === '1' || strtolower($feat) === 'yes' || strtolower($feat) === 'true') ? 1 : 0;

        $stockQty = (int)$get($r, ['stock'], 0);

        // Manage stock: WC encodes this as "In stock?" + presence of Stock value.
        // If there's a Stock number, treat as managed. If "In stock?" alone is set
        // without a Stock value, treat as unmanaged (always available).
        // Scrape-style exports also commonly have "stock_status: In Stock / Out
        // of Stock" — we honor that as a fallback.
        $inStock = (string)$get($r, ['in stock?', 'in stock'], '');
        $stockRaw = (string)$get($r, ['stock'], '');
        $manageStk = $stockRaw !== '' ? 1 : 0;

        $stockStatus = strtolower(trim((string)$get($r, ['stock status', 'stock_status'], '')));
        if ($stockStatus !== '' && $stockRaw === '') {
            // No numeric stock value, but a stock-status string is present.
            // Don't force manage_stock on. Just let status reflect availability:
            // "out of stock" rows come in as drafts so they aren't sold.
            if (strpos($stockStatus, 'out') !== false) {
                // We override the published-derived status only if it would
                // have been "active" — don't promote drafts to active.
                if (isset($status) && $status === 'active') $status = 'draft';
            }
        }

        // Weight: WC uses "Weight (oz)" or "Weight (kg)". Scrape-style
        // exports may have plain "weight" with a unit suffix in the value
        // ("16.1 oz"). We strip non-numeric chars and take the first number.
        $weight = null;
        $parseNumeric = function($v): ?float {
            if ($v === null || $v === '') return null;
            // Strip everything that isn't a digit, dot, or minus (covers unit
            // suffixes, currency symbols, etc).
            if (preg_match('/-?\d+(\.\d+)?/', (string)$v, $m)) {
                return (float)$m[0];
            }
            return null;
        };
        $weightRaw = $get($r, ['weight'], '');
        if ($weightRaw !== '') {
            $weight = $parseNumeric($weightRaw);
        } else {
            // Try unit-suffixed columns: "Weight (oz)", "Weight (kg)"
            foreach ($r as $col => $val) {
                if ($val === '' || $weight !== null) continue;
                if (preg_match('/^weight(\s*\([^)]+\))?$/i', $col)) {
                    $weight = $parseNumeric($val);
                }
            }
        }

        // Dimensions: WooCommerce exports "Length (cm/in)", "Width (cm/in)",
        // "Height (cm/in)" as separate columns. Scrape-style exports often
        // pack all three into a single "dimensions" column like "16 × 10 × 4 in"
        // (using × U+00D7 or x). We support both formats.
        $dimL = $parseNumeric($get($r, ['length'], ''));
        $dimW = $parseNumeric($get($r, ['width'], ''));
        $dimH = $parseNumeric($get($r, ['height'], ''));
        if ($dimL === null && $dimW === null && $dimH === null) {
            $dimRaw = (string)$get($r, ['dimensions'], '');
            if ($dimRaw !== '') {
                // Split on × (U+00D7), x, X, * — take the first three numbers
                $parts = preg_split('/\s*[×xX*]\s*/u', $dimRaw);
                if (is_array($parts) && count($parts) >= 3) {
                    $dimL = $parseNumeric($parts[0]);
                    $dimW = $parseNumeric($parts[1]);
                    $dimH = $parseNumeric($parts[2]);
                }
            }
        }
        // Also attempt unit-suffixed columns like "Length (in)"
        if ($dimL === null) {
            foreach ($r as $col => $val) {
                if ($val === '' || $dimL !== null) continue;
                if (preg_match('/^length(\s*\([^)]+\))?$/i', $col)) {
                    $dimL = $parseNumeric($val);
                }
            }
        }
        if ($dimW === null) {
            foreach ($r as $col => $val) {
                if ($val === '' || $dimW !== null) continue;
                if (preg_match('/^width(\s*\([^)]+\))?$/i', $col)) {
                    $dimW = $parseNumeric($val);
                }
            }
        }
        if ($dimH === null) {
            foreach ($r as $col => $val) {
                if ($val === '' || $dimH !== null) continue;
                if (preg_match('/^height(\s*\([^)]+\))?$/i', $col)) {
                    $dimH = $parseNumeric($val);
                }
            }
        }

        // GTIN / UPC / EAN / ISBN — same field, different names
        $gtin = trim((string)$get($r, ['gtin', 'upc', 'ean', 'isbn',
                                       'barcode', 'gtin upc ean or isbn'], ''));

        // Description handling:
        //
        // The DB has short_desc (VARCHAR 500) for the storefront tagline and
        // description (TEXT, ~64KB) for the full body. Scrape-style exports
        // often pack a full marketing paragraph into "short_description" and
        // leave "description" identical or empty. We rescue these by demoting
        // overlong short-desc content to the full description field and
        // excerpting the first ~480 chars as the short_desc.
        $shortDesc = (string)$get($r, ['short description', 'short_description', 'post_excerpt'], '');
        $description = (string)$get($r, ['description', 'post_content'], '');

        // mb_strlen so we count Unicode characters, not bytes
        if (mb_strlen($shortDesc) > 500) {
            // Prefer the longer of the existing description vs the overlong
            // short_desc as the full description body. Scrape-style exports
            // often have short_desc = full body, description = short stub —
            // we don't want to lose the long content.
            if (mb_strlen($shortDesc) > mb_strlen($description)) {
                $description = $shortDesc;
            }
            // Excerpt: take 480 chars, then back up to the last sentence break
            // or word boundary for a clean cut. Append a single ellipsis.
            $excerpt = mb_substr($shortDesc, 0, 480);
            // Prefer sentence break, then word break
            $lastDot = max(
                mb_strrpos($excerpt, '. '),
                mb_strrpos($excerpt, '! '),
                mb_strrpos($excerpt, '? ')
            );
            if ($lastDot !== false && $lastDot > 200) {
                $excerpt = mb_substr($excerpt, 0, $lastDot + 1);
            } else {
                $lastSpace = mb_strrpos($excerpt, ' ');
                if ($lastSpace !== false && $lastSpace > 300) {
                    $excerpt = mb_substr($excerpt, 0, $lastSpace);
                }
            }
            $shortDesc = rtrim($excerpt) . '…';
        }

        $tags  = (string)$get($r, ['tags'], '');

        // Images: "Images" can be a comma-separated list of URLs. Some scrape
        // Images: scrape-style exports often have BOTH "main_image" (single
        // URL) AND "images" (list, pipe- or comma-separated). WC just has
        // "Images". We try main_image first for the primary, then take the
        // remaining URLs as gallery.
        $primaryRaw  = (string)$get($r, ['main image', 'main_image'], '');
        $multiRaw    = (string)$get($r, ['images', 'image'], '');
        $image = '';
        $galleryImages = [];

        // Helper to split either format
        $splitImages = function(string $raw): array {
            if ($raw === '') return [];
            if (strpos($raw, '|') !== false) {
                $parts = preg_split('/\s*\|\s*/', $raw);
            } else {
                $parts = explode(',', $raw);
            }
            return array_values(array_filter(array_map('trim', $parts), fn($u) => $u !== ''));
        };

        $primaryList = $splitImages($primaryRaw);
        $multiList   = $splitImages($multiRaw);

        if (!empty($primaryList)) {
            // main_image column present: take its first URL as the main image,
            // and use everything else (from BOTH columns) as gallery.
            $image = $primaryList[0];
            $rest  = array_merge(array_slice($primaryList, 1), $multiList);
            // De-dupe (some exports list the main image in both columns)
            foreach ($rest as $u) {
                if ($u !== $image && !in_array($u, $galleryImages, true)) {
                    $galleryImages[] = $u;
                }
            }
        } elseif (!empty($multiList)) {
            // Only images column: first is main, rest is gallery
            $image = $multiList[0];
            $galleryImages = array_slice($multiList, 1);
        }

        // Cap URLs at the column limit
        if (mb_strlen($image) > 500) {
            $image = mb_substr($image, 0, 500);
        }
        foreach ($galleryImages as $idx => $g) {
            if (mb_strlen($g) > 500) {
                $galleryImages[$idx] = mb_substr($g, 0, 500);
            }
        }
        $galleryJson = $galleryImages ? json_encode(array_values($galleryImages)) : '';

        // Category: WC ships categories as "Parent > Child, OtherTop > OtherChild"
        // We take the first chunk before any comma, then drop any "Parent > " prefix.
        $categoryId = null;
        $catRaw = trim((string)$get($r, ['categories', 'category'], ''));
        if ($catRaw !== '') {
            $firstCat = trim(explode(',', $catRaw)[0]);
            // Drop "Parent > " prefix; keep the leaf
            if (strpos($firstCat, '>') !== false) {
                $parts = array_map('trim', explode('>', $firstCat));
                $firstCat = end($parts);
            }
            if ($firstCat !== '') {
                $catSlug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $firstCat)), '-');
                $existing = Database::row(
                    "SELECT id FROM shop_categories WHERE tenant_id = ? AND slug = ?",
                    [$tid, $catSlug]
                );
                if ($existing) {
                    $categoryId = (int)$existing['id'];
                } else {
                    $categoryId = Database::insert('shop_categories', [
                        'tenant_id' => $tid, 'slug' => $catSlug, 'name' => $firstCat,
                    ]);
                }
            }
        }

        // ── Match existing product (by SKU first, then slug) ──
        $existingId = null;
        $existingRow = null;
        if ($sku !== '') {
            $existing = Database::row(
                "SELECT id, sku FROM shop_products WHERE tenant_id = ? AND sku = ?",
                [$tid, $sku]);
            if ($existing) {
                $existingId = (int)$existing['id'];
                $existingRow = $existing;
            }
        }
        if ($existingId === null && $slug !== '') {
            $existing = Database::row(
                "SELECT id, sku FROM shop_products WHERE tenant_id = ? AND slug = ?",
                [$tid, $slug]);
            if ($existing) {
                $existingId = (int)$existing['id'];
                $existingRow = $existing;
            }
        }

        // ── Resolve SKU and slug ──
        //
        // CREATING NEW: if {slug} is taken by another product, suffix with
        // -2, -3, ...; if the CSV row had no SKU, generate "auto-{slug}".
        //
        // UPDATING EXISTING: if the CSV row has empty SKU but the existing
        // product has a SKU, preserve it. Otherwise multiple update rows
        // would all set sku='' and collide on the (tenant_id, sku) UNIQUE
        // index. This is the #1 cause of "Duplicate entry '1-'" errors on
        // a SECOND import of the same SKU-less CSV.
        if ($existingId === null) {
            $baseSlug = $slug;
            $n = 2;
            while (Database::value(
                "SELECT id FROM shop_products WHERE tenant_id = ? AND slug = ?",
                [$tid, $slug]
            )) {
                $slug = $baseSlug . '-' . $n++;
                if ($n > 999) break; // sanity
            }
            if ($sku === '') {
                $sku = 'auto-' . $slug;
                // The auto-{slug} pattern should be unique since slug now is,
                // but defensively check anyway — a previous import may have
                // generated the same auto-SKU under a different slug.
                $baseSku = $sku;
                $n = 2;
                while (Database::value(
                    "SELECT id FROM shop_products WHERE tenant_id = ? AND sku = ?",
                    [$tid, $sku]
                )) {
                    $sku = $baseSku . '-' . $n++;
                    if ($n > 999) break;
                }
            }
        } else {
            // UPDATE path: preserve the existing SKU if the CSV row didn't
            // provide one. Without this, repeated imports of SKU-less CSVs
            // try to write sku='' on every row and collide.
            if ($sku === '' && !empty($existingRow['sku'])) {
                $sku = (string)$existingRow['sku'];
            }
            // If even the existing row has no SKU (shouldn't happen after a
            // v1.2.0 import, but possible for products created manually
            // before this fix existed), generate one now so the update is
            // safe.
            if ($sku === '') {
                $sku = 'auto-' . $slug;
                $baseSku = $sku;
                $n = 2;
                while (Database::value(
                    "SELECT id FROM shop_products
                      WHERE tenant_id = ? AND sku = ? AND id != ?",
                    [$tid, $sku, $existingId]
                )) {
                    $sku = $baseSku . '-' . $n++;
                    if ($n > 999) break;
                }
            }
        }

        // Safety net: cap remaining VARCHAR fields at their column lengths so
        // a single oversized value never rejects an entire row. Long content
        // is preserved in the TEXT description column where applicable
        // (handled above).
        if (mb_strlen($name) > 255) $name = mb_substr($name, 0, 255);
        if (mb_strlen($tags) > 500) $tags = mb_substr($tags, 0, 500);
        if (mb_strlen($slug) > 255) $slug = mb_substr($slug, 0, 255);
        if (mb_strlen($sku)  > 100) $sku  = mb_substr($sku,  0, 100);
        if (mb_strlen($gtin) > 50)  $gtin = mb_substr($gtin, 0, 50);

        $payload = [
            'name' => $name, 'sku' => $sku, 'gtin' => $gtin, 'slug' => $slug,
            'description' => $description, 'short_desc' => $shortDesc,
            'price' => $price, 'sale_price' => $salePrice,
            'stock_qty' => $stockQty, 'manage_stock' => $manageStk,
            'weight' => $weight,
            'dimensions_l' => $dimL,
            'dimensions_w' => $dimW,
            'dimensions_h' => $dimH,
            'category_id' => $categoryId,
            'tags' => $tags, 'image_url' => $image, 'gallery_urls' => $galleryJson,
            'status' => $status, 'featured' => $featured,
        ];

        // Drop columns the schema doesn't have (defensive — same as productDbRow)
        static $importCols = null;
        if ($importCols === null) {
            try {
                $rows = Database::rows(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_products'");
                $importCols = array_flip(array_column($rows, 'COLUMN_NAME'));
            } catch (\Throwable $e) {
                $importCols = [];
            }
        }
        if (!empty($importCols)) {
            foreach (array_keys($payload) as $col) {
                if (!isset($importCols[$col])) unset($payload[$col]);
            }
        }

        try {
            if ($existingId) {
                Database::update('shop_products', $payload, 'id = ?', [$existingId]);
                $updated++;
                $productId = $existingId;
            } else {
                $productId = Database::insert('shop_products', array_merge(['tenant_id' => $tid], $payload));
                $created++;
            }

            // Record this parent in the lookup map so variation rows in
            // pass 2 can find it. We index by WC post ID, slug, post_title,
            // and SKU so any of them can be used as the parent reference.
            $wcId    = trim((string)($r['id'] ?? ''));
            $wcTitle = trim((string)$get($r, ['name', 'title', 'post_title']));
            if ($wcId !== '')    $parentIdMap['id:'    . $wcId]    = $productId;
            if ($slug !== '')    $parentIdMap['slug:'  . $slug]    = $productId;
            if ($wcTitle !== '') $parentIdMap['title:' . $wcTitle] = $productId;
            if ($sku !== '')     $parentIdMap['sku:'   . $sku]     = $productId;

            // ── In-row variation parsing (legacy gourmetmenow scrape format) ──
            //
            // Scrape-style CSVs put variation data in an "extra_attributes"
            // column as a Python-style dict literal:
            //   {'Choose Your Flavor': 'Vanilla, Chocolate, ...'}
            //
            // WC native CSVs use "Attribute 1 name" / "Attribute 1 value(s)"
            // pairs in the parent row, with values pipe-separated.
            //
            // The newer Product CSV Import Suite format puts variations in
            // SEPARATE rows with post_parent links — those are handled in
            // pass 2 below, not here.
            $inRowVariants = parseRowVariations($r);
            if (!empty($inRowVariants) && $productId) {
                foreach ($inRowVariants as $vr) {
                    try {
                        // Generate a variant SKU based on the product SKU + value
                        $vSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $vr['value']));
                        $vSlug = trim($vSlug, '-');
                        $vSku  = $sku . '-' . $vSlug;
                        if (mb_strlen($vSku) > 100) $vSku = mb_substr($vSku, 0, 100);

                        // Idempotency: variants table has UNIQUE (product_id,
                        // attribute, value). If it already exists, skip.
                        $exists = Database::value(
                            "SELECT id FROM shop_product_variants
                              WHERE product_id = ? AND attribute = ? AND value = ?",
                            [$productId, $vr['attribute'], $vr['value']]);
                        if (!$exists) {
                            Database::insert('shop_product_variants', [
                                'product_id' => $productId,
                                'attribute'  => $vr['attribute'],
                                'value'      => $vr['value'],
                                'sku'        => $vSku,
                                'price_diff' => 0,
                                'stock_qty'  => 0,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        // Don't fail the product import over one bad variant
                    }
                }
            }
        } catch (\Throwable $e) {
            $errors[] = "Row $rowNum ($name): " . $e->getMessage();
        }
    }
    // Note: $f was already closed after collecting allRows in the pre-pass.

    // ── PASS 2: variations (Product CSV Import Suite format) ──
    //
    // Each row in $variantRows is a variation linked to its parent product
    // by either post_parent (WC numeric ID) or Parent (parent's post_title).
    // We look up the parent in $parentIdMap (populated during pass 1) and
    // insert a row in shop_product_variants. The attribute name comes from
    // any column named "meta:attribute_*" or "attribute *" — these are the
    // per-variation attribute values.
    foreach ($variantRows as $entry) {
        $rowNum = $entry['n'];
        $r      = $entry['r'];

        // Resolve parent. Try post_parent (numeric WC ID) first, then the
        // textual Parent column, then parent_sku. If not found in the
        // in-memory map (built during this import), query the DB —
        // necessary when variations are imported in a SEPARATE CSV after
        // the products CSV is already in the DB.
        $productId = null;

        $pp = trim((string)($r['post_parent'] ?? ''));
        if ($pp !== '' && isset($parentIdMap['id:' . $pp])) {
            $productId = $parentIdMap['id:' . $pp];
        }
        if ($productId === null) {
            $parentTitle = trim((string)($r['parent'] ?? ''));
            if ($parentTitle !== '') {
                if (isset($parentIdMap['title:' . $parentTitle])) {
                    $productId = $parentIdMap['title:' . $parentTitle];
                } else {
                    // DB lookup by title — match exact name
                    $hit = Database::value(
                        "SELECT id FROM shop_products WHERE tenant_id = ? AND name = ?",
                        [$tid, $parentTitle]);
                    if ($hit) $productId = (int)$hit;
                }
            }
        }
        if ($productId === null) {
            $parentSku = trim((string)($r['parent_sku'] ?? ''));
            if ($parentSku !== '') {
                if (isset($parentIdMap['sku:' . $parentSku])) {
                    $productId = $parentIdMap['sku:' . $parentSku];
                } else {
                    $hit = Database::value(
                        "SELECT id FROM shop_products WHERE tenant_id = ? AND sku = ?",
                        [$tid, $parentSku]);
                    if ($hit) $productId = (int)$hit;
                }
            }
        }
        if ($productId === null) {
            $errors[] = "Row $rowNum: variation row, but parent product not found "
                      . "(post_parent='$pp', Parent='"
                      . substr((string)($r['parent'] ?? ''), 0, 40) . "...'). Skipped.";
            continue;
        }

        // Extract the attribute(s) — look for any meta:attribute_*, attribute *,
        // or pa_* column with a non-empty value.
        $attribute = '';
        $value     = '';
        foreach ($r as $col => $val) {
            $val = trim((string)$val);
            if ($val === '') continue;
            $col = (string)$col;
            // meta:attribute_choose-your-flavor → Choose Your Flavor
            if (preg_match('/^meta:attribute[_-](.+)$/i', $col, $m)) {
                $attribute = ucwords(str_replace(['-', '_'], ' ', $m[1]));
                $value     = $val;
                break;
            }
            if (preg_match('/^attribute\s+\d+\s+value\(s\)?$/i', $col)) {
                $value = $val;
                // The attribute name is in the matching "name" column
                if (preg_match('/^attribute\s+(\d+)\s+/i', $col, $nm)) {
                    $nameCol = "attribute {$nm[1]} name";
                    foreach ($r as $k2 => $v2) {
                        if (strcasecmp((string)$k2, $nameCol) === 0) {
                            $attribute = trim((string)$v2);
                            break;
                        }
                    }
                }
                if ($attribute !== '' && $value !== '') break;
            }
            if (preg_match('/^pa_(.+)$/i', $col, $m)) {
                $attribute = ucwords(str_replace(['-', '_'], ' ', $m[1]));
                $value     = $val;
                break;
            }
        }
        if ($attribute === '' || $value === '') {
            $errors[] = "Row $rowNum: variation row with no recognizable attribute. Skipped.";
            continue;
        }

        // Compute price_diff from variation's regular_price - parent's price
        $vPrice = $parsePrice($get($r, ['regular price', 'price', 'regular_price'], '')) ?? 0.0;
        $parent = Database::row("SELECT price FROM shop_products WHERE id = ?", [$productId]);
        $priceDiff = 0.0;
        if ($parent && $vPrice > 0) {
            $priceDiff = $vPrice - (float)$parent['price'];
        }

        $vStock = (int)($parsePrice($get($r, ['stock'], '')) ?? 0);

        // Build a SKU: variation's own sku if provided, else parent_sku + value slug
        $vSku = trim((string)($r['sku'] ?? ''));
        if ($vSku === '') {
            $parentSku = Database::value("SELECT sku FROM shop_products WHERE id = ?", [$productId]) ?: 'var';
            $vSlug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value)), '-');
            $vSku  = $parentSku . '-' . $vSlug;
        }
        if (mb_strlen($vSku) > 100) $vSku = mb_substr($vSku, 0, 100);

        try {
            // Idempotent — only insert if (product_id, attribute, value) is new
            $exists = Database::value(
                "SELECT id FROM shop_product_variants
                  WHERE product_id = ? AND attribute = ? AND value = ?",
                [$productId, $attribute, $value]);
            if ($exists) {
                Database::update('shop_product_variants', [
                    'sku'        => $vSku,
                    'price_diff' => $priceDiff,
                    'stock_qty'  => $vStock,
                ], 'id = ?', [(int)$exists]);
            } else {
                Database::insert('shop_product_variants', [
                    'product_id' => $productId,
                    'attribute'  => $attribute,
                    'value'      => $value,
                    'sku'        => $vSku,
                    'price_diff' => $priceDiff,
                    'stock_qty'  => $vStock,
                ]);
            }
            $variantsImported++;
        } catch (\Throwable $e) {
            $errors[] = "Row $rowNum (variation $value): " . $e->getMessage();
        }
    }

    // Friendly message when the file has only a header
    if ($created === 0 && $updated === 0 && $skipped === 0 && $variantsImported === 0 && empty($errors)) {
        return ['ok' => false,
                'error' => 'The CSV had a header row but no product data. '
                         . 'Make sure you selected products to export before downloading.'];
    }

    return [
        'ok' => true,
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'variants_imported' => $variantsImported,
        'errors'  => $errors,
    ];
}

require $root . '/admin/partials/header.php';

// Load categories for selects
$allCategories = Database::rows(
    "SELECT id, name FROM shop_categories WHERE tenant_id = ? ORDER BY name ASC", [$tid]);

slate_breadcrumbs($mode === 'edit'
    ? [['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
       ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/index.php')],
       ['label' => __('shop_products', 'Products'), 'href' => plugin_url('shop', 'admin/products.php')],
       ['label' => ((int)($editing['id'] ?? 0)) > 0 ? ($editing['name'] ?: 'Edit') : 'New product']]
    : ($mode === 'import'
        ? [['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
           ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/index.php')],
           ['label' => __('shop_products', 'Products'), 'href' => plugin_url('shop', 'admin/products.php')],
           ['label' => __('import_csv', 'Import CSV')]]
        : [['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
           ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/index.php')],
           ['label' => __('shop_products', 'Products')]])
);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if (!empty($GLOBALS['_csv_errors'])): ?>
<div class="card">
    <div class="card-header"><h2 class="text-danger">CSV import errors</h2></div>
    <ul style="margin:0; padding-left:1.25rem;">
        <?php foreach ($GLOBALS['_csv_errors'] as $err): ?>
            <li class="text-sm text-danger"><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php // ════════════════════════ EDIT MODE ════════════════════════ ?>
<?php if ($mode === 'edit'):
    $isNew = ((int)($editing['id'] ?? 0)) === 0;
    $variants = !$isNew ? Database::rows(
        "SELECT * FROM shop_product_variants WHERE product_id = ? ORDER BY display_order, id",
        [(int)$editing['id']]) : [];

    // Media library picker: load assets if the plugin is installed.
    // The picker self-wires via data-mlp-target attributes on buttons,
    // so we only need to enqueue the script + style; no further glue.
    $mediaLibraryActive = class_exists('MediaLibrary')
        && PluginLoader::isActive('media-library');
?>
    <?php if ($mediaLibraryActive): ?>
        <link rel="stylesheet" href="<?= e(plugin_url('media-library', 'assets/css/picker.css')) ?>">
        <script src="<?= e(plugin_url('media-library', 'assets/js/picker.js')) ?>"></script>
    <?php endif; ?>
    <div class="page-header">
        <div>
            <h1><?= $isNew ? __('new_product', 'New product')
                           : e($editing['name'] ?: __('edit_product', 'Edit product')) ?></h1>
            <p class="page-header-sub">
                <?php if (!$isNew && !empty($editing['slug'])): ?>
                    <a href="<?= e(SLATE_URL) ?>/shop/product/<?= e($editing['slug']) ?>" target="_blank">
                        View on storefront ↗
                    </a>
                <?php else: ?>
                    <?= __('product_edit_sub', 'Create a product to sell in your shop.') ?>
                <?php endif; ?>
            </p>
        </div>
        <a href="<?= e(plugin_url('shop', 'admin/products.php')) ?>" class="btn btn-ghost">
            ← <?= __('back_to_list', 'Back to list') ?>
        </a>
    </div>

    <form method="post" enctype="multipart/form-data" id="product-form">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $isNew ? 'create' : 'update' ?>">
        <?php if (!$isNew): ?>
            <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
        <?php endif; ?>
        <input type="hidden" name="existing_image_url" value="<?= e($editing['image_url'] ?? '') ?>">

        <!-- WooCommerce-style 2-column layout -->
        <div class="flex gap-2" style="flex-wrap:wrap; align-items:flex-start;">

            <!-- LEFT: main fields -->
            <div style="flex:2; min-width:320px;">

                <div class="card">
                    <div class="field">
                        <label class="field-label" for="name">
                            <?= __('product_name', 'Product name') ?> <span class="field-required">*</span>
                        </label>
                        <input type="text" id="name" name="name" required maxlength="255"
                               value="<?= e($editing['name'] ?? '') ?>">
                    </div>
                    <div class="field-row field-row-2">
                        <div class="field">
                            <label class="field-label" for="sku"><?= __('sku', 'SKU') ?></label>
                            <input type="text" id="sku" name="sku" maxlength="100"
                                   value="<?= e($editing['sku'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label class="field-label" for="slug">
                                <?= __('slug', 'URL slug') ?>
                            </label>
                            <input type="text" id="slug" name="slug" maxlength="255"
                                   value="<?= e($editing['slug'] ?? '') ?>"
                                   placeholder="auto-generated from name">
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="gtin">
                            <?= __('gtin', 'GTIN, UPC, EAN, or ISBN') ?>
                        </label>
                        <input type="text" id="gtin" name="gtin" maxlength="50"
                               value="<?= e($editing['gtin'] ?? '') ?>"
                               placeholder="<?= __('gtin_placeholder', 'Optional barcode identifier') ?>">
                        <div class="field-hint"><?= __('gtin_hint',
                            'Used by some sales channels (Google Shopping, Amazon) for product matching.') ?></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="short_desc"><?= __('short_desc', 'Short description') ?></label>
                        <textarea id="short_desc" name="short_desc" rows="2"
                                  maxlength="500"><?= e($editing['short_desc'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label class="field-label" for="description"><?= __('description', 'Description') ?></label>
                        <textarea id="description" name="description"
                                  rows="6"><?= e($editing['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Pricing -->
                <div class="card">
                    <div class="card-header"><h2><?= __('pricing', 'Pricing') ?></h2></div>
                    <div class="field-row field-row-2">
                        <div class="field">
                            <label class="field-label" for="price">
                                <?= __('regular_price', 'Regular price') ?>
                                (<?= e($currency) ?>) <span class="field-required">*</span>
                            </label>
                            <input type="number" id="price" name="price" step="0.01" min="0" required
                                   value="<?= e((string)($editing['price'] ?? '')) ?>">
                        </div>
                        <div class="field">
                            <label class="field-label" for="sale_price">
                                <?= __('sale_price', 'Sale price') ?> (<?= e($currency) ?>)
                            </label>
                            <input type="number" id="sale_price" name="sale_price" step="0.01" min="0"
                                   value="<?= e((string)($editing['sale_price'] ?? '')) ?>">
                        </div>
                    </div>
                </div>

                <!-- Inventory -->
                <div class="card">
                    <div class="card-header"><h2><?= __('inventory', 'Inventory') ?></h2></div>
                    <div class="field">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="manage_stock" value="1"
                                style="width:18px;height:18px;accent-color:var(--accent)"
                                <?= !empty($editing['manage_stock']) ? 'checked' : '' ?>>
                            <?= __('manage_stock', 'Track stock for this product') ?>
                        </label>
                    </div>
                    <div class="field">
                        <label class="field-label" for="stock_qty"><?= __('stock_qty', 'Stock quantity') ?></label>
                        <input type="number" id="stock_qty" name="stock_qty" step="1" min="0"
                               value="<?= (int)($editing['stock_qty'] ?? 0) ?>">
                        <div class="field-hint"><?= __('stock_qty_hint',
                            'Ignored when stock tracking is off — the product is then assumed to be always in stock.') ?></div>
                    </div>
                </div>

                <!-- Shipping & dimensions -->
                <div class="card">
                    <div class="card-header"><h2><?= __('shipping', 'Shipping & dimensions') ?></h2></div>
                    <div class="field">
                        <label class="field-label" for="weight">
                            <?= __('weight', 'Weight') ?> (<?= e(Database::setting('weight_unit') ?: 'oz') ?>)
                        </label>
                        <input type="number" id="weight" name="weight" step="0.01" min="0"
                               value="<?= e((string)($editing['weight'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label">
                            <?= __('dimensions', 'Dimensions') ?> (L × W × H) (<?= e(Database::setting('dimension_unit') ?: 'in') ?>)
                        </label>
                        <div class="field-row field-row-3">
                            <input type="number" id="dimensions_l" name="dimensions_l" step="0.01" min="0"
                                   value="<?= e((string)($editing['dimensions_l'] ?? '')) ?>"
                                   placeholder="L" aria-label="Length">
                            <input type="number" id="dimensions_w" name="dimensions_w" step="0.01" min="0"
                                   value="<?= e((string)($editing['dimensions_w'] ?? '')) ?>"
                                   placeholder="W" aria-label="Width">
                            <input type="number" id="dimensions_h" name="dimensions_h" step="0.01" min="0"
                                   value="<?= e((string)($editing['dimensions_h'] ?? '')) ?>"
                                   placeholder="H" aria-label="Height">
                        </div>
                        <div class="field-hint"><?= __('dimensions_hint',
                            'Used on the product page and (later) for shipping rate calculations.') ?></div>
                    </div>
                    <?php
                    // Plugins (e.g. shipping-flat-rate) can append their own
                    // fields to this card via the shop_product_edit_shipping_fields
                    // filter. Each callback receives the current product row
                    // ($editing) and returns an HTML string to append.
                    $extra = Hook::applyFilters('shop_product_edit_shipping_fields', '', $editing);
                    if (is_string($extra) && $extra !== '') {
                        echo $extra;
                    }
                    ?>
                </div>

                <!-- Variants section moved OUTSIDE the main form. See the
                     "Variants" card after </form> below. Rendering it here used
                     to nest <form id="vform"> inside the main update form. The
                     HTML5 parser drops the inner <form> opening tag but keeps
                     its children, so the outer form ended up with two hidden
                     _action inputs and PHP read the second one (save_variant)
                     instead of update on Save. -->
            </div>

            <!-- RIGHT: sidebar -->
            <div style="flex:1; min-width:240px;">

                <!-- Publish box -->
                <div class="card">
                    <div class="card-header"><h2><?= __('publish', 'Publish') ?></h2></div>
                    <div class="field">
                        <label class="field-label" for="status"><?= __('status', 'Status') ?></label>
                        <select id="status" name="status">
                            <option value="active"   <?= ($editing['status'] ?? '') === 'active'   ? 'selected' : '' ?>>Published</option>
                            <option value="draft"    <?= ($editing['status'] ?? '') === 'draft'    ? 'selected' : '' ?>>Draft</option>
                            <option value="archived" <?= ($editing['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="featured" value="1"
                                style="width:18px;height:18px;accent-color:var(--accent)"
                                <?= !empty($editing['featured']) ? 'checked' : '' ?>>
                            <?= __('featured_product', 'Featured product') ?>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <?= $isNew ? __('create', 'Create') : __('save_changes', 'Save changes') ?>
                    </button>
                </div>

                <!-- Category -->
                <div class="card">
                    <div class="card-header"><h2><?= __('category', 'Category') ?></h2></div>
                    <div class="field">
                        <select name="category_id">
                            <option value="0">— <?= __('none', 'None') ?> —</option>
                            <?php foreach ($allCategories as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                    <?= (int)($editing['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="text-sm text-muted">
                        <a href="<?= e(plugin_url('shop', 'admin/categories.php')) ?>">Manage categories →</a>
                    </div>
                </div>

                <!-- Image -->
                <div class="card">
                    <div class="card-header"><h2><?= __('product_image', 'Product image') ?></h2></div>
                    <?php
                    // The picker JS looks for id="<targetId>-preview"
                    // when in single mode and updates that img's src
                    // after picking. We share the same <img> for both
                    // the initial server-rendered preview AND the
                    // post-pick update.
                    $imgUrl = !empty($editing['image_url'])
                        ? (filter_var($editing['image_url'], FILTER_VALIDATE_URL)
                            ? $editing['image_url']
                            : SLATE_URL . '/' . ltrim($editing['image_url'], '/'))
                        : '';
                    ?>
                    <div style="margin-bottom:0.5rem; padding:8px; background:var(--surface-2);
                                border-radius:var(--radius-sm); text-align:center;">
                        <img id="picked_image_url-preview"
                             src="<?= e($imgUrl) ?>"
                             alt=""
                             style="max-width:100%; max-height:160px; <?= $imgUrl === '' ? 'display:none;' : '' ?>">
                    </div>
                    <div class="field">
                        <input type="file" id="image" name="image"
                               accept="image/png,image/jpeg,image/webp,image/gif">
                        <div class="field-hint"><?= __('image_hint', 'JPG, PNG, WebP, GIF. Max 5 MB.') ?></div>
                    </div>
                    <?php if ($mediaLibraryActive): ?>
                        <!-- Hidden field receives the picked path; submit
                             handler in handleImageUpload prefers a new
                             file upload over a picked path over the
                             existing path. -->
                        <input type="hidden" id="picked_image_url" name="picked_image_url" value="">
                        <button type="button" class="mlp-trigger-btn"
                                data-mlp-target="picked_image_url"
                                data-mlp-mode="single">
                            <?= __('pick_from_library', 'Or pick from media library…') ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Gallery -->
                <div class="card">
                    <div class="card-header">
                        <h2><?= __('product_gallery', 'Product gallery') ?></h2>
                        <?php
                        $galleryStored = (string)($editing['gallery_urls'] ?? '');
                        $galleryItems = [];
                        if ($galleryStored !== '') {
                            $decoded = json_decode($galleryStored, true);
                            if (is_array($decoded)) $galleryItems = $decoded;
                            else $galleryItems = array_filter(array_map('trim', explode("\n", $galleryStored)));
                        }
                        ?>
                        <span class="text-muted text-sm"><?= count($galleryItems) ?></span>
                    </div>
                    <?php if (!empty($galleryItems)): ?>
                        <div class="gallery-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap:8px; margin-bottom:14px;">
                            <?php foreach ($galleryItems as $idx => $gImg):
                                $gUrl = filter_var($gImg, FILTER_VALIDATE_URL)
                                        ? $gImg : SLATE_URL . '/' . ltrim($gImg, '/');
                            ?>
                                <div class="gallery-thumb" style="position:relative; aspect-ratio:1; background:var(--surface-2); border-radius:8px; overflow:hidden;">
                                    <img src="<?= e($gUrl) ?>" alt=""
                                         style="width:100%; height:100%; object-fit:cover;">
                                    <input type="hidden" name="gallery_existing[]" value="<?= e($gImg) ?>">
                                    <label style="position:absolute; top:4px; right:4px; background:rgba(255,255,255,0.92); border-radius:6px; padding:2px 6px; font-size:11px; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                                        <input type="checkbox" name="gallery_remove[]" value="<?= e($gImg) ?>"
                                               style="margin:0 4px 0 0; vertical-align:middle;">
                                        <?= __('remove', 'Remove') ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="field">
                        <label class="field-label" for="gallery_files"><?= __('add_gallery_images', 'Add gallery images') ?></label>
                        <input type="file" id="gallery_files" name="gallery_files[]" multiple
                               accept="image/png,image/jpeg,image/webp,image/gif">
                        <div class="field-hint"><?= __('gallery_hint',
                            'Select multiple images. They appear as thumbnails on the product page. JPG, PNG, WebP, GIF. Max 5 MB each.') ?></div>
                    </div>
                    <?php if ($mediaLibraryActive): ?>
                        <button type="button" class="mlp-trigger-btn"
                                data-mlp-target="gallery_files"
                                data-mlp-mode="append"
                                data-mlp-array-name="gallery_existing[]">
                            <?= __('pick_gallery_from_library', '+ Add from media library') ?>
                        </button>
                        <div class="text-muted text-sm" style="margin-top:0.3rem;">
                            <?= __('pick_gallery_hint',
                                'Picked images are added to the gallery on save. Save the product to see them as thumbnails.') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tags -->
                <div class="card">
                    <div class="card-header"><h2><?= __('tags', 'Tags') ?></h2></div>
                    <div class="field">
                        <input type="text" name="tags" maxlength="500"
                               value="<?= e($editing['tags'] ?? '') ?>"
                               placeholder="comma, separated, tags">
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php // ─── Variants section (existing products only) ───
          // Rendered OUTSIDE the main product <form> on purpose: variant
          // add/delete each need their own POST handlers, and nesting them
          // inside the product form breaks the HTML5 parser's nested-form
          // rule (which silently drops inner <form> open tags but keeps
          // their hidden inputs — corrupting the outer form's POST body).
          if (!$isNew): ?>
        <div class="card" style="margin-top:1rem;">
            <div class="card-header">
                <h2><?= __('variants', 'Variants') ?></h2>
                <span class="text-muted text-sm"><?= count($variants) ?></span>
            </div>
            <p class="text-muted text-sm mb-2">
                <?= __('variants_hint',
                    'Add size, color, or other options. Customers will pick a variant on the product page.') ?>
            </p>

            <?php if ($variants): ?>
                <div class="variants-edit-list" style="display:flex; flex-direction:column; gap:0.75rem; margin-bottom:1rem;">
                    <?php foreach ($variants as $v):
                        $vSku       = (string)$v['sku'];
                        $vImage     = (string)($v['image_url'] ?? '');
                        $vDesc      = (string)($v['description'] ?? '');
                        $priceLabel = ((float)$v['price_diff'] >= 0 ? '+' : '')
                            . number_format((float)$v['price_diff'], 2);
                        $detailsId  = 'vrow-' . (int)$v['id'];
                    ?>
                    <details class="card" id="<?= e($detailsId) ?>" style="padding:0;">
                        <summary style="cursor:pointer; padding:0.75rem 1rem; list-style:none; display:flex; align-items:center; gap:0.75rem;">
                            <?php if ($vImage !== ''): ?>
                                <img src="<?= e(shop_admin_image_url($vImage)) ?>" alt=""
                                     style="width:36px; height:36px; object-fit:cover; border-radius:4px; background:#eee;">
                            <?php else: ?>
                                <div style="width:36px; height:36px; border-radius:4px; background:#e8edf3; color:#5a6b7e; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px;">
                                    <?= e(mb_strtoupper(mb_substr($v['value'], 0, 2))) ?>
                                </div>
                            <?php endif; ?>
                            <div style="flex:1;">
                                <div style="font-weight:600;"><?= e($v['attribute']) ?>: <?= e($v['value']) ?></div>
                                <div class="text-muted text-sm">
                                    SKU: <?= e($vSku !== '' ? $vSku : '—') ?>
                                    · <?= (int)$v['stock_qty'] ?> in stock
                                    · <?= e($priceLabel) ?>
                                </div>
                            </div>
                            <span class="text-muted text-sm" style="font-size:12px;">click to edit</span>
                        </summary>
                        <div style="padding:1rem; border-top:1px solid #e5e9f0;">
                            <form method="post" enctype="multipart/form-data">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="save_variant">
                                <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
                                <input type="hidden" name="variant_id" value="<?= (int)$v['id'] ?>">
                                <input type="hidden" name="v_existing_image_url" value="<?= e($vImage) ?>">

                                <div class="field-row field-row-variant">
                                    <div class="field">
                                        <label class="field-label">Attribute</label>
                                        <input type="text" name="v_attribute"
                                               value="<?= e($v['attribute']) ?>"
                                               maxlength="50" required>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Value</label>
                                        <input type="text" name="v_value"
                                               value="<?= e($v['value']) ?>"
                                               maxlength="100" required>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">SKU</label>
                                        <input type="text" name="v_sku"
                                               value="<?= e($vSku) ?>" maxlength="100">
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Price diff</label>
                                        <input type="number" name="v_price_diff"
                                               step="0.01" value="<?= e(number_format((float)$v['price_diff'], 2, '.', '')) ?>">
                                    </div>
                                    <div class="field">
                                        <label class="field-label">Stock</label>
                                        <input type="number" name="v_stock_qty"
                                               step="1" min="0" value="<?= (int)$v['stock_qty'] ?>">
                                    </div>
                                </div>

                                <div class="field" style="margin-top:1rem;">
                                    <label class="field-label">Description (overrides product description for this variant)</label>
                                    <textarea name="v_description" rows="3"
                                              placeholder="Optional. Leave blank to inherit the product description."
                                    ><?= e($vDesc) ?></textarea>
                                </div>

                                <div class="field" style="margin-top:1rem;">
                                    <label class="field-label">Variant image (overrides product image)</label>
                                    <?php if ($vImage !== ''): ?>
                                        <div style="display:flex; align-items:center; gap:1rem; margin-bottom:0.5rem;">
                                            <img id="v_picked_<?= (int)$v['id'] ?>-preview"
                                                 src="<?= e(shop_admin_image_url($vImage)) ?>" alt=""
                                                 style="width:64px; height:64px; object-fit:cover; border-radius:4px; background:#eee;">
                                            <label style="display:flex; align-items:center; gap:0.4rem; font-size:13px;">
                                                <input type="checkbox" name="v_clear_image" value="1">
                                                Remove this image
                                            </label>
                                        </div>
                                    <?php else: ?>
                                        <img id="v_picked_<?= (int)$v['id'] ?>-preview" src="" alt=""
                                             style="display:none; width:64px; height:64px; object-fit:cover; border-radius:4px; background:#eee; margin-bottom:0.5rem;">
                                    <?php endif; ?>
                                    <input type="file" name="v_image"
                                           accept="image/png, image/jpeg, image/webp, image/gif">
                                    <div class="text-muted text-sm" style="margin-top:0.3rem;">
                                        Upload a PNG, JPEG, WebP, or GIF up to 5 MB.
                                        <?= $vImage === '' ? 'No image set — the product image will be used.' : '' ?>
                                    </div>
                                    <?php if ($mediaLibraryActive): ?>
                                        <input type="hidden" id="v_picked_<?= (int)$v['id'] ?>"
                                               name="v_picked_image_url" value="">
                                        <button type="button" class="mlp-trigger-btn"
                                                data-mlp-target="v_picked_<?= (int)$v['id'] ?>"
                                                data-mlp-mode="single">
                                            <?= __('pick_from_library', 'Or pick from media library…') ?>
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div class="flex gap-2" style="margin-top:1rem;">
                                    <button type="submit" class="btn"><?= __('save_variant', 'Save variant') ?></button>
                                </div>
                            </form>

                            <form method="post" style="margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid #e5e9f0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_variant">
                                <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
                                <input type="hidden" name="variant_id" value="<?= (int)$v['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm(<?= e(json_encode(sprintf('Delete the "%s: %s" variant?', $v['attribute'], $v['value']))) ?>);">
                                    Remove this variant
                                </button>
                            </form>
                        </div>
                    </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 class="text-sm" style="margin-bottom:0.5rem;"><?= __('add_variant', 'Add variant') ?></h3>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_variant">
                <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
                <div class="field-row field-row-variant">
                    <div class="field">
                        <label class="field-label">Attribute</label>
                        <input type="text" name="v_attribute"
                               placeholder="Size" maxlength="50" required>
                    </div>
                    <div class="field">
                        <label class="field-label">Value</label>
                        <input type="text" name="v_value"
                               placeholder="Medium" maxlength="100" required>
                    </div>
                    <div class="field">
                        <label class="field-label">SKU</label>
                        <input type="text" name="v_sku" maxlength="100">
                    </div>
                    <div class="field">
                        <label class="field-label">Price diff</label>
                        <input type="number" name="v_price_diff"
                               step="0.01" value="0">
                    </div>
                    <div class="field">
                        <label class="field-label">Stock</label>
                        <input type="number" name="v_stock_qty"
                               step="1" min="0" value="0">
                    </div>
                </div>
                <div class="field" style="margin-top:0.75rem;">
                    <label class="field-label">Description (optional, overrides product description)</label>
                    <textarea name="v_description" rows="2"
                              placeholder="Leave blank to inherit the product description."></textarea>
                </div>
                <div class="field" style="margin-top:0.75rem;">
                    <label class="field-label">Image (optional, overrides product image)</label>
                    <img id="v_picked_new-preview" src="" alt=""
                         style="display:none; width:64px; height:64px; object-fit:cover; border-radius:4px; background:#eee; margin-bottom:0.5rem;">
                    <input type="file" name="v_image"
                           accept="image/png, image/jpeg, image/webp, image/gif">
                    <?php if ($mediaLibraryActive): ?>
                        <input type="hidden" id="v_picked_new" name="v_picked_image_url" value="">
                        <button type="button" class="mlp-trigger-btn"
                                data-mlp-target="v_picked_new"
                                data-mlp-mode="single">
                            <?= __('pick_from_library', 'Or pick from media library…') ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2" style="margin-top:0.75rem;">
                    <button type="submit" class="btn"><?= __('add_variant', 'Add variant') ?></button>
                </div>
            </form>
        </div>

        <div class="card" style="margin-top:1rem;">
            <div class="card-header"><h2 class="text-danger"><?= __('danger_zone', 'Danger zone') ?></h2></div>
            <form method="post"
                  onsubmit="return confirm(<?= e(json_encode(sprintf(
                      'Delete product "%s"? This also removes all variants and cannot be undone.',
                      $editing['name']))) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
                <button type="submit" class="btn btn-danger"><?= __('delete_product', 'Delete product') ?></button>
            </form>
        </div>
    <?php else: // $isNew — pending-variants repeater.
                //
                // Lets users define variants on the New Product page. The
                // rows are submitted as $_POST['pending_variants'] and the
                // create handler inserts them in the same transaction as
                // the product itself.
                //
                // We deliberately DO NOT support per-variant image upload
                // on the New page — that requires multi-file $_FILES
                // handling per row with per-row error reporting, and it's
                // a significant complexity bump. Users can add variant
                // images by editing each variant after the initial save.
                //
                // The script at the bottom of this block adds/removes
                // rows client-side. The server tolerates blank rows
                // (skips them silently) so users can leave defaults in
                // place if they only need one variant.
                $pendingVariants = $editing['_pending_variants'] ?? [];
                if (empty($pendingVariants)) {
                    $pendingVariants = [['attribute' => '', 'value' => '', 'sku' => '', 'price_diff' => '0', 'stock_qty' => '0']];
                }
                ?>
        <div class="card" style="margin-top:1rem;">
            <div class="card-header">
                <h2><?= __('variants', 'Variants') ?>
                    <span class="text-muted text-sm" style="font-weight:normal;">
                        (<?= __('optional', 'optional') ?>)
                    </span>
                </h2>
            </div>
            <p class="text-muted text-sm" style="margin-bottom:0.75rem;">
                <?= __('variants_new_hint',
                    'If this product comes in sizes, colors, or other options, list them here. Leave the rows blank if it doesn\'t. You can add a per-variant image after saving.') ?>
            </p>

            <style>
            .pending-variant-row{display:grid;grid-template-columns:1fr 1fr 1fr 100px 80px auto;gap:0.5rem;align-items:end;padding:0.5rem 0;border-bottom:1px solid var(--border,#eee);}
            /* Collapse the 6-column variant row so it doesn't overflow on
               tablets/phones. The inline grid-template was removed from the PHP
               markup AND the JS row builder so these media queries take effect. */
            @media (max-width:760px){ .pending-variant-row{grid-template-columns:1fr 1fr;} }
            @media (max-width:480px){ .pending-variant-row{grid-template-columns:1fr;} }
            </style>
            <div id="pending-variants-list">
                <?php foreach ($pendingVariants as $idx => $pv): ?>
                    <div class="pending-variant-row">
                        <div class="field" style="margin:0;">
                            <label class="field-label" style="font-size:11px;">
                                <?= __('attribute', 'Attribute') ?>
                            </label>
                            <input type="text"
                                   form="product-form"
                                   name="pending_variants[<?= $idx ?>][attribute]"
                                   value="<?= e((string)($pv['attribute'] ?? '')) ?>"
                                   maxlength="50"
                                   placeholder="<?= e(__('attr_placeholder', 'Size')) ?>">
                        </div>
                        <div class="field" style="margin:0;">
                            <label class="field-label" style="font-size:11px;">
                                <?= __('value', 'Value') ?>
                            </label>
                            <input type="text"
                                   form="product-form"
                                   name="pending_variants[<?= $idx ?>][value]"
                                   value="<?= e((string)($pv['value'] ?? '')) ?>"
                                   maxlength="100"
                                   placeholder="<?= e(__('val_placeholder', 'Medium')) ?>">
                        </div>
                        <div class="field" style="margin:0;">
                            <label class="field-label" style="font-size:11px;">
                                <?= __('sku', 'SKU') ?>
                            </label>
                            <input type="text"
                                   form="product-form"
                                   name="pending_variants[<?= $idx ?>][sku]"
                                   value="<?= e((string)($pv['sku'] ?? '')) ?>"
                                   maxlength="100">
                        </div>
                        <div class="field" style="margin:0;">
                            <label class="field-label" style="font-size:11px;">
                                <?= __('price_diff', 'Price diff') ?>
                            </label>
                            <input type="number"
                                   step="0.01"
                                   form="product-form"
                                   name="pending_variants[<?= $idx ?>][price_diff]"
                                   value="<?= e((string)($pv['price_diff'] ?? '0')) ?>">
                        </div>
                        <div class="field" style="margin:0;">
                            <label class="field-label" style="font-size:11px;">
                                <?= __('stock', 'Stock') ?>
                            </label>
                            <input type="number"
                                   step="1" min="0"
                                   form="product-form"
                                   name="pending_variants[<?= $idx ?>][stock_qty]"
                                   value="<?= e((string)($pv['stock_qty'] ?? '0')) ?>">
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-ghost remove-variant-row"
                                    style="margin-top:18px;"
                                    title="<?= e(__('remove_row', 'Remove this row')) ?>">×</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:0.5rem;">
                <button type="button" id="add-variant-row" class="btn btn-sm">
                    + <?= __('add_variant_row', 'Add another variant') ?>
                </button>
            </div>

            <script>
            (function () {
                // Pending-variants repeater. Adds/removes rows on the New
                // Product form. The "remove" button on the only remaining
                // row clears that row instead of removing it, so the form
                // always has at least one row visible (users can also just
                // leave it blank — the server skips empty rows).
                var listEl = document.getElementById('pending-variants-list');
                var addBtn = document.getElementById('add-variant-row');
                if (!listEl || !addBtn) return;

                // Index counter — keep going past the initial row indexes
                // so we don't collide on resubmit.
                var nextIdx = listEl.querySelectorAll('.pending-variant-row').length;

                function makeRow(idx) {
                    var row = document.createElement('div');
                    row.className = 'pending-variant-row';
                    // Styling + responsive column collapse come from the
                    // .pending-variant-row CSS class so the media queries apply.
                    // Note: form="product-form" on every input so the row
                    // submits with the main product create form even
                    // though this card is rendered OUTSIDE that <form>
                    // (HTML5 form-attribute association).
                    row.innerHTML =
                        '<div class="field" style="margin:0;">'
                      +   '<label class="field-label" style="font-size:11px;">Attribute</label>'
                      +   '<input type="text" form="product-form" name="pending_variants[' + idx + '][attribute]" maxlength="50" placeholder="Size">'
                      + '</div>'
                      + '<div class="field" style="margin:0;">'
                      +   '<label class="field-label" style="font-size:11px;">Value</label>'
                      +   '<input type="text" form="product-form" name="pending_variants[' + idx + '][value]" maxlength="100" placeholder="Medium">'
                      + '</div>'
                      + '<div class="field" style="margin:0;">'
                      +   '<label class="field-label" style="font-size:11px;">SKU</label>'
                      +   '<input type="text" form="product-form" name="pending_variants[' + idx + '][sku]" maxlength="100">'
                      + '</div>'
                      + '<div class="field" style="margin:0;">'
                      +   '<label class="field-label" style="font-size:11px;">Price diff</label>'
                      +   '<input type="number" step="0.01" form="product-form" name="pending_variants[' + idx + '][price_diff]" value="0">'
                      + '</div>'
                      + '<div class="field" style="margin:0;">'
                      +   '<label class="field-label" style="font-size:11px;">Stock</label>'
                      +   '<input type="number" step="1" min="0" form="product-form" name="pending_variants[' + idx + '][stock_qty]" value="0">'
                      + '</div>'
                      + '<div>'
                      +   '<button type="button" class="btn btn-sm btn-ghost remove-variant-row" style="margin-top:18px;">\u00d7</button>'
                      + '</div>';
                    return row;
                }

                addBtn.addEventListener('click', function () {
                    listEl.appendChild(makeRow(nextIdx++));
                });

                listEl.addEventListener('click', function (e) {
                    if (!e.target.classList.contains('remove-variant-row')) return;
                    var rows = listEl.querySelectorAll('.pending-variant-row');
                    var row = e.target.closest('.pending-variant-row');
                    if (!row) return;
                    if (rows.length > 1) {
                        row.parentNode.removeChild(row);
                    } else {
                        // Last row — clear inputs instead of removing.
                        row.querySelectorAll('input').forEach(function (input) {
                            if (input.type === 'number') {
                                input.value = input.min || '0';
                            } else {
                                input.value = '';
                            }
                        });
                    }
                });
            })();
            </script>
        </div>
    <?php endif; ?>

<?php // ════════════════════════ IMPORT MODE ═══════════════════════ ?>
<?php elseif ($mode === 'import'): ?>
    <div class="page-header">
        <div>
            <h1><?= __('import_csv', 'Import products from CSV') ?></h1>
            <p class="page-header-sub">
                <?= __('csv_import_sub',
                    'Upload a WooCommerce-style products CSV. Existing rows are matched by SKU then by slug.') ?>
            </p>
        </div>
        <a href="<?= e(plugin_url('shop', 'admin/products.php')) ?>" class="btn btn-ghost">
            ← <?= __('back_to_list', 'Back to list') ?>
        </a>
    </div>

    <div class="card">
        <div class="card-header"><h2>Upload CSV</h2></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="import_csv">
            <div class="field">
                <label class="field-label" for="csv_file">CSV file</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                <div class="field-hint">
                    Supported columns: ID, Type, SKU, Name, Slug, Published, Featured,
                    Short description, Description, Regular price, Sale price,
                    Stock, Manage stock, Weight, Categories, Tags, Images.
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Import</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Need a template?</h2></div>
        <p class="text-muted text-sm">
            Export your existing products first to see the exact column format.
        </p>
        <a href="?export" class="btn">Export current products as CSV</a>
    </div>

<?php // ════════════════════════ LIST MODE ═════════════════════════ ?>
<?php else:
    $search   = trim((string)($_GET['search'] ?? ''));
    $catFilter = (int)($_GET['category_id'] ?? 0);
    $statusF  = (string)($_GET['status'] ?? '');

    $where = "p.tenant_id = ?";
    $args  = [$tid];
    if ($search !== '') {
        $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $args[] = "%$search%"; $args[] = "%$search%";
    }
    // Detect whether shop_products has the v1.1.0 columns. On shared hosts
    // without ALTER privilege the migration's column-add step may have been
    // silently skipped — we still want this page to render, just without
    // category info on each row.
    $hasCategoryCol = false;
    try {
        $hasCategoryCol = (int)Database::value(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'shop_products'
                AND COLUMN_NAME = 'category_id'"
        ) > 0;
    } catch (\Throwable $e) { /* leave false */ }

    if ($catFilter > 0 && $hasCategoryCol) {
        $where .= " AND p.category_id = ?";
        $args[] = $catFilter;
    }
    if (in_array($statusF, ['active','draft','archived'], true)) {
        $where .= " AND p.status = ?";
        $args[] = $statusF;
    }

    if ($hasCategoryCol) {
        $products = Database::rows(
            "SELECT p.*, c.name AS category_name
               FROM shop_products p
          LEFT JOIN shop_categories c ON c.id = p.category_id
              WHERE $where
              ORDER BY p.updated_at DESC LIMIT 200",
            $args
        );
    } else {
        // Fallback: no JOIN, no category_name column
        $products = Database::rows(
            "SELECT p.*, NULL AS category_name
               FROM shop_products p
              WHERE $where
              ORDER BY p.updated_at DESC LIMIT 200",
            $args
        );
    }
?>
    <div class="page-header">
        <div>
            <h1><?= __('shop_products', 'Products') ?></h1>
            <p class="page-header-sub">
                <?= sprintf(__('product_count_label', '%d product(s)'), count($products)) ?>
                · <a href="<?= e(SLATE_URL) ?>/shop/" target="_blank">View storefront ↗</a>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="?import" class="btn">↑ <?= __('import_csv', 'Import CSV') ?></a>
            <a href="?export" class="btn">↓ <?= __('export_csv', 'Export CSV') ?></a>
            <a href="?new" class="btn btn-primary">+ <?= __('new_product', 'New product') ?></a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card">
        <form method="get" class="filter-row" style="margin:0;">
            <div class="field filter-search">
                <label class="field-label">Search</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="name or SKU">
            </div>
            <div class="field">
                <label class="field-label">Category</label>
                <select name="category_id">
                    <option value="0">All categories</option>
                    <?php foreach ($allCategories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $catFilter === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label">Status</label>
                <select name="status">
                    <option value="">Any</option>
                    <option value="active"   <?= $statusF === 'active'   ? 'selected' : '' ?>>Published</option>
                    <option value="draft"    <?= $statusF === 'draft'    ? 'selected' : '' ?>>Draft</option>
                    <option value="archived" <?= $statusF === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn">Filter</button>
                <?php if ($search !== '' || $catFilter > 0 || $statusF !== ''): ?>
                    <a href="<?= e(plugin_url('shop', 'admin/products.php')) ?>" class="btn btn-ghost">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($products)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= __('no_products', 'No products yet') ?></div>
                <p>Create your first product or import a CSV to get started.</p>
            </div>
        </div>
    <?php else: ?>
        <form method="post" id="bulkform">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="bulk">

            <div class="card">
                <div class="card-header">
                    <h2><?= sprintf(__('product_list_label', '%d product(s)'), count($products)) ?></h2>
                    <div class="toolbar">
                        <select name="bulk_action">
                            <option value="">Bulk action…</option>
                            <option value="publish">Publish</option>
                            <option value="draft">Move to draft</option>
                            <option value="archive">Archive</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-sm"
                                onclick="return confirm('Apply to selected products?');">Apply</button>
                    </div>
                </div>

                <div class="data-list" data-single-open>
                    <?php foreach ($products as $p):
                        $stockState = !$p['manage_stock'] ? 'In stock'
                            : ((int)$p['stock_qty'] <= 0 ? 'Out of stock'
                                : ((int)$p['stock_qty'] <= 5 ? 'Low stock' : 'In stock'));
                        $stockColor = !$p['manage_stock'] ? 'success'
                            : ((int)$p['stock_qty'] <= 0 ? 'danger'
                                : ((int)$p['stock_qty'] <= 5 ? 'warning' : 'success'));
                        $statusColor = match($p['status']) {
                            'active' => 'success', 'draft' => 'warning',
                            'archived' => 'muted', default => 'muted',
                        };

                        ob_start(); ?>
                        <label class="flex items-center" style="padding:0 8px;cursor:pointer;">
                            <input type="checkbox" form="bulkform" name="ids[]"
                                   value="<?= (int)$p['id'] ?>"
                                   style="width:18px;height:18px;accent-color:var(--accent);cursor:pointer;"
                                   onclick="event.stopPropagation()"
                                   title="Select for bulk action">
                        </label>
                        <a href="?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                        <?php if (!empty($p['slug'])): ?>
                            <a href="<?= e(SLATE_URL) ?>/shop/product/<?= e($p['slug']) ?>"
                               class="btn btn-sm" target="_blank">View</a>
                        <?php endif; ?>
                        <?php $actions = ob_get_clean();

                        // Build meta as plain text (slate_data_row escapes it for us)
                        $metaParts = [];
                        if ($p['sku'] !== '') $metaParts[] = 'SKU: ' . $p['sku'];
                        $metaParts[] = ShopAPI::formatMoney((float)$p['price'], $currency);
                        if ($p['sale_price'] !== null) {
                            $metaParts[] = 'sale: ' . ShopAPI::formatMoney((float)$p['sale_price'], $currency);
                        }

                        slate_data_row([
                            'avatar'       => mb_strtoupper(mb_substr($p['name'], 0, 2)),
                            'avatar_color' => $statusColor,
                            'title'        => $p['name'],
                            'meta'         => implode(' · ', $metaParts),
                            'badge'        => [$stockState, $stockColor],
                            'detail'       => [
                                'Status'   => $p['status'],
                                'Category' => $p['category_name'] ?? '—',
                                'Price'    => ShopAPI::formatMoney((float)$p['price'], $currency),
                                'Sale'     => $p['sale_price'] !== null
                                    ? ShopAPI::formatMoney((float)$p['sale_price'], $currency) : '—',
                                'Stock'    => $p['manage_stock'] ? (int)$p['stock_qty'] . ' units' : 'Not tracked',
                                'SKU'      => $p['sku'] !== '' ? $p['sku'] : '—',
                                'Featured' => !empty($p['featured']) ? 'Yes' : 'No',
                                'Updated'  => $p['updated_at'],
                            ],
                            'actions' => $actions,
                        ]);
                    endforeach; ?>
                </div>
                <?php slate_data_list_script(); ?>
            </div>
        </form>
    <?php endif; ?>

<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
