# Claude Code Prompt: Studio Luminelli One-Pager

Copy everything below the line into Claude Code, started inside `/Applications/XAMPP/xamppfiles/htdocs/luminelli`.

---

## Role & goal

You are building the first version of the website for **Studio Luminelli**, a one-person vibe coding studio in Basel (Switzerland) that builds webapps, mobile apps and offers hosting and support for SMEs, associations, institutions, artists, freelancers and private individuals.

Build a fast, accessible, SEO- and GEO-optimised **one-pager** for the domain **https://luminelli.ch**, based on the mobile mockup `luminelli_mobile_mockup.png` in the project root. Open and study the mockup before writing any code and match layout, spacing, colours and hierarchy closely. The mockup is mobile only; derive a clean desktop layout from it (see "Responsive").

## Stack & constraints

- Plain **HTML5, CSS, vanilla JavaScript (ES modules)**. No framework, no build step, no npm dependencies for the frontend.
- **One small PHP endpoint** for the contact form (the project runs locally in XAMPP and the target hosting supports PHP). Keep it isolated so it can later be replaced by or integrated into a proper backend.
- No third-party scripts, no CDNs, no cookies, no tracking. Fonts and all assets are self-hosted (also for Swiss revDSG / GDPR reasons, so no cookie banner is needed).
- All paths **relative**, so the site works both at `http://localhost/luminelli/` and at the domain root.
- Content language: **German, Swiss spelling** (`de-CH`): never use "ß", always "ss".

## File structure

```
/index.html
/404.html
/impressum.html
/datenschutz.html
/agb.html
/auftragsverarbeitung.html
/assets/css/styles.css
/assets/js/main.js            (modal, form submit, small helpers)
/assets/fonts/                (Quicksand variable woff2, latin + latin-ext subset)
/assets/img/                  (logo, hero image in AVIF + WebP + JPG, starburst SVG, favicons, og-image)
/api/inquiry.php              (contact endpoint)
/api/config.example.php       (SMTP / mail settings template; real config.php is git-ignored)
/robots.txt
/sitemap.xml
/llms.txt
/site.webmanifest
/.htaccess
/.gitignore
/README.md                    (local setup, deployment, how to configure mail)
```

## Design

- **Font:** Quicksand only (self-hosted variable woff2, `font-display: swap`, preload the main weight). Use weights ~400 for body, ~600/700 for headings and the uppercase statements.
- **Colours:** sample exact values from the mockup and define them as CSS custom properties on `:root`. Approximate starting points:
  - Hero overlay: muted mauve/rose over the photo (e.g. `rgba(150, 80, 110, .55)`)
  - Primary button / accent: coral (around `#E9736B`)
  - Link underline: coral/red underline under dark text
  - Text: near black on white
  - Footer: dark grey (around `#5B5B5B`) with light text; bottom bar light grey pill
- **Logo:** "STUDIO" small above "LUMINELLI", top left. The logo file will be placed in the project folder (look for `logo.*` in the root or `/assets/img/`). Prefer inline SVG; if only a PNG exists, use it with explicit width/height. If no logo exists yet, use a styled text placeholder and leave a clear `TODO`.
- **Starburst icon** centred between hero and intro statement: small, thin-lined SVG.
- Generous whitespace between sections exactly like the mockup. Subtle, tasteful scroll-reveal (IntersectionObserver, opacity + small translate), disabled under `prefers-reduced-motion`.

## Page sections (in this order)

1. **Header:** logo only (links to `/`). No navigation needed for now.
2. **Hero:** full-width Basel old-town photo (placeholder file `assets/img/hero.*` until I provide the real one) with the mauve overlay.
   - Uppercase white statement (use CSS `text-transform`, write the source text in normal case):
     "Raum für Ihre Ideen. Zeit für Ihre Bedürfnisse. Flexibilität für Ihr Budget."
   - Coral pill button **"Anfragen"** that opens the inquiry modal (general inquiry, no chip).
   - Small line at the bottom: "Oder kommen Sie direkt am Luftgässlein 3 in Basel vorbei" (link the address to a map).
   - The hero image is the LCP element: serve AVIF/WebP/JPG via `<picture>`, explicit dimensions, `fetchpriority="high"`, preload, never lazy-load it.
