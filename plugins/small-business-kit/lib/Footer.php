<?php
/**
 * Small Business Kit — Global footer renderer.
 *
 * Rendered on every Content Builder page via the `content_footer`
 * filter alongside SBKHeader. Replaces CB's basic site footer with a
 * modern multi-column dark footer. Config reads from the same SBK
 * settings as the header so brand / phone / email / socials are in
 * one place.
 */
class SBKFooter {

    public static function render(): string {
        $base     = rtrim(SLATE_URL, '/');
        $homeUrl  = $base . '/home';

        $siteName = (string)ContentBuilderAPI::getSiteSetting('site_name', 'Site');
        $tagline  = trim((string)ContentBuilderAPI::getSiteSetting('tagline', ''));
        $logoPath = (string)ContentBuilderAPI::getSiteSetting('logo_url', '');
        $logoUrl  = $logoPath !== '' ? ContentBuilderAPI::mediaUrl($logoPath) : '';
        $about    = trim((string)Database::setting('small-business-kit.footer_about'));
        if ($about === '') $about = $tagline;
        if ($about === '') $about = 'Independent, accredited service since 1996.';

        $phone     = (string)Database::setting('small-business-kit.header_phone');
        $phoneHref = (string)Database::setting('small-business-kit.header_phone_href');
        $email     = (string)Database::setting('small-business-kit.header_email');
        $address   = trim((string)Database::setting('small-business-kit.footer_address'));
        $socials   = json_decode((string)Database::setting('small-business-kit.header_socials'), true) ?: [];

        if ($phone !== '' && $phoneHref === '') {
            $phoneHref = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
        }

        // Two link columns. If footer menus exist they win, else fall back to
        // a reasonable default split derived from the header menu.
        $col1Title = (string)Database::setting('small-business-kit.footer_col1_title') ?: 'Services';
        $col2Title = (string)Database::setting('small-business-kit.footer_col2_title') ?: 'Company';
        $col1Items = json_decode((string)Database::setting('small-business-kit.footer_col1'), true) ?: [];
        $col2Items = json_decode((string)Database::setting('small-business-kit.footer_col2'), true) ?: [];

        if (!$col1Items && !$col2Items) {
            // Derive from header menu.
            $menu = ContentBuilderAPI::getMenuByLocation('header');
            $items = is_array($menu['items'] ?? null) ? $menu['items'] : [];
            // Split roughly in half.
            $half = (int)ceil(count($items) / 2);
            $col1Items = array_slice($items, 0, $half);
            $col2Items = array_slice($items, $half);
        }

        $socialIcons = [
            'facebook'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 10-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.5 2.9h-2.4v7A10 10 0 0022 12z"/></svg>',
            'twitter'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18.9 3H22l-7.4 8.5L23 21h-6.8l-5.3-6.9L4.8 21H1.7l7.9-9L1 3h7l4.8 6.3L18.9 3zm-1.2 16h1.7L6.4 4.9H4.6L17.7 19z"/></svg>',
            'instagram' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.4A4 4 0 1112.6 8 4 4 0 0116 11.4z"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>',
            'linkedin'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.5 2h-17A1.5 1.5 0 002 3.5v17A1.5 1.5 0 003.5 22h17a1.5 1.5 0 001.5-1.5v-17A1.5 1.5 0 0020.5 2zM8 19H5v-9h3v9zM6.5 8.3a1.7 1.7 0 110-3.5 1.7 1.7 0 010 3.5zM19 19h-3v-4.7c0-1.1 0-2.6-1.6-2.6s-1.8 1.2-1.8 2.5V19h-3v-9h2.9v1.2A3.1 3.1 0 0115 9.7c3.1 0 3.7 2 3.7 4.7V19z"/></svg>',
            'youtube'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M23 7.3a3 3 0 00-2-2.1C19 4.7 12 4.7 12 4.7s-7 0-9 .5a3 3 0 00-2 2.1A31 31 0 00.5 12 31 31 0 001 16.7a3 3 0 002 2.1c2 .5 9 .5 9 .5s7 0 9-.5a3 3 0 002-2.1 31 31 0 00.5-4.7c0-1.7-.2-3.3-.5-4.7zM9.8 15.6V8.4l6 3.6-6 3.6z"/></svg>',
        ];

        $year = date('Y');

        ob_start();
        ?>
<footer class="sbk-footer">
  <div class="sbk-footer-inner">
    <div class="sbk-footer-brand">
      <a href="<?= e($homeUrl) ?>" class="sbk-footer-logo" aria-label="<?= e($siteName) ?> — home">
        <?php if ($logoUrl !== ''): ?>
          <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>" width="160" height="46" loading="lazy">
          <span class="sbk-sr-only"><?= e($siteName) ?></span>
        <?php else: ?>
          <span class="sbk-footer-brand-name"><?= e($siteName) ?></span>
        <?php endif; ?>
      </a>
      <p class="sbk-footer-about"><?= e($about) ?></p>
      <?php if ($socials): ?>
        <div class="sbk-footer-social">
          <?php foreach ($socials as $s):
            $p = strtolower($s['platform'] ?? ''); $u = trim($s['url'] ?? '');
            if (!isset($socialIcons[$p]) || $u === '') continue;
          ?>
            <a href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e(ucfirst($p)) ?>"><?= $socialIcons[$p] ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($col1Items): ?>
      <div class="sbk-footer-col">
        <h3 class="sbk-footer-h"><?= e($col1Title) ?></h3>
        <ul>
          <?php foreach ($col1Items as $it): ?>
            <li><a href="<?= e($it['url'] ?? '#') ?>"><?= e($it['label'] ?? '') ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($col2Items): ?>
      <div class="sbk-footer-col">
        <h3 class="sbk-footer-h"><?= e($col2Title) ?></h3>
        <ul>
          <?php foreach ($col2Items as $it): ?>
            <li><a href="<?= e($it['url'] ?? '#') ?>"><?= e($it['label'] ?? '') ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="sbk-footer-col sbk-footer-contact">
      <h3 class="sbk-footer-h">Get in touch</h3>
      <ul>
        <?php if ($phone !== ''): ?>
          <li>
            <span class="sbk-footer-ico">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.7 2.8a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.9.34 1.84.57 2.8.7A2 2 0 0122 16.92z"/></svg>
            </span>
            <a href="<?= e($phoneHref) ?>"><?= e($phone) ?></a>
          </li>
        <?php endif; ?>
        <?php if ($email !== ''): ?>
          <li>
            <span class="sbk-footer-ico">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg>
            </span>
            <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
          </li>
        <?php endif; ?>
        <?php if ($address !== ''): ?>
          <li>
            <span class="sbk-footer-ico">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
            </span>
            <span><?= nl2br(e($address)) ?></span>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>

  <div class="sbk-footer-bottom">
    <div class="sbk-footer-bottom-inner">
      <span>&copy; <?= $year ?> <?= e($siteName) ?>. All rights reserved.</span>
      <a href="<?= e($homeUrl) ?>">Back to top &uarr;</a>
    </div>
  </div>
</footer>
        <?php
        return ob_get_clean();
    }
}
