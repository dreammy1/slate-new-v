<?php
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/SlateMcpAPI.php';
Auth::require();
Auth::requirePerm('slate-mcp.manage');
SlateMcpAPI::ensureSchema();
$pageTitle = 'AI Access'; $currentNav = 'slate-mcp'; $flash = null; $newToken = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    elseif (($_POST['_action'] ?? '') === 'create_token') {
        try { $newToken = SlateMcpAPI::createToken((string)($_POST['label'] ?? ''), (array)($_POST['scopes'] ?? []), (string)($_POST['expires_at'] ?? '')); $flash = ['type' => 'success', 'msg' => 'Token created. Copy it now; Slate cannot show it again.']; }
        catch (Throwable $e) { $flash = ['type' => 'error', 'msg' => $e->getMessage()]; }
    } elseif (($_POST['_action'] ?? '') === 'revoke_token') {
        SlateMcpAPI::revokeToken((int)($_POST['token_id'] ?? 0)); $flash = ['type' => 'success', 'msg' => 'Token revoked immediately.'];
    }
}
$tokens = SlateMcpAPI::listTokens();
require $root . '/admin/partials/header.php';
?>
<?php slate_breadcrumbs([['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => 'AI Access']]); ?>
<div class="page-header"><div><h1>AI Access</h1><p class="page-header-sub">Create narrowly scoped, tenant-specific credentials for approved AI tools.</p></div></div>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if ($newToken): ?><section class="card mb-3"><div class="card-header"><h2>Copy this token now</h2></div><p class="text-muted">Store it in the AI client’s secret manager. It is displayed only once and can be revoked below.</p><textarea class="mcp-token" readonly><?= e($newToken['token']) ?></textarea><p class="field-help">Endpoint: <code><?= e(rtrim(SLATE_URL, '/') . '/slate-mcp/mcp') ?></code></p></section><?php endif; ?>
<section class="card mb-3"><div class="card-header"><h2>Create an AI access token</h2></div><p class="text-muted">Tokens cannot execute code, query the database directly, manage users, access payments, or delete sites. Give each AI client its own minimum-access token.</p><form method="post"><input type="hidden" name="_action" value="create_token"><?= csrf_field() ?><div class="mcp-grid"><div class="field"><label class="field-label">Label</label><input name="label" required maxlength="120" placeholder="Kaimana content assistant"></div><div class="field"><label class="field-label">Expiry date <span class="text-muted">optional</span></label><input type="date" name="expires_at"></div></div><div class="mcp-scopes"><?php foreach (SlateMcpAPI::availableScopes() as $scopeKey => $scopeLabel): $val = is_int($scopeKey) ? $scopeLabel : $scopeKey; ?><label><input type="checkbox" name="scopes[]" value="<?= e($val) ?>" <?= $val === 'react-sites.read' ? 'checked' : '' ?>> <?= e($scopeLabel) ?></label><?php endforeach; ?></div><button class="btn btn-primary" type="submit">Create token</button></form></section>
<section class="card"><div class="card-header"><h2>Issued tokens</h2></div><?php if (!$tokens): ?><p class="text-muted">No AI access tokens have been created for this tenant.</p><?php else: ?><div class="data-list"><?php foreach ($tokens as $token): ?><div class="mcp-row"><div><strong><?= e($token['label']) ?></strong><span><?= e($token['token_prefix']) ?>… · <?= e(implode(', ', $token['scopes'])) ?><?= $token['expires_at'] ? ' · expires ' . e($token['expires_at']) : '' ?><?= $token['last_used_at'] ? ' · used ' . e($token['last_used_at']) : '' ?></span></div><?php if (!$token['revoked_at']): ?><form method="post" onsubmit="return confirm('Revoke this AI token immediately?');"><input type="hidden" name="_action" value="revoke_token"><?= csrf_field() ?><input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Revoke</button></form><?php else: ?><span class="text-muted">Revoked <?= e($token['revoked_at']) ?></span><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?></section>
<style>.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}.mcp-scopes{display:grid;gap:10px;margin:0 0 18px}.mcp-scopes label{font-size:.92rem}.mcp-token{display:block;width:100%;min-height:90px;resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}.field-help{margin:7px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}.mcp-row{display:flex;justify-content:space-between;align-items:center;gap:16px;border-top:1px solid var(--border,#e5e7eb);padding:14px 0}.mcp-row:first-child{border-top:0}.mcp-row strong,.mcp-row span{display:block}.mcp-row span{margin-top:4px;font-size:.82rem;color:var(--muted,#6b7280)}@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}.mcp-row{display:block}.mcp-row form{margin-top:10px}}</style>
<?php require $root . '/admin/partials/footer.php'; ?>
