<?php
/*
 * Kern der Webcam-Anwendung – gemeinsam genutzt von
 *   index.php        Slideshow fürs Tablet
 *   admin.php        Verwaltung im Browser
 *   webcam-cli.php   Download per Cron, Import/Export, Test auf der Kommandozeile
 *
 * Webcams, Status und Favoriten liegen in einer SQLite-Datenbank (DATEN_VERZ/webcams.sqlite).
 * Einstellungen lassen sich in inc/konfig.local.php überschreiben, z. B.:
 *   <?php define('ANZEIGE_SEKUNDEN', 30); define('MAX_ALTER_MIN', 720);
 */
declare(strict_types=1);

if (is_file(__DIR__ . '/konfig.local.php')) {
    require __DIR__ . '/konfig.local.php';
}
foreach ([
    'DATEN_VERZ'       => '/var/lib/webcam',              // Datenbank, Log – außerhalb des Webverzeichnisses
    'BILD_VERZ'        => dirname(__DIR__) . '/bilder',  // aktuelle Bilder für die Slideshow
    'BILD_URL'         => 'bilder',
    'ANZEIGE_SEKUNDEN' => 20,                             // Anzeigedauer pro Bild
    'PARALLEL'         => 8,                              // gleichzeitige Downloads
    'TIMEOUT'          => 20,                             // Sekunden pro Download
    'MIN_BYTES'        => 2000,                           // kleinere Dateien gelten als defekt
    'MAX_ALTER_MIN'    => 1440,                           // ältere Aufnahmen gelten als veraltet (24 h)
    'DEFEKT_TAGE'      => 2,                              // so lange ohne neues Bild = Problemfall
    // Aktueller Firefox (ESR). Manche Server liefern ohne Browser-Kennung nichts aus.
    'USER_AGENT'       => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0',
] as $schluessel => $wert) {
    if (!defined($schluessel)) {
        define($schluessel, $wert);
    }
}

const BILD_TYPEN  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const NAME_MUSTER = '/^[a-z0-9][a-z0-9_-]*$/';

// =============================================================================
// Datenbank
// =============================================================================