3. **Intro statement** (large uppercase text, source in normal case):
   "Mit der KI-Revolution ist Software günstig geworden. Code wird nicht mehr von Hand geschrieben. Die Möglichkeiten sind explodiert. Die Idee und die Bedürfnisse rücken in den Vordergrund. Diesen Vorteil möchten wir unseren Kunden weitergeben."
4. **Services**, each as `<article>` with `<h2>`, text and an underlined text link:
   - **Webapps:** "Webapps laufen im Browser und sind deshalb plattformübergreifend flexibel und erfordern keine Installation auf dem einzelnen Arbeitsplatz und können ortsunabhängig benutzt werden." Link "Unverbindlich anfragen" opens the modal with chip **Webapps**.
   - **Mobile Apps:** "Mobile Apps laufen auf mobilen Geräten wie Smartphones und Tablets und können mit Webapps kombiniert werden (selbe Datenbasis)." Link "Unverbindlich anfragen" opens the modal with chip **Mobile Apps**.
   - **Webhosting:** "Ihre Lösungen können direkt auf unseren Servern gehostet und deployed werden, auf Wunsch mit eigener Domain." Link "Mehr zu Webhosting erfahren": until a subpage exists, open the modal with chip **Webhosting**.
   - **Support:** use a placeholder text about maintenance, updates and help after launch, marked with `TODO: Text von Felix` (the mockup accidentally repeats the hosting text). Link "Erkundigen Sie sich zu unseren Support-Lösungen" opens the modal with chip **Support**.
5. **FAQ** (new, short, important for GEO): 5 to 6 questions as `<details>/<summary>`, plus matching `FAQPage` JSON-LD. Draft answers and mark them `TODO: prüfen`. Suggested questions:
   - Was ist Vibe Coding?
   - Was kostet eine Webapp bei Studio Luminelli?
   - Wie lange dauert die Umsetzung?
   - Für wen eignet sich das Angebot? (KMU, Vereine, Institutionen, Künstler:innen, Selbständige, Privatpersonen)
   - Kann ich meine App bei Studio Luminelli hosten lassen?
   - Arbeiten Sie nur in Basel? (Schwerpunkt Region Basel, Zusammenarbeit auch remote)
6. **Footer** (dark grey), two columns as in the mockup:
   - Left: Logo, "Studio Luminelli", "Felix Weissheimer", "Luftgässlein 3", "4051 Basel", phone `+41 76 757 60 52` (`tel:` link), `service@luminelli.ch` (`mailto:` link). Wrap in `<address>`.
   - Right: **Support**: Telefon, Mail. **Rechtliches**: AGB, Datenschutzerklärung, Auftragsverarbeitungsvertrag, Impressum (fix the overflow of "Auftragsverarbeitungsvertrag" on narrow screens, allow hyphenation / wrapping).
   - Bottom light-grey pill: "Studio Luminelli, Luftgässlein 3, Basel" plus © year.
   - Note: the mockup shows "Luftgässli 3" in the footer. Use **"Luftgässlein 3"** everywhere for consistent NAP data.

Create the four legal pages as simple, styled stub pages sharing header/footer, with placeholder headings and `TODO` content (Impressum with real contact data already filled in).

## Inquiry modal

Behaviour:

- One single modal, reused for all triggers. Triggers carry `data-inquiry-topic` (`""`, `"Webapps"`, `"Mobile Apps"`, `"Webhosting"`, `"Support"`).
- Use the native `<dialog>` element with `showModal()` (built-in focus trapping, Esc to close). Close also via a close button and a click on the backdrop. Return focus to the trigger on close. `aria-labelledby` points to the heading.
- **Animation: the modal slides/grows out of the clicked button.** Read the trigger's `getBoundingClientRect()`, set CSS custom properties for start position and scale, animate with `transform` and `opacity` only (~300 ms, ease-out) to its final centred position (on small screens: a bottom sheet). Reverse animation on close. Respect `prefers-reduced-motion` (simple fade).
- Lock background scroll while open.

