<?php
/**
 * Small Business Kit — Global header renderer.
 *
 * Rendered once per page (via the content_footer filter). The CSS pulls
 * it to the top of the viewport with position: fixed. JS toggles a
 * scroll-state class so it becomes solid on scroll.
 *
 * Header config is stored in site settings via SBKitAPI so it edits in
 * one place and applies everywhere.
 */

class SBKHeader {

    public static function render(): string {
        $base     = rtrim(SLATE_URL, '/');
        $homeUrl  = $base . '/home';

        $siteName = (string)ContentBuilderAPI::getSiteSetting('site_name', '');
        $logoPath = (string)ContentBuilderAPI::getSiteSetting('logo_url', '');
        $logoUrl  = $logoPath !== '' ? ContentBuilderAPI::mediaUrl($logoPath) : '';
        $ctaText  = trim((string)ContentBuilderAPI::getSiteSetting('header_cta_text', ''));
        $ctaHref  = trim((string)ContentBuilderAPI::getSiteSetting('header_cta_href', ''));

        // Header-specific settings (saved by SBK plugin)
        $phone     = (string)Database::setting('small-business-kit.header_phone');
        $phoneHref = (string)Database::setting('small-business-kit.header_phone_href');
        $email     = (string)Database::setting('small-business-kit.header_email');
        $socials   = json_decode((string)Database::setting('small-business-kit.header_socials'), true) ?: [];

        if ($phone !== '' && $phoneHref === '') {
            $phoneHref = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
        }

        $menu = ContentBuilderAPI::getMenuByLocation('header');
        $items = is_array($menu['items'] ?? null) ? $menu['items'] : [];

        $socialIcons = [
            'facebook'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 10-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.5 2.9h-2.4v7A10 10 0 0022 12z"/></svg>',
            'twitter'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18.9 3H22l-7.4 8.5L23 21h-6.8l-5.3-6.9L4.8 21H1.7l7.9-9L1 3h7l4.8 6.3L18.9 3zm-1.2 16h1.7L6.4 4.9H4.6L17.7 19z"/></svg>',
            'instagram' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.4A4 4 0 1112.6 8 4 4 0 0116 11.4z"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>',
            'linkedin'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.5 2h-17A1.5 1.5 0 002 3.5v17A1.5 1.5 0 003.5 22h17a1.5 1.5 0 001.5-1.5v-17A1.5 1.5 0 0020.5 2zM8 19H5v-9h3v9zM6.5 8.3a1.7 1.7 0 110-3.5 1.7 1.7 0 010 3.5zM19 19h-3v-4.7c0-1.1 0-2.6-1.6-2.6s-1.8 1.2-1.8 2.5V19h-3v-9h2.9v1.2A3.1 3.1 0 0115 9.7c3.1 0 3.7 2 3.7 4.7V19z"/></svg>',
            'youtube'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M23 7.3a3 3 0 00-2-2.1C19 4.7 12 4.7 12 4.7s-7 0-9 .5a3 3 0 00-2 2.1A31 31 0 00.5 12 31 31 0 001 16.7a3 3 0 002 2.1c2 .5 9 .5 9 .5s7 0 9-.5a3 3 0 002-2.1 31 31 0 00.5-4.7c0-1.7-.2-3.3-.5-4.7zM9.8 15.6V8.4l6 3.6-6 3.6z"/></svg>',
        ];

        ob_start();
        ?>
<header class="sbk-header" id="sbk-header">
  <?php if ($phone !== '' || $email !== '' || $socials): ?>
    <div class="sbk-utility">
      <div class="sbk-utility-inner">
        <div class="sbk-utility-contact">
          <?php if ($phone !== ''): ?>
            <a href="<?= e($phoneHref) ?>">
              <span class="sbk-utility-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.7 2.8a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.9.34 1.84.57 2.8.7A2 2 0 0122 16.92z"/></svg></span>
              <span><?= e($phone) ?></span>
            </a>
          <?php endif; ?>
          <?php if ($email !== ''): ?>
            <a href="mailto:<?= e($email) ?>">
              <span class="sbk-utility-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg></span>
              <span><?= e($email) ?></span>
            </a>
          <?php endif; ?>
        </div>
        <?php if ($socials): ?>
          <div class="sbk-utility-social">
            <?php foreach ($socials as $s):
              $p = strtolower($s['platform'] ?? '');
              $u = trim($s['url'] ?? '');
              if (!isset($socialIcons[$p]) || $u === '') continue;
            ?>
              <a href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e(ucfirst($p)) ?>"><?= $socialIcons[$p] ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="sbk-mainnav">
    <div class="sbk-mainnav-inner">
      <a class="sbk-brand" href="<?= e($homeUrl) ?>" aria-label="<?= e($siteName) ?> — home">
        <?php if ($logoUrl !== ''): ?>
          <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>" width="160" height="44">
          <span class="sbk-sr-only"><?= e($siteName) ?></span>
        <?php else: ?>
          <span class="sbk-brand-name"><?= e($siteName) ?></span>
        <?php endif; ?>
      </a>

      <button class="sbk-burger" type="button" aria-label="Open menu" data-sbk-burger>
        <span></span><span></span><span></span>
      </button>

      <nav class="sbk-menu" id="sbk-menu">
        <button class="sbk-close" type="button" aria-label="Close menu" data-sbk-close>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
        <div class="sbk-menu-inner">
          <?php foreach ($items as $it): ?>
            <a href="<?= e($it['url'] ?? '#') ?>"><?= e($it['label'] ?? '') ?></a>
          <?php endforeach; ?>
          <?php if ($ctaText !== ''): ?>
            <a class="sbk-nav-cta" href="<?= e($ctaHref !== '' ? $ctaHref : '#') ?>"><?= e($ctaText) ?></a>
          <?php endif; ?>
        </div>
      </nav>
    </div>
  </div>
</header>

<script>
(function () {
  var header = document.getElementById('sbk-header');
  if (!header) return;

  // Sticky scroll state — toggle .sbk-scrolled past 30px
  var onScroll = function () {
    if (window.scrollY > 30) header.classList.add('sbk-scrolled');
    else header.classList.remove('sbk-scrolled');
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Mobile glass overlay menu
  var burger = header.querySelector('[data-sbk-burger]');
  var close  = header.querySelector('[data-sbk-close]');
  var open  = function () { header.classList.add('sbk-open'); document.body.style.overflow = 'hidden'; };
  var shut  = function () { header.classList.remove('sbk-open'); document.body.style.overflow = ''; };
  if (burger) burger.addEventListener('click', open);
  if (close)  close.addEventListener('click', shut);
  header.querySelectorAll('.sbk-menu a').forEach(function (a) { a.addEventListener('click', shut); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') shut(); });
})();
</script>
        <?php
        return ob_get_clean();
    }
}