function db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    if (!is_dir(DATEN_VERZ) && !@mkdir(DATEN_VERZ, 0775, true)) {
        throw new RuntimeException('Datenverzeichnis fehlt: ' . DATEN_VERZ);
    }
    if (!is_writable(DATEN_VERZ)) {
        throw new RuntimeException('Keine Schreibrechte für ' . DATEN_VERZ);
    }
    $db = new PDO('sqlite:' . DATEN_VERZ . '/webcams.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: Slideshow, Verwaltung und Cron-Download können gleichzeitig zugreifen
    $db->exec('PRAGMA busy_timeout = 10000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec("
        CREATE TABLE IF NOT EXISTS webcams (
            id              INTEGER PRIMARY KEY,
            name            TEXT    NOT NULL UNIQUE,     -- Dateiname ohne Endung
            titel           TEXT    NOT NULL DEFAULT '',
            quelle          TEXT    NOT NULL,            -- URL, optional mit html: / mjpeg:
            gruppe          TEXT    NOT NULL DEFAULT '',
            notiz           TEXT    NOT NULL DEFAULT '',
            aktiv           INTEGER NOT NULL DEFAULT 1,
            favorit         INTEGER NOT NULL DEFAULT 0,
            erstellt        INTEGER NOT NULL,
            status          TEXT    NOT NULL DEFAULT 'NEU', -- NEU, OK, GLEICH, ALT, FEHLER
            meldung         TEXT    NOT NULL DEFAULT '',
            datei           TEXT,                        -- aktuelles Bild in BILD_VERZ
            breite          INTEGER,
            hoehe           INTEGER,
            zuletzt_versuch INTEGER,                     -- letzter Download-Versuch
            zuletzt_ok      INTEGER,                     -- letztes gültiges Bild (auch unverändert)
            zuletzt_neu     INTEGER,                     -- Aufnahmezeit des neuesten Bildes
            fehler_folge    INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS einstellungen (
            schluessel TEXT PRIMARY KEY,
            wert       TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS laeufe (
            id       INTEGER PRIMARY KEY,
            start    INTEGER NOT NULL,
            dauer    INTEGER NOT NULL,
            anzahl   INTEGER NOT NULL,
            ok       INTEGER NOT NULL,
            gleich   INTEGER NOT NULL,
            veraltet INTEGER NOT NULL,
            fehler   INTEGER NOT NULL
        );
    ");
    return $db;
}

/** Zahlenspalten als int liefern (PDO-SQLite gibt vor PHP 8.1 Strings zurück). */
function zeile(array $r): array
{
    foreach (['id', 'aktiv', 'favorit', 'erstellt', 'breite', 'hoehe', 'zuletzt_versuch',
              'zuletzt_ok', 'zuletzt_neu', 'fehler_folge'] as $s) {
        if (isset($r[$s])) {
            $r[$s] = (int) $r[$s];
        }
    }
    return $r;
}

function alleWebcams(string $bedingung = '1', array $param = []): array
{
    $st = db()->prepare("SELECT * FROM webcams WHERE $bedingung ORDER BY gruppe, titel COLLATE NOCASE, name");
    $st->execute($param);
    return array_map('zeile', $st->fetchAll());
}

function webcamPerId(int $id): ?array
{
    $r = alleWebcams('id = ?', [$id]);
    return $r[0] ?? null;
}

function einstellung(string $schluessel, ?string $standard = null): ?string
{
    $st = db()->prepare('SELECT wert FROM einstellungen WHERE schluessel = ?');
    $st->execute([$schluessel]);
    $wert = $st->fetchColumn();
    return $wert === false ? $standard : (string) $wert;
}

function setzeEinstellung(string $schluessel, string $wert): void
{
    db()->prepare('INSERT OR REPLACE INTO einstellungen (schluessel, wert) VALUES (?, ?)')
        ->execute([$schluessel, $wert]);
}

function letzterLauf(): ?array
{
    $r = db()->query('SELECT * FROM laeufe ORDER BY start DESC LIMIT 1')->fetch();
    return $r ? array_map('intval', $r) : null;
}

// =============================================================================
// Webcams anlegen, ändern, löschen
// =============================================================================

/** "Görlitz – Obermarkt" → "goerlitz-obermarkt" */
function slug(string $text): string
{
    $text = strtr(mb_strtolower($text, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'å' => 'a', 'ø' => 'o', 'č' => 'c', 'š' => 's', 'ž' => 'z']);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
}

function quelleOhnePrefix(string $quelle): string
{
    return (string) preg_replace('/^(html|mjpeg):/', '', $quelle);
}

/** Liefert den Namen der Webcam, die diese URL schon nutzt (außer $ausser). */
function quelleVergeben(string $quelle, int $ausser = 0): ?string
{
    $url = quelleOhnePrefix($quelle);
    foreach (db()->query('SELECT id, name, quelle FROM webcams') as $r) {
        if ((int) $r['id'] !== $ausser && quelleOhnePrefix($r['quelle']) === $url) {
            return $r['name'];
        }
    }
    return null;
}

/** Formulardaten prüfen → [bereinigte Daten, Fehlermeldungen] */
function pruefeEingaben(array $e, int $id = 0): array
{
    $d = [
        'titel'  => trim((string) ($e['titel'] ?? '')),
        'name'   => strtolower(trim((string) ($e['name'] ?? ''))),
        'quelle' => trim((string) ($e['quelle'] ?? '')),
        'gruppe' => trim((string) ($e['gruppe'] ?? '')),
        'notiz'  => trim((string) ($e['notiz'] ?? '')),
        'aktiv'  => empty($e['aktiv']) ? 0 : 1,
    ];
    if ($d['name'] === '') {
        $d['name'] = slug($d['titel']);
    }
    $fehler = [];
    if ($d['titel'] === '') {
        $fehler[] = 'Bitte einen Titel angeben.';
    }
    if (!preg_match(NAME_MUSTER, $d['name'])) {
        $fehler[] = 'Der Dateiname darf nur a–z, 0–9, „-“ und „_“ enthalten.';
    } else {
        $st = db()->prepare('SELECT id FROM webcams WHERE name = ? AND id <> ?');
        $st->execute([$d['name'], $id]);
        if ($st->fetchColumn()) {
            $fehler[] = "Der Dateiname „{$d['name']}“ ist schon vergeben.";
        }
    }
    if (!preg_match('#^((html|mjpeg):)?https?://\S+$#i', $d['quelle'])) {
        $fehler[] = 'Die URL muss mit http:// oder https:// beginnen und darf keine Leerzeichen enthalten.';
    } elseif (($andere = quelleVergeben($d['quelle'], $id)) !== null) {
        $fehler[] = "Diese URL ist bereits als „{$andere}“ eingetragen.";
    }
    return [$d, $fehler];
}

function legeAn(array $d): int
{
    db()->prepare('INSERT INTO webcams (name, titel, quelle, gruppe, notiz, aktiv, erstellt)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$d['name'], $d['titel'], $d['quelle'], $d['gruppe'], $d['notiz'], $d['aktiv'], time()]);
    return (int) db()->lastInsertId();
}

function aendere(array $alt, array $d): void
{
    $datei = $alt['datei'];
    if ($d['name'] !== $alt['name'] && $datei !== null) {
        // Umbenennen: Bild mitnehmen
        $neu = $d['name'] . '.' . pathinfo($datei, PATHINFO_EXTENSION);
        $datei = @rename(BILD_VERZ . "/$datei", BILD_VERZ . "/$neu") ? $neu : null;
    }
    $quelleNeu = $d['quelle'] !== $alt['quelle'];
    db()->prepare('UPDATE webcams SET name = ?, titel = ?, quelle = ?, gruppe = ?, notiz = ?, aktiv = ?, datei = ?'
                  . ($quelleNeu ? ", status = 'NEU', meldung = '', fehler_folge = 0" : '') . ' WHERE id = ?')
        ->execute([$d['name'], $d['titel'], $d['quelle'], $d['gruppe'], $d['notiz'], $d['aktiv'], $datei, $alt['id']]);
    if (!$d['aktiv']) {
        entferneBild($alt['id']);
    }
}

function setzeAktiv(int $id, bool $an): void
{
    db()->prepare('UPDATE webcams SET aktiv = ? WHERE id = ?')->execute([$an ? 1 : 0, $id]);
    if (!$an) {
        entferneBild($id);
    }
}

function loesche(int $id): void
{
    entferneBild($id);
    db()->prepare('DELETE FROM webcams WHERE id = ?')->execute([$id]);
}

function entferneBild(int $id): void
{
    $cam = webcamPerId($id);
    if ($cam && $cam['datei'] !== null) {
        @unlink(BILD_VERZ . '/' . $cam['datei']);
        db()->prepare('UPDATE webcams SET datei = NULL WHERE id = ?')->execute([$id]);
    }
}

// =============================================================================
// Download
// =============================================================================

function herkunft(string $url): string
{
    $t = parse_url($url);
    return ($t['scheme'] ?? 'http') . '://' . ($t['host'] ?? '') . (isset($t['port']) ? ':' . $t['port'] : '');
}

/** {utc:%H} bzw. {lokal:%Y-%m-%d} in der URL durch Datum/Uhrzeit ersetzen */
function ersetzePlatzhalter(string $url): string
{
    return (string) preg_replace_callback('/\{(utc|lokal):([^}]+)\}/', function (array $m): string {
        $zeichen = ['Y' => 'Y', 'y' => 'y', 'm' => 'm', 'd' => 'd', 'H' => 'H', 'M' => 'i', 'S' => 's'];
        return (string) preg_replace_callback('/%([YymdHMS])/', function (array $t) use ($m, $zeichen): string {
            return $m[1] === 'utc' ? gmdate($zeichen[$t[1]]) : date($zeichen[$t[1]]);
        }, $m[2]);
    }, $url);
}

/** Quelle → [typ (bild|html|mjpeg), url] */
function zerlegeQuelle(string $quelle): array
{
    if (preg_match('/^(html|mjpeg):(.*)$/s', $quelle, $m)) {
        return [$m[1], ersetzePlatzhalter($m[2])];
    }
    return ['bild', ersetzePlatzhalter($quelle)];
}

function absoluteUrl(string $src, string $basis): string
{
    if (preg_match('#^https?://#i', $src)) {
        return $src;
    }
    $t = parse_url($basis);
    if (substr($src, 0, 2) === '//') {
        return ($t['scheme'] ?? 'http') . ':' . $src;
    }
    if (substr($src, 0, 1) === '/') {
        return herkunft($basis) . $src;
    }
    $pfad = $t['path'] ?? '/';
    return herkunft($basis) . substr($pfad, 0, (int) strrpos($pfad, '/') + 1) . $src;
}

/** Erstes Webcam-JPG einer HTML-Seite (bevorzugt mit "cam" im Namen) */
function findeBild(string $html, string $seite): ?string
{
    if (!preg_match_all('/<img[^>]*\ssrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
        return null;
    }
    $kandidaten = [];
    foreach ($m[1] as $src) {
        $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/\.jpe?g([?#]|$)/i', $src)) {
            $kandidaten[] = $src;
        }
    }
    if (!$kandidaten) {
        return null;
    }
    $wahl = $kandidaten[0];
    foreach ($kandidaten as $src) {
        if (stripos($src, 'cam') !== false) {
            $wahl = $src;
            break;
        }
    }
    return absoluteUrl($wahl, $seite);
}

function neuerCurl(string $url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_ENCODING       => '',      // gzip & Co. annehmen und entpacken
        CURLOPT_FILETIME       => true,    // "Last-Modified" des Servers abfragen
        CURLOPT_MAXFILESIZE    => 30 * 1024 * 1024,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_REFERER        => herkunft($url) . '/',
        CURLOPT_HTTPHEADER     => [
            'Accept: image/jpeg,image/png,image/*;q=0.8,*/*;q=0.5',
            'Accept-Language: de-DE,de;q=0.9,en;q=0.6',
        ],
    ]);
    return $ch;
}

function curlFehler(int $code, int $http): string
{
    if ($code === 0) {
        return "HTTP-Fehler $http";
    }
    $texte = [6 => 'Host unbekannt', 7 => 'keine Verbindung', 28 => 'Zeitüberschreitung',
              35 => 'TLS-Fehler', 47 => 'zu viele Weiterleitungen', 52 => 'leere Antwort',
              56 => 'Verbindung abgebrochen', 60 => 'Zertifikat ungültig', 63 => 'Datei zu groß'];
    return $texte[$code] ?? "Fehler $code";
}

function griffId($handle): int
{
    return is_object($handle) ? spl_object_id($handle) : (int) $handle;
}

/**
 * Lädt alle Quellen parallel (curl_multi, ffmpeg für mjpeg:) und ruft für jede
 * fertige Quelle $fertig($schluessel, $ergebnis) auf – so liegen nie alle Bilder
 * gleichzeitig im Speicher.
 * $ergebnis: typ, url, daten | fehler, zeit_server (Last-Modified oder null), content_type
 */
function ladeAlle(array $quellen, callable $fertig): void
{
    $warteschlange = [];
    $streams = [];
    foreach ($quellen as $k => $quelle) {
        [$typ, $url] = zerlegeQuelle($quelle);
        if ($typ === 'mjpeg') {
            $streams[$k] = starteStream($url);
        } else {
            $warteschlange[] = ['k' => $k, 'typ' => $typ, 'url' => $url,
                                'stufe' => $typ === 'html' ? 'seite' : 'bild', 'versuch' => 1];
        }
    }

    $mh = curl_multi_init();
    $aktiv = [];
    $nachfuellen = function () use (&$warteschlange, &$aktiv, $mh): void {
        while (count($aktiv) < PARALLEL && $warteschlange) {
            $job = array_shift($warteschlange);
            $ch = neuerCurl($job['url']);
            curl_multi_add_handle($mh, $ch);
            $aktiv[griffId($ch)] = [$ch, $job];
        }
    };
    $nachfuellen();
    while ($aktiv) {
        curl_multi_exec($mh, $laeuft);
        if ($laeuft && curl_multi_select($mh, 1.0) === -1) {
            usleep(20000);
        }
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            [, $job] = $aktiv[griffId($ch)];
            unset($aktiv[griffId($ch)]);
            $code = (int) $info['result'];
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $typ  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $zeit = (int) curl_getinfo($ch, CURLINFO_FILETIME);
            $daten = $code === 0 ? (string) curl_multi_getcontent($ch) : '';
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $basis = ['typ' => $job['typ'], 'url' => $job['url'], 'content_type' => $typ];
            $stream = stripos($typ, 'multipart/x-mixed-replace') !== false;
            if ($code !== 0 || $http >= 400) {
                $voruebergehend = in_array($code, [7, 28, 52, 56], true) || in_array($http, [408, 429, 500, 502, 503, 504], true);
                if ($job['versuch'] === 1 && $voruebergehend && !$stream) {
                    $job['versuch'] = 2;              // einmal wiederholen, wie curl --retry 1
                    $warteschlange[] = $job;
                } else {
                    $fertig($job['k'], $basis + ['fehler' => curlFehler($code, $http), 'stream' => $stream]);
                }
            } elseif ($job['stufe'] === 'seite') {
                $bild = findeBild($daten, $job['url']);
                if ($bild === null) {
                    $fertig($job['k'], $basis + ['fehler' => 'kein Webcam-Bild auf der Seite gefunden']);
                } else {
                    $warteschlange[] = ['stufe' => 'bild', 'url' => $bild, 'versuch' => 1] + $job;
                }
            } else {
                $fertig($job['k'], $basis + ['daten' => $daten, 'zeit_server' => $zeit > 0 ? $zeit : null]);
            }
        }
        $nachfuellen();
    }
    curl_multi_close($mh);

    foreach ($streams as $k => $s) {
        $fertig($k, beendeStream($s));
    }
}

/** Ein Einzelbild aus einem MJPEG-Stream holen – läuft parallel zu den curl-Downloads */
function starteStream(string $url): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'webcam');
    $proc = @proc_open(
        ['timeout', (string) TIMEOUT, 'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
         '-user_agent', USER_AGENT, '-f', 'mjpeg', '-i', $url,
         '-frames:v', '1', '-q:v', '2', '-f', 'image2', '-update', '1', $tmp],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    return ['proc' => $proc, 'tmp' => $tmp, 'url' => $url];
}

function beendeStream(array $s): array
{
    $code = is_resource($s['proc']) ? proc_close($s['proc']) : 127;
    $daten = (string) @file_get_contents($s['tmp']);
    @unlink($s['tmp']);
    $basis = ['typ' => 'mjpeg', 'url' => $s['url'], 'content_type' => ''];
    if ($code !== 0 || $daten === '') {
        $text = $code === 124 ? 'Zeitüberschreitung (Stream)' : ($code === 127 ? 'ffmpeg nicht installiert' : 'Stream nicht lesbar');
        return $basis + ['fehler' => $text];
    }
    return $basis + ['daten' => $daten, 'zeit_server' => null];
}

/** Aufnahmezeit aus den EXIF-Daten eines JPEGs */
function exifZeit(string $daten): ?int
{
    $roh = null;
    if (function_exists('exif_read_data')) {
        $strom = fopen('php://memory', 'w+b');
        fwrite($strom, $daten);
        rewind($strom);
        $exif = @exif_read_data($strom);
        fclose($strom);
        if (is_array($exif)) {
            $roh = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
        }
    } elseif (preg_match('/\d{4}:\d\d:\d\d \d\d:\d\d:\d\d/', substr($daten, 0, 65536), $m)) {
        $roh = $m[0];
    }
    if (!is_string($roh)) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y:m:d H:i:s', trim($roh));
    return ($dt && (int) $dt->format('Y') >= 2000) ? $dt->getTimestamp() : null;
}

/**
 * Prüft ein Download-Ergebnis: echtes Bild? Größe? Aufnahmezeit?
 * Ergänzt endung, breite, hoehe, groesse, aufnahme, aufnahme_quelle (server|exif|keine)
 * bzw. fehler und ggf. vorschlag (html:/mjpeg:-Quelle).
 */
function pruefeBild(array $e, int $start): array
{
    if (!empty($e['stream']) && $e['typ'] === 'bild') {
        $e['vorschlag'] = 'mjpeg:' . $e['url'];
    }
    if (isset($e['fehler'])) {
        return $e;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($e['daten']);
    if (!isset(BILD_TYPEN[$mime])) {
        if ($e['typ'] === 'bild' && strpos($mime, 'text/') === 0 && findeBild($e['daten'], $e['url']) !== null) {
            $e['vorschlag'] = 'html:' . $e['url'];
        }
        return ['fehler' => "kein Bild ($mime)"] + $e;
    }
    if (strlen($e['daten']) < MIN_BYTES) {
        return ['fehler' => 'Datei zu klein (' . strlen($e['daten']) . ' Bytes)'] + $e;
    }
    $groesse = @getimagesizefromstring($e['daten']);
    if (!$groesse) {
        return ['fehler' => 'Bild beschädigt'] + $e;
    }
    $e += ['endung' => BILD_TYPEN[$mime], 'breite' => $groesse[0], 'hoehe' => $groesse[1], 'groesse' => strlen($e['daten'])];

    if ($e['zeit_server'] !== null) {
        [$e['aufnahme'], $e['aufnahme_quelle']] = [$e['zeit_server'], 'server'];
    } elseif ($mime === 'image/jpeg' && ($exif = exifZeit($e['daten'])) !== null) {
        [$e['aufnahme'], $e['aufnahme_quelle']] = [$exif, 'exif'];
    } else {
        [$e['aufnahme'], $e['aufnahme_quelle']] = [$start, 'keine'];
    }
    $e['aufnahme'] = min($e['aufnahme'], time());   // Uhren in der Zukunft kappen
    $e['veraltet'] = $e['aufnahme'] < $start - MAX_ALTER_MIN * 60;
    return $e;
}

/**
 * Geprüftes Ergebnis übernehmen: Bild atomar nach BILD_VERZ schreiben, wenn es neu ist.
 * Liefert status (OK, GLEICH, ALT, FEHLER), meldung, aufnahme, datei.
 */
function verarbeite(array $cam, array $e, int $start): array
{
    if (isset($e['fehler'])) {
        return ['status' => 'FEHLER', 'meldung' => $e['fehler']];
    }
    $name = $cam['name'];
    $datei = "$name.{$e['endung']}";
    $ziel = BILD_VERZ . "/$datei";
    if (is_file($ziel) && file_get_contents($ziel) === $e['daten']) {
        // Zeitstempel bleibt → Alter der Aufnahme bleibt sichtbar
        return ['status' => 'GLEICH', 'meldung' => 'Bild unverändert', 'aufnahme' => (int) filemtime($ziel), 'datei' => $datei];
    }
    $zeit = $e['aufnahme'];
    if ($e['veraltet']) {
        // Ändert sich das Bild trotz alter Zeitangabe, lebt die Kamera – nur ihre Uhr geht falsch
        $ref = DATEN_VERZ . "/veraltet/$datei";
        $lebt = is_file($ziel) || (is_file($ref) && file_get_contents($ref) !== $e['daten']);
        if (!$lebt) {
            @mkdir(dirname($ref), 0775);
            @file_put_contents($ref, $e['daten']);
            $quelle = $e['aufnahme_quelle'] === 'exif' ? 'EXIF' : 'Server';
            return ['status' => 'ALT', 'aufnahme' => $zeit,
                    'meldung' => 'veraltet: Aufnahme vom ' . date('d.m.Y H:i', $zeit) . " (laut $quelle)"];
        }
        $zeit = $start;
    }
    if (!is_dir(BILD_VERZ)) {
        @mkdir(BILD_VERZ, 0775, true);
    }
    $tmp = BILD_VERZ . '/.neu-' . $datei;
    if (@file_put_contents($tmp, $e['daten']) === false) {
        return ['status' => 'FEHLER', 'meldung' => 'Keine Schreibrechte für ' . BILD_VERZ];
    }
    chmod($tmp, 0644);
    touch($tmp, $zeit);
    rename($tmp, $ziel);                              // atomar: die Slideshow sieht nie ein halbes Bild
    foreach (BILD_TYPEN as $endung) {
        if ($endung !== $e['endung']) {
            @unlink(BILD_VERZ . "/$name.$endung");
        }
    }
    @unlink(DATEN_VERZ . "/veraltet/$datei");
    return ['status' => 'OK', 'meldung' => '', 'aufnahme' => $zeit, 'datei' => $datei];
}

function speichereStatus(int $id, array $s, int $jetzt): void
{
    $sql = 'UPDATE webcams SET zuletzt_versuch = :j, status = :st, meldung = :m';
    $p = [':j' => $jetzt, ':st' => $s['status'], ':m' => $s['meldung'], ':id' => $id];
    if ($s['status'] === 'FEHLER') {
        $sql .= ', fehler_folge = fehler_folge + 1';
    } else {
        $sql .= ', zuletzt_ok = :j, fehler_folge = 0';
        $p[':a'] = $s['aufnahme'];
        if ($s['status'] === 'OK') {
            $sql .= ', zuletzt_neu = :a, datei = :d, breite = :b, hoehe = :h';
            $p += [':d' => $s['datei'], ':b' => $s['breite'] ?? null, ':h' => $s['hoehe'] ?? null];
        } elseif ($s['status'] === 'GLEICH') {
            $sql .= ', zuletzt_neu = COALESCE(zuletzt_neu, :a), datei = :d';
            $p[':d'] = $s['datei'];
        } else { // ALT
            $sql .= ', zuletzt_neu = MAX(COALESCE(zuletzt_neu, 0), :a)';
        }
    }
    db()->prepare("$sql WHERE id = :id")->execute($p);
}

/** Lädt die angegebenen Webcams, aktualisiert Bilder und Status. Liefert id → Status. */
function ladeWebcams(array $cams): array
{
    $start = time();
    $nachId = [];
    $quellen = [];
    foreach ($cams as $c) {
        $nachId[$c['id']] = $c;
        $quellen[$c['id']] = $c['quelle'];
    }
    $alle = [];
    ladeAlle($quellen, function ($id, array $e) use (&$alle, $nachId, $start): void {
        $p = pruefeBild($e, $start);
        $s = verarbeite($nachId[$id], $p, $start) + ['breite' => $p['breite'] ?? null, 'hoehe' => $p['hoehe'] ?? null];
        speichereStatus((int) $id, $s, $start);
        $alle[$id] = $s + ['url' => $p['url']];
    });
    return $alle;
}

/** Eine Quelle testen, ohne etwas zu speichern (Verwaltung, Kommandozeile) */
function testeQuelle(string $quelle): array
{
    $start = time();
    $r = [];
    ladeAlle(['test' => $quelle], function ($k, array $e) use (&$r, $start): void {
        $r = pruefeBild($e, $start);
    });
    return $r + ['start' => $start];
}

/** Bilder entfernen, die zu keiner aktiven Webcam gehören oder zu alt sind */
function raeumeAuf(bool $alteLoeschen): array
{
    $meldungen = [];
    $erlaubt = [];
    foreach (alleWebcams('aktiv = 1 AND datei IS NOT NULL') as $c) {
        $erlaubt[$c['datei']] = $c['id'];
    }
    $grenze = time() - MAX_ALTER_MIN * 60;
    foreach (is_dir(BILD_VERZ) ? scandir(BILD_VERZ) : [] as $f) {
        $pfad = BILD_VERZ . "/$f";
        if (!is_file($pfad)) {
            continue;
        }
        if ($f[0] === '.') {                          // Reste abgebrochener Läufe
            if (strpos($f, '.neu-') === 0 && filemtime($pfad) < time() - 3600) {
                @unlink($pfad);
            }
            continue;
        }
        if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), BILD_TYPEN, true)) {
            continue;
        }
        if (!isset($erlaubt[$f])) {
            @unlink($pfad);
            $meldungen[] = "Entfernt (keine aktive Webcam): $f";
        } elseif ($alteLoeschen && filemtime($pfad) < $grenze) {
            @unlink($pfad);
            $meldungen[] = "Entfernt (veraltet): $f";
        }
    }
    foreach ($erlaubt as $datei => $id) {
        if (!is_file(BILD_VERZ . "/$datei")) {
            db()->prepare('UPDATE webcams SET datei = NULL WHERE id = ?')->execute([$id]);
        }
    }
    foreach (glob(DATEN_VERZ . '/veraltet/*') ?: [] as $ref) {
        if (filemtime($ref) < time() - 7 * 86400) {
            @unlink($ref);
        }
    }
    return $meldungen;
}

