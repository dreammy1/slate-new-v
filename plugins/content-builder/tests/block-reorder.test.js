const test = require("node:test");
const assert = require("node:assert/strict");
const { applySortEnd } = require("../admin/assets/block-reorder.js");

function block(id) { return { id }; }
function list(items) { return { __cbList: items }; }
function item(blockValue) { return { __cbBlock: blockValue }; }

function move(from, to, dragged, newIndex) {
  let synced = 0;
  let repainted = 0;
  const ok = applySortEnd({
    from: list(from),
    to: list(to),
    item: item(dragged),
    newIndex,
  }, {
    sync: () => { synced += 1; },
    repaint: () => { repainted += 1; },
  });
  return { ok, synced, repainted };
}

test("reorders blocks within a top-level list", () => {
  const a = block("a");
  const b = block("b");
  const c = block("c");
  const top = [a, b, c];

  const result = move(top, top, a, 2);

  assert.equal(result.ok, true);
  assert.deepEqual(top, [b, c, a]);
  assert.equal(result.synced, 1);
  assert.equal(result.repainted, 0);
});

test("moves a block from one column into another", () => {
  const a = block("a");
  const b = block("b");
  const c = block("c");
  const leftColumn = [a, b];
  const rightColumn = [c];

  const result = move(leftColumn, rightColumn, b, 1);

  assert.equal(result.ok, true);
  assert.deepEqual(leftColumn, [a]);
  assert.deepEqual(rightColumn, [c, b]);
  assert.equal(result.synced, 1);
});

test("moves a block between a column and a flex-container child list", () => {
  const columnBlock = block("column-block");
  const flexBlock = block("flex-block");
  const column = [columnBlock];
  const children = [flexBlock];

  const result = move(column, children, columnBlock, 0);

  assert.equal(result.ok, true);
  assert.deepEqual(column, []);
  assert.deepEqual(children, [columnBlock, flexBlock]);
});

test("uses block identity rather than a stale nested DOM index", () => {
  const a = block("a");
  const b = block("b");
  const c = block("c");
  const column = [a, b, c];

  const result = move(column, column, c, 0);

  assert.equal(result.ok, true);
  assert.deepEqual(column, [c, a, b]);
});

test("repaints and does not mutate data for an invalid Sortable event", () => {
  const a = block("a");
  const top = [a];
  let repainted = 0;
  const result = applySortEnd({ from: list(top), to: list(top), item: item(block("other")), newIndex: 0 }, {
    sync: () => { throw new Error("sync must not run"); },
    repaint: () => { repainted += 1; },
  });

  assert.equal(result, false);
  assert.equal(repainted, 1);
  assert.equal(top[0], a);
});
