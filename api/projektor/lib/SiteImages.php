<?php
declare(strict_types=1);

/**
 * Imports images from the visitor's OWN website (checkbox in the form) so the
 * agent can reuse them in the draft as local copies.
 *
 * The URL comes from an anonymous visitor, so every request is SSRF-hardened:
 * http(s) on ports 80/443 only, every resolved IP must be public, the checked
 * IP is pinned for the connection (no DNS rebinding), redirects are followed
 * manually and re-checked, responses are size- and time-limited, and the
 * downloaded bytes must really be an image.
 */

const PJ_SITE_MAX_IMAGES = 12;
const PJ_SITE_MAX_HTML = 2 * 1024 * 1024;
const PJ_SITE_MAX_IMAGE = 5 * 1024 * 1024;
const PJ_SITE_TIME_BUDGET = 25; // seconds for the whole import
const PJ_SITE_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg', 'image/avif' => 'avif'];

/**
 * Download up to PJ_SITE_MAX_IMAGES images from $pageUrl into $dir.
 * Never throws; problems are reported in 'notes'.
 *
 * @return array{files: list<array{name: string, mime: string, size: int, source: string}>, notes: list<string>}
 */
function pj_import_site_images(string $pageUrl, string $dir): array
{
    $deadline = microtime(true) + PJ_SITE_TIME_BUDGET;
    $result = ['files' => [], 'notes' => []];

    $page = pj_safe_fetch($pageUrl, PJ_SITE_MAX_HTML, $deadline);
    if ($page['error']) {
        $result['notes'][] = 'Seite nicht geladen: ' . $page['error'];
        return $result;
    }
    if (!str_contains(strtolower($page['type']), 'html')) {
        $result['notes'][] = 'Referenz ist keine HTML-Seite (' . $page['type'] . ')';
        return $result;
    }

    $n = 0;
    $seenHashes = [];
    if ($svg = pj_extract_inline_logo($page['body'])) {
        $name = sprintf('site-%02d-logo.svg', ++$n);
        file_put_contents($dir . '/' . $name, $svg);
        $seenHashes[md5($svg)] = true;
        $result['files'][] = ['name' => $name, 'mime' => 'image/svg+xml', 'size' => strlen($svg), 'source' => $page['url'] . ' (inline SVG)'];
    }

    $candidates = pj_extract_image_urls($page['body'], $page['url']);
    foreach ($candidates as $url) {
        if (count($result['files']) >= PJ_SITE_MAX_IMAGES || microtime(true) > $deadline) {
            break;
        }
        $img = pj_safe_fetch($url, PJ_SITE_MAX_IMAGE, $deadline);
        if ($img['error'] || $img['body'] === '') {
            continue;
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($img['body']);
        if ($mime === 'text/xml' || $mime === 'image/svg') {
            $mime = str_contains($img['body'], '<svg') ? 'image/svg+xml' : $mime;
        }
        $ext = PJ_SITE_IMAGE_TYPES[$mime] ?? null;
        if (!$ext) {
            continue;
        }
        $hash = md5($img['body']);
        if (isset($seenHashes[$hash])) {
            continue;
        }
        // Skip tracking pixels, spacers and tiny icons (raster only).
        if ($ext !== 'svg') {
            $size = @getimagesizefromstring($img['body']);
            if (!$size || $size[0] < 120 || $size[1] < 60) {
                if (!pj_looks_like_logo($url)) {
                    continue;
                }
            }
        }
        $seenHashes[$hash] = true;
        $stem = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)), '-')) ?: 'bild';
        $name = sprintf('site-%02d-%s.%s', ++$n, substr($stem, 0, 30), $ext);
        file_put_contents($dir . '/' . $name, $img['body']);
        $result['files'][] = ['name' => $name, 'mime' => $mime, 'size' => strlen($img['body']), 'source' => $url];
    }
    if (!$result['files']) {
        $result['notes'][] = 'Keine geeigneten Bilder gefunden (' . count($candidates) . ' Kandidaten)';
    }
    return $result;
}

/**
 * Many sites embed the logo as inline <svg>. Take the first <svg> that is
 * marked as logo (itself or an ancestor within 3 levels: class/id/aria-label/
 * title containing "logo", or inside the first link in <header>), stripped of
 * scripts, event handlers and external references.
 */