/** Kompletter Download-Lauf (Cron). Liefert null, wenn schon ein Lauf aktiv ist. */
function downloadLauf(): ?array
{
    $sperre = fopen(DATEN_VERZ . '/download.lock', 'c');
    if (!$sperre || !flock($sperre, LOCK_EX | LOCK_NB)) {
        return null;
    }
    $start = time();
    $cams = alleWebcams('aktiv = 1');
    $status = ladeWebcams($cams);
    $z = ['OK' => 0, 'GLEICH' => 0, 'ALT' => 0, 'FEHLER' => 0];
    foreach ($status as $s) {
        $z[$s['status']]++;
    }
    // Veraltete Bilder nur löschen, wenn überhaupt etwas geklappt hat
    // (fällt das Netz aus, bleiben die letzten Bilder lieber stehen)
    $aufgeraeumt = raeumeAuf($z['OK'] + $z['GLEICH'] > 0);

    db()->prepare('INSERT INTO laeufe (start, dauer, anzahl, ok, gleich, veraltet, fehler) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$start, time() - $start, count($cams), $z['OK'], $z['GLEICH'], $z['ALT'], $z['FEHLER']]);
    db()->exec('DELETE FROM laeufe WHERE id NOT IN (SELECT id FROM laeufe ORDER BY start DESC LIMIT 500)');

    $text = [sprintf('%s  %d Webcams: %d aktualisiert, %d unverändert, %d veraltet, %d Fehler (%d s)',
        date('Y-m-d H:i:s', $start), count($cams), $z['OK'], $z['GLEICH'], $z['ALT'], $z['FEHLER'], time() - $start)];
    $namen = array_column($cams, 'name', 'id');
    asort($namen);
    foreach ($namen as $id => $name) {
        $s = $status[$id];
        if ($s['status'] === 'FEHLER' || $s['status'] === 'ALT') {
            $text[] = sprintf('  %-9s %-30s %s  %s', $s['status'] === 'ALT' ? 'VERALTET' : 'FEHLER', $name, $s['meldung'], $s['url']);
        }
    }
    foreach ($aufgeraeumt as $m) {
        $text[] = "  $m";
    }
    protokolliere($text);
    flock($sperre, LOCK_UN);
    return ['text' => $text, 'zaehler' => $z];
}

function protokolliere(array $zeilen): void
{
    $datei = DATEN_VERZ . '/download.log';
    if (is_file($datei) && filesize($datei) > 2 * 1024 * 1024) {   // auf ~1 MB kürzen
        $rest = (string) file_get_contents($datei, false, null, filesize($datei) - 1024 * 1024);
        file_put_contents($datei, substr($rest, (int) strpos($rest, "\n") + 1));
    }
    file_put_contents($datei, implode("\n", $zeilen) . "\n", FILE_APPEND | LOCK_EX);
}

// =============================================================================
// Import / Export im Textformat (webcams.txt)
// =============================================================================

/**
 * Format je Zeile: name  quelle  Titel …
 *   "# --- Gruppe ---"     beginnt eine Gruppe
 *   "#aus DATUM name …"    ausgeschaltete Webcam
 *   "# Text" direkt über einem Eintrag wird zur Notiz
 */
function leseTextliste(string $text): array
{
    $cams = [];
    $gruppe = '';
    $notiz = [];
    foreach (preg_split('/\r?\n/', $text) as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '') {
            $notiz = [];
            continue;
        }
        if (preg_match('/^#\s*-{2,}\s*(.*?)\s*-*\s*$/u', $zeile, $m)) {
            $gruppe = (string) preg_replace('/\s*\(.*\)$/', '', $m[1]);
            $notiz = [];
            continue;
        }
        $aktiv = 1;
        if (preg_match('/^#aus\s+\S+\s+(.*)$/', $zeile, $m)) {
            [$zeile, $aktiv] = [$m[1], 0];
        } elseif (preg_match('#^\#([a-z0-9][a-z0-9_-]*\s+((html|mjpeg):)?https?://.*)$#', $zeile, $m)) {
            [$zeile, $aktiv] = [$m[1], 0];               // auskommentierter Eintrag
        } elseif ($zeile[0] === '#') {
            $notiz[] = ltrim(substr($zeile, 1));
            continue;
        }
        $teile = preg_split('/\s+/', $zeile, 3);
        if (count($teile) < 2) {
            continue;
        }
        $cams[] = ['name' => $teile[0], 'quelle' => $teile[1], 'titel' => $teile[2] ?? '',
                   'gruppe' => $gruppe, 'notiz' => implode("\n", $notiz), 'aktiv' => $aktiv];
        $notiz = [];
    }
    return $cams;
}

