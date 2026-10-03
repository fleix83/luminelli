// Studio Luminelli – Projektor: configurator form + live status view.
// Keep FONTS in sync with PJ_FONTS in api/projektor/lib/bootstrap.php.

const FONTS = [
  ['inter', 'Inter'],
  ['dm-sans', 'DM Sans'],
  ['space-grotesk', 'Space Grotesk'],
  ['quicksand', 'Quicksand'],
  ['nunito', 'Nunito'],
  ['lora', 'Lora'],
  ['playfair-display', 'Playfair Display'],
  ['fraunces', 'Fraunces'],
];
const COLORS = [
  ['#ee6e70', 'Koralle'],
  ['#1f5fd1', 'Blau'],
  ['#0f766e', 'Petrol'],
  ['#15803d', 'Grün'],
  ['#b45309', 'Ocker'],
  ['#be123c', 'Bordeaux'],
  ['#6d28d9', 'Violett'],
  ['#1f2937', 'Anthrazit'],
];
const MAX_FILES = 8;
const MAX_FILE_BYTES = 8 * 1024 * 1024;
const MAX_TOTAL_BYTES = 25 * 1024 * 1024;

const $ = (id) => document.getElementById(id);
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function showView(id) {
  for (const view of document.querySelectorAll('.pj-view')) view.hidden = view.id !== id;
  const el = $(id);
  if (id !== 'pj-config') {
    el.focus({ preventScroll: true });
    window.scrollTo({ top: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
  }
}

function showPageError(text) {
  const box = $('pj-error');
  box.textContent = text;
  box.hidden = false;
}

function formatBytes(n) {
  return n < 1024 * 1024 ? `${Math.max(1, Math.round(n / 1024))} KB` : `${(n / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}

/* ---------- Colour helpers ---------- */

function luminance(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const onColor = (hex) => (1.05 / (luminance(hex) + 0.05) >= 3 ? '#fff' : '#1a1a1a');

/* ---------- Configurator ---------- */

function renderChoices(form) {
  const fonts = $('pj-fonts');
  fonts.innerHTML = '';
  FONTS.forEach(([slug, name], i) => {
    const wrap = document.createElement('div');
    wrap.className = 'pj-font';
    wrap.innerHTML = `<input type="radio" name="font" id="pj-font-${slug}" value="${slug}"${i === 0 ? ' checked' : ''}>
      <label for="pj-font-${slug}"><span class="pj-font__sample" aria-hidden="true">Aa</span><span class="pj-font__name">${name}</span></label>`;
    wrap.querySelector('.pj-font__sample').style.fontFamily = `"PJ ${name}", var(--font)`;
    wrap.querySelector('.pj-font__name').style.fontFamily = `"PJ ${name}", var(--font)`;
    fonts.append(wrap);
  });

  const swatches = $('pj-swatches');
  swatches.innerHTML = '';
  COLORS.forEach(([hex, name], i) => {
    const wrap = document.createElement('div');
    wrap.className = 'pj-swatch';
    wrap.innerHTML = `<input type="radio" name="color" id="pj-color-${i}" value="${hex}"${i === 0 ? ' checked' : ''}>
      <label for="pj-color-${i}"><span class="visually-hidden">${name}</span></label>`;
    wrap.querySelector('label').style.setProperty('--sw', hex);
    wrap.querySelector('label').title = name;
    swatches.append(wrap);
  });
  const custom = document.createElement('label');
  custom.className = 'pj-custom';
  custom.innerHTML = `<input type="radio" name="color" id="pj-color-custom" value="#3a7d6b" class="visually-hidden">
    <input type="color" id="pj-color-picker" value="#3a7d6b" aria-label="Eigene Farbe wählen"> Eigene Farbe`;
  swatches.append(custom);

  const picker = $('pj-color-picker');
  const customRadio = $('pj-color-custom');
  picker.addEventListener('input', () => {
    customRadio.value = picker.value;
    customRadio.checked = true;
    updatePreview(form);
  });
  picker.addEventListener('click', () => {
    customRadio.checked = true;
    updatePreview(form);
  });
  form.addEventListener('change', (e) => {
    if (e.target.name === 'font' || e.target.name === 'color') updatePreview(form);
  });
  updatePreview(form);
}

function updatePreview(form) {
  const color = form.elements.color.value || COLORS[0][0];
  const fontSlug = form.elements.font.value || FONTS[0][0];
  const fontName = FONTS.find(([s]) => s === fontSlug)?.[1] ?? 'Inter';
  const preview = $('pj-preview');
  preview.style.setProperty('--pj-color', color);
  preview.style.setProperty('--pj-on-color', onColor(color));
  preview.style.setProperty('--pj-font', `"PJ ${fontName}"`);
  document.querySelector('.pj-custom').classList.toggle('is-active', $('pj-color-custom').checked);
}

/** Selected files (kept in JS so files can be removed and dropped in). */
const state = { files: [], screenshot: null };

function renderFiles() {
  const list = $('pj-filelist');
  list.innerHTML = '';
  state.files.forEach((file, i) => {
    const li = document.createElement('li');
    li.innerHTML = '<span class="pj-file-name"></span><span class="pj-file-size"></span><button type="button" aria-label="">×</button>';
    li.querySelector('.pj-file-name').textContent = file.name;
    li.querySelector('.pj-file-size').textContent = formatBytes(file.size);
    const btn = li.querySelector('button');
    btn.setAttribute('aria-label', `${file.name} entfernen`);
    btn.addEventListener('click', () => {
      state.files.splice(i, 1);
      renderFiles();
      $('pj-files').focus();
    });
    list.append(li);
  });
}

function addFiles(fileList) {
  const errorEl = $('pj-files-error');
  errorEl.textContent = '';
  for (const file of fileList) {
    if (state.files.length >= MAX_FILES) {
      errorEl.textContent = `Bitte höchstens ${MAX_FILES} Dateien.`;
      break;
    }
    if (file.size > MAX_FILE_BYTES) {
      errorEl.textContent = `«${file.name}» ist grösser als 8 MB.`;
      continue;
    }
    if (!state.files.some((f) => f.name === file.name && f.size === file.size)) state.files.push(file);
  }
  renderFiles();
}

function renderScreenshot() {
  const out = $('pj-screenshot-name');
  out.innerHTML = '';
  if (!state.screenshot) return;
  out.textContent = state.screenshot.name;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.textContent = '×';
  btn.setAttribute('aria-label', 'Screenshot entfernen');
  btn.addEventListener('click', () => {
    state.screenshot = null;
    $('pj-screenshot').value = '';
    renderScreenshot();
  });
  out.append(btn);
}

function initFiles() {
  const input = $('pj-files');
  input.addEventListener('change', () => {
    addFiles(input.files);
    input.value = '';
  });
  const drop = $('pj-drop');
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => {
    e.preventDefault();
    drop.classList.add('is-over');
  }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, () => drop.classList.remove('is-over')));
  drop.addEventListener('drop', (e) => {
    e.preventDefault();
    addFiles(e.dataTransfer.files);
  });

  const shot = $('pj-screenshot');
  shot.addEventListener('change', () => {
    const file = shot.files[0];
    $('pj-screenshot-error').textContent = '';
    if (file && !/^image\/(png|jpe?g|webp|gif)$/.test(file.type)) {
      $('pj-screenshot-error').textContent = 'Der Screenshot muss ein Bild sein (JPG, PNG, WebP).';
      shot.value = '';
      return;
    }
    if (file && file.size > MAX_FILE_BYTES) {
      $('pj-screenshot-error').textContent = 'Der Screenshot ist grösser als 8 MB.';
      shot.value = '';
      return;
    }
    state.screenshot = file || null;
    renderScreenshot();
  });
}

/* ---------- Validation ---------- */

const FIELD_INPUT = {
  url: 'pj-url', project: 'pj-project', description: 'pj-description', email: 'pj-email',
  consent: 'pj-consent', files: 'pj-files', screenshot: 'pj-screenshot', font: 'pj-font-inter', color: 'pj-color-0',
};

function setError(field, message) {
  const errorEl = $(`pj-${field}-error`);
  if (errorEl) errorEl.textContent = message || '';
  const input = $(FIELD_INPUT[field]);
  if (!input) return;
  if (message) input.setAttribute('aria-invalid', 'true');
  else input.removeAttribute('aria-invalid');
}

function validate(form) {
  const v = (name) => (form.elements[name]?.value ?? '').trim();
  const errors = {};
  const url = v('url');
  if (url && !/^(https?:\/\/)?[^\s/$.?#]+\.[^\s]{2,}$/i.test(url)) errors.url = 'Bitte geben Sie eine gültige Adresse ein, z. B. https://beispiel.ch.';
  const project = v('project');
  if (project.length < 5) errors.project = 'Bitte beschreiben Sie Ihr Projekt in einem Satz.';
  const description = v('description');
  if (description.length < 30) errors.description = `Bitte beschreiben Sie etwas ausführlicher, was Sie umsetzen möchten (noch ${30 - description.length} Zeichen).`;
  const email = v('email');
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) errors.email = 'Bitte geben Sie eine gültige E-Mail-Adresse ein oder lassen Sie das Feld leer.';
  if (!form.elements.consent.checked) errors.consent = 'Bitte bestätigen Sie den Hinweis zur Datenverarbeitung.';
  const total = state.files.reduce((n, f) => n + f.size, 0) + (state.screenshot?.size ?? 0);
  if (total > MAX_TOTAL_BYTES) errors.files = 'Die Dateien sind zusammen zu gross (max. 25 MB).';
  return errors;
}

function showErrors(errors) {
  for (const field of Object.keys(FIELD_INPUT)) setError(field, errors[field]);
  const first = Object.keys(FIELD_INPUT).find((f) => errors[f]);
  if (first) $(FIELD_INPUT[first])?.focus();
}

function initForm() {
  const form = $('pj-form');
  if (!form) return;
  $('pj-ts').value = String(Date.now());
  renderChoices(form);
  initFiles();

  const description = $('pj-description');
  const counter = $('pj-description-count');
  description.addEventListener('input', () => { counter.textContent = `${description.value.length} / 4000`; });

  let submitted = false;
  form.addEventListener('input', (e) => {
    if (!submitted) return;
    const errors = validate(form);
    const field = Object.entries(FIELD_INPUT).find(([, id]) => id === e.target.id)?.[0];
    if (field) setError(field, errors[field]);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    submitted = true;
    $('pj-submit-error').textContent = '';
    const errors = validate(form);
    showErrors(errors);
    if (Object.keys(errors).length) return;

    const data = new FormData(form);
    data.delete('files[]');
    data.delete('screenshot');
    state.files.forEach((f) => data.append('files[]', f, f.name));
    if (state.screenshot) data.append('screenshot', state.screenshot, state.screenshot.name);

    const button = form.querySelector('.pj-submit');
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Projektor startet';
    try {
      const res = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
      const body = await res.json().catch(() => ({}));
      if (!res.ok || !body.ok) {
        if (body.fields) showErrors(body.fields);
        throw new Error(body.error || 'Ihre Anfrage konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.');
      }
      // Session is running: switch to the live view (and make the URL bookmarkable).
      history.pushState(null, '', `projektor.html?job=${encodeURIComponent(body.job)}&t=${encodeURIComponent(body.t)}`);
      initStatus(body.job, body.t);
    } catch (err) {
      $('pj-submit-error').textContent = err instanceof TypeError
        ? 'Keine Verbindung zum Server. Bitte prüfen Sie Ihre Internetverbindung.'
        : err.message;
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = 'Umsetzen';
    }
  });
}

/* ---------- Status view ---------- */

function initStatus(jobId, token) {
  showView('pj-status');
  const url = `api/projektor/status.php?id=${encodeURIComponent(jobId)}&t=${encodeURIComponent(token)}`;
  let startedAt = null;
  let timer = null;
  let clock = null;
  let lastActivity = '';

  const tickClock = () => {
    if (!startedAt) return;
    const s = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
    $('pj-elapsed').textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  };

  const render = (data) => {
    $('pj-status-project').textContent = data.project || '';
    if (data.has_email) $('pj-leave-hint').textContent = 'Sie können diese Seite schliessen: Wir senden Ihnen den Link per E-Mail, sobald der Entwurf fertig ist.';
    if (data.started_at) startedAt = Date.parse(data.started_at);
    if (data.state === 'running') {
      const lines = data.activity?.length ? data.activity : ['Liest Ihre Angaben'];
      const key = lines.join('|');
      if (key !== lastActivity) {
        lastActivity = key;
        const list = $('pj-activity');
        list.innerHTML = '';
        lines.slice(0, 5).forEach((line) => {
          const li = document.createElement('li');
          li.textContent = line;
          list.append(li);
        });
      }
      return true;
    }
    clearInterval(clock);
    $('pj-running').hidden = true;
    if (data.state === 'done') {
      $('pj-done').hidden = false;
      $('pj-done-title').textContent = data.title || data.project;
      $('pj-done-summary').textContent = data.summary || '';
      $('pj-done-open').href = data.draft_url;
      $('pj-iframe').src = data.draft_url;
      if (data.placeholders?.length) {
        $('pj-placeholders').hidden = false;
        const list = $('pj-placeholders-list');
        list.innerHTML = '';
        data.placeholders.forEach((p) => {
          const li = document.createElement('li');
          li.textContent = p;
          list.append(li);
        });
      }
      document.title = `Ihr Entwurf ist fertig | Studio Luminelli`;
    } else {
      $('pj-failed').hidden = false;
    }
    return false;
  };

  const poll = async () => {
    if (document.hidden) {
      timer = setTimeout(poll, 3000);
      return;
    }
    try {
      const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        showPageError(data.error || 'Der Status konnte nicht geladen werden.');
        return;
      }
      if (!render(data)) return;
    } catch {
      // network hiccup: try again
    }
    const minutes = startedAt ? (Date.now() - startedAt) / 60000 : 0;
    timer = setTimeout(poll, minutes > 5 ? 10000 : 5000);
  };

  clock = setInterval(tickClock, 1000);
  document.querySelectorAll('.pj-device').forEach((btn) => {
    btn.addEventListener('click', () => {
      $('pj-frame').dataset.device = btn.dataset.device;
      document.querySelectorAll('.pj-device').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
    });
  });
  poll();
  return () => clearTimeout(timer);
}

/* ---------- Init ---------- */

// Back button after the switch to the live view: show the matching state again.
window.addEventListener('popstate', () => location.reload());

const params = new URLSearchParams(location.search);
if (params.get('job') && params.get('t')) {
  initForm(); // keep the form usable if the user navigates back
  initStatus(params.get('job'), params.get('t'));
} else {
  initForm();
}
