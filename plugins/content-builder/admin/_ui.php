<?php
/**
 * Content Builder — small reusable admin list UI.
 *
 * Same pattern as Studio's admin/_ui.php: wraps the shared design-system
 * primitives (`.data-list` + slate_data_row for expandable rows, `.segmented`
 * for tabs, `.filter-*` for the bar) and adds client-side tab + search
 * filtering plus an optional bulk-select bar, so the Pages/Posts list looks
 * and behaves like the rest of the admin's data lists (e.g. Studio ›
 * Instructors) instead of its own bespoke markup. No core files modified.
 *
 * Usage:
 *   cb_ui_list_start($tabs, 'Search pages…', $bulkActions);
 *   foreach (...) cb_ui_row([... slate_data_row args ..., '_filter'=>'published', '_search'=>'home /home']);
 *   cb_ui_list_end($rowCount, 'No pages yet', $opts);
 */

if (!function_exists('cb_ui_css')) {
    function cb_ui_css(): void
    {
        if (defined('CB_UI_CSS_EMITTED')) return;
        define('CB_UI_CSS_EMITTED', true);
        ?>
        <style>
        .cbui-list { display: flex; flex-direction: column; gap: var(--space-3); }
        .cbui-filterbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: var(--space-2); flex-wrap: wrap;
        }
        .cbui-filterbar .segmented { flex-wrap: wrap; }
        .cbui-seg-count {
            font-size: 10.5px; font-weight: 600; line-height: 1;
            padding: 2px 6px; border-radius: 999px;
            background: var(--surface-sunken, var(--surface-2)); color: var(--muted);
            font-variant-numeric: tabular-nums;
        }
        .segmented-item.is-active .cbui-seg-count { background: var(--accent-soft); color: var(--accent-ink, var(--accent)); }
        .cbui-search {
            flex: 1 1 200px; max-width: 280px; min-width: 0;
            border: 1px solid var(--border); border-radius: 10px;
            background: var(--surface); color: var(--text);
            font: inherit; font-size: 12.5px; padding: 8px 12px; min-height: 36px;
        }
        .cbui-search:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--ring); }
        .cbui-search::placeholder { color: var(--subtle); }
        .cbui-empty {
            text-align: center; color: var(--muted); font-size: 13px;
            padding: 32px 20px; border: 1px dashed var(--border-stronger);
            border-radius: var(--radius-lg); background: var(--surface);
        }
        .cbui-empty[hidden] { display: none; }
        .cbui-empty svg.icon { width: 26px; height: 26px; color: var(--subtle); margin-bottom: 10px; }
        .cbui-empty-t { font-size: 14px; font-weight: 650; color: var(--text); }
        .cbui-empty-s { margin: 5px auto 0; max-width: 46ch; }
        .cbui-empty .btn { margin-top: var(--space-3); }
        /* Bulk selection */
        .cbui-list.is-bulk .data-row { position: relative; }
        .cbui-list.is-bulk .data-row-summary { padding-left: 42px; }
        .cbui-check {
            position: absolute; left: 15px; top: 20px;
            width: 16px; height: 16px; z-index: 2; cursor: pointer; accent-color: var(--accent);
        }
        .cbui-bulkbar {
            display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap;
            padding: 10px 14px; border: 1px solid var(--accent); border-radius: var(--radius-lg);
            background: var(--accent-soft);
        }
        .cbui-bulkbar[hidden] { display: none; }
        .cbui-selcount { font-size: 12.5px; font-weight: 600; color: var(--accent-deep, var(--accent)); }
        .cbui-selall { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-2); cursor: pointer; }
        .cbui-selall input { width: 15px; height: 15px; accent-color: var(--accent); }
        .cbui-bulk-actions { display: inline-flex; gap: var(--space-2); margin-left: auto; flex-wrap: wrap; }
        </style>
        <?php
    }
}

