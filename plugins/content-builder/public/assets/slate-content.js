/**
 * Slate content renderer — the second consumer of the frozen document.
 *
 * Reads the ADR-0013 envelope and renders the same blocks the PHP renderer does.
 * No build step (ADR-0003): plain ES modules, no JSX, no bundler. A React app
 * mounts the output; it does not have to compile this.
 *
 * Blocks render to markup strings rather than straight to DOM so the same code
 * serves server-side rendering in a Node host, a hydration pass in the browser,
 * and the test that compares this output against the PHP renderer's. A DOM-only
 * implementation could not be checked against PHP at all.
 *
 * Every block reads the SAME props the PHP template reads. That is the property
 * worth protecting: when a block's fields change, both renderers change from one
 * definition rather than drifting.
 */

/** htmlspecialchars($s, ENT_QUOTES, 'UTF-8') — matched exactly so output can be compared. */
export function e(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/**
 * Mirror of slate_safe_url(). An executable scheme in an href is the exact hole
 * closed in #6; a second renderer that did not carry the same rule would quietly
 * reopen it on the React side.
 */
export function safeUrl(url, fallback = '#') {
  const raw = String(url ?? '').trim();
  if (raw === '') return fallback;

  // Browsers ignore control characters while parsing a scheme, so strip them for
  // the decision and return the original.
  const probe = raw.replace(/[\u0000-\u0020\u007f]+/g, '');
  const scheme = probe.match(/^([a-zA-Z][a-zA-Z0-9+.-]*):/);
  if (scheme && !['http', 'https', 'mailto', 'tel'].includes(scheme[1].toLowerCase())) {
    return fallback;
  }
  return raw;
}

const oneOf = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);

/**
 * Mirror of ContentBuilderAPI::mediaUrl(). Slate can live under a sub-path
 * (/slate), so a stored '/uploads/x.png' resolves against the DOMAIN root and
 * 404s unless the install's base is prepended. The consumer cannot know that
 * base, so the envelope carries it in meta.base.
 */
