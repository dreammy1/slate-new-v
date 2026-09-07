<?php
/** Inline SVG icon set shared by all sb-* blocks. */
class SBKIcons {
    public static function svg(string $name, int $size = 24): string {
        $paths = [
            'shield' => '<path d="M12 3l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V7z"/>',
            'bolt'   => '<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>',
            'check'  => '<path d="M20 6L9 17l-5-5"/>',
            'star'   => '<path d="M12 2l3 7 7 .8-5.4 4.8L18 22l-6-3.5L6 22l1.4-7.4L2 9.8 9 9z"/>',
            'gauge'  => '<circle cx="12" cy="14" r="9"/><path d="M12 14l4-4M3 14a9 9 0 0118 0"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/>',
            'wave'   => '<path d="M2 12c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/><path d="M2 18c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/>',
            'droplet'=> '<path d="M12 3s7 8 7 13a7 7 0 11-14 0c0-5 7-13 7-13z"/>',
            'video'  => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
            'phone'  => '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.7 2.8a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.9.34 1.84.57 2.8.7A2 2 0 0122 16.92z"/>',
            'globe'  => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 010 20M12 2a15 15 0 000 20"/>',
            'pin'    => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>',
            'mail'   => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/>',
            'boat'   => '<path d="M2 20s2 2 5 2 4-2 5-2 2 2 5 2 5-2 5-2"/><path d="M3 16l1.5-4h15L21 16"/><path d="M12 4l4 8H8z"/><path d="M12 4v8"/>',
            'sail'   => '<path d="M2 20s2 2 5 2 4-2 5-2 2 2 5 2 5-2 5-2"/><path d="M12 2v15M12 4l8 13H4z"/>',
            'arrow'  => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'heart'  => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
            'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'leaf'   => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/>',
        ];
        $p = $paths[$name] ?? $paths['star'];
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
    }

    public static function options(): array {
        $names = ['shield','bolt','check','star','gauge','search','wave','droplet','video','phone','globe','pin','mail','boat','sail','arrow','heart','clock','leaf'];
        return array_map(fn($n) => ['v' => $n, 'l' => ucfirst($n)], $names);
    }
}
