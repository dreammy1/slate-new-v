<?php
/**
 * Slate — Posts and Pages Admin Bridge.
 *
 * Route: /admin/posts.php?type=page or /admin/posts.php?type=post
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$target = SLATE_ROOT . '/plugins/content-builder/admin/posts.php';
// Bridge only when the plugin is actually active — its files stay on
// disk while deactivated/uninstalling, so an is_file()-only check let a
// deactivated Content Builder still route in here and fatal on classes
// (ContentBuilderAPI, PostType, ...) that were never loaded this request.
if (PluginLoader::isActive('content-builder') && is_file($target)) {
    require $target;
    exit;
}

Auth::require();
Auth::requirePerm('content.view');
$pageTitle = 'Content';
$currentNav = 'content-page';
require __DIR__ . '/partials/header.php';
?>
<div class="card">
    <div class="card-header">
        <h2><?= __('content', 'Content') ?></h2>
    </div>
    <div class="card-body">
        <p><?= __('content_builder_missing', 'The Content Builder plugin is required to manage pages and posts.') ?></p>
    </div>
</div>
<?php
require __DIR__ . '/partials/footer.php';
