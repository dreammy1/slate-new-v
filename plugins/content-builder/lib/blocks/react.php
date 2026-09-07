<?php
/**
 * @var string $component  registered React component name
 * @var array  $componentProps  props passed to it (NOT 'props' — see note below)
 * @var string $fallback   what the server renders when React is not running
 *
 * ADR-0013 point 5. The block names a component and carries data; it never
 * carries markup. The PHP renderer emits a mount point plus the fallback so the
 * page is not a hole for a crawler or a visitor without JS, and T2's runtime
 * hydrates the same element from the same props.
 *
 * The fallback is ESCAPED, not raw. Raw markup is what the `html` block is for,
 * and that one is permission-gated behind content.publish; letting a fallback
 * smuggle markup past that gate would reopen it. A fallback needing real markup
 * should sit next to this block as an `html` block instead.
 *
 * The field is `componentProps`, not `props`. Renderer::renderBlock() does
 * extract($props, EXTR_SKIP), and $props already holds the block's whole prop
 * array — so a field named `props` is silently skipped and the template sees the
 * outer array instead of its own value. No block may use that name.
 */
$name = trim((string)($component ?? ''));
if ($name === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $name)) return;

$payload = is_array($componentProps ?? null) ? $componentProps : [];
$json    = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) $json = '{}';
?>
<div class="cb-react" data-component="<?= e($name) ?>" data-props="<?= e($json) ?>">
    <?php if (($fallback ?? '') !== ''): ?><div class="cb-react-fallback"><?= e($fallback) ?></div><?php endif; ?>
</div>
