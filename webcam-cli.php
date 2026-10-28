<?php
/*
 * webcam-cli.php – Download per Cron und Verwaltung auf der Kommandozeile
 *
 * Immer als Webserver-Benutzer aufrufen, damit Datenbank und Bilder ihm gehören:
 *   sudo -u www-data php /var/www/html/webcam/webcam-cli.php BEFEHL
 *
 * Cron (/etc/cron.d/webcam):
 *   0,30 6-20 * * * www-data php /var/www/html/webcam/webcam-cli.php download >/dev/null 2>&1
 * Das Protokoll steht in DATEN_VERZ/download.log und in der Verwaltung (admin.php).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/inc/webcam.php';

function hilfe(): void
{
    echo <<<TEXT
Aufruf: php webcam-cli.php BEFEHL …

  download                  alle aktiven Webcams laden (für Cron)
  test URL                  Download einer URL testen, ohne etwas zu speichern
  import DATEI [FAVORITEN]  Webcams aus Textliste übernehmen (z. B. webcams.txt),
                            optional favorites.txt der alten Slideshow
  export                    alle Webcams als Textliste ausgeben (Sicherung)
  passwort                  Passwort für die Verwaltung setzen

TEXT;
}

function fehler(string $text): void
{
    fwrite(STDERR, "Fehler: $text\n");
    exit(1);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fehler("Bitte nicht als root ausführen, sonst gehören Datenbank und Bilder root.\n"
         . "       Stattdessen: sudo -u www-data php " . $argv[0] . ' ' . implode(' ', array_slice($argv, 1)));
}
try {
    db();
} catch (Throwable $e) {
    fehler($e->getMessage() . "\n       Einrichten: sudo mkdir -p " . DATEN_VERZ . ' && sudo chown www-data: ' . DATEN_VERZ);
}

$befehl = $argv[1] ?? 'hilfe';
switch ($befehl) {
    case 'download':
        $ergebnis = downloadLauf();
        if ($ergebnis === null) {
            echo "Download läuft bereits – Abbruch.\n";
            exit(0);
        }
        echo implode("\n", $ergebnis['text']), "\n";
        break;

    case 'test':
        if (!isset($argv[2])) {
            fehler('Aufruf: php webcam-cli.php test URL');
        }
        $r = testeQuelle($argv[2]);
        echo "Teste {$r['url']} …\n";
        if (isset($r['fehler'])) {
            echo "✘ {$r['fehler']}\n";
            if (isset($r['vorschlag'])) {
                echo "  → Vorschlag: {$r['vorschlag']}\n";
            }
            exit(1);
        }
        printf("✔ Bild OK: %s, %dx%d Pixel, %d KB\n", strtoupper($r['endung']), $r['breite'], $r['hoehe'], intdiv($r['groesse'], 1024));
        if ($r['aufnahme_quelle'] === 'keine') {
            echo "  Aufnahmezeit: unbekannt (Server sendet kein Datum, keine EXIF-Daten)\n";
        } else {
            printf("  Aufnahmezeit: %s – %s (laut %s)\n", date('d.m.Y H:i', $r['aufnahme']),
                alterText($r['start'] - $r['aufnahme']), $r['aufnahme_quelle'] === 'exif' ? 'EXIF-Daten' : 'Server');
        }
        if ($r['veraltet']) {
            echo "⚠ Das Bild ist veraltet – die Webcam ist vermutlich außer Betrieb.\n";
        }
        break;

    case 'import':
        if (!isset($argv[2]) || !is_readable($argv[2])) {
            fehler('Aufruf: php webcam-cli.php import DATEI [FAVORITEN]  (Datei lesbar?)');
        }
        $favoriten = [];
        if (isset($argv[3]) && is_readable($argv[3])) {
            foreach (file($argv[3], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $f) {
                $favoriten[pathinfo(trim($f), PATHINFO_FILENAME)] = true;
            }
        }
        $neu = $uebersprungen = 0;
        foreach (leseTextliste((string) file_get_contents($argv[2])) as $c) {
            [$d, $f] = pruefeEingaben($c + ['aktiv' => $c['aktiv']]);
            if ($d['titel'] === '') {
                $d['titel'] = ucwords(str_replace(['-', '_'], ' ', $d['name']));
                [$d, $f] = pruefeEingaben($d);
            }
            if ($f) {
                echo "Übersprungen: {$c['name']} – " . implode(' ', $f) . "\n";
                $uebersprungen++;
                continue;
            }
            $id = legeAn($d);
            if (isset($favoriten[$d['name']])) {
                db()->prepare('UPDATE webcams SET favorit = 1 WHERE id = ?')->execute([$id]);
            }
            // vorhandenes Bild (z. B. von webcam-download.sh) gleich übernehmen
            foreach (BILD_TYPEN as $endung) {
                $pfad = BILD_VERZ . "/{$d['name']}.$endung";
                if ($d['aktiv'] && is_file($pfad)) {
                    db()->prepare("UPDATE webcams SET datei = ?, zuletzt_neu = ?, status = 'GLEICH' WHERE id = ?")
                        ->execute([basename($pfad), filemtime($pfad), $id]);
                    break;
                }
            }
            $neu++;
        }
        echo "$neu Webcams importiert, $uebersprungen übersprungen.\n";
        break;

    case 'export':
        echo schreibeTextliste();
        break;

    case 'passwort':
        echo 'Neues Passwort für die Verwaltung: ';
        @system('stty -echo');
        $pw = trim((string) fgets(STDIN));
        @system('stty echo');
        echo "\n";
        if (strlen($pw) < 6) {
            fehler('Mindestens 6 Zeichen.');
        }
        setzeEinstellung('admin_passwort', password_hash($pw, PASSWORD_DEFAULT));
        echo "Passwort gespeichert.\n";
        break;

    default:
        hilfe();
        exit($befehl === 'hilfe' ? 0 : 1);
}
