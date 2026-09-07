<?php
require __DIR__.'/config.php';
$tid  = current_tenant_id();
$base = rtrim(SLATE_URL, '/');

/* 1. Replace /slate/p/<slug> → /slate/<slug> in the header menu items. */
$menu = ContentBuilderAPI::getMenuByLocation('header', $tid);
if ($menu) {
    $items = $menu['items'];
    foreach ($items as &$it) {
        if (!empty($it['url'])) $it['url'] = str_replace('/slate/p/', '/slate/', $it['url']);
    }
    unset($it);
    ContentBuilderAPI::saveMenu([
        'id'=>$menu['id'],'name'=>$menu['name'],'location'=>'header','items'=>$items
    ], $tid);
    echo "✓ header menu links shortened\n";
}

/* 2. Update SBK footer columns (services + company) saved as JSON settings. */
foreach (['small-business-kit.footer_col1','small-business-kit.footer_col2'] as $key) {
    $items = json_decode((string)Database::setting($key), true) ?: [];
    foreach ($items as &$it) {
        if (!empty($it['url'])) $it['url'] = str_replace('/slate/p/', '/slate/', $it['url']);
    }
    unset($it);
    Database::setSetting($key, json_encode($items));
}
echo "✓ footer column links shortened\n";

/* 3. Header CTA href. */
$cta = (string)ContentBuilderAPI::getSiteSetting('header_cta_href');
if ($cta !== '') {
    ContentBuilderAPI::setSiteSetting('header_cta_href', str_replace('/slate/p/', '/slate/', $cta));
}
echo "✓ header CTA link shortened\n";

/* 4. Replace /p/<slug> hrefs inside every page's layout (sb-* blocks). */
$rows = Database::rows("SELECT id,slug FROM contentbuilder_posts WHERE tenant_id=? AND type='page'", [$tid]);
foreach ($rows as $r) {
    $p = ContentBuilderAPI::getPost((int)$r['id'], $tid);
    if (!$p) continue;
    $json = json_encode($p['layout']);
    $new  = str_replace('/slate/p/', '/slate/', $json);
    if ($new !== $json) {
        $layout = json_decode($new, true) ?: [];
        ContentBuilderAPI::savePost([
            'id'=>$p['id'],'type'=>'page','title'=>$p['title'],
            'slug'=>$p['slug'],'status'=>$p['status'],'layout'=>$layout,
        ], $tid);
        echo "  ✓ rewrote /p/ → / inside /{$p['slug']}\n";
    }
}
echo "Done.\n";
