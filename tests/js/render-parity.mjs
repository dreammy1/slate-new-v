/**
 * T2 — the two renderers agree on the same document.
 *
 * Renders every frozen fixture through the JS renderer and compares it against
 * the HTML the PHP renderer produced (tests/fixtures/documents/expected/, the
 * goldens T1 checked in). If these two ever disagree, "HTML vs React is just an
 * output choice" stops being true and a page starts depending on which renderer
 * served it.
 *
 * Whitespace is normalised before comparing. PHP templates are indented for
 * humans and the JS builds strings without that indentation; requiring
 * byte-identical output would force one of them to be written badly to satisfy
 * the other. Everything that reaches a reader — tags, attributes, order, text —
 * is compared exactly.
 *
 * Run: node tests/js/render-parity.mjs
 */

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const fixtureDir = join(root, 'tests', 'fixtures', 'documents');

const { renderDocument } = await import(
  join(root, 'plugins', 'content-builder', 'public', 'assets', 'slate-content.js')
);

/** Collapse formatting whitespace without touching anything a reader sees. */
function normalise(html) {
  return String(html)
    .replace(/>\s+</g, '><')   // indentation between tags
    .replace(/\s+/g, ' ')      // runs of whitespace inside text
    .trim();
}

/**
 * The PHP schema normaliser upconverts a legacy flat array into one implicit
 * section. The JS renderer receives documents through the API, which has already
 * been normalised — so do the same here rather than teaching the JS renderer a
 * stored shape it will never actually see.
 */
function toDocument(parsed) {
  if (Array.isArray(parsed)) {
    return { schema: 1, type: 'page', template: '', sections: [{ id: 's1', layout: {}, blocks: parsed }], seo: {} };
  }
  return parsed;
}

let pass = 0;
const failures = [];

for (const file of readdirSync(fixtureDir).filter((f) => f.endsWith('.json')).sort()) {
  const name = file.replace(/\.json$/, '');
  const doc = toDocument(JSON.parse(readFileSync(join(fixtureDir, file), 'utf8')));

  let golden;
  try {
    golden = readFileSync(join(fixtureDir, 'expected', `${name}.html`), 'utf8');
  } catch {
    failures.push(`${name}: no PHP golden — run php tests/bin/render-goldens.php`);
    continue;
  }

  const actual = normalise(renderDocument(doc));
  const expected = normalise(golden);

  if (actual === expected) {
    pass++;
    console.log(`ok   - ${name} renders identically in both renderers`);
  } else {
    failures.push(
      `${name}: renderers disagree\n`
      + `    php: ${expected}\n`
      + `    js : ${actual}`
    );
    console.log(`FAIL - ${name} renders differently in the two renderers`);
  }
}

// The security rules have to hold on this side too. A second renderer that
// escaped differently would reopen the holes closed in #6 for React consumers
// only, which is the kind of gap nobody looks for.
const hostile = {
  schema: 1, type: 'page', template: '', seo: {},
  sections: [{
    id: 's1', layout: {}, blocks: [
      { type: 'heading', props: { text: '<script>alert(1)</script>', level: 2 } },
      { type: 'button', props: { text: 'x', href: 'javascript:alert(1)' } },
    ],
  }],
};
const hostileHtml = renderDocument(hostile);
if (hostileHtml.includes('<script>alert(1)</script>') || hostileHtml.includes('javascript:')) {
  failures.push('hostile input: the JS renderer failed to escape text or refuse an executable scheme');
  console.log('FAIL - hostile input is escaped and executable schemes refused');
} else {
  pass++;
  console.log('ok   - hostile input is escaped and executable schemes refused');
}

console.log(`1..${pass + failures.length}`);
if (failures.length) {
  console.log(`# failed ${failures.length}`);
  for (const f of failures) console.log(`  ${f}`);
  process.exit(1);
}
console.log(`# passed ${pass} / ${pass}`);
