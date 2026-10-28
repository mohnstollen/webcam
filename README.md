# Webcam-Slideshow

Lädt regelmäßig Webcam-Bilder herunter, zeigt sie als Vollbild-Slideshow auf einem Tablet
und lässt sich komplett im Browser verwalten.

| Datei | Zweck |
|---|---|
| `index.php` | Slideshow fürs Tablet |
| `admin.php` | Verwaltung im Browser: hinzufügen (mit Download-Test), bearbeiten, ein-/ausschalten, löschen, Probleme aufräumen, Protokoll |
| `webcam-cli.php` | Download per Cron; Import/Export, Test und Passwort auf der Kommandozeile |
| `inc/webcam.php` | gemeinsamer Kern: Einstellungen, Datenbank, Download |
| `webcams.txt` | Startliste für den ersten Import |

Webcams, Status und Favoriten liegen in einer SQLite-Datenbank (`/var/lib/webcam/webcams.sqlite`).
Sie verträgt gleichzeitige Zugriffe von Slideshow, Verwaltung und Cron-Download, braucht keinen
Datenbankserver und besteht aus einer einzigen Datei. Als Sicherung oder zum Bearbeiten im Editor
lässt sich alles jederzeit als Textliste exportieren und wieder importieren.

## Umstieg von der Version mit bash-Skripten

```sh
# 1. Pakete (php-sqlite3 und php-curl werden neu gebraucht)
sudo apt install php-cli php-sqlite3 php-curl ffmpeg
sudo systemctl restart apache2          # bzw. den Webserver/php-fpm, den du nutzt

# 2. Aktuelle Liste sichern – sie kann seit der Installation geändert worden sein
cd /var/www/html/webcam
cp webcams.txt ~/webcams-alt.txt

# 3. Neue Dateien holen (git pull oder kopieren): index.php, admin.php, webcam-cli.php, inc/

# 4. Datenverzeichnis anlegen; Bilder gehören künftig dem Webserver-Benutzer
sudo mkdir -p /var/lib/webcam
sudo chown www-data: /var/lib/webcam
sudo chown -R www-data: bilder

# 5. Liste und Favoriten übernehmen
sudo -u www-data php webcam-cli.php import ~/webcams-alt.txt favorites.txt

# 6. Cron umstellen: alten Eintrag (webcam-download.sh) mit "crontab -e" löschen, dann:
echo '*/30 6-20 * * * www-data php /var/www/html/webcam/webcam-cli.php download >/dev/null 2>&1' \
  | sudo tee /etc/cron.d/webcam

# 7. Erster Lauf von Hand
sudo -u www-data php webcam-cli.php download

# 8. Alte Dateien entfernen
rm -f webcam-download.sh webcam-verwalten.sh webcam-funktionen.sh webcams.status webcams.txt.bak \
      favorites.txt bilder/titel.tsv
```

Danach `http://<server>/webcam/admin.php` öffnen und beim ersten Aufruf ein Passwort festlegen.
`/var/log/webcam-download.log` wird nicht mehr gebraucht. Das Protokoll steht jetzt in
`/var/lib/webcam/download.log` und in der Verwaltung unter „Protokoll“.

**Wichtig:** `webcam-cli.php` immer als `www-data` aufrufen (`sudo -u www-data php …`). Als root
verweigert das Skript den Start. Sonst gehörten Datenbank und Bilder root, und Webserver bzw.
Cron-Job dürften sie nicht mehr ändern.

## Verwaltung im Browser (`admin.php`)

* **Hinzufügen:** URL eintragen und „Testen“ drücken. Angezeigt werden eine Vorschau, Format,
  Auflösung und Aufnahmezeit. Liefert die Adresse eine Webseite oder einen Videostream, schlägt der
  Test die passende Einstellung vor und übernimmt sie per Klick. Der Dateiname entsteht automatisch
  aus dem Titel. Beim Speichern wird der Download **immer** geprüft. Nur wenn er klappt, wird die
  Webcam eingetragen, und ihr Bild erscheint sofort in der Slideshow. Bei veralteten Bildern ist
  eine ausdrückliche Bestätigung nötig.
* **Probleme:** Webcams mit Fehler, mit veraltetem Bild oder ohne neues Bild seit 2 Tagen,
  die ältesten zuerst. Mit „alle auswählen“ → „Ausschalten“ oder „Löschen“ lassen sie sich in einem
  Rutsch aufräumen.
* **Ausgeschaltet** bleiben Webcams gespeichert und lassen sich mit „Ein“ zurückholen.
* Suche und Gruppenfilter, „⟳ Laden“ für einen sofortigen Download, ♥ für Favoriten.
* **Protokoll:** die letzten Download-Läufe und `download.log`. Ist der letzte Lauf länger als
  2 Stunden her, warnt die Übersicht („Läuft der Cron-Job?“).
* **Export** lädt alle Webcams als Textliste herunter (Sicherung).

## Kommandozeile

```sh
sudo -u www-data php webcam-cli.php download             # alle aktiven Webcams laden (Cron)
sudo -u www-data php webcam-cli.php test URL             # Download testen
sudo -u www-data php webcam-cli.php export > sicherung.txt
sudo -u www-data php webcam-cli.php import sicherung.txt # vorhandene Namen werden übersprungen
sudo -u www-data php webcam-cli.php passwort             # Passwort der Verwaltung neu setzen
```

Textformat: eine Webcam pro Zeile, `name  quelle  Titel`. `# --- Gruppe ---` beginnt eine
Gruppe, `#aus DATUM …` markiert ausgeschaltete Webcams, Kommentarzeilen direkt darüber werden zur
Notiz. Quellen mit `html:` (Bild aus Webseite) oder `mjpeg:` (Videostream) davor sind möglich,
ebenso Platzhalter wie `{utc:%H}` für die aktuelle UTC-Stunde.

## Veraltete und defekte Webcams

Bei jedem Download wird die Aufnahmezeit ermittelt: aus dem `Last-Modified`-Datum des Servers,
ersatzweise aus den EXIF-Daten. Aufnahmen, die älter als 24 Stunden sind, kommen nicht in die
Slideshow. Ändert sich ein Bild trotz alter Zeitangabe zwischen zwei Läufen, geht nur die Uhr der
Kamera falsch, und das Bild wird normal gezeigt. Bilder, die sich 24 Stunden nicht ändern,
verschwinden ebenfalls aus der Slideshow. In der Slideshow zeigt „Stand“ die echte Aufnahmezeit.

## Slideshow (`index.php`)

* Tippen rechts/links oder Wischen: nächstes/vorheriges Bild
* Tippen in die Mitte: Steuerung (Pause, ♥ Merken, ★ nur Favoriten, Darstellung, Neu mischen,
  Vollbild, ⚙ Verwaltung)
* Darstellung **Auto**: Das Bild füllt den Bildschirm, wenn dabei höchstens 10 % abgeschnitten
  würden. Sonst wird es ganz und maximal groß gezeigt, mit unscharfem Bildhintergrund an den
  Rändern. Alternativ fest **Ganzes Bild** oder **Füllen**.

## Einstellungen

Standardwerte stehen oben in `inc/webcam.php`. Eigene Werte gehören in `inc/konfig.local.php`
(wird von Updates nicht überschrieben), z. B.:

```php
<?php
define('ANZEIGE_SEKUNDEN', 30);   // Anzeigedauer pro Bild
define('MAX_ALTER_MIN', 720);     // Bilder schon nach 12 Stunden als veraltet behandeln
define('DEFEKT_TAGE', 3);         // erst nach 3 Tagen ohne neues Bild unter „Probleme“
```
