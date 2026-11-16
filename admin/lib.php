<?php
/* Studio Luminelli admin: content storage and static build.
   Content lives in content/site.json. build() writes the static pages from it.
   Runs only under PHP's built-in server (admin/start.command) or the CLI, never on the live host. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' && PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ROOT = __DIR__ . '/..';
const CONTENT_FILE = ROOT . '/content/site.json';

function load_content(): array
{
    return json_decode((string) file_get_contents(CONTENT_FILE), true, 512, JSON_THROW_ON_ERROR);
}

function save_content(array $c): void
{
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents(CONTENT_FILE, $json . "\n", LOCK_EX);
}

/* ---------- Text helpers ---------- */

function e(string $s): string
{
    return htmlspecialchars($s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'); // attributes always use double quotes
}

/** Light markup for copy: **Betonung**, {nicht trennen}, ~ = geschütztes Leerzeichen. */
function md(string $s): string
{
    $h = e(trim($s));
    $h = preg_replace('/\*\*(.+?)\*\*/u', '<strong class="em">$1</strong>', $h);
    $h = preg_replace('/\{(.+?)\}/u', '<span class="nowrap">$1</span>', $h);
    return str_replace('~', '&nbsp;', $h);
}

/** Plain text for meta tags and JSON: markup removed. */
function plain(string $s): string
{
    return trim(str_replace(['**', '{', '}', '~'], ['', '', '', ' '], $s));
}

function lines(string $s): array
{
    return array_values(array_filter(array_map('trim', explode("\n", $s)), 'strlen'));
}

function phone_href(string $phone): string
{
    return preg_replace('/[^+0-9]/', '', $phone);
}

function phone_html(string $phone): string
{
    return str_replace(' ', '&nbsp;', e($phone));
}

function slugify(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'é' => 'e', 'è' => 'e', 'à' => 'a']);
    $s = preg_replace('/^für\s+/u', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'loesung';
}

function json_ld(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $json = preg_replace('/^/m', '  ', $json);
    return "  <script type=\"application/ld+json\">\n" . $json . "\n  </script>\n";
}

/* ---------- Shared page parts ---------- */

function logo(string $home, bool $linked): string
{
    $inner = rtrim((string) file_get_contents(__DIR__ . '/templates/logo-inner.svg'));
    if (!$linked) {
        return "    <header class=\"brand\">\n      <svg class=\"luminelli-logo\" width=\"101\" height=\"76\" viewBox=\"0 0 101 76\" role=\"img\" aria-label=\"Studio Luminelli\">\n$inner\n      </svg>\n    </header>\n";
    }
    return "    <header class=\"brand\">\n      <a href=\"$home\" aria-label=\"Studio Luminelli, zur Startseite\"><svg class=\"luminelli-logo\" width=\"101\" height=\"76\" viewBox=\"0 0 101 76\" aria-hidden=\"true\" focusable=\"false\">\n$inner\n      </svg></a>\n    </header>\n";
}

/**
 * <head> for all indexable pages.
 * $p: base ('' or '../'), path (URL path), title, description, og_title, og_description, og_image_alt, extra (raw), jsonld (raw)
 */
function head(array $c, array $p): string
{
    $f = $c['facts'];
    $v = (int) $c['settings']['asset_version'];
    $b = $p['base'];
    $url = rtrim($f['url'], '/') . '/' . $p['path'];
    $og_title = e($p['og_title'] ?? $p['title']);
    $og_desc = e($p['og_description'] ?? $p['description']);
    $alt = e($p['og_image_alt'] ?? $f['name']);
    $site = rtrim($f['url'], '/');
    return <<<HTML
<!DOCTYPE html>
<html lang="de-CH">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>{$p['title_html']}</title>
  <meta name="description" content="{$p['description_html']}">
  <link rel="canonical" href="$url">
  <meta name="robots" content="{$p['robots']}">
{$p['extra']}  <meta name="theme-color" content="#ffffff">
  <meta name="format-detection" content="telephone=no">

  <link rel="preload" href="{$b}assets/fonts/cormorant-latin-400-italic.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="{$b}assets/fonts/cormorant-latin-700-italic.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="{$b}assets/css/site.css?v=$v">
{$p['styles']}  <script src="{$b}assets/js/site.js?v=$v" defer></script>

  <link rel="icon" href="{$b}assets/img/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="{$b}assets/img/favicon-32.png" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="{$b}assets/img/apple-touch-icon.png">
  <link rel="manifest" href="{$b}site.webmanifest">

  <meta property="og:type" content="website">
  <meta property="og:locale" content="de_CH">
  <meta property="og:site_name" content="{$f['name']}">
  <meta property="og:url" content="$url">
  <meta property="og:title" content="$og_title">
  <meta property="og:description" content="$og_desc">
  <meta property="og:image" content="$site/assets/img/og-image.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="$alt">
  <meta name="twitter:card" content="summary_large_image">

{$p['jsonld']}</head>

HTML;
}

