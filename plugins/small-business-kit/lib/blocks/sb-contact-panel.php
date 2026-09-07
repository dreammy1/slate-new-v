<?php
/** sb-contact-panel — a modern two-column contact section:
 *  left = an embedded form ("Send Us a Message"); right = contact-info cards
 *  plus a service-area panel (uploaded map image, or a stylized SVG fallback).
 *  Mirrors the classic "contact + coverage" layout.
 *
 * @var string $eyebrow @var string $heading @var string $accentLine @var string $sub
 * @var string $formSlug @var string $minHeight
 * @var string $phone @var string $phoneHref @var string $intlPhone @var string $intlPhoneHref
 * @var string $addrName @var string $addrLines
 * @var string $serviceHeading @var string $serviceNote @var string $mapImage
 */
require_once __DIR__ . '/../Icons.php';

$base = rtrim(defined('SLATE_URL') ? SLATE_URL : '', '/');
$slug = trim((string)($formSlug ?? 'contact')); if ($slug === '') $slug = 'contact';
$minH = (int)($minHeight ?? 560); if ($minH < 240 || $minH > 4000) $minH = 560;
$src  = $base . '/forms/' . rawurlencode($slug) . '?embed=1&chrome=0';

$phone     = trim((string)($phone ?? ''));
$phoneHref = trim((string)($phoneHref ?? ''));
$intl      = trim((string)($intlPhone ?? ''));
$intlHref  = trim((string)($intlPhoneHref ?? ''));
$addrName  = trim((string)($addrName ?? ''));
$addrLines = trim((string)($addrLines ?? ''));
$svcHead   = trim((string)($serviceHeading ?? 'Our Service Area'));
$svcNote   = trim((string)($serviceNote ?? ''));
$mapImg    = trim((string)($mapImage ?? ''));
if ($mapImg !== '' && function_exists('cb_media_url')) { $mapImg = cb_media_url($mapImg); }
elseif ($mapImg !== '' && !preg_match('#^(https?:)?//#', $mapImg)) { $mapImg = $base . '/' . ltrim($mapImg, '/'); }

