/*
 * Content Builder — block editor (vanilla JS, no build step).
 *
 * Reads the palette from window.CB_PALETTE (printed by post-edit.php),
 * keeps an in-memory layout array, and serializes it into #cb-layout on
 * every change so a normal form POST carries the JSON.
 *
 * Supports: add, reorder (up/down), edit fields, delete, and one level of
 * nesting for the "columns" block (each column holds its own blocks).
 */
(function () {
  "use strict";

  var PALETTE = window.CB_PALETTE || {};
  var layoutInput = document.getElementById("cb-layout");
  var canvas = document.getElementById("cb-canvas");
  var palette = document.getElementById("cb-palette");
  if (!layoutInput || !canvas || !palette) return;

  var layout;
  try { layout = JSON.parse(layoutInput.value || "[]"); }
  catch (e) { layout = []; }
  if (!Array.isArray(layout)) layout = [];

  // Which block cards are expanded (accordion). Keyed by the block OBJECT so
  // it survives repaints (add/move/remove keep the same references). Default:
  // all collapsed → a compact, scannable list instead of one giant scroll.
  var expanded = new WeakSet();

  var sortableInstances = [];
  var previewTimer = null;
  var previewFrame = document.getElementById("cb-live-preview");
  var previewRefresh = document.getElementById("cb-preview-refresh");

  function sync() {
    layoutInput.value = JSON.stringify(layout);
    paint();
    queuePreview();
  }

  function submitPreview() {
    if (!previewFrame || !window.CB_PREVIEW_ID) return;
    var form = document.createElement("form");
    form.method = "post";
    form.target = previewFrame.name;
    form.action = previewFrame.getAttribute("src").split("?")[0] + "?id=" + encodeURIComponent(window.CB_PREVIEW_ID);
    form.style.display = "none";

    // Copy the host form's CSRF hidden inputs rather than assuming a token name.
    var sourceForm = document.getElementById("cb-form");
    if (sourceForm) {
      Array.prototype.forEach.call(sourceForm.querySelectorAll('input[type="hidden"]'), function (input) {
        if (!input.name || input.name === "layout" || input.name === "action") return;
        var copy = document.createElement("input");
        copy.type = "hidden"; copy.name = input.name; copy.value = input.value;
        form.appendChild(copy);
      });
    }
    var layoutField = document.createElement("input");
    layoutField.type = "hidden"; layoutField.name = "_preview_layout";
    layoutField.value = JSON.stringify(layout);
    form.appendChild(layoutField);
    document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  function queuePreview() {
    if (!previewFrame || !window.CB_PREVIEW_ID) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(submitPreview, 180);
  }

  function defaults(type) {
    var d = (PALETTE[type] && PALETTE[type].defaults) || {};
    return JSON.parse(JSON.stringify(d));
  }

  // Backfill any props a block is MISSING with its type's defaults, so every
  // control reflects the value the page actually renders (a block saved before
  // a field existed would otherwise show the dropdown's first option while the
  // page uses the template default — the "control shows X but renders Y" bug).
  // Only fills undefined keys, so intentional values (incl. "" / "center") win.
  function fillDefaults(block) {
    if (!block || !block.type) return;
    var d = defaults(block.type);
    block.props = block.props || {};
    Object.keys(d).forEach(function (k) {
      if (block.props[k] === undefined) block.props[k] = d[k];
    });
    // Recurse into columns (each column holds its own block list)...
    if (Array.isArray(block.props.cols)) {
      block.props.cols.forEach(function (col) {
        if (col && Array.isArray(col.blocks)) col.blocks.forEach(fillDefaults);
      });
    }
    // ...and into a flex container's flat children list.
    if (Array.isArray(block.props.children)) {
      block.props.children.forEach(fillDefaults);
    }
  }
  function normalizeLayout() { layout.forEach(fillDefaults); }

  function addBlock(type, list) {
    var b = { type: type, props: defaults(type) };
    expanded.add(b); // newly added blocks open so you can edit right away
    list.push(b);
    sync();
  }

  // A short human summary of a block for its collapsed header (heading text,
  // else first meaningful text prop, else nothing).
  function blockSummary(block) {
    var p = block.props || {};
    var s = p.heading || p.title || p.text || p.lede || p.sub || p.eyebrow || p.label || "";
    if (typeof s !== "string") s = "";
    s = s.replace(/\s+/g, " ").trim();
    return s.length > 60 ? s.slice(0, 60) + "…" : s;
  }

  function move(list, from, to) {
    if (to < 0 || to >= list.length) return;
    var b = list.splice(from, 1)[0];
    list.splice(to, 0, b);
    sync();
  }

  function remove(list, i) { list.splice(i, 1); sync(); }

  // ---- Drag & drop (SortableJS) ----------------------------------------
  // Every block-list container in the editor (the top-level canvas, each
  // column's block list, and a flex container's children list) is registered
  // here with the JS array it renders. All lists share one Sortable "group"
  // so a block can be dragged out of a column into another column, into/out
  // of a flex container, or onto the top-level canvas — not just reordered
  // in place. If the Sortable library failed to load for any reason, the
  // editor still works via the ↑/↓ buttons on each block.
  function registerSortable(el, list) {
    el.__cbList = list;
    if (typeof window.Sortable !== "function") return;
    var sortable = window.Sortable.create(el, {
      group: "cb-blocks",
      handle: ".cb-drag-handle",
      animation: 150,
      fallbackOnBody: true,
      ghostClass: "cb-sortable-ghost",
      chosenClass: "cb-sortable-chosen",
      dragClass: "cb-sortable-drag",
      draggable: ".cb-block",
      sort: true,
      emptyInsertThreshold: 28,
      fallbackTolerance: 4,
      onEnd: onSortEnd
    });
    sortableInstances.push(sortable);
  }

  function onSortEnd(evt) {
    if (!window.ContentBuilderReorder || typeof window.ContentBuilderReorder.applySortEnd !== "function") {
      paint();
      return;
    }
    window.ContentBuilderReorder.applySortEnd(evt, { repaint: paint, sync: sync });
  }

  // ---- Palette ----
  // Per-block-type icon (inline SVG paths) + short description for the palette.
  var BLOCK_ICONS = {
    heading:   '<path d="M6 4v16M18 4v16M6 12h12"/>',
    paragraph: '<path d="M4 6h16M4 12h16M4 18h10"/>',
    image:     '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>',
    button:    '<rect x="3" y="8" width="18" height="8" rx="4"/>',
    columns:   '<rect x="3" y="4" width="7" height="16"/><rect x="14" y="4" width="7" height="16"/>',
    container: '<rect x="3" y="6" width="18" height="12" rx="1"/><path d="M9 8v8M15 8v8"/>',
    html:      '<path d="M8 9l-3 3 3 3M16 9l3 3-3 3M13 6l-2 12"/>',
    "post-list": '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    hero:      '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14h18M8 9h5"/>',
    "icon-grid": '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    "image-grid": '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    cta:       '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M9 12h6"/>',
    testimonial: '<path d="M7 8h6a2 2 0 012 2v3a2 2 0 01-2 2H9l-3 3v-3a2 2 0 01-2-2v-3"/>',
  };

  function buildPalette() {
    var groups = {};
    Object.keys(PALETTE).forEach(function (type) {
      var g = PALETTE[type].group || "Content";
      (groups[g] = groups[g] || []).push(type);
    });
    palette.innerHTML = "";
    Object.keys(groups).forEach(function (g) {
      var wrap = document.createElement("div");
      wrap.className = "cb-palette-group";
      wrap.innerHTML = '<span class="cb-palette-label">' + esc(g) + "</span>";
      var tiles = document.createElement("div");
      tiles.className = "cb-palette-tiles";
      groups[g].forEach(function (type) {
        var icon = BLOCK_ICONS[type] || BLOCK_ICONS.paragraph;
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "cb-tile";
        btn.title = "Add " + (PALETTE[type].label || type);
        btn.innerHTML =
          '<span class="cb-tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
          'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">' +
          icon + '</svg></span>' +
          '<span class="cb-tile-label">' + esc(PALETTE[type].label || type) + '</span>';
        btn.addEventListener("click", function () { addBlock(type, layout); });
        tiles.appendChild(btn);
      });
      wrap.appendChild(tiles);
      palette.appendChild(wrap);
    });
  }

  // ---- Canvas ----
  function paint() {
    sortableInstances.forEach(function (instance) {
      if (instance && typeof instance.destroy === "function") instance.destroy();
    });
    sortableInstances = [];
    canvas.innerHTML = "";
    if (!layout.length) {
      canvas.innerHTML = '<div class="cb-canvas-empty">No blocks yet. Add one above.</div>';
    } else {
      layout.forEach(function (block, i) {
        canvas.appendChild(renderCard(block, layout, i));
      });
    }
    registerSortable(canvas, layout);
  }

  // Which field keys belong on the "Style" tab (appearance/layout). Everything
  // else is "Content". A field may override with an explicit f.group.
  var STYLE_KEYS = {
    align:1, bg:1, cols:1, tall:1, mediaSide:1, btnStyle:1, btn2Style:1, shape:1,
    minHeight:1, radius:1, pad:1, padX:1, padY:1, gap:1, colGap:1, fieldGap:1,
    size:1, weight:1, variant:1, layout:1, height:1, width:1, maxWidth:1, bgColor:1,
    textColor:1, accent:1
  };
  function groupFields(fields) {
    var g = { content: [], style: [] };
    (fields || []).forEach(function (f) {
      var grp = f.group === "style" ? "style" : (f.group === "content" ? "content" : (STYLE_KEYS[f.key] ? "style" : "content"));
      g[grp].push(f);
    });
    return g;
  }
  function renderTabs(block, groups) {
    var wrap = document.createElement("div");
    wrap.className = "cb-tabs";
    var bar = document.createElement("div"); bar.className = "cb-tabbar";
    var panels = document.createElement("div"); panels.className = "cb-tabpanels";
    var order = [["content", "Content"], ["style", "Style"]];
    var firstSet = false;
    order.forEach(function (t) {
      if (!groups[t[0]].length) return;
      var active = !firstSet; firstSet = true;
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "cb-tab" + (active ? " is-active" : "");
      btn.textContent = t[1];
      var panel = document.createElement("div");
      panel.className = "cb-tabpanel" + (active ? " is-active" : "");
      groups[t[0]].forEach(function (f) { panel.appendChild(renderField(block, f)); });
      btn.addEventListener("click", function () {
        bar.querySelectorAll(".cb-tab").forEach(function (b) { b.classList.remove("is-active"); });
        panels.querySelectorAll(".cb-tabpanel").forEach(function (p) { p.classList.remove("is-active"); });
        btn.classList.add("is-active"); panel.classList.add("is-active");
      });
      bar.appendChild(btn); panels.appendChild(panel);
    });
    wrap.appendChild(bar); wrap.appendChild(panels);
    return wrap;
  }

  function renderCard(block, list, i) {
    var def = PALETTE[block.type] || { label: block.type, fields: [] };
    var isOpen = expanded.has(block);
    var card = document.createElement("div");
    card.className = "cb-block" + (isOpen ? " is-open" : "");
    card.__cbBlock = block;

    var head = document.createElement("div");
    head.className = "cb-block-head";
    var summary = blockSummary(block);
    head.innerHTML =
      '<span class="cb-drag-handle" title="Drag to move" aria-hidden="true">⠿</span>' +
      '<span class="cb-block-chev" aria-hidden="true">▸</span>' +
      '<span class="cb-block-titles">' +
        '<span class="cb-block-type">' + esc(def.label || block.type) + "</span>" +
        (summary ? '<span class="cb-block-sum">' + esc(summary) + "</span>" : "") +
      "</span>";

    var controls = document.createElement("div");
    controls.className = "cb-block-controls";
    controls.appendChild(iconBtn("↑", function () { move(list, i, i - 1); }));
    controls.appendChild(iconBtn("↓", function () { move(list, i, i + 1); }));
    controls.appendChild(iconBtn("✕", function () { remove(list, i); }, "cb-danger"));
    head.appendChild(controls);

    // Click the header (but not a control button or the drag handle) to
    // expand/collapse in place.
    head.addEventListener("click", function (ev) {
      if (ev.target.closest(".cb-block-controls") || ev.target.closest(".cb-drag-handle")) return;
      if (expanded.has(block)) expanded.delete(block); else expanded.add(block);
      card.classList.toggle("is-open", expanded.has(block));
    });
    card.appendChild(head);

    var body = document.createElement("div");
    body.className = "cb-block-body";

    // Settings fields (Content/Style tabs) render for every block, including
    // ones that also nest a block list — a "columns" or "container" block has
    // both its own layout settings AND child widgets to manage.
    var fields = def.fields || [];
    if (block.type === "columns") fields = prepareColumnsFields(block, fields);
    if (fields.length) {
      var groups = groupFields(fields);
      if (groups.style.length) {
        body.appendChild(renderTabs(block, groups)); // tabs only when there's style to split out
      } else {
        groups.content.forEach(function (f) { body.appendChild(renderField(block, f)); });
      }
    }

    if (def.nest === "columns") {
      body.appendChild(renderColumns(block));
    } else if (def.nest === "children") {
      body.appendChild(renderChildren(block));
    }

    card.appendChild(body);
    return card;
  }

  // The "ratio" options list mixes 2/3/4-column ratios together (e.g. both
  // "50-50" and "33-33-33" are valid <select> entries regardless of how many
  // columns exist). Rendering all of them lets someone pick a ratio meant for
  // a different column count than the block actually has, which produces a
  // grid-template-columns that disagrees with the number of <div class="cb-col">
  // children — the layout silently breaks (a column ends up empty, or the
  // last column never gets its own track). Filter the options down to the
  // ones that match the block's actual column count, and drop the field
  // entirely for 1 column (there's nothing to ratio between).
  function ratioColCount(v) { return String(v || "").split("-").length; }
  function prepareColumnsFields(block, fields) {
    var numCols = parseInt(block.props.numCols, 10) || (Array.isArray(block.props.cols) ? block.props.cols.length : 2) || 2;
    return (fields || [])
      .map(function (f) {
        if (f.key !== "ratio") return f;
        var opts = (f.options || []).filter(function (o) { return ratioColCount(o.v) === numCols; });
        return { key: f.key, type: f.type, label: f.label, options: opts, group: f.group };
      })
      .filter(function (f) { return !(f.key === "ratio" && numCols <= 1); });
  }

  // Keep `cols` and `ratio` consistent when the column count changes: grow
  // or shrink the `cols` array, and if the current ratio no longer matches
  // the new count, pick the first valid ratio for it. Shrinking that would
  // discard widgets asks for confirmation first.
  function resizeCols(block, n) {
    n = Math.max(1, Math.min(4, n || 1));
    var cols = Array.isArray(block.props.cols) ? block.props.cols : [];
    if (n < cols.length) {
      var removed = cols.slice(n);
      var lost = removed.reduce(function (sum, c) {
        return sum + (Array.isArray(c.blocks) ? c.blocks.length : 0);
      }, 0);
      if (lost > 0 && !window.confirm(
        "Removing column" + (removed.length > 1 ? "s" : "") + " will delete " + lost +
        " widget" + (lost > 1 ? "s" : "") + " inside " + (removed.length > 1 ? "them" : "it") + ". Continue?"
      )) {
        return false;
      }
      cols = cols.slice(0, n);
    } else {
      while (cols.length < n) cols.push({ blocks: [] });
    }
    block.props.cols = cols;

    var def = PALETTE.columns || {};
    var ratioField = (def.fields || []).filter(function (f) { return f.key === "ratio"; })[0] || {};
    var validRatios = (ratioField.options || [])
      .map(function (o) { return o.v; })
      .filter(function (v) { return ratioColCount(v) === n; });
    if (n > 1 && validRatios.indexOf(block.props.ratio) === -1 && validRatios.length) {
      block.props.ratio = validRatios[0];
    }
    return true;
  }

  // Block types that may be dropped into a column or a flex container. Nested
  // "columns"/"container" blocks are excluded to keep the layout tree shallow
  // (one level of nesting), matching how the builder has always worked.
  function nestableTypes() {
    return Object.keys(PALETTE).filter(function (t) { return !PALETTE[t].nest; });
  }

  function renderColumns(block) {
    if (!block.props) block.props = { cols: [{ blocks: [] }, { blocks: [] }] };
    if (!Array.isArray(block.props.cols)) block.props.cols = [{ blocks: [] }, { blocks: [] }];
    var wrap = document.createElement("div");
    wrap.className = "cb-cols-editor";
    wrap.style.gridTemplateColumns = "repeat(" + block.props.cols.length + ", 1fr)";
    block.props.cols.forEach(function (col, ci) {
      if (!Array.isArray(col.blocks)) col.blocks = [];
      var colEl = document.createElement("div");
      colEl.className = "cb-col-editor";
      colEl.innerHTML = '<div class="cb-col-label">Column ' + (ci + 1) + "</div>";

      var list = document.createElement("div");
      list.className = "cb-col-blocklist";
      col.blocks.forEach(function (b, bi) {
        list.appendChild(renderCard(b, col.blocks, bi));
      });
      colEl.appendChild(list);
      registerSortable(list, col.blocks);

      var add = document.createElement("select");
      add.className = "cb-col-add";
      add.innerHTML = '<option value="">+ add block…</option>' +
        nestableTypes()
          .map(function (t) { return '<option value="' + t + '">' + esc(PALETTE[t].label) + "</option>"; })
          .join("");
      add.addEventListener("change", function () {
        if (add.value) { addBlock(add.value, col.blocks); }
      });
      colEl.appendChild(add);
      wrap.appendChild(colEl);
    });
    return wrap;
  }

  // Flat child list for the "Flex Container" block — same idea as a single
  // column, minus the multi-column grid wrapper.
  function renderChildren(block) {
    if (!block.props) block.props = {};
    if (!Array.isArray(block.props.children)) block.props.children = [];
    var children = block.props.children;

    var wrap = document.createElement("div");
    wrap.className = "cb-children-editor";
    wrap.innerHTML = '<div class="cb-col-label">Widgets in this container</div>';

    var list = document.createElement("div");
    list.className = "cb-children-blocklist";
    children.forEach(function (b, bi) {
      list.appendChild(renderCard(b, children, bi));
    });
    wrap.appendChild(list);
    registerSortable(list, children);

    var add = document.createElement("select");
    add.className = "cb-col-add";
    add.innerHTML = '<option value="">+ add widget…</option>' +
      nestableTypes()
        .map(function (t) { return '<option value="' + t + '">' + esc(PALETTE[t].label) + "</option>"; })
        .join("");
    add.addEventListener("change", function () {
      if (add.value) { addBlock(add.value, children); }
    });
    wrap.appendChild(add);
    return wrap;
  }

  // The contracted keyed media reference (ADR-0013 point 4): {key, alt, focal}.
  // Deliberately NOT a URL. A URL is correct only for the host and path that
  // produced it, so a document carrying one renders right in one place and wrong
  // in another; the key is resolved wherever the rendering happens.
  //
  // The editor holds only the key, so it cannot know the thumbnail's URL — the
  // key -> media mapping lives server side. post-edit.php resolves the keys
  // already in the document into window.CB_MEDIA_URLS for exactly this. Without
  // it every image would look deleted each time the page was reopened.
  function renderMediaField(block, f) {
    var field = document.createElement("div");
    field.className = "cb-field";
    field.innerHTML = '<label class="cb-field-label">' + esc(f.label || f.key) + "</label>";

    var val = block.props[f.key];
    if (!val || typeof val !== "object") val = {};
    // Local only: never written back into the document.
    var previewUrl = (window.CB_MEDIA_URLS || {})[val.key] || "";

    var preview = document.createElement("div");
    preview.className = "cb-img-preview";

    var alt = document.createElement("input");
    alt.type = "text";
    alt.className = "cb-field-input";
    alt.placeholder = "Alt text — describes the image for screen readers";
    alt.value = val.alt || "";

    var sync = function () {
      if (!val.key) { delete block.props[f.key]; }
      else {
        var next = { key: val.key, alt: alt.value };
        // Carry focal only when it is a real pair; a half-set value is noise.
        if (val.focal && val.focal.length === 2) { next.focal = val.focal; }
        block.props[f.key] = next;
      }
      layoutInput.value = JSON.stringify(layout);
    };

    var refresh = function () {
      if (previewUrl) {
        preview.innerHTML = '<img src="' + esc(previewUrl) + '" alt="">';
      } else if (val.key) {
        // A key we cannot preview is still a real reference — say so rather than
        // showing an empty box that reads as "no image selected".
        preview.innerHTML = '<span class="cb-muted">' + esc(val.key) + " (no preview)</span>";
      } else {
        preview.innerHTML = "";
      }
    };
    refresh();
    alt.addEventListener("input", sync);

    if (window.CB_HAS_MEDIA === true) {
      var pick = document.createElement("button");
      pick.type = "button";
      pick.className = "cb-icon-btn";
      pick.style.width = "auto";
      pick.style.padding = "0 .6rem";
      pick.textContent = val.key ? "Replace image" : "Choose from library";
      pick.addEventListener("click", function () {
        if (!window.MediaPicker || typeof window.MediaPicker.open !== "function") {
          alert("Media library is still loading — try again in a moment.");
          return;
        }
        window.MediaPicker.open({
          mode: "single",
          onPick: function (path, item) {
            var id = item && item.id ? parseInt(item.id, 10) : 0;
            if (!id) {
              // No id means no stable reference. Refuse rather than falling back
              // to the path: a silent downgrade to the deprecated URL form is
              // how documents stop being portable.
              alert("That item has no media id, so it cannot be referenced by key.");
              return;
            }
            val.key = "media:" + id;
            previewUrl = (item && item.url) || path || "";
            pick.textContent = "Replace image";
            sync();
            refresh();
          }
        });
      });
      field.appendChild(preview);
      field.appendChild(pick);

      var clear = document.createElement("button");
      clear.type = "button";
      clear.className = "cb-icon-btn";
      clear.style.width = "auto";
      clear.style.padding = "0 .6rem";
      clear.textContent = "Clear";
      clear.addEventListener("click", function () {
        val = {};
        previewUrl = "";
        alt.value = "";
        pick.textContent = "Choose from library";
        sync();
        refresh();
      });
      field.appendChild(clear);
    } else {
      field.appendChild(preview);
      var note = document.createElement("div");
      note.className = "cb-muted";
      note.textContent = "Media library unavailable — cannot choose an image.";
      field.appendChild(note);
    }

    field.appendChild(alt);
    return field;
  }

  function renderField(block, f) {
    if (!block.props) block.props = {};

    // Keyed media reference — its own control, not a text box.
    if (f.type === "media") {
      return renderMediaField(block, f);
    }

    // Repeater: an editable list of sub-item objects (icon-grid, image-grid).
    if (f.type === "repeater") {
      return renderRepeater(block, f);
    }

    // Column count needs to resize the `cols` array (and keep `ratio` valid
    // for the new count) alongside the plain prop write, and a full repaint
    // since the column editor itself must add/remove column slots.
    if (f.key === "numCols" && block.type === "columns") {
      return renderNumColsField(block, f);
    }

    var field = document.createElement("div");
    field.className = "cb-field";
    var id = "f_" + Math.random().toString(36).slice(2);
    field.innerHTML = '<label class="cb-field-label" for="' + id + '">' + esc(f.label || f.key) + "</label>";

    var input;
    if (f.type === "textarea") {
      input = document.createElement("textarea");
      input.rows = 3;
    } else if (f.type === "select") {
      input = document.createElement("select");
      (f.options || []).forEach(function (o) {
        var opt = document.createElement("option");
        opt.value = o.v; opt.textContent = o.l;
        input.appendChild(opt);
      });
    } else {
      input = document.createElement("input");
      input.type = "text";
    }
    input.id = id;
    input.className = "cb-field-input";
    if (f.placeholder) input.placeholder = f.placeholder;
    input.value = block.props[f.key] != null ? block.props[f.key] : "";
    var onEdit = function () {
      block.props[f.key] = input.value;
      layoutInput.value = JSON.stringify(layout); // live sync, no repaint (keeps focus)
    };
    // Listen to BOTH: text/number/textarea fire "input"; some browsers only
    // fire "change" for <select> — without it, a dropdown change (e.g. text
    // alignment) would silently never be saved.
    input.addEventListener("input", onEdit);
    input.addEventListener("change", onEdit);
    field.appendChild(input);

    // Image fields get a "Choose from library" button + thumbnail preview,
    // but only if the Media Library plugin exposed its MediaPicker global.
    if (f.type === "image") {
      var hasPicker = (window.CB_HAS_MEDIA === true);
      var preview = document.createElement("div");
      preview.className = "cb-img-preview";
      var refreshPreview = function () {
        var v = input.value || "";
        preview.innerHTML = v ? '<img src="' + esc(v) + '" alt="">' : "";
      };
      refreshPreview();
      input.addEventListener("input", refreshPreview);

      if (hasPicker) {
        var pick = document.createElement("button");
        pick.type = "button";
        pick.className = "cb-icon-btn";
        pick.style.width = "auto";
        pick.style.padding = "0 .6rem";
        pick.textContent = "Choose from library";
        pick.addEventListener("click", function () {
          if (!window.MediaPicker || typeof window.MediaPicker.open !== "function") {
            alert("Media library is still loading — try again in a moment.");
            return;
          }
          window.MediaPicker.open({
            mode: "single",
            onPick: function (path) {
              input.value = path;
              block.props[f.key] = path;
              layoutInput.value = JSON.stringify(layout);
              refreshPreview();
            }
          });
        });
        var row = document.createElement("div");
        row.className = "cb-img-actions";
        row.appendChild(pick);
        field.appendChild(row);
      }
      field.appendChild(preview);
    }
    return field;
  }

  function renderNumColsField(block, f) {
    var field = document.createElement("div");
    field.className = "cb-field";
    var id = "f_" + Math.random().toString(36).slice(2);
    field.innerHTML = '<label class="cb-field-label" for="' + id + '">' + esc(f.label || f.key) + "</label>";

    var input = document.createElement("select");
    input.id = id;
    input.className = "cb-field-input";
    (f.options || []).forEach(function (o) {
      var opt = document.createElement("option");
      opt.value = o.v; opt.textContent = o.l;
      input.appendChild(opt);
    });
    var current = block.props.numCols != null
      ? String(block.props.numCols)
      : String((Array.isArray(block.props.cols) ? block.props.cols.length : 2) || 2);
    input.value = current;
    input.addEventListener("change", function () {
      var n = parseInt(input.value, 10) || 1;
      if (resizeCols(block, n)) {
        block.props.numCols = String(n);
      } else {
        input.value = current; // user declined the data-loss confirm — revert
      }
      sync(); // repaint either way: rebuilds the column editor for the real state
    });
    field.appendChild(input);
    return field;
  }

  function renderRepeater(block, f) {
    if (!Array.isArray(block.props[f.key])) block.props[f.key] = [];
    var list = block.props[f.key];
    var wrap = document.createElement("div");
    wrap.className = "cb-field";
    wrap.innerHTML = '<label class="cb-field-label">' + esc(f.label || f.key) + "</label>";

    var container = document.createElement("div");
    container.className = "cb-repeater";

    function repaintRepeater() {
      container.innerHTML = "";
      list.forEach(function (item, idx) {
        var card = document.createElement("div");
        card.className = "cb-repeater-item";
        var head = document.createElement("div");
        head.className = "cb-repeater-head";
        head.innerHTML = '<span>#' + (idx + 1) + "</span>";
        var ctr = document.createElement("div");
        ctr.appendChild(iconBtn("↑", function () { if (idx>0){ list.splice(idx-1,0,list.splice(idx,1)[0]); sync(); } }));
        ctr.appendChild(iconBtn("↓", function () { if (idx<list.length-1){ list.splice(idx+1,0,list.splice(idx,1)[0]); sync(); } }));
        ctr.appendChild(iconBtn("✕", function () { list.splice(idx,1); sync(); }, "cb-danger"));
        head.appendChild(ctr);
        card.appendChild(head);
        (f.item || []).forEach(function (sub) {
          card.appendChild(renderField({ props: item }, sub));
        });
        container.appendChild(card);
      });
    }

    repaintRepeater();
    wrap.appendChild(container);

    var add = document.createElement("button");
    add.type = "button";
    add.className = "cb-icon-btn";
    add.style.width = "auto";
    add.style.padding = "0 .6rem";
    add.style.marginTop = ".4rem";
    add.textContent = "+ Add item";
    add.addEventListener("click", function () {
      var blank = {};
      (f.item || []).forEach(function (sub) { blank[sub.key] = ""; });
      list.push(blank);
      sync();
    });
    wrap.appendChild(add);
    return wrap;
  }

  function iconBtn(label, fn, cls) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "cb-icon-btn" + (cls ? " " + cls : "");
    b.textContent = label;
    b.addEventListener("click", fn);
    return b;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  // Auto-fill slug from title if slug is empty.
  var title = document.getElementById("title");
  var slug = document.getElementById("slug");
  if (title && slug) {
    title.addEventListener("blur", function () {
      if (!slug.value.trim() && title.value.trim()) {
        slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
      }
    });
  }

  // ---- Section pattern gallery ----------------------------------------
  // Premade, pre-filled sections from window.CB_PATTERNS. Clicking a card
  // appends its blocks to the layout; the user then edits them in place.
  var PATTERNS = window.CB_PATTERNS || {};

  // A pattern's `thumb` token list -> a tiny schematic preview.
  var THUMB = {
    "band":      '<span class="cb-th-band"></span>',
    "band-dark": '<span class="cb-th-band cb-th-dark"></span>',
    "img":       '<span class="cb-th-img"></span>',
    "split":     '<span class="cb-th-split"><i></i><b></b></span>',
    "h":         '<span class="cb-th-h"></span>',
    "t":         '<span class="cb-th-t"></span>',
    "btn":       '<span class="cb-th-btn"></span>',
    "row2":      '<span class="cb-th-row"><i></i><i></i></span>',
    "row3":      '<span class="cb-th-row"><i></i><i></i><i></i></span>',
    "row4":      '<span class="cb-th-row"><i></i><i></i><i></i><i></i></span>',
    "quote":     '<span class="cb-th-quote"></span>'
  };
  function thumbHtml(tokens) {
    return (tokens || []).map(function (t) { return THUMB[t] || ""; }).join("");
  }

  // The patterns carry no thumbnail data, so derive a small schematic from
  // the block types they contain (hero->banner/split, icon-grid->card row…).
  function thumbFromBlocks(blocks) {
    var out = [];
    (blocks || []).forEach(function (b) {
      var p = b.props || {};
      switch (b.type) {
        case "hero":
          if (p.layout === "split") { out.push("split"); }
          else { out.push((p.overlay === "dark") ? "band-dark" : "img", "h", "t", "btn"); }
          break;
        case "icon-grid":
          out.push((p.bg === "dark") ? "band-dark" : "h", "row" + (p.cols || "3"));
          break;
        case "image-grid": out.push("h", "row" + (p.cols || "3")); break;
        case "testimonial": out.push("quote"); break;
        case "cta": out.push("band-dark", "h", "btn"); break;
        case "heading": out.push("h"); break;
        case "paragraph": out.push("t"); break;
        case "columns": out.push("row2"); break;
        case "html":
          // A full custom-HTML design — show a finished-page schematic.
          out.push("band-dark", "h", "t", "btn", "row3", "quote", "band-dark");
          break;
        default: out.push("band");
      }
    });
    return out.length ? out : ["band"];
  }

  // Generic gallery wiring used for BOTH the section library (append) and the
  // page-template library (replace). cfg = {btnId, modalId, tabsId, gridId, onPick}.
  function setupGallery(cfg) {
    var openBtn = document.getElementById(cfg.btnId);
    var modal   = document.getElementById(cfg.modalId);
    var tabsEl  = document.getElementById(cfg.tabsId);
    var gridEl  = document.getElementById(cfg.gridId);
    if (!openBtn || !modal || !tabsEl || !gridEl) return;

    var data = cfg.data || {};
    var groups = [], byGroup = {};
    Object.keys(data).forEach(function (key) {
      var g = data[key].category || "Other";
      if (!byGroup[g]) { byGroup[g] = []; groups.push(g); }
      byGroup[g].push(key);
    });
    if (!groups.length) { openBtn.style.display = "none"; return; }
    var active = groups[0];

    function renderTabs() {
      tabsEl.innerHTML = "";
      groups.forEach(function (g) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "cb-section-tab" + (g === active ? " is-active" : "");
        b.textContent = g;
        b.addEventListener("click", function () { active = g; renderTabs(); renderGrid(); });
        tabsEl.appendChild(b);
      });
    }

    function renderGrid() {
      gridEl.innerHTML = "";
      (byGroup[active] || []).forEach(function (key) {
        var p = data[key];
        var n = Array.isArray(p.blocks) ? p.blocks.length : 0;
        var card = document.createElement("button");
        card.type = "button";
        card.className = "cb-section-card";
        // Real rendered preview when the entry ships one (page templates);
        // otherwise fall back to the schematic block diagram (sections).
        var thumb = p.preview
          ? '<span class="cb-section-thumb cb-live-thumb"><iframe loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true" srcdoc="' + esc(p.preview) + '"></iframe></span>'
          : '<span class="cb-section-thumb">' + thumbHtml(thumbFromBlocks(p.blocks)) + "</span>";
        card.innerHTML =
          thumb +
          '<span class="cb-section-name">' + esc(p.label || key) + "</span>" +
          '<span class="cb-section-meta">' + n + (n === 1 ? " block" : " blocks") + "</span>";
        card.addEventListener("click", function () {
          if (cfg.onPick(p)) close();
        });
        gridEl.appendChild(card);
      });
    }

    function open()  { renderTabs(); renderGrid(); modal.hidden = false; document.body.classList.add("cb-modal-open"); }
    function close() { modal.hidden = true; document.body.classList.remove("cb-modal-open"); }

    openBtn.addEventListener("click", open);
    Array.prototype.forEach.call(modal.querySelectorAll("[data-cb-close]"), function (el) {
      el.addEventListener("click", close);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !modal.hidden) close();
    });
  }

  function cloneBlocks(blocks) {
    return Array.isArray(blocks) ? JSON.parse(JSON.stringify(blocks)) : [];
  }

  // Section library: append the chosen section's blocks to the end of the page.
  setupGallery({
    btnId: "cb-add-section", modalId: "cb-section-modal",
    tabsId: "cb-section-tabs", gridId: "cb-section-grid",
    data: PATTERNS,
    onPick: function (p) {
      cloneBlocks(p.blocks).forEach(function (b) { layout.push(b); });
      sync();
      var last = canvas.lastElementChild;
      if (last && last.scrollIntoView) last.scrollIntoView({ behavior: "smooth", block: "center" });
      return true;
    }
  });

  // Page-template library: replace the whole page (confirm if it has content).
  setupGallery({
    btnId: "cb-add-template", modalId: "cb-template-modal",
    tabsId: "cb-template-tabs", gridId: "cb-template-grid",
    data: window.CB_TEMPLATES || {},
    onPick: function (p) {
      if (layout.length && !window.confirm("Replace the current page with this template? Your existing blocks will be removed.")) {
        return false;
      }
      layout.length = 0;
      cloneBlocks(p.blocks).forEach(function (b) { layout.push(b); });
      // Designer pages ship as a full HTML document — switch the page's render
      // mode so it outputs verbatim (no theme shell) when saved.
      if (p.mode) {
        var rm = document.querySelector('select[name="cb_render_mode"]');
        if (rm && rm.value !== p.mode) {
          rm.value = p.mode;
          rm.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
      sync();
      // The admin shell scrolls inside the floating .app-panel on desktop, but
      // the window on mobile — scroll whichever is actually the scroll container.
      var _panel = document.querySelector(".app-panel");
      var _scroller = (_panel && _panel.scrollHeight > _panel.clientHeight + 1) ? _panel : window;
      _scroller.scrollTo({ top: 0, behavior: "smooth" });
      return true;
    }
  });

  if (previewFrame) {
    previewFrame.name = "cb-live-preview-frame";
    previewFrame.addEventListener("load", function () {
      previewFrame.classList.add("is-ready");
    });
  }
  if (previewRefresh) {
    previewRefresh.addEventListener("click", function () {
      submitPreview();
    });
  }
  if (previewFrame) {
    window.addEventListener("message", function (event) {
      if (event.source !== previewFrame.contentWindow || event.origin !== window.location.origin) return;
      var data = event.data || {};
      if (data.type !== "canvas-height") return;
      var height = Math.max(320, Math.min(2400, parseInt(data.height, 10) || 0));
      if (height) previewFrame.style.height = height + "px";
    });
  }

  // Toggle the (collapsed-by-default) block palette.
  var _paletteToggle = document.getElementById("cb-add-block-toggle");
  if (_paletteToggle && palette) {
    _paletteToggle.addEventListener("click", function () {
      var show = palette.hasAttribute("hidden");
      if (show) palette.removeAttribute("hidden"); else palette.setAttribute("hidden", "");
      _paletteToggle.setAttribute("aria-expanded", show ? "true" : "false");
      _paletteToggle.classList.toggle("is-active", show);
    });
  }

  normalizeLayout();
  layoutInput.value = JSON.stringify(layout); // persist backfilled defaults on next save
  buildPalette();
  paint();
  queuePreview();
})();
