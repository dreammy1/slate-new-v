<?php
/**
 * Renderer — turns a saved layout (array of blocks) into HTML.
 *
 * Each block is either:
 *   - a built-in block with a 'tpl' PHP template, or
 *   - a plugin block with a 'render' callback.
 *
 * Nested blocks (columns) get a $renderBlock closure to recurse.
 */
class Renderer {

    public static function render(?array $layout): string {
        if (!$layout) return '';
        $out = '';
        foreach ($layout as $block) {
            if (is_array($block)) $out .= self::renderBlock($block);
        }
        return $out;
    }

    public static function renderBlock(array $block): string {
        $type = $block['type'] ?? '';
        $def  = BlockRegistry::get($type);
        if (!$def) return '';

        $props = $block['props'] ?? [];

        // Named $rendered, not $html: extract(EXTR_SKIP) below refuses to
        // overwrite a variable that already exists in this scope, so a plain
        // $html accumulator here silently ate the "Raw HTML" block's own
        // `html` prop — extract() saw $html already set (to '') and skipped
        // it, so that block's template always ran with an empty $html and
        // rendered nothing, no matter what content was saved.
        $rendered = '';
        // Plugin-provided block via callback.
        if (!empty($def['render']) && is_callable($def['render'])) {
            try {
                $rendered = (string)call_user_func($def['render'], $props, $block);
            } catch (\Throwable $ex) {
                return '<!-- block "' . htmlspecialchars($type) . '" failed to render -->';
            }
        } elseif (!empty($def['tpl']) && is_file($def['tpl'])) {
            // Built-in block via template file.
            $renderBlock = fn(array $b) => self::renderBlock($b);
            ob_start();
            extract($props, EXTR_SKIP);
            include $def['tpl'];
            $rendered = ob_get_clean();
        }

        if (!$rendered) return '';

        return self::applyStyle($rendered, $block['style'] ?? []);
    }

    /**
     * Wrap rendered HTML in styling container with background, overlay, padding, etc.
     */
    public static function applyStyle(string $html, array $style): string {
        if (empty($style)) return $html;

        $css = '';
        if (!empty($style['bgColor'])) {
            $op = isset($style['bgOpacity']) ? ((int)$style['bgOpacity']) / 100 : 1;
            $hex = ltrim($style['bgColor'], '#');
            if (strlen($hex) === 6 || strlen($hex) === 3) {
                if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                $css .= "background-color:rgba($r,$g,$b,$op);";
            }
        }
        if (!empty($style['bgImage'])) {
            $bgUrl = htmlspecialchars($style['bgImage'], ENT_QUOTES);
            if (!empty($style['bgOverlay'])) {
                $overlay = htmlspecialchars($style['bgOverlay'], ENT_QUOTES);
                $css .= "background-image:linear-gradient($overlay,$overlay),url('$bgUrl');";
            } else {
                $css .= "background-image:url('$bgUrl');";
            }
            $css .= "background-size:cover;background-position:center;";
        }
        if (!empty($style['textColor'])) $css .= "color:" . htmlspecialchars($style['textColor'], ENT_QUOTES) . ";";
        if (!empty($style['textAlign'])) $css .= "text-align:" . htmlspecialchars($style['textAlign'], ENT_QUOTES) . ";";
        if (isset($style['paddingTop']) && $style['paddingTop'] !== '') $css .= "padding-top:" . (int)$style['paddingTop'] . "px;";
        if (isset($style['paddingBottom']) && $style['paddingBottom'] !== '') $css .= "padding-bottom:" . (int)$style['paddingBottom'] . "px;";
        if (isset($style['paddingLeft']) && $style['paddingLeft'] !== '') $css .= "padding-left:" . (int)$style['paddingLeft'] . "px;";
        if (isset($style['paddingRight']) && $style['paddingRight'] !== '') $css .= "padding-right:" . (int)$style['paddingRight'] . "px;";
        if (isset($style['marginTop']) && $style['marginTop'] !== '') $css .= "margin-top:" . (int)$style['marginTop'] . "px;";
        if (isset($style['marginBottom']) && $style['marginBottom'] !== '') $css .= "margin-bottom:" . (int)$style['marginBottom'] . "px;";
        if (isset($style['borderRadius']) && $style['borderRadius'] !== '') $css .= "border-radius:" . (int)$style['borderRadius'] . "px;";
        if (!empty($style['maxWidth'])) $css .= "max-width:" . htmlspecialchars($style['maxWidth'], ENT_QUOTES) . (is_numeric($style['maxWidth']) ? "px" : "") . ";";

        $classes = ['cb-block-wrapper', 've-block-style'];
        if (!empty($style['hideDesktop'])) $classes[] = 've-hide-desktop';
        if (!empty($style['hideTablet'])) $classes[] = 've-hide-tablet';
        if (!empty($style['hideMobile'])) $classes[] = 've-hide-mobile';
        if (!empty($style['customClass'])) $classes[] = htmlspecialchars($style['customClass'], ENT_QUOTES);

        return '<div class="' . implode(' ', $classes) . '"' . ($css ? ' style="' . $css . '"' : '') . '>' . $html . '</div>';
    }
}