if (!function_exists('cb_ui_list_start')) {
    /**
     * @param array $tabs  [['value'=>'all','label'=>'All','count'=>N], …]; empty = no tabs.
     * @param ?string $searchPlaceholder  null = no search box.
     * @param array $bulkActions  [['value'=>'trash','label'=>'Trash','danger'=>true,'confirm'=>'…'], …].
     *              When non-empty, the list becomes a POST <form> with per-row checkboxes and a
     *              bulk bar; buttons submit name="_do" value="bulk_<value>" with checked ids[].
     */
    function cb_ui_list_start(array $tabs = [], ?string $searchPlaceholder = 'Search…', array $bulkActions = []): void
    {
        cb_ui_css();
        $bulk = $bulkActions !== [];
        $GLOBALS['__cb_ui_bulk'] = $bulk;

        if ($bulk) {
            echo '<form method="post" class="cbui-list is-bulk" data-cbui-list>';
            echo function_exists('csrf_field') ? csrf_field() : '';
            echo '<div class="cbui-bulkbar" hidden>';
            echo '<label class="cbui-selall"><input type="checkbox" class="cbui-check-all"> Select all</label>';
            echo '<span class="cbui-selcount">0 selected</span>';
            echo '<span class="cbui-bulk-actions">';
            foreach ($bulkActions as $ba) {
                $val     = (string)($ba['value'] ?? '');
                $label   = (string)($ba['label'] ?? ucfirst($val));
                $danger  = !empty($ba['danger']);
                $confirm = (string)($ba['confirm'] ?? '');
                echo '<button type="submit" name="_do" value="bulk_' . e($val) . '" class="btn btn-sm'
                   . ($danger ? ' btn-danger' : '') . '"'
                   . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
                   . e($label) . '</button>';
            }
            echo '</span></div>';
        } else {
            echo '<div class="cbui-list" data-cbui-list>';
        }

        if ($tabs || $searchPlaceholder !== null) {
            echo '<div class="cbui-filterbar">';
            if ($tabs) {
                echo '<div class="segmented" role="tablist">';
                foreach ($tabs as $i => $t) {
                    $val   = (string)($t['value'] ?? 'all');
                    $label = (string)($t['label'] ?? ucfirst($val));
                    $count = $t['count'] ?? null;
                    $cls   = 'segmented-item' . ($i === 0 ? ' is-active' : '');
                    echo '<button type="button" class="' . $cls . '" data-filter-value="' . e($val) . '"'
                       . ' role="tab" aria-selected="' . ($i === 0 ? 'true' : 'false') . '">'
                       . e($label)
                       . ($count !== null ? ' <span class="cbui-seg-count">' . (int)$count . '</span>' : '')
                       . '</button>';
                }
                echo '</div>';
            }
            if ($searchPlaceholder !== null) {
                echo '<input type="search" class="cbui-search" placeholder="' . e($searchPlaceholder)
                   . '" aria-label="' . e($searchPlaceholder) . '" autocomplete="off">';
            }
            echo '</div>';
        }
        echo '<div class="data-list">';
    }
}

if (!function_exists('cb_ui_row')) {
    /** slate_data_row + optional `_filter` / `_search` tags and (in bulk mode) an `_id` checkbox. */
    function cb_ui_row(array $args): void
    {
        $filter = strtolower((string)($args['_filter'] ?? 'all'));
        $search = strtolower((string)($args['_search'] ?? ''));
        $id     = (int)($args['_id'] ?? 0);
        unset($args['_filter'], $args['_search'], $args['_id']);

        ob_start();
        slate_data_row($args);
        $html = ob_get_clean();

        $open = '<article class="data-row" data-filter="' . e($filter) . '" data-search="' . e($search) . '">';
        if (!empty($GLOBALS['__cb_ui_bulk']) && $id > 0) {
            $open .= '<input type="checkbox" class="cbui-check" name="ids[]" value="' . $id
                   . '" aria-label="Select">';
        }
        echo str_replace('<article class="data-row">', $open, $html);
    }
}

if (!function_exists('cb_ui_list_end')) {
    /**
     * @param array $opts ['sub'=>…, 'cta_href'=>…, 'cta_label'=>…, 'icon'=>…]
     */
    function cb_ui_list_end(int $rowCount, string $emptyLabel = 'Nothing here yet', array $opts = []): void
    {
        $bulk = !empty($GLOBALS['__cb_ui_bulk']);
        echo '</div>'; // .data-list

        echo '<div class="cbui-empty" data-empty="none"' . ($rowCount > 0 ? ' hidden' : '') . '>';
        if (function_exists('slate_icon')) {
            echo slate_icon((string)($opts['icon'] ?? 'file'), 'icon');
        }
        echo '<div class="cbui-empty-t">' . e($emptyLabel) . '</div>';
        if (!empty($opts['sub'])) {
            echo '<p class="cbui-empty-s">' . e((string)$opts['sub']) . '</p>';
        }
        if (!empty($opts['cta_href']) && !empty($opts['cta_label'])) {
            echo '<a class="btn btn-primary" href="' . e((string)$opts['cta_href']) . '">'
               . e((string)$opts['cta_label']) . '</a>';
        }
        echo '</div>';

        echo '<div class="cbui-empty" data-empty="filtered" hidden>';
        if (function_exists('slate_icon')) { echo slate_icon('search', 'icon'); }
        echo '<div class="cbui-empty-t">No matches</div>';
        echo '<p class="cbui-empty-s">Nothing here matches the current tab or search.</p>';
        echo '<button type="button" class="btn cbui-empty-reset">Clear filters</button>';
        echo '</div>';

        echo $bulk ? '</form>' : '</div>'; // .cbui-list
        $GLOBALS['__cb_ui_bulk'] = false;
        if (function_exists('slate_data_list_script')) { slate_data_list_script(); } // expand/collapse
        cb_ui_filter_script();
    }
}