Content (top to bottom):

1. Heading in normal font size: "Sie haben eine Frage oder möchten mit uns in Kontakt treten?"
2. If a topic is set: a **chip** (rounded pill in theme style) showing the topic, e.g. "Webapps". Also stored in a hidden input `topic`.
3. Field **E-Mail** (`type="email"`, `required`, `autocomplete="email"`, visible label).
4. Field **Nachricht** (`<textarea>`, `required`, 10 to 5000 chars, visible label).
5. Short privacy note: "Ihre Angaben verwenden wir nur zur Bearbeitung Ihrer Anfrage." with link to the Datenschutzerklärung.
6. Coral **"Anfragen"** button in theme style.

Submit flow:

- Client-side validation with friendly German error messages (inline, `aria-describedby`, `aria-invalid`).
- Send via `fetch` (POST, JSON or FormData) to `api/inquiry.php`. Disable the button and show a loading state.
- On success: the modal closes (with the reverse animation) and a small, accessible toast appears ("Danke! Wir melden uns so rasch wie möglich.", `role="status"`). Reset the form.
- On error: keep the modal open and show the error message inside it, data stays in the fields.
- **Progressive enhancement:** without JS, the form posts normally to `api/inquiry.php`, which redirects back to `index.html?anfrage=gesendet#kontakt` (or `=fehler`) and the page shows a simple message.

## `api/inquiry.php`

- Accepts POST only. Validates and sanitises: valid email, message length, topic must be one of the whitelist values or empty.
- **Spam protection without third parties:** honeypot field (visually hidden, `tabindex="-1"`, `autocomplete="off"`), time trap (reject submissions faster than ~3 s after modal open, timestamp in a hidden field), simple file-based rate limit per IP (e.g. max 5 per hour).
- Protect against header injection (strip CR/LF from anything that goes into headers).
- Sends the mail to **service@luminelli.ch**:
  - From: `noreply@luminelli.ch` (domain sender, for deliverability), **Reply-To: the user's email**.
  - Subject: `Neue Anfrage über luminelli.ch: Webapps` (topic, or `Allgemein` when empty).
  - Body (plain text, UTF-8): Thema, E-Mail, Nachricht, Datum/Uhrzeit (Europe/Zurich), Herkunftsseite.
- Mail transport configurable in `config.php`: PHP `mail()` or SMTP (if SMTP, implement a minimal SMTP client or include PHPMailer as a vendored file, no Composer requirement). Include a **DEV mode** that writes mails to a git-ignored log file instead of sending, because XAMPP cannot send mail locally.
- Responds with JSON `{ ok: true }` / `{ ok: false, error: "..." }` for fetch requests and with a redirect for normal form posts.
- Structure the code with one clear function (e.g. `handle_inquiry(array $data): array`) so it can later be moved into the real backend or extended to store inquiries in a database. **Do not build any further backend now.**

## SEO

- `<html lang="de-CH">`, semantic landmarks (`header`, `main`, `section`, `article`, `footer`), exactly one `<h1>`, logical heading order.
- The `<h1>` must contain the core keywords. Recommendation: a small visible kicker above the hero statement, e.g. "Studio Luminelli: Webapps & Mobile Apps aus Basel", as the `<h1>`; the hero statement itself is a styled paragraph. No hidden keyword text.
- `<title>` (max ~60 chars), e.g. "Studio Luminelli | Webapps & Mobile Apps in Basel".
- Meta description (~150 chars), canonical `https://luminelli.ch/`, `robots` index/follow, `theme-color`.
- Open Graph + Twitter Card tags with a 1200×630 `og-image` (generate a simple branded one from logo + colours).
- Favicons: SVG favicon, 32px PNG, apple-touch-icon, `site.webmanifest`.
- `robots.txt` (allow all, including AI crawlers such as GPTBot, ClaudeBot, PerplexityBot, Google-Extended; reference sitemap) and `sitemap.xml` (index + legal pages, `lastmod`).
- Legal stub pages: own titles, descriptions, canonicals.
- Descriptive link texts and `aria-label`s where the visible text repeats ("Unverbindlich anfragen zu Webapps").
- All images with meaningful `alt`, explicit `width`/`height`, lazy loading except the hero.

