<?php
/** sb-survey-tabs — a modern segmented tab switcher that embeds two forms
 *  (e.g. Powerboat / Sailboat survey orders) inline. The inactive form is
 *  lazy-loaded on first activation; both iframes auto-resize to their content
 *  height via the `cb-form-height` postMessage the Forms embed emits.
 *
 * @var string $eyebrow @var string $heading @var string $accentLine @var string $sub
 * @var string $bg
 * @var string $tab1Label @var string $tab1Desc @var string $tab1Slug @var string $tab1Icon
 * @var string $tab2Label @var string $tab2Desc @var string $tab2Slug @var string $tab2Icon
 * @var string $minHeight
 */
require_once __DIR__ . '/../Icons.php';

$bg   = in_array($bg ?? 'surface', ['page','surface','tint','dark'], true) ? $bg : 'surface';
$minH = (int)($minHeight ?? 640); if ($minH < 240 || $minH > 6000) $minH = 640;
$base = rtrim(defined('SLATE_URL') ? SLATE_URL : '', '/');

$tabs = [
    [
        'label' => trim((string)($tab1Label ?? 'Powerboat survey')) ?: 'Powerboat survey',
        'desc'  => trim((string)($tab1Desc ?? '')),
        'slug'  => trim((string)($tab1Slug ?? '')),
        'icon'  => trim((string)($tab1Icon ?? 'boat')) ?: 'boat',
    ],
    [
        'label' => trim((string)($tab2Label ?? 'Sailboat survey')) ?: 'Sailboat survey',
        'desc'  => trim((string)($tab2Desc ?? '')),
        'slug'  => trim((string)($tab2Slug ?? '')),
        'icon'  => trim((string)($tab2Icon ?? 'sail')) ?: 'sail',
    ],
];
// Unique id so multiple instances on a page don't collide.
$uid = 'sbft-' . substr(md5(($tabs[0]['slug'] ?? '') . '|' . ($tabs[1]['slug'] ?? '') . '|' . $minH), 0, 8);
?>
<section class="sb sb-bg-<?= e($bg) ?>">
  <div class="sb-inner">
    <?php if (!empty($eyebrow) || !empty($heading)): ?>
      <div class="sb-head">
        <?php if (!empty($eyebrow)): ?><span class="sb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading) || !empty($accentLine)): ?>
          <h2><?= e($heading ?? '') ?> <?php if (!empty($accentLine)): ?><em><?= e($accentLine) ?></em><?php endif; ?></h2>
        <?php endif; ?>
        <?php if (!empty($sub)): ?><p class="sb-section-sub"><?= e($sub) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="sb-formtabs" id="<?= e($uid) ?>" data-sb-formtabs>
      <div class="sb-formtabs-nav" role="tablist" aria-label="Choose a form">
        <?php foreach ($tabs as $i => $t): ?>
          <button type="button" class="sb-formtab<?= $i === 0 ? ' is-active' : '' ?>"
                  role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                  id="<?= e($uid) ?>-tab<?= $i ?>" aria-controls="<?= e($uid) ?>-panel<?= $i ?>"
                  data-sb-tab="<?= $i ?>">
            <span class="sb-formtab-ico"><?= SBKIcons::svg($t['icon'], 22) ?></span>
            <span class="sb-formtab-txt">
              <strong><?= e($t['label']) ?></strong>
              <?php if ($t['desc'] !== ''): ?><span><?= e($t['desc']) ?></span><?php endif; ?>
            </span>
          </button>
        <?php endforeach; ?>
      </div>

      <div class="sb-formtabs-panels">
        <?php foreach ($tabs as $i => $t):
            $src = $t['slug'] !== '' ? $base . '/forms/' . rawurlencode($t['slug']) . '?embed=1&chrome=0' : '';
            ?>
          <div class="sb-formtab-panel<?= $i === 0 ? ' is-active' : '' ?>"
               role="tabpanel" id="<?= e($uid) ?>-panel<?= $i ?>"
               aria-labelledby="<?= e($uid) ?>-tab<?= $i ?>" data-sb-panel="<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>>
            <?php if ($src === ''): ?>
              <div class="sb-formtab-empty">This tab has no form selected yet.</div>
            <?php elseif ($i === 0): ?>
              <iframe class="sb-formtab-frame" title="<?= e($t['label']) ?>" src="<?= e($src) ?>"
                      scrolling="no" style="height:<?= $minH ?>px"></iframe>
            <?php else: ?>
              <iframe class="sb-formtab-frame" title="<?= e($t['label']) ?>" data-sb-src="<?= e($src) ?>"
                      scrolling="no" style="height:<?= $minH ?>px"></iframe>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<script data-cfasync="false">(function(){
  var root = document.getElementById('<?= e($uid) ?>');
  if (!root || root.__sbInit) return; root.__sbInit = 1;
  var tabs   = root.querySelectorAll('[data-sb-tab]');
  var panels = root.querySelectorAll('[data-sb-panel]');
  function activate(i){
    tabs.forEach(function(t){
      var on = t.getAttribute('data-sb-tab') === String(i);
      t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true':'false');
    });
    panels.forEach(function(p){
      var on = p.getAttribute('data-sb-panel') === String(i);
      p.classList.toggle('is-active', on); if (on) p.removeAttribute('hidden'); else p.setAttribute('hidden','');
      if (on){ // lazy-load the frame the first time this panel is shown
        var f = p.querySelector('iframe[data-sb-src]');
        if (f && !f.src){ f.src = f.getAttribute('data-sb-src'); }
      }
    });
  }
  tabs.forEach(function(t){ t.addEventListener('click', function(){ activate(t.getAttribute('data-sb-tab')); }); });

  // Same-origin embeds: read the real content height DIRECTLY (robust against
  // Rocket Loader stalling the postMessage handshake). Runs per iframe on load
  // + a few settle ticks; postMessage kept as a cross-origin fallback.
  function measure(f){
    try {
      var d = f.contentDocument || (f.contentWindow && f.contentWindow.document);
      if (d && d.body){
        var h = Math.max(d.body.scrollHeight, d.documentElement ? d.documentElement.scrollHeight : 0);
        if (h > 40) { f.style.height = h + 'px'; return true; }
      }
    } catch (e) {}
    return false;
  }
  root.querySelectorAll('iframe.sb-formtab-frame').forEach(function(f){
    f.addEventListener('load', function(){
      measure(f);
      [80,250,600,1200].forEach(function(t){ setTimeout(function(){ measure(f); }, t); });
      try { var d = f.contentDocument; if (d && window.ResizeObserver){ new ResizeObserver(function(){ measure(f); }).observe(d.body); } } catch (e) {}
    });
    measure(f);
  });
  if (!window.__sbFormtabsResize){
    window.__sbFormtabsResize = 1;
    window.addEventListener('message', function(e){
      if (!e || !e.data || e.data.type !== 'cb-form-height') return;
      var frames = document.querySelectorAll('iframe.sb-formtab-frame');
      for (var i=0;i<frames.length;i++){
        if (frames[i].contentWindow === e.source){
          var h = parseInt(e.data.height,10); if (h>0) frames[i].style.height = h + 'px';
        }
      }
    });
  }
})();</script>