function pj_extract_inline_logo(string $html): ?string
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    foreach ($doc->getElementsByTagName('svg') as $svg) {
        $isLogo = false;
        for ($el = $svg, $depth = 0; $el instanceof DOMElement && $depth < 4; $el = $el->parentNode, $depth++) {
            $hint = strtolower($el->getAttribute('class') . ' ' . $el->getAttribute('id') . ' ' . $el->getAttribute('aria-label') . ' ' . $el->getAttribute('title'));
            if (str_contains($hint, 'logo')) {
                $isLogo = true;
                break;
            }
        }
        if (!$isLogo) {
            $title = $svg->getElementsByTagName('title')->item(0);
            $isLogo = $title && str_contains(strtolower($title->textContent), 'logo');
        }
        if (!$isLogo) {
            continue;
        }
        // Sanitise: drop scripts/foreignObject/use-with-external-href and on* / javascript: attributes.
        foreach (['script', 'foreignObject'] as $tag) {
            foreach (iterator_to_array($svg->getElementsByTagName($tag)) as $bad) {
                $bad->parentNode->removeChild($bad);
            }
        }
        $all = array_merge([$svg], iterator_to_array($svg->getElementsByTagName('*')));
        foreach ($all as $el) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                $val = strtolower(trim($attr->nodeValue));
                if (str_starts_with($name, 'on') || str_contains($val, 'javascript:')
                    || (in_array($name, ['href', 'xlink:href'], true) && !str_starts_with($val, '#'))) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
        }
        if (!$svg->hasAttribute('xmlns')) {
            $svg->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }
        $out = $doc->saveXML($svg);
        if ($out !== false) {
            // The HTML parser lowercases attribute names; SVG needs camelCase.
            $camel = ['viewBox', 'preserveAspectRatio', 'gradientUnits', 'gradientTransform', 'patternUnits',
                'patternTransform', 'clipPathUnits', 'maskUnits', 'maskContentUnits', 'stdDeviation',
                'baseFrequency', 'textLength', 'lengthAdjust', 'markerWidth', 'markerHeight', 'refX', 'refY',
                'spreadMethod', 'startOffset', 'filterUnits', 'primitiveUnits', 'pathLength'];
            foreach ($camel as $attr) {
                $out = preg_replace('/(\s)' . strtolower($attr) . '=/', '$1' . $attr . '=', $out);
            }
            $elements = ['linearGradient', 'radialGradient', 'clipPath', 'textPath', 'feGaussianBlur', 'feOffset',
                'feBlend', 'feColorMatrix', 'feComposite', 'feFlood', 'feMerge', 'feMergeNode', 'animateTransform'];
            foreach ($elements as $tag) {
                $out = preg_replace('#<(/?)' . strtolower($tag) . '([\s/>])#', '<$1' . $tag . '$2', $out);
            }
        }
        if ($out !== false && strlen($out) > 200 && strlen($out) < 300_000) {
            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out;
        }
    }
    return null;
}

function pj_looks_like_logo(string $url): bool
{
    return (bool) preg_match('/logo|brand|signet/i', (string) parse_url($url, PHP_URL_PATH));
}

/**
 * Collect image URLs from HTML, most useful first: logo, og/twitter image,
 * then <img>/<source> in document order (largest srcset candidate).
 *
 * @return list<string>
 */
function pj_extract_image_urls(string $html, string $pageUrl): array
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $base = $pageUrl;
    foreach ($doc->getElementsByTagName('base') as $b) {
        if ($href = trim($b->getAttribute('href'))) {
            $base = pj_resolve_url($href, $pageUrl) ?? $pageUrl;
        }
        break;
    }

    $logos = [];
    $meta = [];
    $images = [];
    foreach ($doc->getElementsByTagName('meta') as $m) {
        $key = strtolower($m->getAttribute('property') ?: $m->getAttribute('name'));
        if (in_array($key, ['og:image', 'og:image:url', 'twitter:image'], true) && ($c = trim($m->getAttribute('content')))) {
            $meta[] = $c;
        }
    }
    foreach (['img', 'source'] as $tag) {
        foreach ($doc->getElementsByTagName($tag) as $el) {
            $src = trim($el->getAttribute('src') ?: $el->getAttribute('data-src') ?: '');
            $srcset = trim($el->getAttribute('srcset') ?: $el->getAttribute('data-srcset') ?: '');
            if ($srcset !== '') {
                $src = pj_largest_from_srcset($srcset) ?? $src;
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            $hint = strtolower($src . ' ' . $el->getAttribute('alt') . ' ' . $el->getAttribute('class') . ' ' . $el->getAttribute('id'));
            if (str_contains($hint, 'logo')) {
                $logos[] = $src;
            } else {
                $images[] = $src;
            }
        }
    }

    $out = [];
    foreach (array_merge(array_slice($logos, 0, 2), $meta, $images) as $u) {
        $abs = pj_resolve_url(html_entity_decode($u), $base);
        if ($abs && !in_array($abs, $out, true)) {
            $out[] = $abs;
        }
        if (count($out) >= 40) {
            break;
        }
    }
    return $out;
}

