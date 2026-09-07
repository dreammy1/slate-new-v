<?php
/**
 * SiteHub — fleet control plane admin page.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
Auth::require();
Auth::requirePerm('sitehub.view');

$pageTitle  = __('sitehub', 'SiteHub');
$currentNav = 'sitehub';
$flash      = null;
$canManage  = Auth::can('sitehub.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'ping') {
            $r = SitehubAPI::ping((int)($_POST['site_id'] ?? 0));
            $flash = ['type' => $r['ok'] ? 'success' : 'error', 'msg' => $r['ok'] ? __('ping_ok', 'Site is online.') : ($r['error'] ?: __('ping_fail', 'Site did not respond.'))];

        } elseif ($action === 'scan') {
            $r = SitehubAPI::scan((int)($_POST['site_id'] ?? 0), true);
            $flash = ['type' => $r['ok'] ? 'success' : 'error', 'msg' => $r['ok'] ? __('scan_ok', 'Scan refreshed.') : ($r['error'] ?: __('scan_fail', 'Scan failed.'))];

        } elseif ($canManage && $action === 'add_site') {
            $name = trim((string)($_POST['name'] ?? ''));
            $url  = trim((string)($_POST['url'] ?? ''));
            $tok  = trim((string)($_POST['token'] ?? ''));
            if ($name === '' || $url === '' || $tok === '') {
                $flash = ['type' => 'error', 'msg' => __('all_required', 'Name, URL and token are all required.')];
            } elseif (!preg_match('#^https?://#i', $url)) {
                $flash = ['type' => 'error', 'msg' => __('url_invalid', 'URL must start with http:// or https://')];
            } else {
                $id = SitehubAPI::add($name, $url, $tok);
                SitehubAPI::ping($id);
                AuditLog::record('sitehub.site_added', $url);
                $flash = ['type' => 'success', 'msg' => __('site_added', 'Site added and pinged.')];
            }

        } elseif ($canManage && $action === 'remove_site') {
            SitehubAPI::remove((int)($_POST['site_id'] ?? 0));
            AuditLog::record('sitehub.site_removed', 'site#' . (int)($_POST['site_id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('site_removed', 'Site removed.')];

        } elseif ($canManage && $action === 'pull') {
            $r = SitehubAPI::pull((int)($_POST['site_id'] ?? 0));
            $flash = ['type' => $r['ok'] ? 'success' : 'error', 'msg' => $r['ok'] ? sprintf(__('pull_ok', 'Backup saved (%s KB).'), number_format($r['size'] / 1024, 0)) : ($r['error'] ?: __('pull_fail', 'Pull failed.'))];

        } elseif ($action === 'refresh_all') {
            $n = 0;
            foreach (SitehubAPI::sites() as $s) { SitehubAPI::ping((int)$s['id']); SitehubAPI::scan((int)$s['id'], true); $n++; }
            $flash = ['type' => 'success', 'msg' => sprintf(__('refreshed_all', 'Refreshed %d site(s).'), $n)];

        } elseif ($canManage && $action === 'fleet_replace') {
            $from = trim((string)($_POST['from'] ?? ''));
            $to   = trim((string)($_POST['to'] ?? ''));
            if ($from === '' || $to === '') {
                $flash = ['type' => 'error', 'msg' => __('colors_required', 'Both colors are required.')];
            } else {
                $sites = 0; $total = 0;
                foreach (SitehubAPI::sites() as $s) {
                    if ($s['status'] !== 'online') continue;
                    $r = SitehubAPI::replaceColor((int)$s['id'], $from, $to);
                    if ($r['ok']) { $sites++; $total += (int)($r['data']['count'] ?? 0); }
                }
                AuditLog::record('sitehub.fleet_replace_color', $from . ' -> ' . $to);
                $flash = ['type' => 'success', 'msg' => sprintf(__('fleet_replace_ok', 'Replaced on %d site(s), %d total occurrence(s).'), $sites, $total)];
            }

        } elseif ($canManage && $action === 'fleet_radius') {
            $px = (int)($_POST['px'] ?? 0);
            $sites = 0;
            foreach (SitehubAPI::sites() as $s) {
                if ($s['status'] !== 'online') continue;
                $r = SitehubAPI::setRadius((int)$s['id'], $px);
                if ($r['ok']) { $sites++; }
            }
            AuditLog::record('sitehub.fleet_radius', $px . 'px');
            $flash = ['type' => 'success', 'msg' => sprintf(__('fleet_radius_ok', 'Set corner radius on %d site(s).'), $sites)];

        } elseif ($canManage && $action === 'push') {
            $targets = array_map('intval', (array)($_POST['targets'] ?? []));
            if (empty($_FILES['bundle']['tmp_name']) || !is_uploaded_file($_FILES['bundle']['tmp_name'])) {
                $flash = ['type' => 'error', 'msg' => __('no_bundle', 'Choose a bundle .zip to push.')];
            } elseif (empty($targets)) {
                $flash = ['type' => 'error', 'msg' => __('no_targets', 'Select at least one target site.')];
            } else {
                $bytes = file_get_contents($_FILES['bundle']['tmp_name']);
                $ok = 0;
                foreach ($targets as $tid) {
                    $r = SitehubAPI::push($tid, $bytes, ['assign_parts' => true, 'build_menus' => true, 'media_relink' => true]);
                    if ($r['ok']) { $ok++; }
                }
                AuditLog::record('sitehub.push', count($targets) . ' targets');
                $flash = ['type' => 'success', 'msg' => sprintf(__('push_ok', 'Pushed to %d of %d site(s).'), $ok, count($targets))];
            }
        }
    }
}

require $root . '/admin/partials/header.php';
$sites   = SitehubAPI::sites();
$summary = SitehubAPI::fleetSummary();
$backups = SitehubAPI::backups();
?>
<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('sitehub', 'SiteHub')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('sitehub', 'SiteHub') ?></h1>
        <p class="page-header-sub"><?= __('sitehub_subtitle', 'Manage every PortKit site from one place.') ?></p>
    </div>
    <div>
        <form method="post" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="refresh_all">
            <button type="submit" class="btn"><?= __('refresh_all', 'Refresh all') ?></button>
        </form>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="field-row field-row-2" style="gap:12px;flex-wrap:wrap">
    <?php
    $cards = [
        [__('sites_online', 'Sites online'), $summary['online'] . ' / ' . $summary['sites']],
        [__('hardcoded_colors', 'Hardcoded colors'), $summary['hardcoded_colors']],
        [__('empty_links', 'Empty links'), $summary['links_empty']],
        [__('images_no_alt', 'Images no alt'), $summary['images_no_alt']],
        [__('pages_no_h1', 'Pages no H1'), $summary['pages_no_h1']],
        [__('legacy_pages', 'Legacy-section pages'), $summary['legacy_pages']],
    ];
    foreach ($cards as $c): ?>
        <div class="card" style="flex:1;min-width:150px">
            <div class="text-muted text-sm"><?= e($c[0]) ?></div>
            <div style="font-size:26px;font-weight:700;line-height:1.1;margin-top:4px"><?= e((string)$c[1]) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card-header">
    <h2><?= __('connected_sites', 'Connected sites') ?></h2>
    <span class="text-muted text-sm"><?= count($sites) ?></span>
</div>

<?php if (empty($sites)): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('no_sites', 'No sites yet') ?></div>
        <p><?= __('no_sites_intro', 'Add a PortKit site below. In WordPress: PortKit → Settings → Remote API → enable it and copy the token.') ?></p>
    </div></div>
<?php else: ?>
    <div class="data-list" data-single-open>
    <?php foreach ($sites as $s):
        $scan = !empty($s['last_scan']) ? json_decode($s['last_scan'], true) : null;
        $t = is_array($scan) ? ($scan['totals'] ?? []) : [];
        $statusColor = $s['status'] === 'online' ? 'success' : ($s['status'] === 'offline' ? 'danger' : 'muted');
        ob_start(); ?>
        <form method="post" style="margin:0;display:inline"><?= csrf_field() ?>
            <input type="hidden" name="_action" value="ping"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm"><?= __('ping', 'Ping') ?></button></form>
        <form method="post" style="margin:0;display:inline"><?= csrf_field() ?>
            <input type="hidden" name="_action" value="scan"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm"><?= __('scan', 'Scan') ?></button></form>
        <?php if ($canManage): ?>
        <form method="post" style="margin:0;display:inline"><?= csrf_field() ?>
            <input type="hidden" name="_action" value="pull"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm"><?= __('pull', 'Pull backup') ?></button></form>
        <form method="post" style="margin:0;display:inline"
              onsubmit="return confirm(<?= e(json_encode(__('confirm_remove', 'Remove this site from SiteHub?'))) ?>);"><?= csrf_field() ?>
            <input type="hidden" name="_action" value="remove_site"><input type="hidden" name="site_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm btn-danger"><?= __('remove', 'Remove') ?></button></form>
        <?php endif;
        $actions = ob_get_clean();

        $detail = [
            'URL'     => ['label' => 'URL', 'html' => '<a href="' . e($s['url']) . '" target="_blank" rel="noopener">' . e($s['url']) . '</a>'],
            'Version' => $s['version'] ?: '—',
            'Last seen' => $s['last_seen'] ?: '—',
        ];
        if ($t) {
            $detail['Hardcoded colors'] = (string)($t['hardcoded_colors'] ?? 0);
            $detail['Empty links']      = (string)($t['links_empty'] ?? 0);
            $detail['Images no alt']    = (string)($t['images_no_alt'] ?? 0);
            $detail['Pages no H1']      = (string)($t['pages_no_h1'] ?? 0);
            $detail['Legacy-section pages'] = (string)($scan['legacy_pages'] ?? 0);
        }
        if (!empty($s['last_error'])) {
            $detail['Last error'] = ['label' => 'Last error', 'value' => $s['last_error'], 'muted' => true];
        }

        slate_data_row([
            'avatar'       => mb_strtoupper(mb_substr($s['name'], 0, 2)),
            'avatar_color' => $statusColor,
            'title'        => $s['name'],
            'meta'         => preg_replace('#^https?://#', '', $s['url']),
            'badge'        => [$s['status'], $statusColor],
            'detail'       => $detail,
            'actions'      => $actions,
        ]);
    endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="card">
    <div class="card-header"><h2><?= __('add_site', 'Add a site') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="add_site">
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="name"><?= __('site_name', 'Site name') ?> <span class="field-required">*</span></label>
                <input type="text" id="name" name="name" required maxlength="190" placeholder="Acme Co.">
            </div>
            <div class="field">
                <label class="field-label" for="url"><?= __('site_url', 'Site URL') ?> <span class="field-required">*</span></label>
                <input type="url" id="url" name="url" required placeholder="https://client-site.com">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="token"><?= __('conn_token', 'PortKit connection token') ?> <span class="field-required">*</span></label>
            <input type="text" id="token" name="token" required>
            <p class="field-hint"><?= __('token_hint', 'In WordPress: PortKit → Settings → Remote API → enable inbound API and copy the token. Stored encrypted.') ?></p>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('add_site', 'Add site') ?></button>
    </form>
</div>

<div class="field-row field-row-2" style="gap:12px;flex-wrap:wrap">
    <div class="card" style="flex:1;min-width:300px">
        <div class="card-header"><h2><?= __('fleet_color', 'Fleet: replace a color') ?></h2></div>
        <form method="post" onsubmit="return confirm(<?= e(json_encode(__('confirm_fleet', 'Apply to ALL online sites? A snapshot is taken on each.'))) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="fleet_replace">
            <div class="field-row field-row-2">
                <div class="field"><label class="field-label" for="from"><?= __('from_color', 'From (hex)') ?></label>
                    <input type="text" id="from" name="from" placeholder="#ff0000" required></div>
                <div class="field"><label class="field-label" for="to"><?= __('to_color', 'To (hex)') ?></label>
                    <input type="text" id="to" name="to" placeholder="#009687" required></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= __('apply_all', 'Apply to all online') ?></button>
        </form>
    </div>
    <div class="card" style="flex:1;min-width:300px">
        <div class="card-header"><h2><?= __('fleet_radius', 'Fleet: corner radius') ?></h2></div>
        <form method="post" onsubmit="return confirm(<?= e(json_encode(__('confirm_fleet', 'Apply to ALL online sites? A snapshot is taken on each.'))) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="fleet_radius">
            <div class="field"><label class="field-label" for="px"><?= __('radius_px', 'Set every corner radius to (px)') ?></label>
                <input type="number" id="px" name="px" value="8" min="0"></div>
            <button type="submit" class="btn btn-primary"><?= __('apply_all', 'Apply to all online') ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><?= __('push_bundle', 'Push a bundle') ?></h2></div>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="push">
        <div class="field">
            <label class="field-label" for="bundle"><?= __('bundle_zip', 'Bundle (.zip)') ?></label>
            <input type="file" id="bundle" name="bundle" accept=".zip" required>
        </div>
        <div class="field">
            <label class="field-label"><?= __('targets', 'Target sites') ?></label>
            <?php foreach ($sites as $s): ?>
                <label style="display:block;margin:4px 0">
                    <input type="checkbox" name="targets[]" value="<?= (int)$s['id'] ?>" <?= $s['status'] === 'online' ? 'checked' : '' ?>>
                    <?= e($s['name']) ?> <span class="text-muted text-sm"><?= e($s['status']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('push', 'Push bundle') ?></button>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($backups)): ?>
<div class="card-header"><h2><?= __('backups', 'Backups') ?></h2><span class="text-muted text-sm"><?= count($backups) ?></span></div>
<div class="data-list">
    <?php foreach ($backups as $b):
        ob_start(); ?>
        <a class="btn btn-sm" href="<?= e(plugin_url('sitehub', 'admin/download.php?id=' . (int)$b['id'])) ?>"><?= __('download', 'Download') ?></a>
        <?php $actions = ob_get_clean();
        slate_data_row([
            'avatar'       => 'ZIP',
            'avatar_color' => 'info',
            'title'        => $b['site_name'] ?: ('site#' . (int)$b['site_id']),
            'meta'         => $b['created_at'] . ' · ' . number_format($b['size'] / 1024, 0) . ' KB',
            'actions'      => $actions,
        ]);
    endforeach; ?>
</div>
<?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require $root . '/admin/partials/footer.php'; ?>