function mailto(array $c): string
{
    $f = $c['facts'];
    return 'mailto:' . e($f['email']) . '?subject=' . rawurlencode($c['home']['mail_subject']);
}

function cta(array $c, string $text, string $link): string
{
    $mail = e($c['facts']['email']);
    return "      <p class=\"cta\"><span class=\"marked\">" . md($text) . " <a class=\"link\" href=\"" . mailto($c) . "\" data-copy=\"$mail\">" . md($link) . "</a></span></p>\n"
        . "      <p class=\"note\" role=\"status\" aria-live=\"polite\"></p>\n";
}

function webpage_ld(array $c, string $path, string $name, string $desc, string $modified): array
{
    $site = rtrim($c['facts']['url'], '/');
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        '@id' => "$site/$path#webpage",
        'url' => "$site/$path",
        'name' => $name,
        'description' => $desc,
        'inLanguage' => 'de-CH',
        'isPartOf' => ['@id' => "$site/#website"],
        'about' => ['@id' => "$site/#studio"],
        'dateModified' => $modified,
    ];
}

/* ---------- Pages ---------- */

function render_home(array $c): string
{
    $f = $c['facts'];
    $h = $c['home'];
    $s = $h['seo'];
    $site = rtrim($f['url'], '/');
    $areas = array_map(
        fn($a) => ['@type' => in_array($a, ['Schweiz', 'Switzerland'], true) ? 'Country' : 'AdministrativeArea', 'name' => $a],
        lines($f['area_served'])
    );
    $ld = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'ProfessionalService',
                '@id' => "$site/#studio",
                'name' => $f['name'],
                'alternateName' => $f['alternate_name'],
                'url' => "$site/",
                'logo' => ['@type' => 'ImageObject', 'url' => "$site/assets/img/logo.png", 'width' => 404, 'height' => 304],
                'image' => "$site/assets/img/og-image.jpg",
                'description' => $f['description'],
                'email' => $f['email'],
                'telephone' => $f['phone'],
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $f['street'],
                    'postalCode' => $f['postal_code'],
                    'addressLocality' => $f['city'],
                    'addressRegion' => $f['region'],
                    'addressCountry' => $f['country'],
                ],
                'areaServed' => $areas,
                'founder' => ['@id' => "$site/#owner"],
                'knowsAbout' => lines($f['knows_about']),
                'knowsLanguage' => 'de',
                'contactPoint' => [
                    '@type' => 'ContactPoint',
                    'contactType' => 'customer service',
                    'email' => $f['email'],
                    'telephone' => $f['phone'],
                    'availableLanguage' => 'de',
                ],
                'makesOffer' => [
                    '@type' => 'Offer',
                    'itemOffered' => [
                        '@type' => 'Service',
                        'name' => $f['service_name'],
                        'serviceType' => $f['service_type'],
                        'description' => $f['service_description'],
                        'audience' => ['@type' => 'BusinessAudience', 'audienceType' => $f['audience']],
                        'provider' => ['@id' => "$site/#studio"],
                    ],
                ],
            ],
            [
                '@type' => 'Person',
                '@id' => "$site/#owner",
                'name' => $f['owner'],
                'jobTitle' => $f['owner_role'],
                'worksFor' => ['@id' => "$site/#studio"],
            ],
            [
                '@type' => 'WebSite',
                '@id' => "$site/#website",
                'url' => "$site/",
                'name' => $f['name'],
                'inLanguage' => 'de-CH',
                'publisher' => ['@id' => "$site/#studio"],
            ],
            [
                '@type' => 'WebPage',
                '@id' => "$site/#webpage",
                'url' => "$site/",
                'name' => $s['title'],
                'description' => $s['description'],
                'inLanguage' => 'de-CH',
                'isPartOf' => ['@id' => "$site/#website"],
                'about' => ['@id' => "$site/#studio"],
                'primaryImageOfPage' => "$site/assets/img/og-image.jpg",
                'dateModified' => $h['modified'],
            ],
            [
                '@type' => 'ItemList',
                '@id' => "$site/#loesungen",
                'name' => plain($h['solutions_heading']),
                'itemListElement' => array_map(
                    fn($i, $sol) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $sol['title'], 'url' => "$site/loesungen/{$sol['slug']}.html"],
                    array_keys($c['solutions']),
                    $c['solutions']
                ),
            ],
        ],
    ];

    $tv = substr(md5(render_tints($c)), 0, 8);
    $extra = '  <meta name="author" content="' . e($f['owner']) . "\">\n"
        . '  <meta name="geo.region" content="' . e($f['geo_region']) . "\">\n"
        . '  <meta name="geo.placename" content="' . e($f['geo_placename']) . "\">\n";
    $out = head($c, [
        'base' => '', 'path' => '', 'title' => $s['title'], 'title_html' => e($s['title']),
        'description' => $s['description'], 'description_html' => e($s['description']),
        'og_title' => $s['og_title'], 'og_description' => $s['og_description'], 'og_image_alt' => $s['og_image_alt'],
        'robots' => 'index, follow, max-image-preview:large, max-snippet:-1', 'extra' => $extra, 'jsonld' => json_ld($ld),
        'styles' => "  <link rel=\"stylesheet\" href=\"assets/css/tints.css?v=$tv\">\n",
    ]);
    $out .= "<body>\n  <div class=\"page\">\n" . logo('./', false) . "\n";
    $out .= "    <main class=\"letter\">\n      <h1><span class=\"marked\">" . md($h['h1']) . "</span></h1>\n\n";
    foreach ($h['paragraphs'] as $para) {
        if (trim($para) !== '') {
            $out .= "      <p><span class=\"marked\">" . md($para) . "</span></p>\n\n";
        }
    }
    $out .= cta($c, $h['cta_text'], $h['cta_link']);
    $out .= "    </main>\n\n" . sender($c) . "\n";
    $out .= "    <section class=\"solutions\" id=\"loesungen\" aria-labelledby=\"loesungen-titel\">\n";
    $out .= "      <h2 id=\"loesungen-titel\"><span>" . md($h['solutions_heading']) . "</span></h2>\n      <ul class=\"tiles\">\n";
    foreach ($c['solutions'] as $sol) {
        $slug = e($sol['slug']);
        $out .= "        <li class=\"tile tile--$slug\">\n"
            . "          <a href=\"loesungen/$slug.html\"><h3><span>" . md($sol['tile_title']) . "</span></h3><p>" . md($sol['tile_text']) . "</p></a>\n        </li>\n";
    }
    $out .= "      </ul>\n    </section>\n  </div>\n</body>\n</html>\n";
    return $out;
}

