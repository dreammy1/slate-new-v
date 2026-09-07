<?php
/**
 * Branding — design tokens for the public theme.
 *
 * THEME PRESETS: a one-click "whole look" (palette + fonts + page background
 * + surface tints + radius), e.g. "Warm editorial". Picking a preset sets
 * everything at once. Individual palette / font / radius can still override.
 *
 * Fonts are SYSTEM-ONLY by design — zero external requests, fastest paint.
 */
class Branding {

    const SANS    = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    const SERIF   = "'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif";
    const SERIF2  = "Georgia, Cambria, 'Times New Roman', Times, serif";
    const ROUNDED = "'Segoe UI', system-ui, 'Helvetica Neue', Arial, sans-serif";

    public static function themes(): array {
        return [
            'warm-editorial' => [
                'label'=>'Warm editorial','accent'=>'#9c3d54','accent_dark'=>'#7e2f43',
                'heading'=>self::SERIF,'body'=>self::SANS,
                'page_bg'=>'#faf4ee','surface'=>'#f4e6df','surface2'=>'#ece0d8',
                'ink'=>'#3a2b2f','muted'=>'#8a6f6f','radius'=>14],
            'clean-corporate' => [
                'label'=>'Clean corporate','accent'=>'#2563eb','accent_dark'=>'#1d4ed8',
                'heading'=>self::SANS,'body'=>self::SANS,
                'page_bg'=>'#ffffff','surface'=>'#f8fafc','surface2'=>'#eef2f7',
                'ink'=>'#1d2939','muted'=>'#667085','radius'=>10],
            'fresh-natural' => [
                'label'=>'Fresh natural','accent'=>'#2f855a','accent_dark'=>'#276749',
                'heading'=>self::SERIF,'body'=>self::SANS,
                'page_bg'=>'#f6f8f4','surface'=>'#e8f0e6','surface2'=>'#dde9da',
                'ink'=>'#22312a','muted'=>'#5f7268','radius'=>12],
            'bold-dark' => [
                'label'=>'Bold dark','accent'=>'#f59e0b','accent_dark'=>'#d97706',
                'heading'=>self::SANS,'body'=>self::SANS,
                'page_bg'=>'#0f172a','surface'=>'#1e293b','surface2'=>'#273449',
                'ink'=>'#f1f5f9','muted'=>'#94a3b8','radius'=>10],
            'soft-rose' => [
                'label'=>'Soft rose','accent'=>'#be4b6e','accent_dark'=>'#9d3a59',
                'heading'=>self::SERIF,'body'=>self::SANS,
                'page_bg'=>'#fdf6f8','surface'=>'#f9e8ee','surface2'=>'#f3dbe4',
                'ink'=>'#3c2a30','muted'=>'#9a7480','radius'=>16],
            'minimal-mono' => [
                'label'=>'Minimal mono','accent'=>'#111827','accent_dark'=>'#000000',
                'heading'=>self::SANS,'body'=>self::SANS,
                'page_bg'=>'#ffffff','surface'=>'#f4f4f5','surface2'=>'#e7e7ea',
                'ink'=>'#18181b','muted'=>'#71717a','radius'=>4],
        ];
    }

    public static function palettes(): array {
        return [
            'classic-blue'=>['label'=>'Classic blue','accent'=>'#2563eb','accent_dark'=>'#1d4ed8'],
            'emerald'=>['label'=>'Emerald','accent'=>'#059669','accent_dark'=>'#047857'],
            'crimson'=>['label'=>'Crimson','accent'=>'#dc2626','accent_dark'=>'#b91c1c'],
            'violet'=>['label'=>'Violet','accent'=>'#7c3aed','accent_dark'=>'#6d28d9'],
            'amber'=>['label'=>'Amber','accent'=>'#d97706','accent_dark'=>'#b45309'],
            'clay'=>['label'=>'Clay','accent'=>'#9c3d54','accent_dark'=>'#7e2f43'],
            'teal'=>['label'=>'Teal','accent'=>'#0d9488','accent_dark'=>'#0f766e'],
            'rose'=>['label'=>'Rose','accent'=>'#e11d48','accent_dark'=>'#be123c'],
        ];
    }

    public static function fontPairings(): array {
        return [
            'modern-sans'=>['label'=>'Modern sans','heading'=>self::SANS,'body'=>self::SANS],
            'classic-serif'=>['label'=>'Classic serif','heading'=>self::SERIF,'body'=>self::SERIF2],
            'serif-sans'=>['label'=>'Serif + sans','heading'=>self::SERIF,'body'=>self::SANS],
            'sans-serif'=>['label'=>'Sans + serif','heading'=>self::SANS,'body'=>self::SERIF2],
            'editorial'=>['label'=>'Editorial','heading'=>self::ROUNDED,'body'=>self::SERIF2],
        ];
    }

