<?php
require __DIR__.'/config.php';
foreach (Database::rows("SELECT id, path, original_name, width, height FROM media_files WHERE kind='image' ORDER BY id DESC LIMIT 15") as $r) {
    printf("%d %s %dx%d  %s\n", $r['id'], $r['original_name'], $r['width'], $r['height'], $r['path']);
}