function sender(array $c): string
{
    $f = $c['facts'];
    $mail = e($f['email']);
    return "    <footer class=\"sender\">\n      <address>\n"
        . "        <p>" . e($f['name']) . "<br>" . e($f['street']) . "<br>" . e($f['postal_code'] . ' ' . $f['city']) . "</p>\n"
        . "        <p class=\"contact\"><a href=\"tel:" . phone_href($f['phone']) . "\">" . phone_html($f['phone']) . "</a><br><a href=\"mailto:$mail\" data-copy=\"$mail\">$mail</a></p>\n"
        . "      </address>\n      <p class=\"legal\"><a href=\"impressum.html\">Impressum &amp; Datenschutz</a></p>\n    </footer>\n";
}

function render_solution(array $c, array $sol): string
{
    $sp = $c['solution_page'];
    $path = 'loesungen/' . $sol['slug'] . '.html';
    $ld = webpage_ld($c, $path, $sol['title'] . ' | ' . $c['facts']['name'], $sol['seo_description'], $sol['modified']);
    $out = head($c, [
        'base' => '../', 'path' => $path, 'title' => $sol['title'], 'title_html' => e($sol['title'] . ' | ' . $c['facts']['name']),
        'description' => $sol['seo_description'], 'description_html' => e($sol['seo_description']),
        'og_title' => $sol['title'], 'og_description' => $sol['seo_description'],
        'robots' => 'index, follow', 'extra' => '', 'styles' => '', 'jsonld' => json_ld($ld),
    ]);
    $out .= "<body>\n  <div class=\"page\">\n" . logo('../', true) . "\n";
    $out .= "    <main class=\"letter doc solution-page\">\n      <h1>" . md($sol['title']) . "</h1>\n\n";
    $out .= "      <p class=\"lead\"><span class=\"marked\">" . md($sol['intro']) . "</span></p>\n\n      <ul class=\"examples\">\n";
    foreach ($sol['examples'] as $ex) {
        $out .= "        <li>\n          <h2>" . md($ex['title']) . "</h2>\n          <p><span class=\"marked\">" . md($ex['text']) . "</span></p>\n";
        $meta = [];
        if (trim($ex['today']) !== '') $meta[] = e($sp['today_label']) . ' ' . md($ex['today']);
        if (trim($ex['fits']) !== '') $meta[] = e($sp['fits_label']) . ' ' . md($ex['fits']);
        if ($meta) $out .= "          <p class=\"meta\">" . implode('<br>', $meta) . "</p>\n";
        $out .= "        </li>\n";
    }
    $out .= "      </ul>\n\n" . cta($c, $sp['cta_text'], $sp['cta_link']) . "    </main>\n\n";
    $out .= "    <footer class=\"sender\">\n      <nav aria-label=\"" . e(plain($sp['more_heading'])) . "\">\n        <p>" . md($sp['more_heading']) . "</p>\n        <ul class=\"more\">\n";
    foreach ($c['solutions'] as $o) {
        if ($o['slug'] !== $sol['slug']) {
            $out .= "          <li><a href=\"" . e($o['slug']) . ".html\">" . md($o['tile_title']) . "</a></li>\n";
        }
    }
    $out .= "        </ul>\n      </nav>\n      <p class=\"legal\"><a href=\"../\">Zur Startseite</a></p>\n    </footer>\n  </div>\n</body>\n</html>\n";
    return $out;
}

