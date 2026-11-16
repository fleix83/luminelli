<?php
/* Studio Luminelli admin. Start with admin/start.command, then open http://localhost:8765/admin/ */

declare(strict_types=1);
require __DIR__ . '/lib.php';

// Only on this computer: localhost host header (blocks DNS rebinding) and a session token on every POST.
if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(403);
    exit('Nur lokal verfügbar.');
}
session_start();
$_SESSION['token'] ??= bin2hex(random_bytes(16));
$token = $_SESSION['token'];

$c = load_content();
$today = date('Y-m-d');
$section = $_GET['s'] ?? 'start';
$slug = $_GET['slug'] ?? '';
$errors = [];
$publishLog = null;

function find_solution(array $c, string $slug): ?int
{
    foreach ($c['solutions'] as $i => $s) {
        if ($s['slug'] === $slug) return $i;
    }
    return null;
}

function str(array $a, string $k): string
{
    return trim(str_replace("\r\n", "\n", (string) ($a[$k] ?? '')));
}

function git(string $args): array
{
    $proc = proc_open('git ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    return [$code, trim($out)];
}

const PUBLISH_PATHS = 'content/site.json index.html impressum.html 404.html sitemap.xml llms.txt loesungen assets/css/tints.css';

/* ---------- Save ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, $_POST['token'] ?? '')) {
        http_response_code(400);
        exit('Sitzung abgelaufen. Bitte Seite neu laden.');
    }
    $p = $_POST;
    $action = $p['action'] ?? '';
    $redirect = '?s=' . urlencode($section) . ($slug !== '' ? '&slug=' . urlencode($slug) : '');

    switch ($action) {
        case 'facts':
            foreach (array_keys($c['facts']) as $k) {
                if (isset($p['facts'][$k])) $c['facts'][$k] = str($p['facts'], $k);
            }
            $c['home']['modified'] = $c['imprint']['modified'] = $today;
            break;

        case 'home':
            foreach (['title', 'description', 'og_title', 'og_description', 'og_image_alt'] as $k) {
                $c['home']['seo'][$k] = str($p['seo'], $k);
            }
            foreach (['h1', 'cta_text', 'cta_link', 'mail_subject', 'solutions_heading'] as $k) {
                $c['home'][$k] = str($p, $k);
            }
            $c['home']['paragraphs'] = array_values(array_filter(array_map(
                fn($x) => trim(str_replace("\r\n", "\n", $x)),
                $p['paragraphs'] ?? []
            ), 'strlen'));
            $c['home']['modified'] = $today;
            break;

        case 'order':
            $bySlug = array_column($c['solutions'], null, 'slug');
            $sorted = [];
            foreach ($p['order'] ?? [] as $s) {
                if (isset($bySlug[$s])) {
                    $sorted[] = $bySlug[$s];
                    unset($bySlug[$s]);
                }
            }
            $c['solutions'] = array_merge($sorted, array_values($bySlug));
            $c['home']['modified'] = $today;
            break;

        case 'solution_page':
            foreach (['cta_text', 'cta_link', 'more_heading', 'today_label', 'fits_label'] as $k) {
                $c['solution_page'][$k] = str($p, $k);
            }
            foreach ($c['solutions'] as &$s) $s['modified'] = $today;
            unset($s);
            break;

        case 'add_solution':
            $title = str($p, 'tile_title');
            if ($title === '') {
                $errors[] = 'Bitte einen Titel für die neue Kachel eingeben.';
                break;
            }
            $new = slugify($title);
            while (find_solution($c, $new) !== null) $new .= '-2';
            $c['solutions'][] = [
                'slug' => $new, 'tile_title' => $title, 'tile_text' => '', 'tint' => '#eceefc',
                'title' => 'Lösungen ' . mb_strtolower(mb_substr($title, 0, 1)) . mb_substr($title, 1),
                'seo_description' => '', 'intro' => '',
                'examples' => [['title' => '', 'text' => '', 'today' => '', 'fits' => '']],
                'modified' => $today,
            ];
            $c['home']['modified'] = $today;
            $redirect = '?s=loesung&slug=' . urlencode($new);
            break;

        case 'solution':
            $i = find_solution($c, $slug);
            if ($i === null) break;
            $s = &$c['solutions'][$i];
            foreach (['tile_title', 'tile_text', 'title', 'seo_description', 'intro'] as $k) {
                $s[$k] = str($p, $k);
            }
            $s['tint'] = preg_match('/^#[0-9a-fA-F]{6}$/', $p['tint'] ?? '') ? strtolower($p['tint']) : $s['tint'];
            $s['examples'] = [];
            foreach ($p['examples'] ?? [] as $ex) {
                $row = ['title' => str($ex, 'title'), 'text' => str($ex, 'text'), 'today' => str($ex, 'today'), 'fits' => str($ex, 'fits')];
                if (implode('', $row) !== '') $s['examples'][] = $row;
            }
            $newSlug = slugify(str($p, 'slug') ?: $s['tile_title']);
            if ($newSlug !== $s['slug'] && find_solution($c, $newSlug) === null) {
                $s['slug'] = $newSlug;
                $redirect = '?s=loesung&slug=' . urlencode($newSlug);
            }
            $s['modified'] = $today;
            unset($s);
            $c['home']['modified'] = $today;
            break;

        case 'delete_solution':
            $i = find_solution($c, $slug);
            if ($i !== null && count($c['solutions']) > 1) {
                array_splice($c['solutions'], $i, 1);
                $c['home']['modified'] = $today;
            }
            $redirect = '?s=loesungen';
            break;

        case 'imprint':
            $c['imprint']['seo']['title'] = str($p['seo'], 'title');
            $c['imprint']['seo']['description'] = str($p['seo'], 'description');
            $c['imprint']['h1'] = str($p, 'h1');
            $c['imprint']['stand'] = str($p, 'stand');
            $c['imprint']['sections'] = [];
            foreach ($p['sections'] ?? [] as $sec) {
                $row = ['heading' => str($sec, 'heading'), 'text' => str($sec, 'text')];
                if ($row['heading'] . $row['text'] !== '') $c['imprint']['sections'][] = $row;
            }
            $c['imprint']['modified'] = $today;
            break;

        case 'seo':
            foreach (['description', 'service_name', 'service_type', 'service_description', 'audience', 'knows_about', 'area_served', 'geo_region', 'geo_placename', 'alternate_name'] as $k) {
                $c['facts'][$k] = str($p['facts'], $k);
            }
            foreach (array_keys($c['llms']) as $k) {
                $c['llms'][$k] = str($p['llms'], $k);
            }
            $c['home']['modified'] = $today;
            break;

        case 'notfound':
            foreach (array_keys($c['notfound']) as $k) $c['notfound'][$k] = str($p, $k);
            break;

        case 'publish':
            $msg = str($p, 'message') ?: 'Inhalte aktualisiert';
            [$code, $out] = git('add -A -- ' . PUBLISH_PATHS);
            $log = "$ git add\n$out\n";
            if ($code === 0) {
                [$code, $out] = git('commit -m ' . escapeshellarg($msg) . ' -- ' . PUBLISH_PATHS);
                $log .= "$ git commit\n$out\n";
            }
            if ($code === 0) {
                [$code, $out] = git('push');
                $log .= "$ git push\n$out\n";
            }
            $_SESSION['flash'] = $code === 0 ? 'Veröffentlicht. Die Website ist in einer Minute aktuell.' : 'Veröffentlichen hat nicht geklappt. Details unten.';
            $_SESSION['log'] = $log;
            header('Location: ?s=publish');
            exit;
    }

    if (!$errors) {
        save_content($c);
        build($c);
        $_SESSION['flash'] = 'Gespeichert. Die Seiten sind neu erstellt.';
        header('Location: ' . $redirect);
        exit;
    }
}

$flash = $_SESSION['flash'] ?? null;
$publishLog = $_SESSION['log'] ?? null;
unset($_SESSION['flash'], $_SESSION['log']);

/* ---------- Form helpers ---------- */

function field(string $label, string $name, string $value, array $o = []): string
{
    $id = 'f-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
    $type = $o['type'] ?? 'text';
    $max = isset($o['max']) ? ' data-max="' . (int) $o['max'] . '"' : '';
    $req = !empty($o['required']) ? ' required' : '';
    $html = '<div class="field' . (isset($o['class']) ? ' ' . $o['class'] : '') . '">';
    $html .= '<label for="' . $id . '">' . e($label) . '</label>';
    if ($type === 'textarea') {
        $rows = $o['rows'] ?? 3;
        $html .= '<textarea id="' . $id . '" name="' . e($name) . '" rows="' . $rows . '"' . $max . $req . '>' . e($value) . '</textarea>';
    } else {
        $html .= '<input id="' . $id . '" type="' . $type . '" name="' . e($name) . '" value="' . e($value) . '"' . $max . $req . '>';
    }
    if (!empty($o['hint']) || $max) {
        $html .= '<p class="hint"><span>' . e($o['hint'] ?? '') . '</span>' . ($max ? '<span class="count"></span>' : '') . '</p>';
    }
    return $html . '</div>';
}

function form_open(string $action, string $token): string
{
    return '<form method="post" class="panel"><input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="' . e($action) . '">';
}

function save_button(string $label = 'Speichern'): string
{
    return '<div class="actions"><button type="submit" class="primary">' . e($label) . '</button></div></form>';
}

const MARKUP_HINT = '**Wort** = Betonung in Rot, {Wort-Paar} = nicht trennen, ~ = festes Leerzeichen.';

$nav = [
    'start' => 'Startseite',
    'loesungen' => 'Lösungen',
    'impressum' => 'Impressum',
    'kontakt' => 'Kontaktdaten',
    'seo' => 'SEO & GEO',
    'fehlerseite' => 'Fehlerseite',
    'publish' => 'Veröffentlichen',
];
$active = $section === 'loesung' ? 'loesungen' : $section;
$preview = '../';
if ($section === 'loesung' && $slug !== '') $preview = '../loesungen/' . rawurlencode($slug) . '.html';
if ($section === 'impressum') $preview = '../impressum.html';
?>
<!DOCTYPE html>
<html lang="de-CH">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Admin | Studio Luminelli</title>
  <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
  <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
</head>
<body>
<header class="top">
  <a class="brand" href="./">Studio Luminelli <span>Admin</span></a>
  <a class="preview" href="<?= e($preview) ?>" target="_blank" rel="noopener">Vorschau ansehen ↗</a>
</header>

<div class="shell">
  <nav class="side" aria-label="Bereiche">
    <ul>
      <?php foreach ($nav as $k => $label): ?>
        <li><a href="?s=<?= $k ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
      <?php endforeach ?>
    </ul>
  </nav>

  <main class="content">
    <?php if ($flash): ?><p class="flash"><?= e($flash) ?></p><?php endif ?>
    <?php foreach ($errors as $err): ?><p class="flash error"><?= e($err) ?></p><?php endforeach ?>

<?php if ($section === 'start'): $h = $c['home']; ?>
    <h1>Startseite</h1>
    <?= form_open('home', $token) ?>
      <fieldset>
        <legend>Brief</legend>
        <?= field('Einstieg (Hauptüberschrift)', 'h1', $h['h1'], ['type' => 'textarea', 'rows' => 2, 'hint' => MARKUP_HINT]) ?>
        <div class="repeater" data-name="paragraphs">
          <p class="label">Absätze</p>
          <?php foreach ($h['paragraphs'] as $i => $para): ?>
            <div class="item">
              <textarea name="paragraphs[]" rows="4" aria-label="Absatz <?= $i + 1 ?>"><?= e($para) ?></textarea>
              <div class="item-tools"><button type="button" data-move="-1" aria-label="Nach oben">↑</button><button type="button" data-move="1" aria-label="Nach unten">↓</button><button type="button" data-remove aria-label="Entfernen">✕</button></div>
            </div>
          <?php endforeach ?>
          <template><div class="item"><textarea name="paragraphs[]" rows="4" aria-label="Neuer Absatz"></textarea><div class="item-tools"><button type="button" data-move="-1" aria-label="Nach oben">↑</button><button type="button" data-move="1" aria-label="Nach unten">↓</button><button type="button" data-remove aria-label="Entfernen">✕</button></div></div></template>
          <button type="button" class="add" data-add>+ Absatz hinzufügen</button>
        </div>
        <div class="row">
          <?= field('Schlusssatz', 'cta_text', $h['cta_text'], ['type' => 'textarea', 'rows' => 2]) ?>
          <?= field('Link-Text (öffnet E-Mail)', 'cta_link', $h['cta_link'], ['type' => 'textarea', 'rows' => 2]) ?>
        </div>
        <?= field('Betreff der E-Mail', 'mail_subject', $h['mail_subject']) ?>
        <?= field('Überschrift Lösungen', 'solutions_heading', $h['solutions_heading']) ?>
      </fieldset>
      <fieldset>
        <legend>Suchmaschinen und Teilen</legend>
        <?= field('Seitentitel', 'seo[title]', $h['seo']['title'], ['max' => 60, 'hint' => 'Erscheint als Titel in Google.']) ?>
        <?= field('Beschreibung', 'seo[description]', $h['seo']['description'], ['type' => 'textarea', 'max' => 160, 'hint' => 'Text unter dem Titel in Google.']) ?>
        <?= field('Titel beim Teilen', 'seo[og_title]', $h['seo']['og_title'], ['max' => 70, 'hint' => 'Für WhatsApp, LinkedIn und Co.']) ?>
        <?= field('Beschreibung beim Teilen', 'seo[og_description]', $h['seo']['og_description'], ['type' => 'textarea', 'max' => 200]) ?>
        <?= field('Alternativtext Vorschaubild', 'seo[og_image_alt]', $h['seo']['og_image_alt']) ?>
      </fieldset>
    <?= save_button() ?>

<?php elseif ($section === 'loesungen'): $sp = $c['solution_page']; ?>
    <h1>Lösungen</h1>
    <form method="post" class="panel" data-sortable-form>
      <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="order">
      <fieldset>
        <legend>Kacheln</legend>
        <p class="intro">Ziehen Sie die Kacheln in die gewünschte Reihenfolge oder nutzen Sie die Pfeile.</p>
        <ul class="tiles-sort" data-sortable>
          <?php foreach ($c['solutions'] as $s): ?>
            <li draggable="true">
              <input type="hidden" name="order[]" value="<?= e($s['slug']) ?>">
              <span class="grip" aria-hidden="true">⋮⋮</span>
              <span class="swatch" data-tint="<?= e($s['tint']) ?>"></span>
              <span class="tile-info"><strong><?= e(plain($s['tile_title'])) ?></strong><small><?= count($s['examples']) ?> Beispiele</small></span>
              <span class="item-tools"><button type="button" data-move="-1" aria-label="Nach oben">↑</button><button type="button" data-move="1" aria-label="Nach unten">↓</button></span>
              <a class="edit" href="?s=loesung&amp;slug=<?= e(rawurlencode($s['slug'])) ?>">Bearbeiten</a>
            </li>
          <?php endforeach ?>
        </ul>
      </fieldset>
      <div class="actions"><button type="submit" class="primary" data-order-save hidden>Reihenfolge speichern</button></div>
    </form>

    <?= form_open('add_solution', $token) ?>
      <fieldset>
        <legend>Neue Kachel</legend>
        <div class="row row-end">
          <?= field('Titel', 'tile_title', '', ['hint' => 'Zum Beispiel «Für Arztpraxen».']) ?>
          <div class="actions"><button type="submit">Kachel anlegen</button></div>
        </div>
      </fieldset>
    </form>

    <?= form_open('solution_page', $token) ?>
      <fieldset>
        <legend>Texte auf allen Lösungsseiten</legend>
        <div class="row">
          <?= field('Schlusssatz', 'cta_text', $sp['cta_text'], ['type' => 'textarea', 'rows' => 2]) ?>
          <?= field('Link-Text (öffnet E-Mail)', 'cta_link', $sp['cta_link'], ['type' => 'textarea', 'rows' => 2]) ?>
        </div>
        <div class="row three">
          <?= field('Überschrift Seitenspalte', 'more_heading', $sp['more_heading']) ?>
          <?= field('Bezeichnung «Heute»', 'today_label', $sp['today_label']) ?>
          <?= field('Bezeichnung «Passt für»', 'fits_label', $sp['fits_label']) ?>
        </div>
      </fieldset>
    <?= save_button() ?>

<?php elseif ($section === 'loesung' && ($i = find_solution($c, $slug)) !== null): $s = $c['solutions'][$i]; ?>
    <p class="back"><a href="?s=loesungen">← Alle Lösungen</a></p>
    <h1><?= e(plain($s['tile_title'])) ?></h1>
    <form method="post" class="panel">
      <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="solution">
      <fieldset>
        <legend>Kachel auf der Startseite</legend>
        <div class="row tint-row">
          <?= field('Titel', 'tile_title', $s['tile_title'], ['required' => true]) ?>
          <?= field('Farbton', 'tint', $s['tint'], ['type' => 'color', 'class' => 'narrow']) ?>
        </div>
        <?= field('Kurztext', 'tile_text', $s['tile_text'], ['type' => 'textarea', 'max' => 140, 'hint' => 'Ein bis zwei Sätze.']) ?>
      </fieldset>
      <fieldset>
        <legend>Seite</legend>
        <?= field('Seitentitel', 'title', $s['title'], ['required' => true]) ?>
        <?= field('Einleitung', 'intro', $s['intro'], ['type' => 'textarea', 'rows' => 4, 'hint' => MARKUP_HINT]) ?>
        <div class="repeater" data-name="examples">
          <p class="label">Beispiele</p>
          <?php foreach (array_merge($s['examples'], [null]) as $j => $ex): if ($ex === null) break; ?>
            <div class="item card">
              <div class="item-head"><span class="num"></span><div class="item-tools"><button type="button" data-move="-1" aria-label="Nach oben">↑</button><button type="button" data-move="1" aria-label="Nach unten">↓</button><button type="button" data-remove aria-label="Entfernen">✕</button></div></div>
              <?= field('Titel', "examples[$j][title]", $ex['title']) ?>
              <?= field('Was das Tool macht', "examples[$j][text]", $ex['text'], ['type' => 'textarea', 'rows' => 3]) ?>
              <div class="row">
                <?= field('Heute oft', "examples[$j][today]", $ex['today']) ?>
                <?= field('Passt für', "examples[$j][fits]", $ex['fits']) ?>
              </div>
            </div>
          <?php endforeach ?>
          <template>
            <div class="item card">
              <div class="item-head"><span class="num"></span><div class="item-tools"><button type="button" data-move="-1" aria-label="Nach oben">↑</button><button type="button" data-move="1" aria-label="Nach unten">↓</button><button type="button" data-remove aria-label="Entfernen">✕</button></div></div>
              <?= field('Titel', 'examples[__][title]', '') ?>
              <?= field('Was das Tool macht', 'examples[__][text]', '', ['type' => 'textarea', 'rows' => 3]) ?>
              <div class="row"><?= field('Heute oft', 'examples[__][today]', '') ?><?= field('Passt für', 'examples[__][fits]', '') ?></div>
            </div>
          </template>
          <button type="button" class="add" data-add>+ Beispiel hinzufügen</button>
        </div>
      </fieldset>
      <fieldset>
        <legend>Suchmaschinen</legend>
        <?= field('Beschreibung', 'seo_description', $s['seo_description'], ['type' => 'textarea', 'max' => 160, 'hint' => 'Text unter dem Titel in Google.']) ?>
        <?= field('Adresse der Seite', 'slug', $s['slug'], ['hint' => 'luminelli.ch/loesungen/' . $s['slug'] . '.html. Nur ändern, wenn nötig: alte Links funktionieren danach nicht mehr.']) ?>
      </fieldset>
      <div class="actions"><button type="submit" class="primary">Speichern</button></div>
    </form>
    <form method="post" class="panel danger-zone" data-confirm="Diese Lösung und ihre Seite löschen?">
      <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="delete_solution">
      <div class="actions"><button type="submit" class="danger">Lösung löschen</button></div>
    </form>

<?php elseif ($section === 'impressum'): $im = $c['imprint']; ?>
    <h1>Impressum und Datenschutz</h1>
    <?= form_open('imprint', $token) ?>
      <fieldset>
        <legend>Inhalt</legend>
        <?= field('Überschrift', 'h1', $im['h1']) ?>
        <p class="intro">Adresse, Telefon und E-Mail kommen aus den <a href="?s=kontakt">Kontaktdaten</a>. Schreiben Sie [E-Mail], um die E-Mail-Adresse als Link einzufügen.</p>
        <div class="repeater" data-name="sections">
          <p class="label">Abschnitte</p>
          <?php foreach ($im['sections'] as $j => $sec): ?>
            <div class="item card">
              <div class="item-head"><span class="num"></span><div class="item-tools"><button type="button" data-move="-1" aria-label="Nach oben">↑</button><button type="button" data-move="1" aria-label="Nach unten">↓</button><button type="button" data-remove aria-label="Entfernen">✕</button></div></div>
              <?= field('Zwischentitel', "sections[$j][heading]", $sec['heading']) ?>
              <?= field('Text', "sections[$j][text]", $sec['text'], ['type' => 'textarea', 'rows' => 4, 'hint' => 'Leerzeile = neuer Absatz.']) ?>
            </div>
          <?php endforeach ?>
          <template>
            <div class="item card">
              <div class="item-head"><span class="num"></span><div class="item-tools"><button type="button" data-move="-1" aria-label="Nach oben">↑</button><button type="button" data-move="1" aria-label="Nach unten">↓</button><button type="button" data-remove aria-label="Entfernen">✕</button></div></div>
              <?= field('Zwischentitel', 'sections[__][heading]', '') ?>
              <?= field('Text', 'sections[__][text]', '', ['type' => 'textarea', 'rows' => 4]) ?>
            </div>
          </template>
          <button type="button" class="add" data-add>+ Abschnitt hinzufügen</button>
        </div>
        <?= field('Stand', 'stand', $im['stand']) ?>
      </fieldset>
      <fieldset>
        <legend>Suchmaschinen</legend>
        <?= field('Seitentitel', 'seo[title]', $im['seo']['title'], ['max' => 60]) ?>
        <?= field('Beschreibung', 'seo[description]', $im['seo']['description'], ['type' => 'textarea', 'max' => 160]) ?>
      </fieldset>
    <?= save_button() ?>

<?php elseif ($section === 'kontakt'): $f = $c['facts']; ?>
    <h1>Kontaktdaten</h1>
    <?= form_open('facts', $token) ?>
      <fieldset>
        <legend>Studio</legend>
        <div class="row">
          <?= field('Name', 'facts[name]', $f['name'], ['required' => true]) ?>
          <?= field('Website', 'facts[url]', $f['url'], ['type' => 'url', 'required' => true]) ?>
        </div>
        <div class="row">
          <?= field('Inhaber', 'facts[owner]', $f['owner']) ?>
          <?= field('Rolle', 'facts[owner_role]', $f['owner_role']) ?>
        </div>
      </fieldset>
      <fieldset>
        <legend>Adresse</legend>
        <?= field('Strasse', 'facts[street]', $f['street']) ?>
        <div class="row three">
          <?= field('PLZ', 'facts[postal_code]', $f['postal_code'], ['class' => 'narrow']) ?>
          <?= field('Ort', 'facts[city]', $f['city']) ?>
          <?= field('Kanton (Kürzel)', 'facts[region]', $f['region'], ['class' => 'narrow']) ?>
        </div>
        <div class="row">
          <?= field('Land', 'facts[country_name]', $f['country_name']) ?>
          <?= field('Ländercode', 'facts[country]', $f['country'], ['class' => 'narrow', 'hint' => 'CH']) ?>
        </div>
      </fieldset>
      <fieldset>
        <legend>Erreichbarkeit</legend>
        <div class="row">
          <?= field('Telefon', 'facts[phone]', $f['phone'], ['type' => 'tel', 'hint' => 'Mit Leerzeichen, wie es angezeigt werden soll.']) ?>
          <?= field('E-Mail', 'facts[email]', $f['email'], ['type' => 'email', 'required' => true]) ?>
        </div>
      </fieldset>
      <p class="intro">Diese Angaben erscheinen auf allen Seiten, im Impressum, in den strukturierten Daten für Google und in llms.txt.</p>
    <?= save_button() ?>

<?php elseif ($section === 'seo'): $f = $c['facts']; $l = $c['llms']; ?>
    <h1>SEO &amp; GEO</h1>
    <?= form_open('seo', $token) ?>
      <fieldset>
        <legend>Strukturierte Daten (Google)</legend>
        <?= field('Kurzname', 'facts[alternate_name]', $f['alternate_name']) ?>
        <?= field('Beschreibung des Studios', 'facts[description]', $f['description'], ['type' => 'textarea', 'rows' => 4]) ?>
        <div class="row">
          <?= field('Angebot', 'facts[service_name]', $f['service_name']) ?>
          <?= field('Art der Dienstleistung', 'facts[service_type]', $f['service_type']) ?>
        </div>
        <?= field('Beschreibung des Angebots', 'facts[service_description]', $f['service_description'], ['type' => 'textarea']) ?>
        <?= field('Zielgruppe', 'facts[audience]', $f['audience']) ?>
        <div class="row">
          <?= field('Fachgebiete', 'facts[knows_about]', $f['knows_about'], ['type' => 'textarea', 'rows' => 4, 'hint' => 'Eines pro Zeile.']) ?>
          <?= field('Einzugsgebiet', 'facts[area_served]', $f['area_served'], ['type' => 'textarea', 'rows' => 4, 'hint' => 'Eines pro Zeile.']) ?>
        </div>
        <div class="row">
          <?= field('Region (geo.region)', 'facts[geo_region]', $f['geo_region'], ['hint' => 'Zum Beispiel CH-BS.']) ?>
          <?= field('Ort (geo.placename)', 'facts[geo_placename]', $f['geo_placename']) ?>
        </div>
      </fieldset>
      <fieldset>
        <legend>Für KI-Suchdienste (llms.txt)</legend>
        <?= field('Zusammenfassung', 'llms[summary]', $l['summary'], ['type' => 'textarea', 'rows' => 3]) ?>
        <?= field('Angebot', 'llms[offer]', $l['offer'], ['type' => 'textarea', 'rows' => 5, 'hint' => 'Ein Punkt pro Zeile.']) ?>
        <?= field('Zielgruppe und Region', 'llms[audience]', $l['audience'], ['type' => 'textarea', 'rows' => 3]) ?>
        <div class="row">
          <?= field('Hinweis zum Kontakt', 'llms[contact_note]', $l['contact_note']) ?>
          <?= field('Stand', 'llms[stand]', $l['stand']) ?>
        </div>
        <p class="intro">Lösungen, Beispiele, Seiten und Kontaktdaten fügt das System selbst in llms.txt und sitemap.xml ein.</p>
      </fieldset>
    <?= save_button() ?>

<?php elseif ($section === 'fehlerseite'): $n = $c['notfound']; ?>
    <h1>Fehlerseite (404)</h1>
    <?= form_open('notfound', $token) ?>
      <fieldset>
        <legend>Inhalt</legend>
        <?= field('Seitentitel', 'title', $n['title']) ?>
        <?= field('Überschrift', 'h1', $n['h1']) ?>
        <?= field('Text', 'text', $n['text'], ['type' => 'textarea']) ?>
        <?= field('Link zur Startseite', 'link', $n['link']) ?>
      </fieldset>
    <?= save_button() ?>

<?php elseif ($section === 'publish'):
    [, $status] = git('status --porcelain -- ' . PUBLISH_PATHS); ?>
    <h1>Veröffentlichen</h1>
    <?= form_open('publish', $token) ?>
      <fieldset>
        <legend>Änderungen</legend>
        <?php if ($status === ''): ?>
          <p class="intro">Keine offenen Änderungen. Die Website ist auf dem neusten Stand.</p>
        <?php else: ?>
          <p class="intro">Diese Dateien gehen online:</p>
          <ul class="changes">
            <?php foreach (explode("\n", $status) as $line): ?><li><code><?= e(trim(substr($line, 3))) ?></code></li><?php endforeach ?>
          </ul>
          <?= field('Kurze Notiz', 'message', '', ['hint' => 'Optional, zum Beispiel «Neue Lösung für Arztpraxen».']) ?>
        <?php endif ?>
      </fieldset>
      <?php if ($status !== ''): ?>
        <div class="actions"><button type="submit" class="primary">Jetzt veröffentlichen</button></div>
      <?php endif ?>
    </form>
    <?php if ($publishLog): ?><pre class="log"><?= e($publishLog) ?></pre><?php endif ?>

<?php else: ?>
    <h1>Nicht gefunden</h1>
    <p><a href="?s=loesungen">Zurück zu den Lösungen</a></p>
<?php endif ?>
  </main>
</div>
</body>
</html>
