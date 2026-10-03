<?php
declare(strict_types=1);

/**
 * POST (multipart) from projektor.html. Validates the request, checks the
 * daily caps (per IP and global), stores the uploads and starts the Managed
 * Agents session right away. Returns the status URL for the live view.
 */

require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    pj_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$pj = pj_config();
pj_defer_housekeeping($pj);
if ($reason = pj_unavailable_reason($pj)) {
    pj_json(['ok' => false, 'error' => $reason], 503);
}

// Request larger than post_max_size: PHP silently drops $_POST and $_FILES.
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    pj_json(['ok' => false, 'error' => 'Die Dateien sind zusammen zu gross (max. 25 MB).'], 413);
}

// Honeypot + time trap (form rendered → submit must take at least 5 s)
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    pj_json(['ok' => true]); // pretend success, start nothing
}
$ts = (string) ($_POST['ts'] ?? '');
if (!ctype_digit($ts) || (microtime(true) * 1000 - (float) $ts) < 5000) {
    pj_json(['ok' => false, 'error' => 'Das ging etwas zu schnell. Bitte senden Sie das Formular erneut.'], 400);
}

$in = fn(string $k) => trim(str_replace("\r\n", "\n", (string) ($_POST[$k] ?? '')));
$errors = [];

$url = $in('url');
if ($url !== '') {
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false || !str_contains($host, '.')) {
        $errors['url'] = 'Bitte geben Sie eine gültige Adresse ein, z. B. https://beispiel.ch.';
    }
}
$ownSite = ($_POST['own_site'] ?? '') === '1';
if ($ownSite && $url === '') {
    $errors['url'] = 'Bitte geben Sie die Adresse Ihrer Website an, damit wir die Bilder übernehmen können.';
}
$project = $in('project');
if (mb_strlen($project) < 5 || mb_strlen($project) > 200) {
    $errors['project'] = 'Bitte beschreiben Sie Ihr Projekt in einem Satz (5 bis 200 Zeichen).';
}
$description = $in('description');
if (mb_strlen($description) < 30 || mb_strlen($description) > 4000) {
    $errors['description'] = 'Bitte beschreiben Sie in 30 bis 4000 Zeichen, was Sie umsetzen möchten.';
}
$font = $in('font');
if (!isset(PJ_FONTS[$font])) {
    $errors['font'] = 'Bitte wählen Sie eine Schriftart.';
}
$color = strtolower($in('color'));
if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
    $errors['color'] = 'Bitte wählen Sie eine Farbe.';
}
$email = $in('email'); // optional: only to send the link when the draft is ready
if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email))) {
    $errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein oder lassen Sie das Feld leer.';
}
if (($_POST['consent'] ?? '') !== '1') {
    $errors['consent'] = 'Bitte bestätigen Sie den Hinweis zur Datenverarbeitung.';
}

/** Normalise $_FILES[field] into a list. */
$filesOf = function (string $field): array {
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return [];
    }
    if (!is_array($f['name'])) {
        return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $list = [];
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $list[] = ['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
        }
    }
    return $list;
};

$finfo = new finfo(FILEINFO_MIME_TYPE);
$uploads = [];
$total = 0;
$candidates = array_merge(
    array_map(fn($f) => $f + ['role' => 'screenshot'], array_slice($filesOf('screenshot'), 0, 1)),
    array_map(fn($f) => $f + ['role' => 'asset'], $filesOf('files')),
);
if (count($candidates) > PJ_MAX_FILES + 1) {
    $errors['files'] = 'Bitte laden Sie höchstens ' . PJ_MAX_FILES . ' Dateien hoch.';
}
$usedNames = [];
foreach ($candidates as $f) {
    if (isset($errors['files']) || isset($errors['screenshot'])) {
        break;
    }
    $field = $f['role'] === 'screenshot' ? 'screenshot' : 'files';
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $errors[$field] = 'Eine Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.';
        break;
    }
    $mime = (string) $finfo->file($f['tmp_name']);
    $ext = PJ_UPLOAD_TYPES[$mime] ?? null;
    if ($mime === 'text/plain' && preg_match('/\.(csv|json)$/i', $f['name'], $m)) {
        $ext = strtolower($m[1]); // finfo often reports CSV/JSON as text/plain
        $mime = $ext === 'csv' ? 'text/csv' : 'application/json';
    }
    if ($f['role'] === 'screenshot' && !in_array($ext, ['jpg', 'png', 'webp', 'gif'], true)) {
        $errors['screenshot'] = 'Der Screenshot muss ein Bild sein (JPG, PNG, WebP).';
        break;
    }
    if ($ext === null) {
        $errors['files'] = '«' . mb_substr($f['name'], 0, 60) . '» hat ein Format, das wir nicht verarbeiten können. Erlaubt: Bilder, PDF, CSV, JSON, XLSX, TXT.';
        break;
    }
    if ($f['size'] > PJ_MAX_FILE_BYTES) {
        $errors[$field] = '«' . mb_substr($f['name'], 0, 60) . '» ist grösser als 8 MB.';
        break;
    }
    $total += $f['size'];
    // Safe, unique file name: ascii slug + detected extension
    $stem = strtr(pathinfo($f['name'], PATHINFO_FILENAME), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'ç' => 'c']);
    $stem = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $stem), '-')) ?: 'datei';
    $stem = substr($stem, 0, 40);
    $name = "$stem.$ext";
    for ($n = 2; isset($usedNames[$name]); $n++) {
        $name = "$stem-$n.$ext";
    }
    $usedNames[$name] = true;
    $uploads[] = ['tmp' => $f['tmp_name'], 'name' => $name, 'mime' => $mime, 'size' => (int) $f['size'], 'role' => $f['role']];
}
if ($total > PJ_MAX_TOTAL_BYTES) {
    $errors['files'] = 'Die Dateien sind zusammen zu gross (max. 25 MB).';
}

