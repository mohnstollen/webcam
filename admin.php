<?php
/*
 * Webcam-Verwaltung im Browser: Webcams hinzufügen (mit Test), bearbeiten,
 * ein-/ausschalten, löschen, Problemfälle aufräumen, Download-Protokoll ansehen.
 */
declare(strict_types=1);
require __DIR__ . '/inc/webcam.php';

// Anmeldung 30 Tage merken: eigene Sitzungsdateien, damit die System-Aufräumung
// (Standard 24 Minuten) sie nicht vorzeitig löscht
const SITZUNG_SEKUNDEN = 30 * 86400;
$sitzungen = DATEN_VERZ . '/sitzungen';
if (is_dir($sitzungen) || @mkdir($sitzungen, 0700, true)) {
    session_save_path($sitzungen);
    ini_set('session.gc_maxlifetime', (string) SITZUNG_SEKUNDEN);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
}
session_name('webcamverwaltung');
session_set_cookie_params(['lifetime' => SITZUNG_SEKUNDEN, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function token(): string
{
    if (empty($_SESSION['token'])) {
        $_SESSION['token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['token'];
}

function tokenFeld(): string
{
    return '<input type="hidden" name="token" value="' . h(token()) . '">';
}

function pruefeToken(): void
{
    if (!hash_equals(token(), (string) ($_POST['token'] ?? ''))) {
        http_response_code(400);
        exit('Sitzung abgelaufen – bitte die Seite neu laden.');
    }
}

function weiter(string $ziel, string $meldung = ''): void
{
    if ($meldung !== '') {
        $_SESSION['meldung'] = $meldung;
    }
    header('Location: ' . $ziel);
    exit;
}

function zeitText(?int $t): string
{
    if (!$t) {
        return 'nie';
    }
    return (date('Y-m-d', $t) === date('Y-m-d') ? 'heute ' : date('d.m.Y ', $t)) . date('H:i', $t);
}

/** Quelle ↔ Formularfelder Typ + URL */
function quelleAusFormular(array $p): string
{
    $typ = $p['typ'] ?? 'bild';
    $url = trim((string) ($p['url'] ?? ''));
    return in_array($typ, ['html', 'mjpeg'], true) ? "$typ:$url" : $url;
}

/** Status für die Anzeige: [css-Klasse, Text] */
function statusAnzeige(array $c): array
{
    if (!$c['aktiv']) {
        return ['aus', 'Aus'];
    }
    $texte = ['NEU' => ['neu', 'Neu'], 'OK' => ['ok', 'OK'], 'GLEICH' => ['gleich', 'Unverändert'],
              'ALT' => ['alt', 'Veraltet'], 'FEHLER' => ['fehler', 'Fehler']];
    return $texte[$c['status']] ?? ['neu', $c['status']];
}

function istProblem(array $c): bool
{
    return $c['aktiv'] && $c['zuletzt_versuch'] !== null
        && (in_array($c['status'], ['FEHLER', 'ALT'], true)
            || !$c['zuletzt_neu'] || $c['zuletzt_neu'] < time() - DEFEKT_TAGE * 86400);
}

/** Testergebnis für die Anzeige (ohne Bilddaten, mit kleiner Vorschau) */
function testFuerAnzeige(array $r): array
{
    $a = ['ok' => !isset($r['fehler']), 'url' => $r['url'] ?? '', 'fehler' => $r['fehler'] ?? null,
          'vorschlag' => $r['vorschlag'] ?? null];
    if ($a['ok']) {
        $a += [
            'format'   => strtoupper($r['endung']) . ", {$r['breite']}×{$r['hoehe']} Pixel, " . max(1, intdiv($r['groesse'], 1024)) . ' KB',
            'aufnahme' => $r['aufnahme_quelle'] === 'keine'
                ? 'unbekannt (Server sendet kein Datum, keine EXIF-Daten)'
                : date('d.m.Y H:i', $r['aufnahme']) . ' – ' . alterText($r['start'] - $r['aufnahme'])
                  . ($r['aufnahme_quelle'] === 'exif' ? ' (laut EXIF-Daten)' : ' (laut Server)'),
            'veraltet' => $r['veraltet'],
            'klein'    => $r['breite'] < 640,
            'vorschau' => 'data:' . array_search($r['endung'], BILD_TYPEN, true) . ';base64,' . base64_encode($r['daten']),
        ];
    }
    return $a;
}

// -----------------------------------------------------------------------------
// Seitengerüst
// -----------------------------------------------------------------------------

function kopf(string $titel, bool $navigation = true): void
{
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titel) ?> – Webcam-Verwaltung</title>
<style>
  :root {
    --hg: #f4f5f7; --karte: #fff; --text: #1d2330; --leise: #667085; --linie: #dde1e7;
    --akzent: #2563eb; --akzent-text: #fff; --gefahr: #c62828;
    --ok: #1f8a4c; --gleich: #6b7280; --alt: #c76a00; --fehler: #c62828; --aus: #374151; --neu: #2563eb;
  }
  @media (prefers-color-scheme: dark) {
    :root { --hg: #111418; --karte: #1b2027; --text: #e6e8eb; --leise: #9aa3af; --linie: #2c333d;
            --akzent: #4f8cff; --gefahr: #ef5350; --alt: #f0a020; --fehler: #ef5350; --aus: #6b7280; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--hg); color: var(--text);
         font: 16px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  a { color: var(--akzent); }
  header { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 20px;
           padding: 12px 16px; background: var(--karte); border-bottom: 1px solid var(--linie); }
  header h1 { font-size: 20px; margin: 0; margin-right: auto; }
  header h1 a { color: inherit; text-decoration: none; }
  main { max-width: 1400px; margin: 0 auto; padding: 16px; }
  .knopf, button { display: inline-flex; align-items: center; justify-content: center; gap: 6px;
           min-height: 40px; padding: 6px 14px; border: 1px solid var(--linie); border-radius: 8px;
           background: var(--karte); color: var(--text); font: inherit; font-size: 15px;
           text-decoration: none; cursor: pointer; }
  .knopf:hover, button:hover { border-color: var(--akzent); }
  .haupt { background: var(--akzent); border-color: var(--akzent); color: var(--akzent-text); }
  .gefahr { color: var(--gefahr); }
  .meldung { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; border: 1px solid var(--linie); background: var(--karte); }
  .meldung.warnung { border-color: var(--alt); }
  .meldung.fehler { border-color: var(--fehler); color: var(--fehler); }
  .lauf { font-size: 14px; color: var(--leise); }
  .lauf.warnung { color: var(--alt); font-weight: 600; }

  .werkzeuge { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
  .werkzeuge input[type=search], .werkzeuge select { min-height: 40px; padding: 6px 10px; font: inherit;
           border: 1px solid var(--linie); border-radius: 8px; background: var(--karte); color: var(--text); }
  .werkzeuge input[type=search] { flex: 1 1 220px; }
  .chip { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--linie); background: var(--karte);
          color: var(--text); text-decoration: none; font-size: 15px; }
  .chip.an { background: var(--akzent); border-color: var(--akzent); color: var(--akzent-text); }
  .sammel { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; font-size: 15px; color: var(--leise); }

  .raster { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
  .karte { position: relative; display: flex; flex-direction: column; background: var(--karte);
           border: 1px solid var(--linie); border-radius: 12px; overflow: hidden; }
  .karte.inaktiv { opacity: .6; }
  .karte .vorschau { position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden; background: #000; }
  .karte .vorschau img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
  .karte .leer { display: flex; align-items: center; justify-content: center; height: 100%; color: #aaa; font-size: 14px; }
  .karte .auswahl { position: absolute; top: 8px; left: 8px; width: 26px; height: 26px; }
  .karte .inhalt { padding: 10px 12px; flex: 1; }
  .karte h3 { font-size: 16px; margin: 0 0 2px; }
  .klein { font-size: 13px; color: var(--leise); word-break: break-all; }
  .karte .zeile { display: flex; gap: 8px; align-items: center; margin-top: 6px; font-size: 14px; flex-wrap: wrap; }
  .karte .hinweis { font-size: 13px; margin-top: 4px; }
  .karte .aktionen { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 12px 12px; }
  .karte .aktionen .knopf, .karte .aktionen button { min-height: 34px; padding: 4px 10px; font-size: 14px; }
  .marke { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; color: #fff; }
  .marke.ok { background: var(--ok); } .marke.gleich { background: var(--gleich); }
  .marke.alt { background: var(--alt); } .marke.fehler { background: var(--fehler); }
  .marke.aus { background: var(--aus); } .marke.neu { background: var(--neu); }
  .fav { color: #e83e8c; }

  form.formular { max-width: 760px; background: var(--karte); border: 1px solid var(--linie); border-radius: 12px; padding: 20px; }
  .feld { margin-bottom: 16px; }
  .feld label { display: block; font-weight: 600; margin-bottom: 4px; }
  .feld input[type=text], .feld input[type=url], .feld input[type=password], .feld textarea, .feld select {
         width: 100%; min-height: 42px; padding: 8px 10px; font: inherit; border: 1px solid var(--linie);
         border-radius: 8px; background: var(--hg); color: var(--text); }
  .feld textarea { min-height: 70px; }
  .feld .klein { margin-top: 4px; }
  .typen { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-top: 6px; font-weight: normal; }
  .typen label { font-weight: normal; display: inline-flex; gap: 6px; align-items: center; }
  .knoepfe { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  #testergebnis { margin: 0 0 16px; padding: 12px; border-radius: 8px; border: 1px solid var(--linie); background: var(--hg); }
  #testergebnis:empty { display: none; }
  #testergebnis img { max-width: 100%; max-height: 300px; border-radius: 6px; display: block; margin-top: 8px; }
  #testergebnis .gut { color: var(--ok); font-weight: 600; }
  #testergebnis .schlecht { color: var(--fehler); font-weight: 600; }
  #testergebnis .warn { color: var(--alt); font-weight: 600; }
  table { border-collapse: collapse; width: 100%; background: var(--karte); font-size: 14px; }
  th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--linie); }
  pre { background: var(--karte); border: 1px solid var(--linie); border-radius: 8px; padding: 12px;
        overflow-x: auto; font-size: 13px; max-height: 60vh; }
  .anmelden { max-width: 380px; margin: 10vh auto; }
</style>
</head>
<body>
<header>
  <h1><a href="admin.php">📷 Webcam-Verwaltung</a></h1>
  <?php if ($navigation): ?>
    <a class="knopf haupt" href="admin.php?seite=neu">＋ Webcam hinzufügen</a>
    <a class="knopf" href="admin.php?seite=protokoll">Protokoll</a>
    <a class="knopf" href="admin.php?export=1">Export</a>
    <a class="knopf" href="index.php">Slideshow</a>
    <form method="post" style="margin:0"><?= tokenFeld() ?><button name="aktion" value="abmelden">Abmelden</button></form>
  <?php endif; ?>
</header>
<main>
<?php
    if (!empty($_SESSION['meldung'])) {
        echo '<div class="meldung">' . h($_SESSION['meldung']) . '</div>';
        unset($_SESSION['meldung']);
    }
}

function fuss(): void
{
    ?>
</main>
<script>
  // Sicherheitsabfrage für Löschen & Co.
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-frage]');
    if (b && !confirm(b.getAttribute('data-frage'))) ev.preventDefault();
  });
</script>
</body>
</html>
<?php
}

// -----------------------------------------------------------------------------
// Datenbank verfügbar?
// -----------------------------------------------------------------------------

try {
    db();
} catch (Throwable $e) {
    kopf('Einrichtung', false);
    echo '<div class="meldung fehler">' . h($e->getMessage()) . '</div>'
       . '<p>Die Datenbank liegt in <code>' . h(DATEN_VERZ) . '</code>. Einrichten auf dem Server:</p>'
       . '<pre>sudo apt install php-sqlite3 php-curl' . "\n"
       . 'sudo mkdir -p ' . h(DATEN_VERZ) . "\n" . 'sudo chown www-data: ' . h(DATEN_VERZ) . '</pre>';
    fuss();
    exit;
}

// -----------------------------------------------------------------------------
// Anmeldung
// -----------------------------------------------------------------------------

$aktion = (string) ($_POST['aktion'] ?? '');
$hash = einstellung('admin_passwort');
$anmeldeFehler = '';

if ($hash === null && $aktion === 'passwort_setzen') {
    pruefeToken();
    $pw = (string) ($_POST['passwort'] ?? '');
    if (strlen($pw) < 6) {
        $anmeldeFehler = 'Mindestens 6 Zeichen.';
    } elseif ($pw !== ($_POST['passwort2'] ?? '')) {
        $anmeldeFehler = 'Die Passwörter stimmen nicht überein.';
    } else {
        setzeEinstellung('admin_passwort', password_hash($pw, PASSWORD_DEFAULT));
        session_regenerate_id(true);
        $_SESSION['angemeldet'] = true;
        weiter('admin.php', 'Passwort gespeichert – willkommen!');
    }
}
if ($hash !== null && $aktion === 'anmelden') {
    pruefeToken();
    if (password_verify((string) ($_POST['passwort'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['angemeldet'] = true;
        weiter('admin.php');
    }
    sleep(2);
    $anmeldeFehler = 'Falsches Passwort.';
}
if (empty($_SESSION['angemeldet'])) {
    kopf('Anmelden', false);
    $erstmal = $hash === null;
    ?>
    <form method="post" class="formular anmelden">
      <?= tokenFeld() ?>
      <h2><?= $erstmal ? 'Passwort festlegen' : 'Anmelden' ?></h2>
      <?php if ($erstmal): ?><p class="klein">Noch kein Passwort vergeben. Lege jetzt eins für die Verwaltung fest.</p><?php endif; ?>
      <?php if ($anmeldeFehler): ?><div class="meldung fehler"><?= h($anmeldeFehler) ?></div><?php endif; ?>
      <div class="feld"><label for="pw">Passwort</label>
        <input type="password" id="pw" name="passwort" required autofocus autocomplete="<?= $erstmal ? 'new-password' : 'current-password' ?>"></div>
      <?php if ($erstmal): ?>
      <div class="feld"><label for="pw2">Passwort wiederholen</label>
        <input type="password" id="pw2" name="passwort2" required autocomplete="new-password"></div>
      <?php endif; ?>
      <button class="haupt" name="aktion" value="<?= $erstmal ? 'passwort_setzen' : 'anmelden' ?>">
        <?= $erstmal ? 'Speichern' : 'Anmelden' ?></button>
    </form>
    <?php
    fuss();
    exit;
}

// -----------------------------------------------------------------------------
// Aktionen (alle per POST mit Token)
// -----------------------------------------------------------------------------

$formular = null;   // bei Fehlern: Formular mit Eingaben erneut zeigen

if ($aktion !== '') {
    pruefeToken();
    @set_time_limit(300);   // Downloads können länger dauern als die üblichen 30 s
    $zurueck = 'admin.php' . (isset($_POST['zurueck']) && preg_match('/^\?[\w=&%-]*$/', (string) $_POST['zurueck']) ? $_POST['zurueck'] : '');
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));

    switch ($aktion) {
        case 'abmelden':
            $_SESSION = [];
            session_destroy();
            weiter('admin.php');
            // no break
        case 'testen':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(testFuerAnzeige(testeQuelle(quelleAusFormular($_POST))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;

        case 'speichern':
            $id = (int) ($_POST['id'] ?? 0);
            $alt = $id ? webcamPerId($id) : null;
            if ($id && !$alt) {
                weiter('admin.php', 'Webcam nicht gefunden.');
            }
            $eingabe = $_POST;
            $eingabe['quelle'] = quelleAusFormular($_POST);
            [$d, $fehler] = pruefeEingaben($eingabe, $id);
            $test = null;
            // Neue Webcam oder geänderte URL: erst testen, nur bei Erfolg speichern
            if (!$fehler && (!$alt || $alt['quelle'] !== $d['quelle'])) {
                $test = testeQuelle($d['quelle']);
                if (isset($test['fehler'])) {
                    $fehler[] = 'Der Download-Test ist fehlgeschlagen: ' . $test['fehler'] . ' – nicht gespeichert.';
                } elseif ($test['veraltet'] && empty($_POST['trotzdem'])) {
                    $fehler[] = 'Das Bild ist veraltet. Wenn du die Webcam trotzdem eintragen möchtest, '
                              . 'setze unten das Häkchen „Trotzdem speichern“.';
                }
            }
            if ($fehler) {
                $formular = ['d' => $d, 'id' => $id, 'fehler' => $fehler, 'test' => $test ? testFuerAnzeige($test) : null,
                             'veraltet' => $test && empty($test['fehler']) && $test['veraltet']];
                break;
            }
            if ($alt) {
                aendere($alt, $d);
            } else {
                $id = legeAn($d);
            }
            $cam = webcamPerId($id);
            if ($test && $cam['aktiv']) {
                // Getestetes Bild gleich übernehmen → sofort in der Slideshow
                $s = verarbeite($cam, $test, $test['start']) + ['breite' => $test['breite'], 'hoehe' => $test['hoehe']];
                speichereStatus($id, $s, $test['start']);
            }
            weiter('admin.php#cam' . $id, ($alt ? 'Gespeichert: ' : 'Hinzugefügt: ') . $d['titel']);
            // no break
        case 'laden':
            $cams = [];
            foreach ($ids as $id) {
                if (($c = webcamPerId($id)) && $c['aktiv']) {
                    $cams[] = $c;
                }
            }
            $texte = [];
            $namen = array_column($cams, 'titel', 'id');
            foreach (ladeWebcams($cams) as $id => $s) {
                $texte[] = $namen[$id] . ': ' . ['OK' => 'neues Bild geladen', 'GLEICH' => 'Bild unverändert',
                    'ALT' => $s['meldung'], 'FEHLER' => 'Fehler – ' . $s['meldung']][$s['status']];
            }
            weiter($zurueck . (count($ids) === 1 ? '#cam' . $ids[0] : ''), implode(' · ', $texte) ?: 'Nichts zu laden.');
            // no break
        case 'aus':
        case 'an':
            foreach ($ids as $id) {
                setzeAktiv($id, $aktion === 'an');
            }
            weiter($zurueck, count($ids) . ($aktion === 'an' ? ' eingeschaltet' : ' ausgeschaltet')
                . ($aktion === 'an' ? ' – Bilder kommen mit dem nächsten Download.' : '.'));
            // no break
        case 'favorit':
            foreach ($ids as $id) {
                db()->prepare('UPDATE webcams SET favorit = 1 - favorit WHERE id = ?')->execute([$id]);
            }
            weiter($zurueck . (count($ids) === 1 ? '#cam' . $ids[0] : ''));
            // no break
        case 'loeschen':
            foreach ($ids as $id) {
                loesche($id);
            }
            weiter($zurueck, count($ids) . ' gelöscht.');
            // no break
        default:
            weiter('admin.php');
    }
}

if (isset($_GET['export'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="webcams-' . date('Y-m-d') . '.txt"');
    echo schreibeTextliste();
    exit;
}

// -----------------------------------------------------------------------------
// Seiten
// -----------------------------------------------------------------------------

$seite = (string) ($_GET['seite'] ?? '');

if ($formular !== null || $seite === 'neu' || $seite === 'bearbeiten') {
    if ($formular === null) {
        $id = (int) ($_GET['id'] ?? 0);
        $cam = $seite === 'bearbeiten' ? webcamPerId($id) : null;
        if ($seite === 'bearbeiten' && !$cam) {
            weiter('admin.php', 'Webcam nicht gefunden.');
        }
        $formular = ['id' => $cam ? $id : 0, 'fehler' => [], 'test' => null, 'veraltet' => false,
                     'd' => $cam ?: ['titel' => '', 'name' => '', 'quelle' => '', 'gruppe' => (string) ($_GET['gruppe'] ?? ''),
                                     'notiz' => '', 'aktiv' => 1]];
    }
    $d = $formular['d'];
    [$typ, ] = zerlegeQuelle($d['quelle'] !== '' ? $d['quelle'] : 'x');
    $url = quelleOhnePrefix($d['quelle']);
    $gruppen = db()->query("SELECT DISTINCT gruppe FROM webcams WHERE gruppe <> '' ORDER BY gruppe")->fetchAll(PDO::FETCH_COLUMN);
    kopf($formular['id'] ? 'Bearbeiten' : 'Hinzufügen');
    ?>
    <h2><?= $formular['id'] ? 'Webcam bearbeiten' : 'Webcam hinzufügen' ?></h2>
    <?php foreach ($formular['fehler'] as $f): ?><div class="meldung fehler"><?= h($f) ?></div><?php endforeach; ?>
    <form method="post" class="formular" id="formular">
      <?= tokenFeld() ?>
      <input type="hidden" name="id" value="<?= (int) $formular['id'] ?>">

      <div class="feld">
        <label for="url">Bild-Adresse (URL)</label>
        <input type="url" id="url" name="url" value="<?= h($url) ?>" required placeholder="https://…/webcam.jpg" autocomplete="off">
        <div class="typen">
          <label><input type="radio" name="typ" value="bild" <?= $typ === 'bild' ? 'checked' : '' ?>> Bild</label>
          <label><input type="radio" name="typ" value="html" <?= $typ === 'html' ? 'checked' : '' ?>> Webseite (Bild darin suchen)</label>
          <label><input type="radio" name="typ" value="mjpeg" <?= $typ === 'mjpeg' ? 'checked' : '' ?>> MJPEG-Videostream</label>
        </div>
        <div class="klein">Platzhalter für Datum/Uhrzeit möglich, z. B. <code>{utc:%H}</code> für die aktuelle UTC-Stunde.</div>
      </div>

      <div class="knoepfe" style="margin-bottom:12px">
        <button type="button" id="testen">🔍 Testen</button>
        <span class="klein" id="teststatus"></span>
      </div>
      <div id="testergebnis"></div>

      <div class="feld">
        <label for="titel">Titel</label>
        <input type="text" id="titel" name="titel" value="<?= h($d['titel']) ?>" required placeholder="z. B. Görlitz – Obermarkt">
      </div>
      <div class="feld">
        <label for="name">Dateiname</label>
        <input type="text" id="name" name="name" value="<?= h($d['name']) ?>" pattern="[a-z0-9][a-z0-9_\-]*" placeholder="wird aus dem Titel gebildet">
        <div class="klein">Nur a–z, 0–9, „-“ und „_“. Wird zum Bildnamen, z. B. <code>goerlitz-obermarkt.jpg</code>.</div>
      </div>
      <div class="feld">
        <label for="gruppe">Gruppe</label>
        <input type="text" id="gruppe" name="gruppe" value="<?= h($d['gruppe']) ?>" list="gruppen" placeholder="z. B. Erzgebirge / Sachsen">
        <datalist id="gruppen"><?php foreach ($gruppen as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="feld">
        <label for="notiz">Notiz</label>
        <textarea id="notiz" name="notiz"><?= h($d['notiz']) ?></textarea>
      </div>
      <div class="feld">
        <label><input type="checkbox" name="aktiv" value="1" <?= $d['aktiv'] ? 'checked' : '' ?>> In der Slideshow anzeigen (aktiv)</label>
      </div>
      <?php if ($formular['veraltet']): ?>
      <div class="feld"><label class="warn"><input type="checkbox" name="trotzdem" value="1"> Trotzdem speichern, obwohl das Bild veraltet ist</label></div>
      <?php endif; ?>
      <div class="knoepfe">
        <button class="haupt" name="aktion" value="speichern">Speichern</button>
        <a class="knopf" href="admin.php<?= $formular['id'] ? '#cam' . (int) $formular['id'] : '' ?>">Abbrechen</a>
      </div>
      <p class="klein">Beim Speichern einer neuen Webcam oder einer geänderten URL wird der Download automatisch geprüft.
         Nur wenn er klappt, wird gespeichert – und das Bild erscheint sofort in der Slideshow.</p>
    </form>
    <script>
    (function () {
      var f = document.getElementById('formular'), ergebnis = document.getElementById('testergebnis'),
          status = document.getElementById('teststatus'), titel = f.titel, name = f.name;
      var nameVonHand = name.value !== '';

      function slug(t) {
        return t.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
                .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
      }
      titel.addEventListener('input', function () { if (!nameVonHand) name.value = slug(titel.value); });
      name.addEventListener('input', function () { nameVonHand = name.value !== ''; });

      function el(tag, klasse, text) {
        var e = document.createElement(tag);
        if (klasse) e.className = klasse;
        if (text) e.textContent = text;
        return e;
      }
      function zeige(r) {
        ergebnis.innerHTML = '';
        if (r.ok) {
          ergebnis.appendChild(el('div', 'gut', '✔ Bild lässt sich laden: ' + r.format));
          ergebnis.appendChild(el('div', '', 'Aufnahmezeit: ' + r.aufnahme));
          if (r.veraltet) ergebnis.appendChild(el('div', 'warn', '⚠ Das Bild ist veraltet – die Webcam ist vermutlich außer Betrieb.'));
          if (r.klein) ergebnis.appendChild(el('div', 'warn', 'Hinweis: geringe Auflösung – auf dem Tablet wird das Bild unscharf.'));
          var img = el('img'); img.src = r.vorschau; img.alt = 'Vorschau';
          ergebnis.appendChild(img);
        } else {
          ergebnis.appendChild(el('div', 'schlecht', '✘ ' + r.fehler));
          ergebnis.appendChild(el('div', 'klein', r.url));
        }
        if (r.vorschlag) {
          var m = r.vorschlag.match(/^(html|mjpeg):(.*)$/);
          var zeile = el('div', '', m[1] === 'html'
            ? 'Die Adresse liefert eine Webseite mit einem Webcam-Bild darin. '
            : 'Die Adresse liefert einen Videostream. ');
          var b = el('button', '', 'Als ' + (m[1] === 'html' ? 'Webseite' : 'MJPEG-Stream') + ' übernehmen und testen');
          b.type = 'button';
          b.onclick = function () { f.querySelector('[name=typ][value=' + m[1] + ']').checked = true; f.url.value = m[2]; teste(); };
          zeile.appendChild(b);
          ergebnis.appendChild(zeile);
        }
      }
      function teste() {
        if (!f.url.value) { f.url.focus(); return; }
        status.textContent = 'Lade … (bis zu <?= (int) TIMEOUT * 2 ?> Sekunden)';
        ergebnis.innerHTML = '';
        var daten = new FormData();
        daten.append('aktion', 'testen');
        daten.append('token', f.token.value);
        daten.append('url', f.url.value);
        daten.append('typ', f.querySelector('[name=typ]:checked').value);
        fetch('admin.php', { method: 'POST', body: daten, credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
          .then(function (r) { status.textContent = ''; zeige(r); })
          .catch(function (e) { status.textContent = 'Test fehlgeschlagen: ' + e.message; });
      }
      document.getElementById('testen').addEventListener('click', teste);
      <?php if ($formular['test']): ?>zeige(<?= json_encode($formular['test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>);<?php endif; ?>
    })();
    </script>
    <?php
    fuss();
    exit;
}

if ($seite === 'protokoll') {
    kopf('Protokoll');
    $laeufe = db()->query('SELECT * FROM laeufe ORDER BY start DESC LIMIT 48')->fetchAll();
    $log = is_readable(DATEN_VERZ . '/download.log')
        ? array_slice(file(DATEN_VERZ . '/download.log', FILE_IGNORE_NEW_LINES), -300) : [];
    ?>
    <h2>Download-Läufe</h2>
    <?php if (!$laeufe): ?>
      <div class="meldung warnung">Noch kein Download-Lauf. Ist der Cron-Job eingerichtet? (siehe README)</div>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
      <tr><th>Start</th><th>Dauer</th><th>Webcams</th><th>Aktualisiert</th><th>Unverändert</th><th>Veraltet</th><th>Fehler</th></tr>
      <?php foreach ($laeufe as $l): ?>
      <tr><td><?= h(zeitText((int) $l['start'])) ?></td><td><?= (int) $l['dauer'] ?> s</td><td><?= (int) $l['anzahl'] ?></td>
          <td><?= (int) $l['ok'] ?></td><td><?= (int) $l['gleich'] ?></td><td><?= (int) $l['veraltet'] ?></td><td><?= (int) $l['fehler'] ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <h2>download.log</h2>
    <pre><?= h(implode("\n", array_reverse($log)) ?: '(leer)') ?></pre>
    <?php
    fuss();
    exit;
}

// ---- Übersicht ----------------------------------------------------------------

$filter = (string) ($_GET['filter'] ?? 'alle');
$alle = alleWebcams();
$zahlen = ['alle' => count($alle), 'probleme' => 0, 'aus' => 0, 'favoriten' => 0];
foreach ($alle as $c) {
    $zahlen['probleme'] += istProblem($c) ? 1 : 0;
    $zahlen['aus'] += $c['aktiv'] ? 0 : 1;
    $zahlen['favoriten'] += $c['favorit'];
}
$liste = array_values(array_filter($alle, function (array $c) use ($filter): bool {
    switch ($filter) {
        case 'probleme':  return istProblem($c);
        case 'aus':       return !$c['aktiv'];
        case 'favoriten': return (bool) $c['favorit'];
        default:          return true;
    }
}));
if ($filter === 'probleme') {   // älteste zuerst
    usort($liste, function (array $a, array $b): int { return ($a['zuletzt_neu'] ?? 0) <=> ($b['zuletzt_neu'] ?? 0); });
}
$gruppen = array_values(array_unique(array_filter(array_column($alle, 'gruppe'))));
$lauf = letzterLauf();

kopf('Übersicht');
if (!$lauf): ?>
  <div class="meldung warnung">Es gab noch keinen Download-Lauf. Ist der Cron-Job eingerichtet? (siehe README)</div>
<?php else:
    $alt = time() - $lauf['start'] > 2 * 3600; ?>
  <p class="lauf<?= $alt ? ' warnung' : '' ?>">
    Letzter Download: <?= h(zeitText($lauf['start'])) ?> –
    <?= $lauf['ok'] ?> aktualisiert, <?= $lauf['gleich'] ?> unverändert, <?= $lauf['veraltet'] ?> veraltet, <?= $lauf['fehler'] ?> Fehler
    <?= $alt ? ' – länger als 2 Stunden her. Läuft der Cron-Job?' : '' ?>
    · <a href="admin.php?seite=protokoll">Protokoll</a>
  </p>
<?php endif; ?>

<div class="werkzeuge">
  <?php foreach (['alle' => 'Alle', 'probleme' => 'Probleme', 'aus' => 'Ausgeschaltet', 'favoriten' => '♥ Favoriten'] as $k => $t): ?>
    <a class="chip<?= $filter === $k ? ' an' : '' ?>" href="admin.php?filter=<?= $k ?>"><?= $t ?> (<?= $zahlen[$k] ?>)</a>
  <?php endforeach; ?>
  <input type="search" id="suche" placeholder="Suchen: Titel, Name, URL …" autocomplete="off">
  <select id="gruppenwahl"><option value="">Alle Gruppen</option>
    <?php foreach ($gruppen as $g): ?><option><?= h($g) ?></option><?php endforeach; ?></select>
</div>
<?php if ($filter === 'probleme'): ?>
  <p class="klein">Webcams mit Fehler, mit veraltetem Bild oder ohne neues Bild seit <?= DEFEKT_TAGE ?> Tagen – die ältesten zuerst.</p>
<?php endif; ?>

<form method="post" id="sammel" class="sammel">
  <?= tokenFeld() ?>
  <input type="hidden" name="zurueck" value="?filter=<?= h($filter) ?>">
  <label><input type="checkbox" id="alle-waehlen"> alle sichtbaren auswählen</label>
  <span id="anzahl-gewaehlt"></span>
  <button name="aktion" value="laden">Jetzt laden</button>
  <button name="aktion" value="aus">Ausschalten</button>
  <button name="aktion" value="an">Einschalten</button>
  <button name="aktion" value="loeschen" class="gefahr" data-frage="Ausgewählte Webcams wirklich löschen?">Löschen</button>
</form>

<?php if (!$liste): ?>
  <p>Keine Webcams<?= $filter === 'probleme' ? ' mit Problemen 🎉' : '' ?>.</p>
<?php endif; ?>
<div class="raster">
<?php foreach ($liste as $c):
    [$klasse, $text] = statusAnzeige($c);
    $bild = $c['datei'] !== null && is_file(BILD_VERZ . '/' . $c['datei']);
    $bildUrl = $bild ? BILD_URL . '/' . rawurlencode($c['datei']) . '?v=' . filemtime(BILD_VERZ . '/' . $c['datei']) : '';
    $suche = mb_strtolower($c['titel'] . ' ' . $c['name'] . ' ' . $c['gruppe'] . ' ' . $c['quelle'] . ' ' . $c['notiz'], 'UTF-8');
    ?>
  <article class="karte<?= $c['aktiv'] ? '' : ' inaktiv' ?>" id="cam<?= $c['id'] ?>" data-suche="<?= h($suche) ?>" data-gruppe="<?= h($c['gruppe']) ?>">
    <a class="vorschau" href="<?= $bild ? h($bildUrl) : h(quelleOhnePrefix($c['quelle'])) ?>" target="_blank" rel="noopener">
      <?php if ($bild): ?><img src="<?= h($bildUrl) ?>" loading="lazy" alt=""><?php else: ?><span class="leer">kein aktuelles Bild</span><?php endif; ?>
    </a>
    <input type="checkbox" class="auswahl" name="ids[]" value="<?= $c['id'] ?>" form="sammel" aria-label="auswählen">
    <div class="inhalt">
      <h3><?= h($c['titel']) ?><?= $c['favorit'] ? ' <span class="fav">♥</span>' : '' ?></h3>
      <div class="klein"><?= h($c['name']) ?><?= $c['gruppe'] !== '' ? ' · ' . h($c['gruppe']) : '' ?></div>
      <div class="zeile">
        <span class="marke <?= $klasse ?>"><?= $text ?></span>
        <?php if ($c['zuletzt_neu']): ?><span>Aufnahme <?= h(alterText(time() - $c['zuletzt_neu'])) ?></span><?php endif; ?>
      </div>
      <?php if ($c['meldung'] !== '' && $c['status'] !== 'GLEICH'): ?><div class="hinweis"><?= h($c['meldung']) ?></div><?php endif; ?>
      <?php if ($c['notiz'] !== ''): ?><div class="hinweis klein">📝 <?= h($c['notiz']) ?></div><?php endif; ?>
      <div class="klein" style="margin-top:4px">geprüft: <?= h(zeitText($c['zuletzt_versuch'])) ?></div>
    </div>
    <form method="post" class="aktionen">
      <?= tokenFeld() ?>
      <input type="hidden" name="ids[]" value="<?= $c['id'] ?>">
      <input type="hidden" name="zurueck" value="?filter=<?= h($filter) ?>">
      <a class="knopf" href="admin.php?seite=bearbeiten&amp;id=<?= $c['id'] ?>">Bearbeiten</a>
      <?php if ($c['aktiv']): ?>
        <button name="aktion" value="laden" title="Jetzt neu laden">⟳ Laden</button>
        <button name="aktion" value="aus">Aus</button>
      <?php else: ?>
        <button name="aktion" value="an">Ein</button>
      <?php endif; ?>
      <button name="aktion" value="favorit" title="Favorit"><?= $c['favorit'] ? '♥' : '♡' ?></button>
      <button name="aktion" value="loeschen" class="gefahr" data-frage="„<?= h($c['titel']) ?>“ wirklich löschen?">Löschen</button>
    </form>
  </article>
<?php endforeach; ?>
</div>

<script>
(function () {
  var suche = document.getElementById('suche'), gruppe = document.getElementById('gruppenwahl'),
      karten = [].slice.call(document.querySelectorAll('.karte')), alleWaehlen = document.getElementById('alle-waehlen');

  function filtern() {
    var q = suche.value.toLowerCase().trim(), g = gruppe.value;
    karten.forEach(function (k) {
      var sichtbar = (!q || k.getAttribute('data-suche').indexOf(q) >= 0) && (!g || k.getAttribute('data-gruppe') === g);
      k.style.display = sichtbar ? '' : 'none';
      if (!sichtbar) k.querySelector('.auswahl').checked = false;
    });
    zaehlen();
    try { sessionStorage.setItem('webcamSuche', JSON.stringify([suche.value, gruppe.value])); } catch (e) {}
  }
  function zaehlen() {
    var n = document.querySelectorAll('.auswahl:checked').length;
    document.getElementById('anzahl-gewaehlt').textContent = n ? n + ' ausgewählt:' : '';
  }
  try {
    var gemerkt = JSON.parse(sessionStorage.getItem('webcamSuche') || 'null');
    if (gemerkt) { suche.value = gemerkt[0]; gruppe.value = gemerkt[1]; }
  } catch (e) {}
  suche.addEventListener('input', filtern);
  gruppe.addEventListener('change', filtern);
  alleWaehlen.addEventListener('change', function () {
    karten.forEach(function (k) { if (k.style.display !== 'none') k.querySelector('.auswahl').checked = alleWaehlen.checked; });
    zaehlen();
  });
  document.addEventListener('change', function (e) { if (e.target.classList.contains('auswahl')) zaehlen(); });
  document.getElementById('sammel').addEventListener('submit', function (e) {
    if (!document.querySelectorAll('.auswahl:checked').length) { e.preventDefault(); alert('Bitte zuerst Webcams auswählen.'); }
  });
  filtern();
})();
</script>
<?php
fuss();