function schreibeTextliste(): string
{
    $aus = ["# Webcam-Liste – exportiert am " . date('d.m.Y H:i'),
            "# Format: name  quelle  Titel   (Import: php webcam-cli.php import DATEI)"];
    $gruppe = null;
    foreach (alleWebcams() as $c) {
        if ($c['gruppe'] !== $gruppe) {
            $gruppe = $c['gruppe'];
            $aus[] = '';
            $aus[] = '# --- ' . ($gruppe !== '' ? $gruppe : 'Ohne Gruppe') . ' ---';
        }
        foreach ($c['notiz'] !== '' ? explode("\n", $c['notiz']) : [] as $n) {
            $aus[] = '# ' . $n;
        }
        $aus[] = ($c['aktiv'] ? '' : '#aus ' . date('Y-m-d') . ' ')
            . str_pad($c['name'], 30) . ' ' . $c['quelle'] . ($c['titel'] !== '' ? '  ' . $c['titel'] : '');
    }
    return implode("\n", $aus) . "\n";
}

// =============================================================================
// Anzeige-Helfer
// =============================================================================

function alterText(int $sekunden): string
{
    if ($sekunden < 60) {
        return 'gerade eben';
    }
    if ($sekunden < 3600) {
        return 'vor ' . intdiv($sekunden, 60) . ' Min.';
    }
    if ($sekunden < 48 * 3600) {
        return 'vor ' . intdiv($sekunden, 3600) . ' Std.';
    }
    return 'vor ' . intdiv($sekunden, 86400) . ' Tagen';
}