function render_imprint(array $c): string
{
    $f = $c['facts'];
    $i = $c['imprint'];
    $mail = e($f['email']);
    $maillink = "<a class=\"link\" href=\"mailto:$mail\">$mail</a>";
    $ld = webpage_ld($c, 'impressum.html', $i['seo']['title'], $i['seo']['description'], $i['modified']);
    $out = head($c, [
        'base' => '', 'path' => 'impressum.html', 'title' => $i['seo']['title'], 'title_html' => e($i['seo']['title']),
        'description' => $i['seo']['description'], 'description_html' => e($i['seo']['description']),
        'robots' => 'index, follow', 'extra' => '', 'styles' => '', 'jsonld' => json_ld($ld),
    ]);
    $out .= "<body>\n  <div class=\"page\">\n" . logo('./', true) . "\n";
    $out .= "    <main class=\"letter doc\">\n      <h1>" . md($i['h1']) . "</h1>\n\n      <h2>Impressum</h2>\n";
    $out .= "      <p>" . e($f['name']) . "<br>" . e($f['owner_role']) . ": " . e($f['owner']) . "<br>" . e($f['street']) . "<br>"
        . e($f['postal_code'] . ' ' . $f['city']) . "<br>" . e($f['country_name']) . "</p>\n";
    $out .= "      <p>Telefon <a class=\"link\" href=\"tel:" . phone_href($f['phone']) . "\">" . phone_html($f['phone']) . "</a><br>E-Mail <a class=\"link\" href=\"mailto:$mail\" data-copy=\"$mail\">$mail</a></p>\n";
    $out .= "      <p class=\"note\" role=\"status\" aria-live=\"polite\"></p>\n";
    foreach ($i['sections'] as $sec) {
        $out .= "\n      <h2>" . md($sec['heading']) . "</h2>\n";
        foreach (preg_split('/\n\s*\n/', trim($sec['text'])) as $para) {
            $out .= "      <p>" . str_replace('[E-Mail]', $maillink, md($para)) . "</p>\n";
        }
    }
    $out .= "\n      <p class=\"small\">" . md($i['stand']) . "</p>\n    </main>\n\n";
    $out .= "    <footer class=\"sender\">\n      <p class=\"legal\"><a href=\"./\">Zur Startseite</a></p>\n    </footer>\n  </div>\n</body>\n</html>\n";
    return $out;
}

