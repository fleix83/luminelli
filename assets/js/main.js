// Studio Luminelli – main.js
// Inquiry dialog (grows out of its trigger), form validation + submit, toast, scroll reveal.

const TOPICS = ['', 'Webapps', 'Mobile Apps', 'Webhosting', 'Support'];
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

/* ---------- Helpers ---------- */

function onAnimationDone(el, fallbackMs, cb) {
  let done = false;
  const finish = () => {
    if (done) return;
    done = true;
    el.removeEventListener('animationend', finish);
    cb();
  };
  el.addEventListener('animationend', finish);
  setTimeout(finish, fallbackMs);
}

let toastTimer;
function showToast(message) {
  const toast = document.getElementById('toast');
  if (!toast) return;
  clearTimeout(toastTimer);
  toast.textContent = message;
  requestAnimationFrame(() => toast.classList.add('is-visible'));
  toastTimer = setTimeout(() => {
    toast.classList.remove('is-visible');
    setTimeout(() => { toast.textContent = ''; }, 400);
  }, 6000);
}

/* ---------- Inquiry dialog ---------- */

function initInquiry() {
  const dialog = document.getElementById('inquiry-dialog');
  const content = document.getElementById('inquiry-content');
  if (!dialog || !content || typeof dialog.showModal !== 'function') return; // fallback: links jump to #kontakt

  dialog.append(content);

  const form = document.getElementById('inquiry-form');
  const chip = document.getElementById('inquiry-chip');
  const topicInput = document.getElementById('inquiry-topic');
  const tsInput = document.getElementById('inquiry-ts');
  const pageInput = document.getElementById('inquiry-page');
  const emailInput = document.getElementById('inquiry-email');
  const errorBox = document.getElementById('inquiry-error');
  let lastTrigger = null;
  let closing = false;

  // Sets the CSS variables the open/close keyframes start from (trigger centre and size).
  // `final` is the dialog's untransformed rect.
  function setOrigin(trigger, final) {
    const r = trigger?.getBoundingClientRect();
    if (!r || (r.width === 0 && r.height === 0)) {
      dialog.style.setProperty('--from-x', '0px');
      dialog.style.setProperty('--from-y', '40px');
      dialog.style.setProperty('--from-scale', '.9');
      return;
    }
    const scale = Math.max(Math.min(r.width / final.width, r.height / final.height, 1), 0.08);
    dialog.style.setProperty('--from-x', `${r.left + r.width / 2 - (final.left + final.width / 2)}px`);
    dialog.style.setProperty('--from-y', `${r.top + r.height / 2 - (final.top + final.height / 2)}px`);
    dialog.style.setProperty('--from-scale', scale.toFixed(3));
  }

  function measureDialog() {
    dialog.style.animation = 'none';
    const rect = dialog.getBoundingClientRect();
    dialog.style.removeProperty('animation');
    return rect;
  }

  function open(topic, trigger) {
    if (dialog.open) return;
    topic = TOPICS.includes(topic) ? topic : '';
    lastTrigger = trigger || null;
    topicInput.value = topic;
    chip.textContent = topic;
    chip.hidden = topic === '';
    tsInput.value = String(Date.now());
    pageInput.value = location.href.split('#')[0];
    errorBox.textContent = '';

    dialog.showModal();
    setOrigin(trigger, measureDialog());
    document.body.classList.add('is-locked');
    // Focus the first field rather than the close button.
    emailInput.focus({ preventScroll: true });
  }

  function close() {
    if (!dialog.open || closing) return;
    closing = true;
    if (lastTrigger && lastTrigger.isConnected) setOrigin(lastTrigger, dialog.getBoundingClientRect());
    dialog.classList.add('is-closing');
    onAnimationDone(dialog, reducedMotion.matches ? 200 : 350, () => {
      dialog.classList.remove('is-closing');
      dialog.close();
      closing = false;
    });
  }

  dialog.addEventListener('close', () => {
    document.body.classList.remove('is-locked');
    if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus({ preventScroll: true });
  });

  // Esc: run the animated close instead of the instant native one.
  dialog.addEventListener('cancel', (e) => {
    e.preventDefault();
    close();
  });

  // Close button + backdrop click (the dialog itself only receives clicks on the backdrop).
  dialog.addEventListener('click', (e) => {
    if (e.target === dialog || e.target.closest('[data-inquiry-close]')) close();
  });

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-inquiry-topic]');
    if (!trigger) return;
    e.preventDefault();
    open(trigger.dataset.inquiryTopic, trigger);
  });
  document.querySelectorAll('[data-inquiry-topic]').forEach((t) => t.setAttribute('aria-haspopup', 'dialog'));

  initForm(form, {
    onSuccess() {
      close();
      showToast('Danke! Wir melden uns so rasch wie möglich.');
    },
  });

  // Deep link: index.html#kontakt opens the dialog.
  if (location.hash === '#kontakt') open('', null);
}

