<?php
/**
 * Content Builder — navigation menu builder.
 *
 * Create named menus, add links (to pages or custom URLs), order them,
 * and assign a menu to the 'header' or 'footer' location. The public
 * Theme renders whatever menu is assigned to each location.
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('content.edit');

$pageTitle  = 'Menus';
$currentNav = 'content-menus';

$editId = (int)($_GET['id'] ?? 0);
$flash  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        $act = $_POST['action'] ?? 'save';
        if ($act === 'delete') {
            ContentBuilderAPI::deleteMenu((int)($_POST['id'] ?? 0));
            $flash = ['type'=>'success','msg'=>'Menu deleted.'];
            $editId = 0;
        } else {
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items)) $items = [];
            // sanitize
            $clean = [];
            foreach ($items as $it) {
                $label = trim((string)($it['label'] ?? ''));
                $url   = trim((string)($it['url'] ?? ''));
                if ($label === '' || $url === '') continue;
                $clean[] = ['label'=>mb_substr($label,0,128), 'url'=>mb_substr($url,0,512),
                            'type'=>($it['type'] ?? 'custom')];
            }
            $savedId = ContentBuilderAPI::saveMenu([
                'id'       => (int)($_POST['id'] ?? 0) ?: null,
                'name'     => $_POST['name'] ?? 'Menu',
                'location' => $_POST['location'] ?? '',
                'items'    => $clean,
            ]);
            $flash  = ['type'=>'success','msg'=>'Menu saved.'];
            $editId = $savedId;
        }
    }
}

$menus = ContentBuilderAPI::getMenus();
$menu  = $editId ? ContentBuilderAPI::getMenu($editId) : null;

// Pages list for the quick "add page link" picker.
$pages = ContentBuilderAPI::listPosts('page', ['status'=>'published','limit'=>200]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Menus'],
]); ?>

<div class="page-header">
    <div><h1>Menus</h1>
    <p class="page-header-sub">Build navigation, then assign it to your header or footer.</p></div>
    <a class="btn" href="menus.php">+ New menu</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="cb-editor-grid">
    <div class="cb-main">
        <form method="post" id="cb-menu-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)($menu['id'] ?? 0) ?>">
            <input type="hidden" name="items" id="cb-menu-items"
                   value="<?= e(json_encode($menu['items'] ?? [])) ?>">

            <div class="card">
                <div class="field">
                    <label class="field-label" for="name">Menu name</label>
                    <input type="text" id="name" name="name" required
                           value="<?= e($menu['name'] ?? '') ?>" placeholder="Main navigation">
                </div>
                <div class="field">
                    <label class="field-label" for="location">Location</label>
                    <select id="location" name="location" class="field-input">
                        <?php $loc = $menu['location'] ?? ''; ?>
                        <option value=""       <?= $loc===''?'selected':'' ?>>— not assigned —</option>
                        <option value="header" <?= $loc==='header'?'selected':'' ?>>Header</option>
                        <option value="footer" <?= $loc==='footer'?'selected':'' ?>>Footer</option>
                    </select>
                    <small class="text-muted">Only one menu can occupy each location.</small>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Menu items</h2></div>
                <div id="cb-menu-list" class="cb-canvas"></div>

                <div class="cb-menu-add">
                    <div class="field">
                        <label class="field-label">Add a page</label>
                        <select id="cb-page-pick" class="field-input">
                            <option value="">— choose a page —</option>
                            <?php foreach ($pages as $p): ?>
                                <option value="<?= e(ContentBuilderAPI::permalink($p)) ?>"
                                        data-label="<?= e($p['title'] ?: $p['slug']) ?>">
                                    <?= e($p['title'] ?: $p['slug']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm mt-2" id="cb-add-page">Add page link</button>
                    </div>
                    <div class="field">
                        <label class="field-label">Add a custom link</label>
                        <input type="text" id="cb-custom-label" placeholder="Label" class="field-input">
                        <input type="text" id="cb-custom-url" placeholder="https://… or /p/slug" class="field-input mt-2">
                        <button type="button" class="btn btn-sm mt-2" id="cb-add-custom">Add custom link</button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save menu</button>
            <?php if ($menu): ?>
                <button type="submit" class="btn btn-danger" name="action" value="delete"
                        onclick="return confirm('Delete this menu?')">Delete menu</button>
            <?php endif; ?>
        </form>
    </div>

    <aside class="cb-sidebar">
        <div class="card">
            <div class="card-header"><h2>Your menus</h2></div>
            <?php if (!$menus): ?>
                <p class="text-muted text-sm">No menus yet.</p>
            <?php else: ?>
                <div class="cb-rows">
                    <?php foreach ($menus as $m): ?>
                        <div class="cb-row">
                            <div class="cb-row-main">
                                <a href="menus.php?id=<?= (int)$m['id'] ?>"><strong><?= e($m['name']) ?></strong></a>
                                <div class="text-muted text-sm">
                                    <?= count($m['items']) ?> items
                                    <?= $m['location'] ? '· ' . e($m['location']) : '· unassigned' ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </aside>
</div>

<script>
(function(){
  var itemsInput = document.getElementById('cb-menu-items');
  var list = document.getElementById('cb-menu-list');
  if(!itemsInput||!list) return;
  var items; try{ items = JSON.parse(itemsInput.value||'[]'); }catch(e){ items=[]; }
  if(!Array.isArray(items)) items=[];

  function sync(){ itemsInput.value = JSON.stringify(items); paint(); }
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}

  function paint(){
    list.innerHTML='';
    if(!items.length){ list.innerHTML='<div class="cb-canvas-empty">No items. Add a page or custom link below.</div>'; return; }
    items.forEach(function(it,i){
      var row=document.createElement('div'); row.className='cb-block';
      row.innerHTML='<div class="cb-block-head">'
        +'<span class="cb-block-type">'+esc(it.label)+'</span>'
        +'<div class="cb-block-controls"></div></div>'
        +'<div class="cb-block-body"><div class="text-muted text-sm">'+esc(it.url)+'</div></div>';
      var ctr=row.querySelector('.cb-block-controls');
      ctr.appendChild(btn('↑',function(){ move(i,i-1); }));
      ctr.appendChild(btn('↓',function(){ move(i,i+1); }));
      ctr.appendChild(btn('✕',function(){ items.splice(i,1); sync(); },'cb-danger'));
      list.appendChild(row);
    });
  }
  function btn(t,fn,cls){var b=document.createElement('button');b.type='button';b.className='cb-icon-btn'+(cls?' '+cls:'');b.textContent=t;b.addEventListener('click',fn);return b;}
  function move(f,t){ if(t<0||t>=items.length)return; var x=items.splice(f,1)[0]; items.splice(t,0,x); sync(); }

  document.getElementById('cb-add-page').addEventListener('click',function(){
    var sel=document.getElementById('cb-page-pick');
    var opt=sel.options[sel.selectedIndex];
    if(!sel.value) return;
    items.push({label:opt.getAttribute('data-label'),url:sel.value,type:'page'}); sync(); sel.value='';
  });
  document.getElementById('cb-add-custom').addEventListener('click',function(){
    var l=document.getElementById('cb-custom-label').value.trim();
    var u=document.getElementById('cb-custom-url').value.trim();
    if(!l||!u) return;
    items.push({label:l,url:u,type:'custom'}); sync();
    document.getElementById('cb-custom-label').value=''; document.getElementById('cb-custom-url').value='';
  });

  paint();
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
