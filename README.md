# Webcam-Slideshow

Lädt regelmäßig Webcam-Bilder herunter und zeigt sie als Vollbild-Slideshow im Browser eines Tablets.

| Datei | Zweck | ersetzt |
|---|---|---|
| `webcam-download.sh` | lädt alle Webcams aus `webcams.txt` nach `bilder/` | `picsdownload2.sh` |
| `webcam-verwalten.sh` | Webcams testen, hinzufügen, abschalten, entfernen | |
| `webcam-funktionen.sh` | gemeinsame Einstellungen (User-Agent, Pfade) und Funktionen | |
| `webcams.txt` | Liste: Name, Quelle, Anzeigetitel | `pics.txt` + Sonderfälle im Skript |
| `webcams.status` | wann jede Webcam zuletzt ein neues Bild lieferte (automatisch) | |
| `index.php` | Slideshow fürs Tablet | `webcam8.php` |
| `favorites.txt` | mit ♥ gemerkte Bilder (wird automatisch angelegt) | |

## Installation

```sh
sudo apt install curl file util-linux ffmpeg     # ffmpeg nur für die Regensburg-Streams
cd /var/www/html/webcam
# index.php, webcam-download.sh und webcams.txt hierher kopieren
chmod +x webcam-download.sh webcam-verwalten.sh
mkdir -p bilder
touch favorites.txt                                   # PHP muss Favoriten schreiben dürfen:
sudo chown www-data: favorites.txt                    #   mit Root-Rechten
# chmod 666 favorites.txt                             #   oder ohne sudo
./webcam-download.sh                                  # erster Lauf, zeigt Fehlerliste
```

Cron (alle 10 Minuten) – den alten Eintrag für `picsdownload2.sh` entfernen:

```
*/10 * * * * /var/www/html/webcam/webcam-download.sh >> /var/log/webcam-download.log 2>&1
```

Auf dem Tablet `http://<server>/webcam/` aufrufen (statt `webcam8.php`).
Die alten nummerierten Bilder (`10.jpg`, `11.jpg`, …) in `/var/www/html/webcam/` können gelöscht werden.

## Webcams verwalten

```sh
./webcam-verwalten.sh test URL                # nur ausprobieren
./webcam-verwalten.sh neu NAME URL [Titel]    # testen und bei Erfolg eintragen
./webcam-verwalten.sh defekt                  # was liefert seit 2 Tagen nichts Neues?
./webcam-verwalten.sh aufraeumen              # defekte einzeln durchgehen: aus/entfernen/testen/behalten
./webcam-verwalten.sh aus NAME | an NAME | entfernen NAME
```

`neu` trägt nur ein, wenn sich das Bild mit genau demselben curl-Aufruf wie im echten
Download laden lässt und wirklich ein Bild ist. Liefert die Adresse eine Webseite oder
einen Videostream, schlägt der Test `html:` bzw. `mjpeg:` vor.
Ausgeschaltete Webcams bleiben als `#aus DATUM …` in der Liste und lassen sich mit `an`
zurückholen. Vor jeder Änderung wird `webcams.txt.bak` angelegt.

`defekt` findet Webcams, die noch nie ein Bild geliefert haben (gleich nach dem ersten
Download-Lauf), sowie tote **und** eingefrorene Webcams, die seit X Tagen kein neues
Bild gebracht haben (`defekt 7` für eine Woche).

### Veraltete Bilder

Manche Webcams liefern noch ein Bild, das aber seit Monaten nicht mehr aktualisiert wurde.
Die Aufnahmezeit wird deshalb bei jedem Download ermittelt – aus dem `Last-Modified`-Datum
des Servers, ersatzweise aus den EXIF-Daten des Bildes. Ist die Aufnahme älter als 24 Stunden
(`MAX_ALTER_MIN`), kommt das Bild nicht in die Slideshow und die Webcam steht sofort unter
`defekt`, z. B. „veraltet: Aufnahme vom 25.06.2026“. Ändert sich das Bild trotz alter
Zeitangabe zwischen zwei Läufen, geht nur die Uhr der Kamera falsch – dann wird es normal
angezeigt. In der Slideshow zeigt „Stand“ die echte Aufnahmezeit.

Prüfen, was ein Server meldet: `curl -sI URL | grep -i last-modified`

## Bedienung

* Tippen rechts / links: nächstes / vorheriges Bild, Wischen ebenso
* Tippen in die Mitte: Steuerung (Pause, ♥ Merken, ★ nur Favoriten, Darstellung, Neu mischen, Vollbild)
* Darstellung **Auto**: füllt den Bildschirm, wenn dabei höchstens 10 % abgeschnitten würden,
  sonst wird das ganze Bild maximal groß gezeigt (Ränder mit unscharfem Bildhintergrund).
  Alternativ fest **Ganzes Bild** oder **Füllen**.
* Unten links stehen der Titel und das Alter der Aufnahme (orange ab 2 Stunden).

Anzeigedauer: `ANZEIGE_SEKUNDEN` in `index.php`, weitere Einstellungen im `CFG`-Block.