function pj_largest_from_srcset(string $srcset): ?string
{
    $best = null;
    $bestW = -1;
    foreach (explode(',', $srcset) as $part) {
        $bits = preg_split('/\s+/', trim($part));
        if (!$bits || $bits[0] === '') {
            continue;
        }
        $w = isset($bits[1]) ? (float) $bits[1] : 1;
        if ($w > $bestW) {
            $bestW = $w;
            $best = $bits[0];
        }
    }
    return $best;
}

/** Resolve a (possibly relative) URL against a base. Returns null for non-http(s). */
function pj_resolve_url(string $url, string $base): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
        return null; // data:, javascript:, mailto: ...
    }
    $b = parse_url($base);
    if (!$b || empty($b['host'])) {
        return null;
    }
    $origin = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($url, '//')) {
        return ($b['scheme'] ?? 'https') . ':' . $url;
    }
    if (str_starts_with($url, '/')) {
        $path = $url;
    } else {
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        $path = $dir . $url;
    }
    // Normalise ./ and ../
    $query = '';
    if (($q = strpos($path, '?')) !== false) {
        $query = substr($path, $q);
        $path = substr($path, 0, $q);
    }
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            array_pop($segments);
        } elseif ($seg !== '.') {
            $segments[] = $seg;
        }
    }
    return $origin . '/' . ltrim(implode('/', $segments), '/') . $query;
}

/** All resolved IPs must be public unicast addresses. Returns the IP to pin, or null. */
function pj_public_ip_for(string $host): ?string
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        if (!preg_match('/^[a-z0-9.-]+$/i', $host) || !str_contains($host, '.')) {
            return null;
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));
    }
    if (!$ips) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        // Extra ranges not covered by the flags: CGNAT, IPv4-mapped IPv6, ULA/link-local IPv6.
        if (preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|0\.)/', $ip) || preg_match('/^(::ffff:|f[cd]|fe[89ab])/i', $ip)) {
            return null;
        }
    }
    // Prefer IPv4 for compatibility.
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
    }
    return $ips[0];
}

/**
 * GET with SSRF protection, manual redirects (max 3), size and time limits.
 *
 * @return array{body: string, type: string, url: string, error: ?string}
 */
function pj_safe_fetch(string $url, int $maxBytes, float $deadline): array
{
    for ($hop = 0; $hop <= 3; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = $p['host'] ?? '';
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443], true) || isset($p['user'])) {
            return ['body' => '', 'type' => '', 'url' => $url, 'error' => 'URL nicht erlaubt'];
        }
        $ip = pj_public_ip_for($host);
        if ($ip === null) {
            return ['body' => '', 'type' => '', 'url' => $url, 'error' => 'Adresse nicht öffentlich oder nicht auflösbar'];
        }
        $remaining = $deadline - microtime(true);
        if ($remaining < 1) {
            return ['body' => '', 'type' => '', 'url' => $url, 'error' => 'Zeitlimit'];
        }

        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*', // proxies would bypass the IP pin
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => (int) max(1, min(10, $remaining)),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LuminelliProjektor/1.0; +https://luminelli.ch/projektor.html)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,image/webp,image/jpeg,image/png,image/*;q=0.8,*/*;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0; // abort transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);

        if ($tooBig) {
            return ['body' => '', 'type' => $type, 'url' => $url, 'error' => 'zu gross'];
        }
        if ($code >= 300 && $code < 400 && $location !== '') {
            $url = pj_resolve_url($location, $url) ?? '';
            continue; // re-validated at the top of the loop
        }
        if ($code !== 200) {
            return ['body' => '', 'type' => $type, 'url' => $url, 'error' => $err !== '' ? $err : "HTTP $code"];
        }
        return ['body' => $body, 'type' => $type, 'url' => $url, 'error' => null];
    }
    return ['body' => '', 'type' => '', 'url' => $url, 'error' => 'zu viele Weiterleitungen'];
}