if ($errors) {
    pj_json(['ok' => false, 'error' => 'Bitte prüfen Sie die markierten Felder.', 'fields' => $errors], 422);
}

$ip = client_ip();
if (!rate_limit_allows('pj-submit:' . $ip, $pj['_root'], 'projektor-submits', (int) ($pj['submits_per_ip_per_hour'] ?? 3), 3600)) {
    pj_json(['ok' => false, 'error' => 'Sie haben in kurzer Zeit mehrere Anfragen gesendet. Bitte versuchen Sie es später erneut.'], 429);
}
$ipHash = hash_id('ip:' . $ip, $pj['_root']);
if ($limitError = pj_limits_check($pj, $ipHash, true)) {
    rate_limit_release('pj-submit:' . $ip, $pj['_root'], 'projektor-submits');
    pj_json(['ok' => false, 'error' => $limitError], 429);
}

$job = pj_job_create([
    'email' => $email,
    'ip_hash' => $ipHash,
    'reference_url' => $url,
    'own_site' => $ownSite,
    'project' => $project,
    'description' => $description,
    'font' => $font,
    'color' => $color,
    'files' => [],
]);
foreach ($uploads as $u) {
    move_uploaded_file($u['tmp'], pj_job_dir($job['id']) . '/uploads/' . $u['name']);
    $job['files'][] = ['name' => $u['name'], 'stored' => $u['name'], 'mime' => $u['mime'], 'size' => $u['size'], 'role' => $u['role']];
}

// Visitor's own website: copy its images (server-side, SSRF-hardened) so the
// agent can reuse them. Problems here never block the draft.
if ($ownSite && $url !== '') {
    $import = pj_import_site_images($url, pj_job_dir($job['id']) . '/uploads');
    foreach ($import['files'] as $f) {
        $job['files'][] = ['name' => $f['name'], 'stored' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size'], 'role' => 'site', 'source' => $f['source']];
    }
    $job['site_import_notes'] = $import['notes'];
}

pj_job_save($job);

try {
    pj_start_session($pj, $job);
    @mkdir(PJ_DIR . '/sessions', 0750, true);
    file_put_contents(pj_session_index_file($job['session_id']), $job['id']);
    $job['state'] = 'running';
    $job['started_at'] = pj_now()->format(DATE_ATOM);
    $job['last_poll'] = time();
} catch (Throwable $e) {
    app_log('[projektor] start ' . $job['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
    // Our failure: give the slots back so the visitor can try again.
    pj_limits_release($pj, $ipHash);
    rate_limit_release('pj-submit:' . $ip, $pj['_root'], 'projektor-submits');
    pj_job_save(pj_fail($pj, $job, 'Start fehlgeschlagen: ' . $e->getMessage()));
    pj_json(['ok' => false, 'error' => 'Der Projektor konnte gerade nicht starten. Bitte versuchen Sie es in ein paar Minuten erneut.'], 502);
}

$statusToken = pj_job_issue_status_token($job);
pj_job_save($job);

pj_json([
    'ok' => true,
    'state' => 'running',
    'job' => $job['id'],
    't' => $statusToken,
    'status_url' => pj_status_url($pj, $job['id'], $statusToken),
]);
