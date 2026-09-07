<?php
require __DIR__ . '/config.php';

$before = (string) Database::setting('sidebar_theme');   // remember current

// Registry sanity
$themes = slate_sidebar_themes();
echo "themes: " . implode(', ', array_keys($themes)) . "\n";

// Emit for 'teal'
Database::setSetting('sidebar_theme', 'teal');
ob_start(); slate_sidebar_theme_emit(); $out = trim(ob_get_clean());
echo "teal emit: " . ($out !== '' ? $out : '(empty)') . "\n";
echo (strpos($out, '--sidebar-bg:#05242F') !== false ? "teal bg OK\n" : "teal bg MISSING\n");

// Restore original (unset => 'ink' default, which emits nothing)
Database::setSetting('sidebar_theme', $before);
echo "restored sidebar_theme to: " . var_export($before, true) . "\n";
