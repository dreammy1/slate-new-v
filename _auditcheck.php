<?php
require __DIR__.'/config.php';
$home = ContentBuilderAPI::getPostBySlug('page','home');
$head = Hook::applyFilters('content_head_tags', '', $home);
$foot = Hook::applyFilters('content_footer', '', $home);

foreach ([
  '<meta name="description"' => 'meta description tag',
  'og:title' => 'OpenGraph title',
  'og:description' => 'OpenGraph description',
  'rel="preload" as="image"' => 'hero image preload',
  '<h3 class="sbk-footer-h">' => 'footer h3 (no longer h4)',
  'aria-label=' => 'aria-label on links',
  'sbk-sr-only' => 'screen-reader-only label rendered',
  '<h4' => 'NO h4 anywhere',
] as $needle => $desc) {
    $in = str_contains($head . $foot, $needle);
    $expected = !str_starts_with($desc, 'NO ');
    echo (($in === $expected) ? '✓' : '✗') . " $desc\n";
}