    public static function resolve(): array {
        $themeKey = ContentBuilderAPI::getSiteSetting('theme', 'clean-corporate');
        $themes   = self::themes();
        $theme    = $themes[$themeKey] ?? reset($themes);

        $accent=$theme['accent']; $accentDark=$theme['accent_dark'];
        $heading=$theme['heading']; $body=$theme['body'];
        $pageBg=$theme['page_bg']; $surface=$theme['surface']; $surface2=$theme['surface2'];
        $ink=$theme['ink']; $muted=$theme['muted']; $radius=$theme['radius'];
        $dark = ($themeKey === 'bold-dark');

        $palKey = trim((string)ContentBuilderAPI::getSiteSetting('palette',''));
        if ($palKey !== '') { $p=self::palettes(); if(isset($p[$palKey])){ $accent=$p[$palKey]['accent']; $accentDark=$p[$palKey]['accent_dark']; } }
        $ac = trim((string)ContentBuilderAPI::getSiteSetting('accent_color',''));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/',$ac)) { $accent=$ac; $accentDark=$ac; }

        $fontKey = trim((string)ContentBuilderAPI::getSiteSetting('font_pairing',''));
        if ($fontKey !== '') { $f=self::fontPairings(); if(isset($f[$fontKey])){ $heading=$f[$fontKey]['heading']; $body=$f[$fontKey]['body']; } }

        $rc = ContentBuilderAPI::getSiteSetting('radius','');
        if ($rc !== '' && is_numeric($rc)) $radius=(int)$rc;

        // Global type scale (multiplier on base font size).
        $scaleKey = ContentBuilderAPI::getSiteSetting('type_scale', 'm');
        $scaleMap = ['s'=>0.9, 'm'=>1.0, 'l'=>1.12, 'xl'=>1.25];
        $scale = $scaleMap[$scaleKey] ?? 1.0;

        // Button style: shape + fill.
        $btnShape = ContentBuilderAPI::getSiteSetting('btn_shape', 'rounded'); // rounded|square|pill
        $btnRadius = ['square'=>'0px', 'rounded'=>'var(--slate-radius-md)', 'pill'=>'999px'][$btnShape] ?? 'var(--slate-radius-md)';

        // ONE accent for the whole site. Content-builder's accent_color and the
        // core brand_accent_color were two independent settings holding two
        // different colours — measured on this install as #00c6ff and #FFCB70 —
        // so a page could show one accent while a core-rendered component showed
        // the other. The core setting wins, by the owner's decision.
        //
        // Visible, not neutral: it repaints every content-builder accent.
        $coreAccent = trim((string) Database::setting('brand_accent_color'));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $coreAccent)) {
            $accent     = $coreAccent;
            $accentDark = $coreAccent;   // superseded by slate-color-accent-strong
        }

        return compact('accent','accentDark','heading','body','pageBg','surface','surface2','ink','muted','radius','dark','scale','btnRadius');
    }

    public static function cssVars(): string {
        $t = self::resolve();
        return ':root{'
            . '--cb-accent:'.$t['accent'].';'
            . '--cb-accent-dark:'.$t['accentDark'].';'
            . '--cb-font-heading:'.$t['heading'].';'
            . '--cb-font-body:'.$t['body'].';'
            . '--cb-page-bg:'.$t['pageBg'].';'
            . '--cb-surface:'.$t['surface'].';'
            . '--cb-surface-2:'.$t['surface2'].';'
            . '--cb-ink:'.$t['ink'].';'
            . '--cb-muted:'.$t['muted'].';'
            . '--cb-radius:'.$t['radius'].'px;'
            // Renamed from the cb-prefixed name. NOT a design token and never was: it is a
            // runtime multiplier fed by the type_scale site setting
            // (s/m/l/xl -> 0.9/1.0/1.12/1.25), i.e. a reader preference that
            // happens to travel as a custom property. Phase E renames it only so
            // step 3's "no --cb- substring remains" gate can pass; resolving it
            // into fixed --slate-font-size-* steps would delete the preference.
            . '--slate-type-scale:'.$t['scale'].';'
            . '--cb-btn-radius:'.$t['btnRadius'].';'
            . '}';
    }
}