function render_404(array $c): string
{
    $n = $c['notfound'];
    $v = (int) $c['settings']['asset_version'];
    return "<!DOCTYPE html>\n<html lang=\"de-CH\">\n<head>\n  <meta charset=\"utf-8\">\n"
        . "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n"
        . "  <title>" . e($n['title']) . "</title>\n  <meta name=\"robots\" content=\"noindex\">\n  <meta name=\"theme-color\" content=\"#ffffff\">\n"
        . "  <!-- Root-absolute paths: this page is served for any missing URL on luminelli.ch -->\n"
        . "  <link rel=\"stylesheet\" href=\"/assets/css/site.css?v=$v\">\n  <script src=\"/assets/js/site.js?v=$v\" defer></script>\n"
        . "  <link rel=\"icon\" href=\"/assets/img/favicon.svg\" type=\"image/svg+xml\">\n"
        . "  <link rel=\"icon\" href=\"/assets/img/favicon-32.png\" sizes=\"32x32\" type=\"image/png\">\n</head>\n"
        . "<body>\n  <div class=\"page\">\n" . logo('/', true) . "\n"
        . "    <main class=\"letter doc\">\n      <h1>" . md($n['h1']) . "</h1>\n      <p>" . md($n['text']) . "</p>\n"
        . "      <p><a class=\"link\" href=\"/\">" . md($n['link']) . "</a></p>\n    </main>\n  </div>\n</body>\n</html>\n";
}

function render_tints(array $c): string
{
    $css = "/* Tile tints for the Lösungen section. Generated by the admin from content/site.json. */\n";
    foreach ($c['solutions'] as $s) {
        $tint = preg_match('/^#[0-9a-fA-F]{6}$/', $s['tint']) ? $s['tint'] : '#eceefc';
        $css .= '.tile--' . $s['slug'] . ' { --tint: ' . $tint . "; }\n";
    }
    return $css;
}

function render_sitemap(array $c): string
{
    $site = rtrim($c['facts']['url'], '/');
    $rows = [["$site/", $c['home']['modified']], ["$site/impressum.html", $c['imprint']['modified']]];
    foreach ($c['solutions'] as $s) {
        $rows[] = ["$site/loesungen/{$s['slug']}.html", $s['modified']];
    }
    $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($rows as [$loc, $mod]) {
        $out .= "  <url><loc>" . e($loc) . "</loc><lastmod>" . e($mod) . "</lastmod></url>\n";
    }
    return $out . "</urlset>\n";
}

function render_llms(array $c): string
{
    $f = $c['facts'];
    $l = $c['llms'];
    $site = rtrim($f['url'], '/');
    $out = "# {$f['name']}\n\n> " . plain($l['summary']) . "\n\nWebsite: $site/\nSprache: Deutsch (Schweiz)\nStand: {$l['stand']}\n\n## Angebot\n\n";
    foreach (lines($l['offer']) as $line) $out .= '- ' . plain($line) . "\n";
    $out .= "\n## Zielgruppe\n\n" . plain($l['audience']) . "\n\n## Lösungen\n\n";
    foreach ($c['solutions'] as $s) {
        $out .= "- [" . plain($s['title']) . "]($site/loesungen/{$s['slug']}.html): " . plain($s['tile_text']) . "\n";
        foreach ($s['examples'] as $ex) $out .= "  - " . plain($ex['title']) . ': ' . plain($ex['text']) . "\n";
    }
    $out .= "\n## Kontakt\n\n- {$f['name']}, {$f['owner']}, {$f['street']}, {$f['postal_code']} {$f['city']}, {$f['country_name']}\n"
        . "- Telefon: {$f['phone']}\n- E-Mail: {$f['email']}\n- " . plain($l['contact_note']) . "\n\n## Seiten\n\n"
        . "- [Startseite]($site/): Angebot und Kontakt\n";
    foreach ($c['solutions'] as $s) $out .= "- [" . plain($s['title']) . "]($site/loesungen/{$s['slug']}.html)\n";
    return $out . "- [Impressum und Datenschutz]($site/impressum.html)\n";
}

/** Write every static file. Returns the list of written paths (relative to the site root). */
function build(array $c): array
{
    $files = [
        'index.html' => render_home($c),
        'impressum.html' => render_imprint($c),
        '404.html' => render_404($c),
        'sitemap.xml' => render_sitemap($c),
        'llms.txt' => render_llms($c),
        'assets/css/tints.css' => render_tints($c),
    ];
    foreach ($c['solutions'] as $s) {
        $files['loesungen/' . $s['slug'] . '.html'] = render_solution($c, $s);
    }
    if (!is_dir(ROOT . '/loesungen')) mkdir(ROOT . '/loesungen');
    // remove pages of deleted solutions
    foreach (glob(ROOT . '/loesungen/*.html') as $old) {
        if (!isset($files['loesungen/' . basename($old)])) unlink($old);
    }
    foreach ($files as $path => $html) {
        file_put_contents(ROOT . '/' . $path, $html, LOCK_EX);
    }
    return array_keys($files);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    foreach (build(load_content()) as $p) echo "$p\n";
}
