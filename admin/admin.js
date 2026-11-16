/* Studio Luminelli admin: repeaters, sorting, counters. Works without it, just with fewer conveniences. */
(function () {
  'use strict';

  /* Character counters */
  function updateCount(input) {
    var max = +input.dataset.max;
    var out = input.closest('.field').querySelector('.count');
    if (!out) return;
    var n = input.value.length;
    out.textContent = n + ' / ' + max;
    out.classList.toggle('over', n > max);
  }
  document.addEventListener('input', function (e) {
    if (e.target.dataset && e.target.dataset.max) updateCount(e.target);
  });
  document.querySelectorAll('[data-max]').forEach(updateCount);

  /* Swatches show the tile tint */
  document.querySelectorAll('.swatch').forEach(function (s) {
    s.style.background = 'linear-gradient(#fff, ' + s.dataset.tint + ')';
  });

  /* Repeaters: renumber names like examples[3][title] after any change */
  function renumber(rep) {
    var name = rep.dataset.name;
    rep.querySelectorAll(':scope > .item').forEach(function (item, i) {
      item.querySelectorAll('[name]').forEach(function (el) {
        el.name = el.name.replace(new RegExp('^' + name + '\\[[^\\]]*\\]'), name + '[' + i + ']');
      });
      var num = item.querySelector('.num');
      if (num) num.textContent = String(i + 1);
    });
  }
  document.querySelectorAll('.repeater').forEach(renumber);

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) return;

    if (btn.hasAttribute('data-add')) {
      var rep = btn.closest('.repeater');
      var clone = rep.querySelector('template').content.firstElementChild.cloneNode(true);
      rep.insertBefore(clone, btn);
      renumber(rep);
      var first = clone.querySelector('input, textarea');
      if (first) first.focus();
    }

    if (btn.hasAttribute('data-remove')) {
      var item = btn.closest('.item');
      var r = item.closest('.repeater');
      item.remove();
      renumber(r);
    }

    if (btn.dataset.move) {
      var el = btn.closest('.item, li');
      var dir = +btn.dataset.move;
      var sib = dir < 0 ? el.previousElementSibling : el.nextElementSibling;
      if (sib && !sib.matches('template, .add')) {
        if (dir < 0) el.parentNode.insertBefore(el, sib); else el.parentNode.insertBefore(sib, el);
        btn.focus();
      }
      var rp = el.closest('.repeater');
      if (rp) renumber(rp);
      orderChanged(el);
    }
  });

  /* Tile order: drag and drop, then save */
  function orderChanged(el) {
    var form = el.closest('[data-sortable-form]');
    if (form) form.querySelector('[data-order-save]').hidden = false;
  }
  var dragged = null;
  document.querySelectorAll('[data-sortable] > li').forEach(function (li) {
    li.addEventListener('dragstart', function (e) {
      dragged = li;
      li.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    li.addEventListener('dragend', function () {
      li.classList.remove('dragging');
      document.querySelectorAll('[data-sortable] > li.over').forEach(function (x) { x.classList.remove('over'); });
    });
    li.addEventListener('dragover', function (e) {
      if (!dragged || dragged === li) return;
      e.preventDefault();
      li.classList.add('over');
    });
    li.addEventListener('dragleave', function () { li.classList.remove('over'); });
    li.addEventListener('drop', function (e) {
      e.preventDefault();
      li.classList.remove('over');
      if (!dragged || dragged === li) return;
      var after = e.clientY > li.getBoundingClientRect().top + li.offsetHeight / 2;
      li.parentNode.insertBefore(dragged, after ? li.nextSibling : li);
      orderChanged(li);
      dragged.closest('form').requestSubmit();
    });
  });

  /* Confirm before deleting */
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.dataset.confirm)) e.preventDefault();
    });
  });
})();
