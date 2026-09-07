/* Site Timeclock — employee clock app behaviour (vanilla JS, no build step). */
(function () {
  'use strict';

  // ---- Live timer ---------------------------------------------------------
  function pad(n) { return String(n).padStart(2, '0'); }

  function startTimer() {
    var el = document.getElementById('tc-timer');
    if (!el) return;
    var startMs = parseInt(el.getAttribute('data-start') || '0', 10);
    if (!startMs) return;
    function tick() {
      var diff = Math.max(0, Math.floor((Date.now() - startMs) / 1000));
      var h = Math.floor(diff / 3600);
      var m = Math.floor((diff % 3600) / 60);
      var s = diff % 60;
      el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
    }
    tick();
    setInterval(tick, 1000);
  }

  // ---- Hour slots (drag & drop) ------------------------------------------
  // Slots store the chosen task id in a hidden input named tasks[<slot>].
  function slotInput(slot) {
    return document.querySelector('input[name="tasks[' + slot + ']"]');
  }

  function paintSlot(slotEl, taskId, name, color) {
    var label = slotEl.querySelector('.tc-slot-body');
    var input = slotInput(slotEl.getAttribute('data-slot'));
    if (taskId) {
      slotEl.classList.add('tc-filled');
      label.className = 'tc-slot-task tc-slot-body';
      label.style.background = color;
      label.textContent = name;
      if (input) input.value = taskId;
    } else {
      slotEl.classList.remove('tc-filled');
      label.className = 'tc-slot-empty tc-slot-body';
      label.style.background = '';
      label.textContent = 'Drag a task here';
      if (input) input.value = '';
    }
    updateSummary();
  }

  function updateSummary() {
    var summary = document.getElementById('tc-summary');
    if (!summary) return;
    var counts = {};
    var filled = 0;
    document.querySelectorAll('.tc-slot').forEach(function (s) {
      var input = slotInput(s.getAttribute('data-slot'));
      if (input && input.value) {
        filled++;
        var name = s.querySelector('.tc-slot-body').textContent;
        counts[name] = (counts[name] || 0) + 1;
      }
    });
    var parts = Object.keys(counts).map(function (k) { return k + ': ' + counts[k] + 'h'; });
    summary.textContent = filled
      ? (filled + 'h assigned — ' + parts.join(', '))
      : 'No hours assigned yet.';

    var btn = document.getElementById('tc-clockout-btn');
    if (btn) {
      if (filled > 0) { btn.removeAttribute('disabled'); btn.title = ''; }
      else { btn.setAttribute('disabled', 'disabled'); btn.title = 'Assign at least one task hour before clocking out.'; }
    }
  }

  function wireSlots() {
    var palette = document.querySelectorAll('.tc-chip');
    palette.forEach(function (chip) {
      chip.setAttribute('draggable', 'true');
      chip.addEventListener('dragstart', function (e) {
        e.dataTransfer.setData('text/plain', JSON.stringify({
          id: chip.getAttribute('data-task-id'),
          name: chip.getAttribute('data-task-name'),
          color: chip.getAttribute('data-task-color')
        }));
      });
    });

    document.querySelectorAll('.tc-slot').forEach(function (slot) {
      slot.addEventListener('dragover', function (e) { e.preventDefault(); slot.classList.add('tc-over'); });
      slot.addEventListener('dragleave', function () { slot.classList.remove('tc-over'); });
      slot.addEventListener('drop', function (e) {
        e.preventDefault();
        slot.classList.remove('tc-over');
        var raw = e.dataTransfer.getData('text/plain');
        if (!raw) return;
        try {
          var t = JSON.parse(raw);
          paintSlot(slot, t.id, t.name, t.color);
        } catch (err) { /* ignore */ }
      });
      // Click a filled slot to clear it.
      slot.addEventListener('click', function () {
        var input = slotInput(slot.getAttribute('data-slot'));
        if (input && input.value) paintSlot(slot, null);
      });
    });

    var clearAll = document.getElementById('tc-clear-all');
    if (clearAll) {
      clearAll.addEventListener('click', function () {
        document.querySelectorAll('.tc-slot').forEach(function (s) { paintSlot(s, null); });
      });
    }

    updateSummary();
  }

  document.addEventListener('DOMContentLoaded', function () {
    startTimer();
    wireSlots();
  });
})();