/* ---------- Form ---------- */

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

function validateField(field) {
  const value = field.value.trim();
  if (field.type === 'email') {
    if (!value) return 'Bitte geben Sie Ihre E-Mail-Adresse ein.';
    if (!EMAIL_RE.test(value) || value.length > 254) return 'Bitte geben Sie eine gültige E-Mail-Adresse ein, z. B. name@beispiel.ch.';
  }
  if (field.tagName === 'TEXTAREA') {
    if (!value) return 'Bitte schreiben Sie uns eine Nachricht.';
    if (value.length < 10) return 'Ihre Nachricht ist etwas kurz. Bitte schreiben Sie mindestens 10 Zeichen.';
    if (value.length > 5000) return `Ihre Nachricht ist zu lang (${value.length} von maximal 5000 Zeichen).`;
  }
  return '';
}

function showFieldError(field, message) {
  const errorEl = document.getElementById(`${field.id}-error`);
  if (errorEl) errorEl.textContent = message;
  if (message) field.setAttribute('aria-invalid', 'true');
  else field.removeAttribute('aria-invalid');
}

function initForm(form, { onSuccess }) {
  const fields = [...form.querySelectorAll('input[type="email"], textarea')];
  const submit = form.querySelector('[type="submit"]');
  const errorBox = document.getElementById('inquiry-error');
  let submitted = false;

  fields.forEach((field) => {
    field.addEventListener('input', () => {
      if (submitted || field.hasAttribute('aria-invalid')) showFieldError(field, validateField(field));
    });
    field.addEventListener('blur', () => {
      if (field.value.trim()) showFieldError(field, validateField(field));
    });
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    submitted = true;
    errorBox.textContent = '';

    let firstInvalid = null;
    fields.forEach((field) => {
      const msg = validateField(field);
      showFieldError(field, msg);
      if (msg && !firstInvalid) firstInvalid = field;
    });
    if (firstInvalid) {
      firstInvalid.focus();
      return;
    }

    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    const label = submit.textContent;
    submit.textContent = 'Wird gesendet';

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Ihre Anfrage konnte leider nicht gesendet werden. Bitte versuchen Sie es später erneut.');
      }
      form.reset();
      submitted = false;
      fields.forEach((f) => showFieldError(f, ''));
      onSuccess();
    } catch (err) {
      errorBox.textContent = err instanceof TypeError
        ? 'Keine Verbindung zum Server. Bitte prüfen Sie Ihre Internetverbindung und versuchen Sie es erneut.'
        : err.message;
    } finally {
      submit.disabled = false;
      submit.removeAttribute('aria-busy');
      submit.textContent = label;
    }
  });
}

/* ---------- Scroll reveal ---------- */

function initReveal() {
  const items = document.querySelectorAll('.reveal');
  if (!('IntersectionObserver' in window) || reducedMotion.matches) {
    items.forEach((el) => el.classList.add('is-visible'));
    return;
  }
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      }
    });
  }, { rootMargin: '0px 0px -10% 0px', threshold: 0.1 });
  items.forEach((el) => io.observe(el));
}

/* ---------- Init ---------- */

document.querySelectorAll('[data-year]').forEach((el) => { el.textContent = String(new Date().getFullYear()); });
initReveal();
initInquiry();
