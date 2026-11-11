/* Studio Luminelli: small progressive enhancement, the page works without it.
   A click on a mail link still opens the mail program. On computers without one
   nothing would happen, so the address is also copied and a short note says so. */
(function () {
  'use strict';

  var note = document.querySelector('.note');
  var timer;

  function say(text) {
    if (!note) return;
    note.textContent = text;
    clearTimeout(timer);
    timer = setTimeout(function () { note.textContent = ''; }, 8000);
  }

  document.querySelectorAll('a[data-copy]').forEach(function (link) {
    link.addEventListener('click', function () {
      var address = link.getAttribute('data-copy');
      if (!address || !navigator.clipboard || !window.isSecureContext) return;
      navigator.clipboard.writeText(address).then(function () {
        say('Falls sich kein Mailprogramm öffnet: ' + address + ' ist in Ihrer Zwischenablage.');
      }, function () { /* copying is optional */ });
    });
  });
})();

/* Logo: the three asterisks flicker yellow while the pointer moves or the page scrolls,
   then fade back to black. Off for prefers-reduced-motion. */
(function () {
  'use strict';

  var GLOW = [255, 196, 20];   // yellow, #ffc414
  var SENSITIVITY = 1;         // higher = reacts more strongly
  var stars = document.querySelectorAll('.luminelli-logo .stern');
  if (!stars.length) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var energy = 0, raf = 0, last = null, sy = window.scrollY;
  var phase = [0, 2.1, 4.3];

  function push(target) {
    var t = Math.min(1, target * SENSITIVITY);
    if (t > energy) energy += (t - energy) * 0.6;
    if (!raf) raf = requestAnimationFrame(tick);
  }
  function paint(el, v) {
    var k = Math.pow(v, 0.5);
    el.style.stroke = 'rgb(' + Math.round(GLOW[0] * k) + ',' + Math.round(GLOW[1] * k) + ',' + Math.round(GLOW[2] * k) + ')';
  }
  function tick(now) {
    energy *= 0.97;
    var t = now / 1000;
    stars.forEach(function (el, i) {
      var p = phase[i % 3];
      var flick = 0.8 + 0.2 * Math.sin(t * (7 + i * 1.7) + p) + 0.15 * Math.sin(t * (13.3 + i) + p * 2);
      paint(el, Math.max(0, Math.min(1, Math.min(1, energy * 1.25) * flick * (0.9 + Math.random() * 0.2))));
    });
    if (energy > 0.004) { raf = requestAnimationFrame(tick); }
    else { energy = 0; raf = 0; stars.forEach(function (el) { el.style.stroke = ''; }); }
  }
  function onMove(e) {
    var p = e.touches ? e.touches[0] : e;
    if (!p) return;
    var now = performance.now();
    if (last) {
      var dt = Math.max(8, now - last.t);
      push(Math.hypot(p.clientX - last.x, p.clientY - last.y) / dt / 1.1);
    }
    last = { x: p.clientX, y: p.clientY, t: now };
  }
  window.addEventListener('pointermove', onMove, { passive: true });
  window.addEventListener('touchmove', onMove, { passive: true });
  window.addEventListener('scroll', function () {
    var y = window.scrollY;
    push(Math.abs(y - sy) / 50);
    sy = y;
  }, { passive: true });
})();
