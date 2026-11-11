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
