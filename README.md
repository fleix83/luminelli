# Studio Luminelli – luminelli.ch

One-pager for Studio Luminelli (Basel). Plain HTML/CSS/JS (ES modules), no build step, no
third-party requests, no cookies. One PHP endpoint for the contact form.

## Structure

```
index.html                  one-pager (hero, intro, services, about, FAQ, contact form, footer)
impressum.html, datenschutz.html, agb.html, auftragsverarbeitung.html   legal stubs
404.html                    error page (uses root-absolute paths, correct on the live domain)
assets/css/styles.css       all styles, tokens on :root
assets/js/main.js           inquiry dialog, validation, submit, toast, scroll reveal
assets/fonts/               Quicksand variable woff2 (latin + latin-ext subset) + OFL licence
assets/img/                 logo, hero (AVIF/WebP/JPG, 800 + 1600 px), favicons, og-image
api/inquiry.php             contact endpoint (handle_inquiry() holds the logic)
api/lib/SmtpMailer.php      minimal SMTP client (STARTTLS / SSL, AUTH LOGIN)
api/config.example.php      mail settings template, copy to api/config.php
api/storage/                mail.log (DEV mode) + rate-limit files, web access denied, git-ignored
robots.txt, sitemap.xml, llms.txt, site.webmanifest, .htaccess
docs/                       mockup + original prompt (blocked from the web by .htaccess)
```

## Local setup (XAMPP)

1. Project lives in `/Applications/XAMPP/xamppfiles/htdocs/luminelli`; start Apache in XAMPP.
2. `cp api/config.example.php api/config.php` (keep `'dev_mode' => true`).
3. Open <http://localhost/luminelli/>.
4. Form submissions are written to `api/storage/mail.log` instead of being sent.

All paths are relative, so the site works in the subfolder and at the domain root.
Exception: `404.html` and `ErrorDocument` use root-absolute paths, so the custom 404 only
appears on the live domain.

## Mail configuration (server)

In `api/config.php` on the server:

- `'dev_mode' => false`
- `'transport' => 'smtp'` with host/port/username/password of the `noreply@luminelli.ch` mailbox
  (port 587 + `'encryption' => 'tls'`, or 465 + `'ssl'`), or `'transport' => 'mail'` for PHP `mail()`.
- Make sure SPF, DKIM and DMARC for luminelli.ch include the sending server.
- Mails go to `service@luminelli.ch`, From `noreply@luminelli.ch`, Reply-To = the visitor.

Spam protection: honeypot field, time trap (≥ 3 s after opening the dialog; JS only), rate limit
of 5 submissions per hour per (hashed) IP. Change `ip_salt` to a random string.

## Deployment

1. Upload everything **except** `docs/`, `.git/`, `api/config.php` (create it on the server),
   `api/storage/*` contents.
2. Make `api/storage/` writable for PHP.
3. Check that `.htaccess` is active (AllowOverride). It forces `https://luminelli.ch` (no www),
   sets cache and security headers (incl. CSP) and blocks config, logs, storage and docs.
4. Test the form once live, then submit `https://luminelli.ch/sitemap.xml` in Google Search Console
   and Bing Webmaster Tools. Validate JSON-LD with the Rich Results Test.

### Cache busting

CSS/JS/fonts are cached for one year. After changing `styles.css` or `main.js`, bump `?v=1` in
all HTML files. Images are cached for 30 days.

### CSP and the inline script

`index.html` has one inline script (`document.documentElement.classList.add('js')`). Its hash is
whitelisted in the CSP in `.htaccess`. If you change it, recompute:

```sh
printf "%s" "document.documentElement.classList.add('js')" | openssl dgst -sha256 -binary | base64
```

## Hero photo

Source: `docs/20260110_092036.jpg` (1848×4000 after EXIF rotation). Art direction in `index.html`:

- below 720 px: `hero-portrait-600|900|1200.{avif,webp,jpg}`, the portrait photo with the top 550 px
  of sky trimmed; the bottom is softened because it sits under the nearly opaque overlay (saves bytes)
- from 720 px: `hero-wide-1200|1848.{avif,webp,jpg}`, a 1848×1300 crop around the houses (y 950–2250)

