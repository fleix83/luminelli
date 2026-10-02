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

Source: `docs/luminelli_hero_bg.jpg` (804×1570, portrait). Art direction in `index.html`:

- below 720 px: `hero-portrait-600|804.{avif,webp,jpg}`, the full portrait photo (bottom part softened,
  it sits under the nearly opaque overlay anyway, which saves bytes)
- from 720 px: `hero-wide-804.{avif,webp,jpg}`, a 16:10 crop around the houses

The wide variant is only 804 px wide and gets upscaled on large screens. For a sharper desktop hero,
provide a landscape photo with at least 2000 px width and regenerate `hero-wide-*`:

```sh
cd assets/img
# crop/resize to e.g. 1600x1000 → hero-wide-1600.jpg, then:
cwebp -q 65 hero-wide-1600.jpg -o hero-wide-1600.webp
avifenc -q 40 hero-wide-1600.jpg hero-wide-1600.avif
```

and update the `<source media="(min-width: 720px)">` tags and the preload in `index.html`
(width/height attributes included). Re-encoded files contain no EXIF/GPS data; keep it that way.

## Keeping facts consistent (SEO/GEO)

Name, address, phone, e-mail, service names and FAQ answers appear in: visible HTML, the JSON-LD in
`index.html`, `llms.txt` and `impressum.html`. Change them everywhere together.