$uid = 'sbcp-' . substr(md5($slug . '|' . $minH), 0, 8);
?>
<section class="sb sb-bg-surface sb-contact-panel-sec">
  <div class="sb-inner">
    <div class="sb-head">
      <?php if (!empty($eyebrow)): ?><span class="sb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
      <?php if (!empty($heading) || !empty($accentLine)): ?>
        <h2><?= e($heading ?? '') ?> <?php if (!empty($accentLine)): ?><em><?= e($accentLine) ?></em><?php endif; ?></h2>
      <?php endif; ?>
      <?php if (!empty($sub)): ?><p class="sb-section-sub"><?= e($sub) ?></p><?php endif; ?>
    </div>

    <!-- ── Contact info row (3 across, equal height) ───────── -->
    <div class="sb-cpanel-inforow">
      <?php if ($phone !== ''): ?>
      <div class="sb-card sb-cpanel-info">
        <span class="sb-card-icon"><?= SBKIcons::svg('phone', 22) ?></span>
        <div><span class="sb-cpanel-info-l">Phone</span>
          <?php if ($phoneHref !== ''): ?><a href="<?= e($phoneHref) ?>"><?= e($phone) ?></a>
          <?php else: ?><strong><?= e($phone) ?></strong><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($intl !== ''): ?>
      <div class="sb-card sb-cpanel-info">
        <span class="sb-card-icon"><?= SBKIcons::svg('globe', 22) ?></span>
        <div><span class="sb-cpanel-info-l">Outside the U.S.</span>
          <?php if ($intlHref !== ''): ?><a href="<?= e($intlHref) ?>"><?= e($intl) ?></a>
          <?php else: ?><strong><?= e($intl) ?></strong><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($addrName !== '' || $addrLines !== ''): ?>
      <div class="sb-card sb-cpanel-info sb-cpanel-addr">
        <span class="sb-card-icon"><?= SBKIcons::svg('pin', 22) ?></span>
        <div><span class="sb-cpanel-info-l">Mailing Address</span>
          <?php if ($addrName !== ''): ?><strong><?= e($addrName) ?></strong><?php endif; ?>
          <?php if ($addrLines !== ''): ?><p><?= nl2br(e($addrLines)) ?></p><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Form + service-area map, side by side (matched height) ── -->
    <div class="sb-cpanel" id="<?= e($uid) ?>">
      <!-- Left: message form -->
      <div class="sb-cpanel-form sb-card">
        <div class="sb-cpanel-formhead">
          <span class="sb-cpanel-formico"><?= SBKIcons::svg('mail', 24) ?></span>
          <div>
            <h3>Send Us a Message</h3>
            <p>Fill out the form and we’ll get back to you shortly.</p>
          </div>
        </div>
        <iframe class="sb-cform-frame" title="Contact form" src="<?= e($src) ?>"
                scrolling="no" style="height:<?= $minH ?>px"></iframe>
      </div>

      <!-- Right: service-area map only (fills to the form's height) -->
      <div class="sb-card sb-cpanel-map">
        <div class="sb-cpanel-map-canvas">
          <?php if ($mapImg !== ''): ?>
            <img src="<?= e($mapImg) ?>" alt="<?= e($svcHead) ?>" loading="lazy" decoding="async">
          <?php else: ?>
            <?php /* Stylized coverage map (Chicago-centered radius). */ ?>
            <svg viewBox="0 0 480 360" preserveAspectRatio="xMidYMid meet" role="img"
                 aria-label="Service area around Chicago and the Great Lakes" class="sb-cpanel-svgmap">
              <defs>
                <radialGradient id="<?= e($uid) ?>-core" cx="48%" cy="50%" r="58%">
                  <stop offset="0%" stop-color="#fff6cf"/><stop offset="100%" stop-color="#fdeeae"/>
                </radialGradient>
              </defs>
              <rect x="-10" y="-10" width="500" height="380" fill="#eef4fb"/>
              <!-- Lake Michigan: filled shoreline anchored to the right edge -->
              <path d="M392 -10 C366 74 364 190 382 268 C392 314 404 338 420 360 L490 360 L490 -10 Z"
                    fill="#2f9ff0" opacity="0.9"/>
              <!-- Coverage rings -->
              <circle cx="212" cy="182" r="158" fill="#cfe3f7" opacity="0.6"/>
              <circle cx="216" cy="182" r="104" fill="url(#<?= e($uid) ?>-core)" opacity="0.92"/>
              <!-- Highways (subtle) -->
              <g stroke="#c3ccd6" stroke-width="2.4" fill="none" opacity="0.7" stroke-linecap="round">
                <path d="M110 90 L340 300"/><path d="M76 196 L360 182"/><path d="M216 54 L216 320"/>
              </g>
              <!-- City dots + labels -->
              <g font-family="var(--slate-font-sans, sans-serif)" font-size="13" font-weight="700" fill="#0b1c2c">
                <g><circle cx="164" cy="118" r="4.5"/><text x="176" y="123">Madison</text></g>
                <g><circle cx="300" cy="108" r="4.5"/><text x="312" y="113">Milwaukee</text></g>
                <g><circle cx="120" cy="188" r="4.5"/><text x="132" y="193">Dubuque</text></g>
                <g><circle cx="196" cy="190" r="4.5"/><text x="208" y="195">Rockford</text></g>
                <g><circle cx="342" cy="200" r="6.5"/><text x="356" y="205" font-size="15" font-weight="800">Chicago</text></g>
                <g><circle cx="178" cy="270" r="4.5"/><text x="190" y="275">Peoria</text></g>
                <g><circle cx="380" cy="252" r="4.5"/><text x="392" y="257">Michigan City</text></g>
              </g>
            </svg>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="sb-cpanel-badges">
      <span><?= SBKIcons::svg('shield', 18) ?> Professional &amp; Reliable</span>
      <span><?= SBKIcons::svg('star', 18) ?> Industry Trusted</span>
      <span><?= SBKIcons::svg('phone', 18) ?> Dedicated Customer Support</span>
    </div>
  </div>
</section>
<script data-cfasync="false">(function(){
  if (window.__sbCformResize) return; window.__sbCformResize = 1;
  var SEL = 'iframe.sb-cform-frame';
  // The embed is same-origin, so read its real content height DIRECTLY — no
  // dependency on the postMessage handshake (which Cloudflare Rocket Loader
  // can stall, leaving the iframe stuck tall). postMessage is kept as a
  // secondary signal for the rare cross-origin case.
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
  function fitAll(){ var fr = document.querySelectorAll(SEL); for (var i=0;i<fr.length;i++) measure(fr[i]); }
  function watch(f){
    f.addEventListener('load', function(){
      measure(f);
      [80,250,600,1200].forEach(function(t){ setTimeout(function(){ measure(f); }, t); });
      // Track later reflows inside the form (validation, dynamic fields).
      try {
        var d = f.contentDocument;
        if (d && window.ResizeObserver){ new ResizeObserver(function(){ measure(f); }).observe(d.body); }
      } catch (e) {}
    });
    measure(f); // in case it's already loaded
  }
  var frames = document.querySelectorAll(SEL);
  for (var i=0;i<frames.length;i++) watch(frames[i]);
  window.addEventListener('resize', fitAll);
  // Fallback: postMessage from the embed (cross-origin safety net).
  window.addEventListener('message', function(e){
    if (!e || !e.data || e.data.type !== 'cb-form-height') return;
    var fr = document.querySelectorAll(SEL);
    for (var j=0;j<fr.length;j++){
      if (fr[j].contentWindow === e.source){ var h = parseInt(e.data.height,10); if (h>0) fr[j].style.height = h + 'px'; }
    }
  });
})();</script>