Encoding: `avifenc -q 42`, `cwebp -q 66`, JPEG quality ~72. Re-encoded files contain no EXIF/GPS data.
Keep the originals in `docs/` (not deployed). If you change the crops, update the `width`/`height`
attributes and both `<link rel="preload">` tags in `index.html`.

## Projektor (AI draft generator)

`projektor.html` lets a visitor describe a project, pick a font and a primary colour, upload files,
and receive a clickable draft built by Claude Sonnet 5.5 (Anthropic **Managed Agents**: Anthropic runs
the agent loop and a sandbox container; this server only starts jobs and collects the result).

### Flow

1. `api/projektor/submit.php` validates the form, stores the request in `api/storage/projektor/jobs/<id>/`
   and mails a confirmation link (honeypot, 5 s time trap, max 3 submits per IP and hour).
2. `api/projektor/confirm.php` (link in the mail) consumes a daily slot (1 per e-mail, 1 per IP,
   `daily_global_cap` in total), uploads the files, starts a session with a **hard budget**
   (`budget_cents`, default $3) and redirects to the status view `projektor.html?job=…&t=…`.
3. The agent (system prompt: `api/projektor/prompt/system.md`) builds the draft in its sandbox and
   leaves `build.zip` + `report.json` as session outputs.
4. `webhook.php` (Anthropic calls it when the session is idle) and `status.php` (while the customer
   watches) check the session. When it is idle,
   `lib/Finalizer.php` unpacks the zip (whitelisted file types, no hidden files, no PHP, max 15 MB)
   to `drafts_dir/<random slug>/`, mails the link to the customer and a summary (incl. cost and the
   agent's notes) to `notify_to`, then deletes the uploaded inputs and the session at Anthropic.

### Setup

1. **Dependencies:** `composer install --no-dev` (creates `vendor/`, git-ignored; upload it with the site).
2. **Subdomain** `entwurf.luminelli.ch` in Plesk with its document root **outside** `httpdocs`
   (e.g. `/entwurf.luminelli.ch`), SSL on. Copy `deploy/entwurf/*` (incl. `.htaccess`) into it. Its CSP
   lets drafts run their own JS but blocks every external request and form post. Remove
   `http://localhost` from its `frame-ancestors` on the server.
3. **Config** in `api/config.php` → `projektor`: `anthropic_api_key`, `site_url` (`https://luminelli.ch`),
   `drafts_dir` (absolute path of the subdomain docroot), `drafts_url` (`https://entwurf.luminelli.ch`).
   Mail must work (`dev_mode => false`), confirmation mails are essential.
4. **Agent:** `php api/projektor/setup.php` (once; again after editing the prompt, model or effort, which
   creates a new agent version). Then set `'enabled' => true`.
5. **Webhook** (no cron job needed): Anthropic Console → **Manage → Webhooks → Add endpoint**
   - URL: `https://luminelli.ch/api/projektor/webhook.php` (exactly this: a redirect, e.g. via www or
     http, disables the endpoint immediately)
   - Events: `session.status_idled` and `session.status_terminated`
   - Copy the signing secret (`whsec_…`, shown only once) into `projektor.webhook_secret`.
   When a session finishes, Anthropic calls the endpoint and the draft is delivered right away.
   Housekeeping (expiring unconfirmed requests, cleaning up old drafts and failed sessions, and a safety
   net for lost webhooks) runs automatically along with Projektor requests, at most every 10 minutes.
   `cron.php` is optional if a cron job ever becomes available.
6. `api/storage/` must be writable for PHP.

### Costs and limits

- Budget per draft is enforced by Anthropic (`budget_cents`); the session pauses at the cap and whatever
  the agent packaged last is delivered. The notification mail shows the real cost.
- Worst case per day: `daily_global_cap` × budget (default 10 × $3 = $30). Set a monthly spend limit
  in the Anthropic Console as a second safety net.
- Watch sessions live in the Console (link in the notification mail) while they run.

### Not verified yet

- Whether a headless browser can be installed in the sandbox for screenshots (the prompt falls back to
  a code review). Check the first sessions in the Console.

## Keeping facts consistent (SEO/GEO)

Name, address, phone, e-mail, service names and FAQ answers appear in: visible HTML, the JSON-LD in
`index.html`, `llms.txt` and `impressum.html`. Change them everywhere together.