export function mediaUrl(value, base = '') {
  const v = String(value ?? '').trim();
  if (v === '') return '';
  if (/^(?:https?:)?\/\//i.test(v) || v.startsWith('data:')) return v;
  if (v.startsWith('/') && base !== '' && !v.startsWith(base + '/')) return base + v;
  return v;
}

/**
 * Resolve a media reference the way ContentBuilderAPI::resolveMedia() does.
 * `resolver` maps a logical key to a URL or {url, alt}; without one, a keyed
 * reference yields no image rather than an <img> whose src is a key.
 */
export function resolveMedia(props, resolver, base = '') {
  const media = props.media;
  if (media && typeof media === 'object' && media.key) {
    const mapped = resolver ? resolver(media.key) : null;
    const url = typeof mapped === 'string' ? mapped : (mapped && mapped.url) || '';
    return {
      url: mediaUrl(url, base),
      alt: media.alt || (mapped && mapped.alt) || '',
      focal: Array.isArray(media.focal) && media.focal.length === 2 ? media.focal : null,
      key: media.key,
    };
  }
  return { url: mediaUrl(props.src || '', base), alt: props.alt || '', focal: null, key: '' };
}

/**
 * Icon paths, mirrored from lib/blocks/icon-grid.php. Duplicated rather than
 * fetched because the renderer must work with no network and no PHP; the parity
 * test is what keeps the two copies honest.
 */
const ICON_PATHS = {
  star: '<path d="M12 2l3 7 7 .8-5.4 4.8L18 22l-6-3.5L6 22l1.4-7.4L2 9.8 9 9z"/>',
  box: '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v9"/>',
  truck: '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
  check: '<path d="M20 6L9 17l-5-5"/>',
  heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
  bolt: '<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>',
  shield: '<path d="M12 3l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V7z"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  leaf: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/>',
  gift: '<rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M5 12v9h14v-9M12 8a3 3 0 1 0-3-3M12 8a3 3 0 1 1 3-3"/>',
};

/** ctx.base, defaulted. */
const p_base = (ctx) => (ctx && ctx.base) || '';

/** PHP nl2br() with its default XHTML break, so output can be compared. */
function nl2br(value) {
  return String(value ?? '').replace(/(\r\n|\n\r|\n|\r)/g, '<br />$1');
}

/** Shared section chrome: eyebrow, title and the divider that follows a title. */
function sectionHead(p) {
  return (p.eyebrow ? `<span class="cb-eyebrow">${e(p.eyebrow)}</span>` : '')
    + (p.heading ? `<h2 class="cb-section-title">${e(p.heading)}</h2><div class="cb-divider"></div>` : '');
}

const blocks = {
  heading(p) {
    const level = Math.min(6, Math.max(1, parseInt(p.level ?? 2, 10) || 2));
    return `<h${level} class="cb-heading">${e(p.text ?? '')}</h${level}>`;
  },

  paragraph(p) {
    if (!p.text) return '';
    return `<p class="cb-paragraph">${e(p.text)}</p>`;
  },

  button(p) {
    // PHP maps the stored value, not a class name: 'secondary' -> btn-secondary,
    // anything else -> btn-primary. An earlier port assumed the field held the
    // class itself and invented a btn-outline that no template emits.
    const style = p.style === 'secondary' ? 'btn-secondary' : 'btn-primary';
    return `<p class="cb-button"><a class="btn ${style}" href="${e(safeUrl(p.href))}">${e(p.text ?? 'Button')}</a></p>`;
  },

  image(p, ctx) {
    const m = resolveMedia(p, ctx.resolveMediaKey, ctx.base || '');
    if (!m.url) return '';
    const width = oneOf(p.width, ['full', 'wide', 'normal'], 'full');
    const style = m.focal
      ? ` style="object-position:${(m.focal[0] * 100).toFixed(2)}% ${(m.focal[1] * 100).toFixed(2)}%"`
      : '';
    return `<figure class="cb-image cb-image-${e(width)}">`
      + `<img src="${e(safeUrl(m.url))}" alt="${e(m.alt)}" loading="lazy"${style}>`
      + `</figure>`;
  },

  hero(p, ctx) {
    const base = ctx.base || '';
    const layout = oneOf(p.layout, ['split', 'banner'], 'banner');
    const overlay = oneOf(p.overlay, ['light', 'medium', 'dark'], 'medium');
    const height = oneOf(p.height, ['short', 'normal', 'tall'], 'normal');
    const mediaSide = p.mediaSide === 'right' ? 'right' : 'left';
    const pad = oneOf(p.pad, ['compact', 'normal', 'spacious'], 'normal');
    const img = mediaUrl(p.image || '', base);
    const hasImg = img !== '';

    const eyebrow = p.eyebrow ? `<span class="cb-eyebrow">${e(p.eyebrow)}</span>` : '';
    const title = p.heading ? `<h1 class="cb-hero-title">${e(p.heading)}</h1>` : '';
    const sub = p.subheading ? `<p class="cb-hero-sub">${e(p.subheading)}</p>` : '';
    const btn1 = p.btnText
      ? `<a class="cb-btn cb-btn-primary" href="${e(safeUrl(p.btnHref))}">${e(p.btnText)}</a>` : '';
    const btn2 = p.btn2Text
      ? `<a class="cb-btn cb-btn-outline" href="${e(safeUrl(p.btn2Href))}"${hasImg ? ' style="color:#fff;box-shadow:inset 0 0 0 1.5px #fff"' : ''}>${e(p.btn2Text)}</a>` : '';
    const actions = `<div class="cb-hero-actions">${btn1}${btn2}</div>`;

    if (layout === 'split' && hasImg) {
      return `<section class="cb-pad-${pad} cb-hero cb-hero-split cb-hero-media-${mediaSide}">`
        + `<div class="cb-hero-inner">`
        + `<div class="cb-hero-media"><img src="${e(safeUrl(img))}" alt="${e(p.heading || '')}"></div>`
        + `<div class="cb-hero-body">`
        + (p.eyebrow ? `<span class="cb-eyebrow" style="text-align:left">${e(p.eyebrow)}</span>` : '')
        + `${title}${sub}${actions}</div></div></section>`;
    }

    const cls = `cb-hero cb-hero-banner cb-hero-h-${height}`
      + (hasImg ? ` cb-overlay-${overlay}` : ' cb-hero-plain');
    const style = hasImg ? ` style="background-image:url('${e(safeUrl(img))}')"` : '';
    return `<section class="cb-pad-${pad} ${cls}"${style}>`
      + `<div class="cb-hero-inner">${eyebrow}${title}${sub}${actions}</div></section>`;
  },

  'rx-hero': function (p, ctx) {
    const tone = p.tone === 'light' ? 'light' : 'dark';
    const bg = mediaUrl(String(p.image ?? '').trim(), p_base(ctx));
    const style = bg !== '' ? ` style="--rx-hero-img:url('${e(bg)}')"` : '';

    const card = (p.cardTitle || p.cardBadge)
      ? `<aside class="rx-hero-card">`
        + (p.cardBadge ? `<span class="rx-hero-badge">${e(p.cardBadge)}</span>` : '')
        + `<div class="rx-hero-plate"><div>`
        + (p.cardTitle ? `<b>${e(p.cardTitle)}</b>` : '')
        + (p.cardNote ? `<span>${e(p.cardNote)}</span>` : '')
        + `</div>`
        + (p.cardPrice ? `<div class="rx-hero-price">${e(p.cardPrice)}</div>` : '')
        + `</div></aside>`
      : '';

    return `<section class="rx rx-hero rx-tone-${tone}${bg !== '' ? ' rx-hero-photo' : ''}"${style}>`
      + `<div class="rx-hero-inner"><div class="rx-hero-copy">`
      + (p.eyebrow ? `<span class="rx-eyebrow">${e(p.eyebrow)}</span>` : '')
      + `<h1 class="rx-hero-title">${e(p.heading ?? '')}`
      + (p.accentLine ? `<br><em>${e(p.accentLine)}</em>` : '')
      + (p.headingEnd ? `<br>${e(p.headingEnd)}` : '')
      + `</h1>`
      + (p.lead ? `<p class="rx-hero-lead">${nl2br(e(p.lead))}</p>` : '')
      + `<div class="rx-actions">`
      + (p.btnText ? `<a class="rx-btn rx-btn-primary" href="${e(safeUrl(p.btnHref))}">${e(p.btnText)}</a>` : '')
      + (p.btn2Text ? `<a class="rx-btn rx-btn-ghost" href="${e(safeUrl(p.btn2Href))}">${e(p.btn2Text)}</a>` : '')
      + `</div>`
      + (p.rating ? `<div class="rx-trust"><span class="rx-stars">★★★★★</span><small>${e(p.rating)}</small></div>` : '')
      + `</div>${card}</div></section>`;
  },

  'rx-marquee': function (p) {
    const items = Array.isArray(p.items) ? p.items : [];
    const phrases = items.map((it) => String(it.text ?? '').trim()).filter((s) => s !== '');
    if (phrases.length === 0) return '';
    const tone = p.tone ?? 'surface';
    // The track is emitted twice so the strip can scroll seamlessly.
    const run = phrases.map((s) => `<span class="rx-marquee-item">${e(s)}</span>`).join('');
    return `<div class="rx rx-marquee rx-mq-${e(tone)}"><div class="rx-marquee-track">${run}${run}</div></div>`;
  },

  'rx-story': function (p, ctx) {
    const tone = p.tone === 'dark' ? 'dark' : 'light';
    const feats = Array.isArray(p.items) ? p.items : [];
    const img = mediaUrl(String(p.image ?? '').trim(), p_base(ctx));

    const visualInner = img === ''
      ? `<div class="rx-story-medallion"><div class="rx-stat-big">${e(p.statBig ?? '')}</div><small>${e(p.statLabel ?? '')}</small></div>`
      : (p.statBig ? `<div class="rx-story-chip"><b>${e(p.statBig)}</b> <span>${e(p.statLabel ?? '')}</span></div>` : '');

    const featGrid = feats.length
      ? `<div class="rx-feat-grid">` + feats.map((f) => `<div class="rx-feat">`
          + (f.icon ? `<span class="rx-feat-ic">${e(f.icon)}</span>` : '')
          + (f.title ? `<b>${e(f.title)}</b>` : '')
          + (f.text ? `<span class="rx-feat-text">${e(f.text)}</span>` : '')
          + `</div>`).join('') + `</div>`
      : '';

    return `<section class="rx rx-story rx-tone-${tone}"><div class="rx-story-inner">`
      + `<div class="rx-story-visual"${img !== '' ? ` style="background-image:url('${e(img)}')"` : ''}>${visualInner}</div>`
      + `<div class="rx-story-copy">`
      + (p.eyebrow ? `<span class="rx-eyebrow">${e(p.eyebrow)}</span>` : '')
      + (p.heading ? `<h2 class="rx-h2">${e(p.heading)}</h2>` : '')
      + (p.body ? `<div class="rx-prose">${nl2br(e(p.body))}</div>` : '')
      + featGrid
      + `</div></div></section>`;
  },

  'rx-menu': function (p, ctx) {
    const tone = p.tone === 'dark' ? 'dark' : 'surface';
    const cols = oneOf(String(p.cols ?? '3'), ['2', '3'], '3');
    const dishes = Array.isArray(p.items) ? p.items : [];

    const grid = dishes.map((d) => {
      const img = mediaUrl(String(d.image ?? '').trim(), p_base(ctx));
      return `<article class="rx-dish">`
        + `<div class="rx-dish-pic"${img !== '' ? ` style="background-image:url('${e(img)}')"` : ''}>`
        + (d.tag ? `<span class="rx-dish-tag">${e(d.tag)}</span>` : '')
        + `</div><div class="rx-dish-body"><div class="rx-dish-row"><h3>${e(d.name ?? '')}</h3>`
        + (d.price !== undefined && d.price !== '' ? `<span class="rx-dish-price">${e(d.price)}</span>` : '')
        + `</div>`
        + (d.text ? `<p>${e(d.text)}</p>` : '')
        + `</div></article>`;
    }).join('');

    return `<section class="rx rx-menu rx-tone-${tone}"><div class="rx-inner">`
      + `<div class="rx-head rx-head-center">`
      + (p.eyebrow ? `<span class="rx-eyebrow">${e(p.eyebrow)}</span>` : '')
      + (p.heading ? `<h2 class="rx-h2">${e(p.heading)}</h2>` : '')
      + (p.intro ? `<p class="rx-head-sub">${e(p.intro)}</p>` : '')
      + `</div><div class="rx-menu-grid rx-cols-${cols}">${grid}</div></div></section>`;
  },

  'rx-gallery': function (p, ctx) {
    const tone = p.tone === 'dark' ? 'dark' : 'light';
    const tiles = Array.isArray(p.items) ? p.items : [];

    const grid = tiles.map((tile) => {
      const img = mediaUrl(String(tile.image ?? '').trim(), p_base(ctx));
      const size = tile.size ?? 'normal';
      const cls = size === 'wide' ? ' rx-tile-wide' : (size === 'tall' ? ' rx-tile-tall' : '');
      return `<figure class="rx-tile${cls}"${img !== '' ? ` style="background-image:url('${e(img)}')"` : ''}>`
        + (tile.label ? `<figcaption class="rx-tile-label">${e(tile.label)}</figcaption>` : '')
        + `</figure>`;
    }).join('');

    return `<section class="rx rx-gallery rx-tone-${tone}"><div class="rx-inner"><div class="rx-head">`
      + (p.eyebrow ? `<span class="rx-eyebrow">${e(p.eyebrow)}</span>` : '')
      + (p.heading ? `<h2 class="rx-h2">${e(p.heading)}</h2>` : '')
      + `</div><div class="rx-gallery-grid">${grid}</div></div></section>`;
  },

  'rx-reviews': function (p, ctx) {
    const tone = p.tone === 'dark' ? 'dark' : 'surface';
    const revs = Array.isArray(p.items) ? p.items : [];

    const grid = revs.map((r) => {
      // PHP: (int)($r['rating'] ?? 5) then clamped to 1..5. An absent rating
      // defaults to 5; a PRESENT one is cast, so 0 and garbage both become 0 and
      // clamp up to 1. `|| 5` would be wrong — 0 is falsy, and a zero rating
      // would silently render as five stars.
      const raw = r.rating ?? 5;
      const cast = Number.isNaN(parseInt(raw, 10)) ? 0 : parseInt(raw, 10);
      const n = Math.max(1, Math.min(5, cast));
      const name = String(r.name ?? '').trim();
      // mb_strtoupper(mb_substr($name, 0, 1)) — first CHARACTER, not first code
      // unit, so an emoji or accented letter is not split in half.
      const initial = name !== '' ? [...name][0].toUpperCase() : '★';
      const stars = '★'.repeat(n) + '☆'.repeat(5 - n);
      return `<blockquote class="rx-review"><span class="rx-stars">${stars}</span>`
        + (r.quote ? `<p>${e(r.quote)}</p>` : '')
        + `<footer class="rx-review-by">`
        + `<span class="rx-avatar"${r.avatar ? ` style="background-image:url('${e(mediaUrl(r.avatar, p_base(ctx)))}')"` : ''}>${r.avatar ? '' : e(initial)}</span>`
        + `<span>`
        + (name !== '' ? `<b>${e(name)}</b>` : '')
        + (r.meta ? `<small>${e(r.meta)}</small>` : '')
        + `</span></footer></blockquote>`;
    }).join('');

    return `<section class="rx rx-reviews rx-tone-${tone}"><div class="rx-inner">`
      + `<div class="rx-head rx-head-center">`
      + (p.eyebrow ? `<span class="rx-eyebrow">${e(p.eyebrow)}</span>` : '')
      + (p.heading ? `<h2 class="rx-h2">${e(p.heading)}</h2>` : '')
      + `</div><div class="rx-reviews-grid">${grid}</div></div></section>`;
  },

  'rx-visit': function (p) {
    const tone = p.tone === 'dark' ? 'dark' : 'surface';
    const hours = Array.isArray(p.items) ? p.items : [];

    const meta = (p.address || p.phone)
      ? `<p class="rx-visit-meta">`
        + (p.address ? `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> ${e(p.address)}` : '')
        + (p.address && p.phone ? ` &nbsp;·&nbsp; ` : '')
        + (p.phone ? `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M6.6 3.6L9.4 8l-2 2.2a12.8 12.8 0 0 0 6.4 6.4l2.2-2 4.4 2.8v3a1.6 1.6 0 0 1-1.75 1.6A17.6 17.6 0 0 1 3 5.35 1.6 1.6 0 0 1 4.6 3.6z"/></svg> ${e(p.phone)}` : '')
        + `</p>`
      : '';

    const list = hours.length
      ? `<ul class="rx-hours">` + hours.map((h) => {
          // PHP: !empty($h['highlight']) && $h['highlight'] !== 'false' — the
          // string 'false' is treated as off, because a select stores strings.
          const hi = h.highlight && h.highlight !== 'false';
          return `<li${hi ? ' class="rx-hours-open"' : ''}><span>${e(h.label ?? '')}</span><b>${e(h.value ?? '')}</b></li>`;
        }).join('') + `</ul>`
      : '';

    return `<section class="rx rx-visit rx-tone-${tone}"><div class="rx-inner"><div class="rx-visit-card">`
      + `<div class="rx-visit-copy">`
      + (p.eyebrow ? `<span class="rx-eyebrow">${e(p.eyebrow)}</span>` : '')
      + (p.heading ? `<h2 class="rx-h2">${e(p.heading)}</h2>` : '')
      + (p.text ? `<p class="rx-head-sub">${nl2br(e(p.text))}</p>` : '')
      + `<div class="rx-actions">`
      + (p.btnText ? `<a class="rx-btn rx-btn-primary" href="${e(safeUrl(p.btnHref))}">${e(p.btnText)}</a>` : '')
      + (p.btn2Text ? `<a class="rx-btn rx-btn-ghost" href="${e(safeUrl(p.btn2Href))}">${e(p.btn2Text)}</a>` : '')
      + `</div>${meta}</div>${list}</div></div></section>`;
  },

  'icon-grid': function (p) {
    const items = Array.isArray(p.items) ? p.items : [];
    const bg = oneOf(p.bg, ['page', 'white', 'tint', 'tint2', 'dark'], 'white');
    const cols = oneOf(String(p.cols ?? '3'), ['2', '3', '4'], '3');
    const pad = oneOf(p.pad, ['compact', 'normal', 'spacious'], 'normal');

    const cards = items.map((it) => {
      const path = ICON_PATHS[it.icon] || ICON_PATHS.star;
      return `<div class="cb-card">`
        + `<span class="cb-card-icon" aria-hidden="true">`
        + `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" width="26" height="26">${path}</svg>`
        + `</span>`
        + (it.title ? `<h3 class="cb-card-title">${e(it.title)}</h3>` : '')
        + (it.text ? `<p class="cb-card-text">${e(it.text)}</p>` : '')
        + (it.linkText && it.linkHref
            ? `<a class="cb-card-link" href="${e(safeUrl(it.linkHref))}">${e(it.linkText)} &rarr;</a>` : '')
        + `</div>`;
    }).join('');

    return `<section class="cb-pad-${pad} cb-section cb-icon-grid cb-bg-${bg}">`
      + `<div class="cb-section-inner">${sectionHead(p)}`
      + `<div class="cb-grid cb-grid-${cols}">${cards}</div></div></section>`;
  },

  'image-grid': function (p, ctx) {
    const items = Array.isArray(p.items) ? p.items : [];
    const bg = oneOf(p.bg, ['page', 'white', 'tint', 'tint2', 'dark'], 'tint');
    const cols = oneOf(String(p.cols ?? '3'), ['2', '3', '4'], '3');
    const pad = oneOf(p.pad, ['compact', 'normal', 'spacious'], 'normal');

    const cards = items.map((it) => {
      const href = String(it.href ?? '').trim();
      const tag = href !== '' ? 'a' : 'div';
      const attr = href !== '' ? ` href="${e(safeUrl(href))}"` : '';
      const img = it.image
        ? `<img class="cb-image-card-img" src="${e(mediaUrl(it.image, ctx.base || ''))}" alt="${e(it.title ?? '')}" loading="lazy">`
        : `<span class="cb-image-card-ph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" width="30" height="30"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg></span>`;
      return `<${tag} class="cb-image-card"${attr}>${img}<div class="cb-image-card-body">`
        + (it.title ? `<h3 class="cb-image-card-title">${e(it.title)}</h3>` : '')
        + (it.text ? `<p class="cb-image-card-text">${e(it.text)}</p>` : '')
        + `</div></${tag}>`;
    }).join('');

    return `<section class="cb-pad-${pad} cb-section cb-image-grid cb-bg-${bg}">`
      + `<div class="cb-section-inner">${sectionHead(p)}`
      + `<div class="cb-grid cb-grid-${cols}">${cards}</div></div></section>`;
  },

  /**
   * Dynamic: its content comes from the database, so the renderer cannot produce
   * it alone. The API resolves it server-side and the envelope carries the items
   * beside the document, keyed by position (ADR-0013 addendum).
   *
   * With no resolved entry this renders the same empty state the PHP template
   * does when its query returns nothing — so a consumer that ignores `resolved`
   * degrades to an accurate "no posts", never to a hole or a crash.
   */
  'post-list': function (p, ctx) {
    const entry = (ctx.resolved && ctx.path && ctx.resolved[ctx.path]) || null;
    const items = entry && Array.isArray(entry.items) ? entry.items : [];

    if (items.length === 0) {
      return '<p class="cb-empty text-muted">No posts found.</p>';
    }

    return '<ul class="cb-post-list">' + items.map((it) =>
      `<li class="cb-post-list-item">`
      + `<a href="${e(safeUrl(it.url))}">${e(it.title || '(untitled)')}</a>`
      + (it.excerpt ? `<p class="cb-post-list-excerpt">${e(it.excerpt)}</p>` : '')
      + `</li>`).join('') + '</ul>';
  },

  cta(p) {
    const pad = oneOf(p.pad, ['compact', 'normal', 'spacious'], 'normal');
    return `<section class="cb-pad-${pad} cb-cta"><div class="cb-cta-inner">`
      + (p.eyebrow ? `<span class="cb-eyebrow" style="color:rgba(255,255,255,.85)">${e(p.eyebrow)}</span>` : '')
      + (p.heading ? `<h2 class="cb-cta-title">${e(p.heading)}</h2>` : '')
      + (p.text ? `<p class="cb-cta-text">${e(p.text)}</p>` : '')
      + (p.btnText ? `<a class="cb-btn cb-btn-primary" href="${e(safeUrl(p.btnHref))}">${e(p.btnText)}</a>` : '')
      + `</div></section>`;
  },

  testimonial(p, ctx) {
    const pad = oneOf(p.pad, ['compact', 'normal', 'spacious'], 'normal');
    const avatar = mediaUrl(p.avatar || '', ctx.base || '');
    return `<section class="cb-pad-${pad} cb-testimonial"><figure class="cb-testimonial-inner">`
      + `<blockquote class="cb-testimonial-quote">&ldquo;${e(p.quote ?? '')}&rdquo;</blockquote>`
      + `<figcaption class="cb-testimonial-cite">`
      + (avatar ? `<img class="cb-testimonial-avatar" src="${e(safeUrl(avatar))}" alt="">` : '')
      + `<span><span class="cb-testimonial-author">${e(p.author ?? '')}</span>`
      + (p.role ? `<span class="cb-testimonial-role">${e(p.role)}</span>` : '')
      + `</span></figcaption></figure></section>`;
  },

  /**
   * A container. Its children are rendered through renderBlock, so nesting
   * behaves identically on both sides and a block inside a column gets the same
   * treatment as one at the top level.
   *
   * Kept in parity with the PHP template (plugins/content-builder/lib/blocks/
   * columns.php) — ratio/valign/gap/stack-on-mobile classes, the gap (and
   * optional min-height) inline style, and data-col-index all mirror it
   * exactly; this renderer never runs in the editor, so it always takes the
   * public (non-editor) branch, including the empty-column placeholder.
   */
  columns(p, ctx) {
    let cols = Array.isArray(p.cols) ? p.cols : [];
    if (cols.length === 0) cols = [{ blocks: [] }, { blocks: [] }];
    const numCols = cols.length;
    const ratio = p.ratio || (numCols === 3 ? '33-33-33' : numCols === 4 ? '25-25-25-25' : '50-50');
    const gap = (p.gap !== undefined && p.gap !== '') ? parseInt(p.gap, 10) : 24;
    const align = p.align || 'stretch';
    const minHeight = p.minHeight ? parseInt(p.minHeight, 10) : 0;
    const stackOnMobile = p.stackOnMobile === undefined ? true : !!p.stackOnMobile;

    const classes = ['cb-columns', `cb-cols-${numCols}`, `cb-ratio-${ratio}`, `cb-valign-${align}`];
    if (stackOnMobile) classes.push('cb-cols-stack');

    let style = `gap: ${gap}px;`;
    if (minHeight > 0) style += `min-height: ${minHeight}px;`;

    const inner = cols.map((col, colIdx) => {
      const kids = Array.isArray(col && col.blocks) ? col.blocks : [];
      const body = kids.length
        ? kids.map((b) => renderBlock(b, ctx)).join('')
        : '<div class="cb-col-empty"></div>';
      return `<div class="cb-col" data-col-index="${colIdx}">${body}</div>`;
    }).join('');

    return `<div class="${classes.join(' ')}" style="${style}">${inner}</div>`;
  },

  /**
   * Raw markup, passed through unescaped — matching the PHP block, which is
   * permission-gated behind content.publish on the way in. The gate lives at the
   * save boundary, not here; a renderer cannot re-check a permission it has no
   * session for, which is exactly why the server enforces it before storage.
   */
  html(p) {
    return String(p.html ?? '');
  },

  /**
   * The mount point. In the browser a React host replaces the fallback with the
   * real component; anywhere else the fallback is what a crawler sees.
   */
  react(p) {
    const name = String(p.component ?? '').trim();
    if (!/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/.test(name)) return '';
    const payload = JSON.stringify(p.componentProps ?? {});
    const fallback = p.fallback
      ? `<div class="cb-react-fallback">${e(p.fallback)}</div>`
      : '';
    return `<div class="cb-react" data-component="${e(name)}" data-props="${e(payload)}">${fallback}</div>`;
  },
};

/** Render one block. An unregistered type is named, never silently dropped. */
export function renderBlock(block, ctx = {}) {
  if (!block || typeof block !== 'object') return '';
  const fn = blocks[block.type];
  if (!fn) return `<!-- unknown block "${e(block.type)}" -->`;
  return fn(block.props || {}, ctx);
}

/** Render a whole document (the `document` member of the envelope). */
export function renderDocument(doc, ctx = {}) {
  if (!doc || !Array.isArray(doc.sections)) return '';
  return doc.sections
    .map((section) => (section.blocks || [])
      // The path identifies a block for resolved-data lookup. It must match the
      // key the API builds — "<sectionId>.<blockIndex>" — or a dynamic block
      // silently renders empty.
      .map((b, i) => renderBlock(b, { ...ctx, path: `${section.id}.${i}` }))
      .join(''))
    .join('');
}

/** Render a full envelope, applying its theme tokens as CSS custom properties. */
export function renderEnvelope(envelope, ctx = {}) {
  const tokens = (envelope && envelope.theme && envelope.theme.tokens) || {};
  const base = ctx.base ?? (envelope && envelope.meta && envelope.meta.base) ?? '';
  const resolved = ctx.resolved ?? (envelope && envelope.resolved) ?? {};
  return { html: renderDocument(envelope && envelope.document, { ...ctx, base, resolved }), tokens };
}

/**
 * Browser entry point: fetch a route and mount it.
 *
 * Theme tokens are applied as --slate-* custom properties on the mount element,
 * so one theme description drives both renderers (ADR-0008).
 */
export async function mount(el, route, options = {}) {
  const base = options.base || '';
  const res = await fetch(`${base}/api/content/${String(route).replace(/^\/+/, '')}`, {
    headers: { Accept: 'application/json' },
  });
  if (!res.ok) throw new Error(`content API returned ${res.status} for ${route}`);

  const envelope = await res.json();
  // The install's base path travels in the envelope, so a consumer on another
  // origin resolves stored URLs the same way the PHP renderer does.
  const ctx = { ...options, base: options.base ?? (envelope.meta && envelope.meta.base) ?? '' };
  const { html, tokens } = renderEnvelope(envelope, ctx);

  for (const [name, value] of Object.entries(tokens)) {
    if (/^--[a-z0-9-]+$/i.test(name)) el.style.setProperty(name, String(value));
  }
  el.innerHTML = html;
  return envelope;
}

export const __blockTypes = Object.keys(blocks);
