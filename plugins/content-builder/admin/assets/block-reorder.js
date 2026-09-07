/*
 * Content Builder — pure block reorder helpers.
 *
 * SortableJS owns DOM movement. This module owns the corresponding in-memory
 * layout update, which keeps nested drag/drop behavior independently testable.
 */
(function (root, factory) {
  if (typeof module === "object" && module.exports) module.exports = factory();
  else root.ContentBuilderReorder = factory();
})(typeof self !== "undefined" ? self : this, function () {
  "use strict";

  function applySortEnd(evt, hooks) {
    hooks = hooks || {};
    var fromList = evt && evt.from && evt.from.__cbList;
    var toList = evt && evt.to && evt.to.__cbList;
    var item = evt && evt.item && evt.item.__cbBlock;

    if (!fromList || !toList || !item) {
      if (typeof hooks.repaint === "function") hooks.repaint();
      return false;
    }

    var fromIndex = fromList.indexOf(item);
    if (fromIndex < 0) {
      if (typeof hooks.repaint === "function") hooks.repaint();
      return false;
    }

    fromList.splice(fromIndex, 1);
    var destination = Math.max(0, Math.min(Number(evt.newIndex) || 0, toList.length));
    toList.splice(destination, 0, item);
    if (typeof hooks.sync === "function") hooks.sync();
    return true;
  }

  return { applySortEnd: applySortEnd };
});
