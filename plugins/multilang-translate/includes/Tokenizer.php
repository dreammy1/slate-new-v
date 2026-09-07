<?php
/**
 * MLT — Tokenizer.
 *
 * Splits an HTML string into alternating text/tag tokens without a full
 * DOM re-parse (keeps the original byte-for-byte markup intact, which a
 * DOMDocument round-trip cannot guarantee). Used by both Harvester
 * (read-only extraction) and Replacer (in-place substitution) so the two
 * always agree on what counts as "translatable text".
 */

class MLT_Tokenizer {

    /** Tags whose text content is never translatable. */
    const SKIP_TAGS = ['script', 'style', 'noscript', 'template', 'code', 'pre'];

    /** Attributes that hold user-visible text worth translating. */
    const TEXT_ATTRS = ['placeholder', 'title', 'alt', 'aria-label', 'data-mlt'];

    /** value="" is only translatable on visible-label inputs. */
    const VALUE_INPUT_TYPES = ['submit', 'button', 'reset'];

    /**
     * Walk $html, calling $onText($normalized, $raw) for every
     * translatable text segment (node text + eligible attribute values).
     * Return value of $onText, if non-null, replaces that segment
     * (used by the Replacer). Returns the (possibly modified) html.
     */
    public static function walk(string $html, callable $onText): string {
        $tokens = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($tokens === false) return $html;

        $skipDepth = 0;
        $skipTag   = null;
        $out = '';

        foreach ($tokens as $tok) {
            if ($tok === '') continue;

            if ($tok[0] === '<') {
                // Tag token — check skip-zone enter/exit, then translate attrs.
                if (preg_match('/^<\/\s*([a-zA-Z0-9]+)/', $tok, $m)) {
                    $tag = strtolower($m[1]);
                    if ($skipDepth > 0 && $tag === $skipTag) { $skipDepth--; if ($skipDepth === 0) $skipTag = null; }
                    $out .= $tok;
                    continue;
                }
                if (preg_match('/^<\s*([a-zA-Z0-9]+)/', $tok, $m)) {
                    $tag = strtolower($m[1]);
                    if ($skipDepth === 0 && in_array($tag, self::SKIP_TAGS, true) && substr($tok, -2) !== '/>') {
                        $skipDepth = 1; $skipTag = $tag;
                    }
                    $tok = self::translateAttrs($tok, $tag, $onText);
                }
                $out .= $tok;
                continue;
            }

            // Text token.
            if ($skipDepth > 0) { $out .= $tok; continue; }

            $out .= self::translateTextNode($tok, $onText);
        }

        return $out;
    }

    private static function translateTextNode(string $raw, callable $onText): string {
        if (trim($raw) === '') return $raw;
        // Preserve exact leading/trailing whitespace; only touch the core.
        if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $raw, $m)) return $raw;
        [, $lead, $core, $trail] = $m;
        if ($core === '' || !preg_match('/\p{L}/u', $core)) return $raw;

        $normalized = self::normalize(html_entity_decode($core, ENT_QUOTES, 'UTF-8'));
        if ($normalized === '' || mb_strlen($normalized) > 2000) return $raw;

        $replacement = $onText($normalized, $core, 'text');
        return $lead . ($replacement !== null ? $replacement : $core) . $trail;
    }

    private static function translateAttrs(string $tagHtml, string $tag, callable $onText): string {
        $isValueEligible = ($tag === 'input');
        $attrList = self::TEXT_ATTRS;
        if ($isValueEligible && preg_match('/\btype\s*=\s*["\']?(' . implode('|', self::VALUE_INPUT_TYPES) . ')["\']?/i', $tagHtml)) {
            $attrList[] = 'value';
        }

        foreach ($attrList as $attr) {
            $tagHtml = preg_replace_callback(
                '/\b(' . preg_quote($attr, '/') . ')\s*=\s*"([^"]*)"/i',
                function ($m) use ($onText) {
                    $val = trim($m[2]);
                    if ($val === '' || !preg_match('/\p{L}/u', $val)) return $m[0];
                    $normalized = self::normalize(html_entity_decode($val, ENT_QUOTES, 'UTF-8'));
                    if ($normalized === '') return $m[0];
                    $replacement = $onText($normalized, $val, 'attr');
                    $final = $replacement !== null ? $replacement : $m[2];
                    return $m[1] . '="' . str_replace('"', '&quot;', $final) . '"';
                },
                $tagHtml
            );
        }
        return $tagHtml;
    }

    /** Collapse internal whitespace + trim, used as the dictionary key basis. */
    public static function normalize(string $s): string {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    public static function hash(string $normalized): string {
        return sha1(mb_strtolower($normalized, 'UTF-8'));
    }
}
