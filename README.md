# Webcam-Slideshow

Lädt regelmäßig Webcam-Bilder herunter und zeigt sie als Vollbild-Slideshow im Browser eines Tablets.

| Datei | Zweck | ersetzt |
|---|---|---|
| `webcam-download.sh` | lädt alle Webcams aus `webcams.txt` nach `bilder/` | `picsdownload2.sh` |
| `webcams.txt` | Liste: Name, Quelle, Anzeigetitel | `pics.txt` + Sonderfälle im Skript |
| `index.php` | Slideshow fürs Tablet | `webcam8.php` |
| `favorites.txt` | mit ♥ gemerkte Bilder (wird automatisch angelegt) | |

## Installation

```sh
sudo apt install curl file util-linux ffmpeg     # ffmpeg nur für die Regensburg-Streams
cd /var/www/html/webcam
# index.php, webcam-download.sh und webcams.txt hierher kopieren
chmod +x webcam-download.sh
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

## Bedienung

* Tippen rechts / links: nächstes / vorheriges Bild, Wischen ebenso
* Tippen in die Mitte: Steuerung (Pause, ♥ Merken, ★ nur Favoriten, Darstellung, Neu mischen, Vollbild)
* Darstellung **Auto**: füllt den Bildschirm, wenn dabei höchstens 10 % abgeschnitten würden,
  sonst wird das ganze Bild maximal groß gezeigt (Ränder mit unscharfem Bildhintergrund).
  Alternativ fest **Ganzes Bild** oder **Füllen**.
* Unten links stehen der Titel und das Alter der Aufnahme (orange ab 2 Stunden).

Anzeigedauer: `ANZEIGE_SEKUNDEN` in `index.php`, weitere Einstellungen im `CFG`-Block.