## Structured data (JSON-LD, one `@graph` in `index.html`)

- `Organization` + `ProfessionalService` (LocalBusiness subtype) for Studio Luminelli: name, alternateName "Luminelli", url, logo, image, email, telephone, `PostalAddress` (Luftgässlein 3, 4051 Basel, BS, CH), `geo` coordinates of the address, `areaServed` (Basel, Basel-Stadt, Basel-Landschaft, Schweiz), `founder` / `Person` Felix Weissheimer, `knowsAbout` (Webapp-Entwicklung, Mobile Apps, KI-gestützte Softwareentwicklung, Vibe Coding, Webhosting), `sameAs` as an empty array with a TODO for future profiles.
- `WebSite` and `WebPage` entities linked via `@id`.
- `OfferCatalog` / `Service` entries for Webapps, Mobile Apps, Webhosting, Support, each with `provider` referencing the organisation.
- `FAQPage` matching the visible FAQ exactly.
- Validate mentally against schema.org; output must pass Google's Rich Results Test.

## GEO (Generative Engine Optimisation)

Goal: AI assistants and AI search (ChatGPT, Claude, Perplexity, Google AI Overviews) should be able to understand, quote and recommend Studio Luminelli correctly.

- **`/llms.txt`** following the llms.txt convention: H1 name, one-sentence summary, short sections "Angebot", "Zielgruppe", "Standort & Kontakt", "FAQ", links to the pages.
- **Entity consistency:** name, address, phone, email and service names identical in HTML, JSON-LD, llms.txt and Impressum.
- **Answer-first wording** in the FAQ and service texts: the first sentence directly answers the question, factual, no marketing fluff.
- Make the key facts available as plain, crawlable text (no text in images, no content only rendered by JS).
- Add a short factual "Über Studio Luminelli" paragraph (can be inside the footer area or above the FAQ): who, what, where, for whom, since when (TODO year). Mark as `TODO: prüfen`.

## Performance

Targets on mobile (Lighthouse): Performance ≥ 95, Accessibility 100, Best Practices 100, SEO 100. LCP < 2.0 s, CLS < 0.05, INP < 200 ms.

- Critical CSS inlined in `<head>` is optional; at least keep the single CSS file small and render-blocking only once. JS with `type="module"` / `defer`.
- Preload hero image and main font file.
- `.htaccess`: force HTTPS and non-www (or the other way, make it a single clear choice, default: no www), gzip/brotli where available, long cache headers for `/assets/` (with versioned filenames or query strings), short cache for HTML, security headers (CSP without `unsafe-inline` for scripts, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`), custom `404.html`. Deny direct web access to `config.php`, log and rate-limit files.

## Accessibility

- WCAG 2.2 AA: check contrast, especially white text on the mauve hero overlay and text on the coral button; adjust the shade slightly if needed and tell me.
- Visible focus styles, keyboard operable modal and FAQ, minimum target size 44×44 px for buttons and links.

## Responsive

- Mobile layout = mockup.
- Tablet/desktop: centred content with max width (~1100 px), hero text larger and left-aligned with the button, intro statement in a narrower readable column, services in a 2×2 grid, footer columns side by side. Use fluid type (`clamp()`).

## Working method

1. Look at the mockup and the folder content, then give me a short plan (structure, colour tokens you extracted, open questions) before coding.
2. Build section by section, starting with the static page, then the modal, then `inquiry.php`, then SEO/GEO files.
3. Test locally at `http://localhost/luminelli/` (DEV mail mode on): modal from every trigger with correct chip, keyboard navigation, form validation, success and error paths, no-JS fallback, mobile and desktop widths.
4. Run an HTML validation and a Lighthouse audit if available, and fix findings.
5. Finish with a list of all `TODO`s I need to fill in (texts, hero photo, logo, legal content, SMTP credentials, founding year, social profiles) and short deployment notes in `README.md`.