if (!function_exists('cb_ui_filter_script')) {
    function cb_ui_filter_script(): void
    {
        if (defined('CB_UI_FILTER_JS')) return;
        define('CB_UI_FILTER_JS', true);
        ?>
        <script>
        (function () {
            document.querySelectorAll('[data-cbui-list]').forEach(function (wrap) {
                var seg      = wrap.querySelector('.segmented');
                var search   = wrap.querySelector('.cbui-search');
                var emptyNil = wrap.querySelector('.cbui-empty[data-empty=none]');
                var emptyFil = wrap.querySelector('.cbui-empty[data-empty=filtered]');
                var reset    = wrap.querySelector('.cbui-empty-reset');
                var rows     = Array.prototype.slice.call(wrap.querySelectorAll('.data-row'));
                var active   = seg && seg.querySelector('.segmented-item.is-active');
                var filter   = (active && active.getAttribute('data-filter-value')) || 'all';

                function apply() {
                    var q = (search && search.value || '').trim().toLowerCase();
                    var shown = 0;
                    rows.forEach(function (r) {
                        var okF = filter === 'all' || r.getAttribute('data-filter') === filter;
                        var okS = q === '' || (r.getAttribute('data-search') || '').indexOf(q) !== -1;
                        var vis = okF && okS;
                        r.style.display = vis ? '' : 'none';
                        if (vis) shown++;
                    });
                    if (emptyNil) emptyNil.hidden = rows.length !== 0;
                    if (emptyFil) emptyFil.hidden = !(rows.length > 0 && shown === 0);
                }

                function selectTab(btn) {
                    if (!seg || !btn) return;
                    seg.querySelectorAll('.segmented-item').forEach(function (x) {
                        var on = x === btn;
                        x.classList.toggle('is-active', on);
                        x.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    filter = btn.getAttribute('data-filter-value') || 'all';
                }

                function clearFilters() {
                    if (search) search.value = '';
                    filter = 'all';
                    if (seg) { selectTab(seg.querySelector('.segmented-item')); }
                    apply();
                    if (search) search.focus();
                }
                if (reset) reset.addEventListener('click', clearFilters);

                if (seg) seg.addEventListener('click', function (e) {
                    var b = e.target.closest('.segmented-item');
                    if (!b) return;
                    e.preventDefault();
                    selectTab(b);
                    apply();
                });
                if (search) search.addEventListener('input', apply);

                apply();

                var bar      = wrap.querySelector('.cbui-bulkbar');
                var selCount = wrap.querySelector('.cbui-selcount');
                var selAll   = wrap.querySelector('.cbui-check-all');
                function checks() { return Array.prototype.slice.call(wrap.querySelectorAll('.cbui-check')); }
                function syncBulk() {
                    if (!bar) return;
                    var picked = checks().filter(function (c) { return c.checked; }).length;
                    bar.hidden = picked === 0;
                    if (selCount) selCount.textContent = picked + ' selected';
                }
                if (bar) {
                    wrap.addEventListener('change', function (e) {
                        if (e.target.classList.contains('cbui-check')) syncBulk();
                    });
                    if (selAll) selAll.addEventListener('change', function () {
                        checks().forEach(function (c) {
                            var row = c.closest('.data-row');
                            if (row && row.style.display === 'none') return;
                            c.checked = selAll.checked;
                        });
                        syncBulk();
                    });
                }
            });

            document.addEventListener('click', function (e) {
                var b = e.target.closest('[data-confirm]');
                if (!b) return;
                if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
            });
        })();
        </script>
        <?php
    }
}
