<?php
/**
 * Content Builder — posts list (filtered by ?type=).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('content.view');

$type = $_GET['type'] ?? 'page';
$pt   = PostType::get($type);
if (!$pt) { http_response_code(404); $pt = ['slug'=>'page','label'=>'Pages','singular'=>'Page']; $type = 'page'; }

$pageTitle  = $pt['label'];
$currentNav = 'content-' . $type;

/** Apply one action to one post, respecting the same permission the old per-row forms used. */
function cb_apply_action(string $verb, int $id): bool {
    switch ($verb) {
        case 'publish': if (Auth::can('content.publish')) { ContentBuilderAPI::publish($id); return true; } break;
        case 'trash':   if (Auth::can('content.delete'))  { ContentBuilderAPI::trash($id);   return true; } break;
        case 'restore': if (Auth::can('content.edit'))    { ContentBuilderAPI::restore($id); return true; } break;
        case 'delete':  if (Auth::can('content.delete'))  { ContentBuilderAPI::deletePost($id); return true; } break;
    }
    return false;
}

// ── Handle single-row (`_do=verb_id`) and bulk (`_do=bulk_verb` + `ids[]`) actions ──
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        $do = (string)($_POST['_do'] ?? '');
        $verbLabel = ['publish'=>'Published','trash'=>'Moved to trash','restore'=>'Restored to drafts','delete'=>'Deleted permanently'];

        if (str_starts_with($do, 'bulk_')) {
            $verb = substr($do, 5);
            $ids  = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0);
            $n = 0;
            foreach ($ids as $id) { if (cb_apply_action($verb, $id)) { $n++; } }
            $flash = $n > 0
                ? ['type'=>'success','msg'=>($verbLabel[$verb] ?? 'Updated') . " $n " . ($n === 1 ? strtolower($pt['singular']) : strtolower($pt['label'])) . '.']
                : ['type'=>'error','msg'=>'Nothing was selected.'];
        } elseif (preg_match('/^(publish|trash|restore|delete)_(\d+)$/', $do, $m)) {
            [$verb, $id] = [$m[1], (int)$m[2]];
            $flash = cb_apply_action($verb, $id)
                ? ['type'=>'success','msg'=>$verbLabel[$verb] ?? 'Updated.']
                : ['type'=>'error','msg'=>'Not permitted.'];
        }
    }
}

// Fetch every post of this type once — tabs/search filter client-side (no
// page reload), same pattern as Studio's Instructors/Classes lists.
$posts = ContentBuilderAPI::listPosts($type, ['status'=>'any', 'limit'=>500, 'orderby'=>'created_at']);

$counts = ['all' => count($posts), 'published' => 0, 'draft' => 0, 'trash' => 0];
foreach ($posts as $p) {
    $st = $p['status'] ?? 'draft';
    if (isset($counts[$st])) { $counts[$st]++; }
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => $pt['label']],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e($pt['label']) ?></h1>
        <p class="page-header-sub">Manage your <?= e(strtolower($pt['label'])) ?>.</p>
    </div>
    <?php if (Auth::can('content.edit')): ?>
        <a href="<?= e(SLATE_URL) ?>/admin/editor.php?type=<?= e(urlencode($type)) ?>" class="btn btn-primary">
            Add new <?= e($pt['singular']) ?>
        </a>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>All <?= e($pt['label']) ?></h2>
        <span class="text-muted text-sm"><?= count($posts) ?> shown</span>
    </div>

    <?php
    $bulkActions = [];
    if (Auth::can('content.publish')) {
        $bulkActions[] = ['value' => 'publish', 'label' => 'Publish'];
    }
    if (Auth::can('content.edit')) {
        $bulkActions[] = ['value' => 'restore', 'label' => 'Restore to draft'];
    }
    if (Auth::can('content.delete')) {
        $bulkActions[] = ['value' => 'trash', 'label' => 'Trash', 'danger' => true,
            'confirm' => 'Move the selected ' . strtolower($pt['label']) . ' to trash?'];
        $bulkActions[] = ['value' => 'delete', 'label' => 'Delete permanently', 'danger' => true,
            'confirm' => 'Permanently delete the selected ' . strtolower($pt['label']) . '? This cannot be undone.'];
    }

    cb_ui_list_start([
        ['value' => 'all',       'label' => 'All',       'count' => $counts['all']],
        ['value' => 'published', 'label' => 'Published', 'count' => $counts['published']],
        ['value' => 'draft',     'label' => 'Drafts',    'count' => $counts['draft']],
        ['value' => 'trash',     'label' => 'Trash',     'count' => $counts['trash']],
    ], 'Search ' . strtolower($pt['label']) . '…', $bulkActions);

    // Single-row action buttons submit `_do=verb_id` into the same enclosing
    // bulk <form> (Studio's technique) — a nested per-row <form> would be
    // invalid HTML once the whole list is already one <form>.
    foreach ($posts as $p):
        $st  = $p['status'] ?? 'draft';
        $id  = (int)$p['id'];
        $mod = $st === 'published' ? 'active' : ($st === 'trash' ? 'danger' : 'warning');

        $actions = '';
        if ($st === 'trash') {
            if (Auth::can('content.edit')) {
                $actions .= '<button type="submit" name="_do" value="restore_' . $id . '" class="btn btn-sm">Restore</button> ';
            }
            if (Auth::can('content.delete')) {
                $actions .= '<button type="submit" name="_do" value="delete_' . $id . '" class="btn btn-sm btn-danger" '
                          . 'data-confirm="Delete permanently? This cannot be undone.">Delete</button>';
            }
        } else {
            $actions .= '<a href="' . e(SLATE_URL) . '/admin/editor.php?id=' . $id . '" class="btn btn-sm">Edit</a> ';
            if ($st === 'published') {
                $actions .= '<a href="' . e(ContentBuilderAPI::publicUrl($type, $p['slug'])) . '" target="_blank" class="btn btn-sm">View</a> ';
            } elseif (Auth::can('content.publish')) {
                $actions .= '<button type="submit" name="_do" value="publish_' . $id . '" class="btn btn-sm">Publish</button> ';
            }
            if (Auth::can('content.delete')) {
                $actions .= '<button type="submit" name="_do" value="trash_' . $id . '" class="btn btn-sm btn-danger" data-confirm="Move to trash?">Trash</button>';
            }
        }

        cb_ui_row([
            '_id'     => $id,
            '_filter' => $st,
            '_search' => strtolower(($p['title'] ?: '') . ' ' . $p['slug']),
            'title'   => $p['title'] ?: '(untitled)',
            'meta'    => '/' . $p['slug'] . ' · updated ' . date('M j, Y', strtotime($p['updated_at'])),
            'badge'   => [ucfirst($st), $mod],
            'actions' => $actions,
        ]);
    endforeach;

    cb_ui_list_end(count($posts), 'No ' . strtolower($pt['label']) . ' yet', [
        'icon'      => 'file',
        'sub'       => 'Create one to get started.',
        'cta_href'  => Auth::can('content.edit') ? (SLATE_URL . '/admin/editor.php?type=' . urlencode($type)) : null,
        'cta_label' => Auth::can('content.edit') ? ('Add new ' . $pt['singular']) : null,
    ]);
    ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
