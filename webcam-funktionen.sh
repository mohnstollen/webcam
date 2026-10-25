# webcam-funktionen.sh – gemeinsame Einstellungen und Funktionen für
# webcam-download.sh und webcam-verwalten.sh (wird per "source" eingebunden).
#
# Vor dem Einbinden muss SKRIPT_VERZ gesetzt sein.
# Alle Einstellungen lassen sich per Umgebungsvariable überschreiben.

LISTE=${LISTE:-$SKRIPT_VERZ/webcams.txt}            # Liste der Webcams
STATUS=${STATUS:-$SKRIPT_VERZ/webcams.status}       # wann lieferte welche Webcam zuletzt ein Bild
ZIEL_VERZ=${ZIEL_VERZ:-/var/www/html/webcam/bilder} # hier liest index.php die Bilder
TIMEOUT=${TIMEOUT:-20}                              # Sekunden pro Download
MIN_BYTES=${MIN_BYTES:-2000}                        # kleinere Dateien gelten als defekt
MAX_ALTER_MIN=${MAX_ALTER_MIN:-1440}                # ältere Aufnahmen gelten als veraltet (24 h)
# Aktueller Firefox (ESR). Manche Server (z. B. Holzhau, Zittau) liefern ohne
# Browser-Kennung nichts aus. Bei Bedarf auf eine aktuelle Version anheben.
USER_AGENT=${USER_AGENT:-"Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0"}

BILD_ENDUNGEN=(jpg png gif webp)
NAME_MUSTER='^[a-z0-9][a-z0-9_-]*$'

herkunft() { # "https://host:port" einer URL
    [[ $1 =~ ^(https?://[^/?#]+) ]] && printf '%s' "${BASH_REMATCH[1]}"
}

lade() { # url ziel   (ziel "-" = stdout)
    # --remote-time: Dateidatum = "Last-Modified" des Servers (falls gesendet)
    curl --fail --silent --location --compressed --remote-time \
         --connect-timeout 10 --max-time "$TIMEOUT" --retry 1 --retry-delay 2 \
         --user-agent "$USER_AGENT" \
         --header "Accept: image/jpeg,image/png,image/*;q=0.8,*/*;q=0.5" \
         --header "Accept-Language: de-DE,de;q=0.9,en;q=0.6" \
         --referer "$(herkunft "$1")/" \
         --output "$2" "$1"
}

curl_fehler() { # Klartext zu den häufigsten curl-Exitcodes
    case $1 in
        6)  echo "Host unbekannt" ;;
        7)  echo "keine Verbindung" ;;
        22) echo "HTTP-Fehler (404/403/…)" ;;
        28) echo "Zeitüberschreitung" ;;
        35|60) echo "TLS/Zertifikat-Fehler" ;;
        124) echo "Zeitüberschreitung (Stream)" ;;
        *)  echo "Fehler $1" ;;
    esac
}

ersetze_platzhalter() { # {utc:%H} / {lokal:%Y} → date-Ausgabe
    local url=$1 re='\{(utc|lokal):([^}]+)\}' ganz zone format wert
    while [[ $url =~ $re ]]; do
        ganz=${BASH_REMATCH[0]} zone=${BASH_REMATCH[1]} format=${BASH_REMATCH[2]}
        if [[ $zone == utc ]]; then wert=$(date -u +"$format"); else wert=$(date +"$format"); fi
        url=${url//"$ganz"/$wert}
    done
    printf '%s' "$url"
}

zerlege_quelle() { # quelle → "typ url" (typ: bild, html, mjpeg; Platzhalter ersetzt)
    local typ=bild url=$1
    case $1 in
        mjpeg:*) typ=mjpeg url=${1#mjpeg:} ;;
        html:*)  typ=html  url=${1#html:} ;;
    esac
    printf '%s %s\n' "$typ" "$(ersetze_platzhalter "$url")"
}

finde_bild() { # HTML auf stdin, Seiten-URL als $1 → absolute URL des Webcam-JPGs
    local seite=$1 kandidaten src basis
    kandidaten=$(grep -oiE "<img[^>]*src=[\"'][^\"']+" | sed -E "s/.*src=[\"']//I" | grep -iE '\.jpe?g([?#]|$)')
    src=$(grep -i 'cam' <<<"$kandidaten" | head -n 1)
    [[ -n $src ]] || src=$(head -n 1 <<<"$kandidaten")
    [[ -n $src ]] || return 1
    src=${src//'&amp;'/'&'}     # Quotes nötig: in bash ≥ 5.2 steht & sonst für den Treffer
    case $src in
        http://*|https://*) ;;
        //*) src="${seite%%:*}:$src" ;;
        /*)  src="$(herkunft "$seite")$src" ;;
        *)   basis=${seite%%[?#]*}
             [[ $basis =~ ^https?://[^/]+/ ]] && basis=${basis%/*}
             src="$basis/$src" ;;
    esac
    printf '%s\n' "$src"
}

beschaffe() { # typ url ziel → Exitcode wie curl
    local bild_url
    case $1 in
        bild)  lade "$2" "$3" ;;
        html)  bild_url=$(lade "$2" - | finde_bild "$2") || return 22
               lade "$bild_url" "$3" ;;
        mjpeg) timeout "$TIMEOUT" ffmpeg -nostdin -hide_banner -loglevel error -y \
                   -user_agent "$USER_AGENT" -f mjpeg -i "$2" \
                   -frames:v 1 -q:v 2 -f image2 -update 1 "$3" ;;
    esac
}

pruefe_bild() { # datei → gibt Endung aus (rc 0) oder den Grund der Ablehnung (rc 1)
    local mime groesse
    mime=$(file -b --mime-type "$1")
    case $mime in
        image/jpeg) set -- "$1" jpg ;;
        image/png)  set -- "$1" png ;;
        image/gif)  set -- "$1" gif ;;
        image/webp) set -- "$1" webp ;;
        *) echo "kein Bild ($mime)"; return 1 ;;
    esac
    groesse=$(stat -c %s "$1")
    if (( groesse < MIN_BYTES )); then
        echo "Datei zu klein ($groesse Bytes)"
        return 1
    fi
    echo "$2"
}

# Wann wurde das Bild aufgenommen? Ausgabe: "unixzeit quelle"
#   quelle = server (Last-Modified, per curl --remote-time als Dateidatum gesetzt),
#            exif (Aufnahmedatum im Bild) oder keine (dann gilt der Download-Zeitpunkt)
aufnahmezeit() { # datei download-start
    local zeit quelle=server exif jetzt
    jetzt=$(date +%s)
    zeit=$(stat -c %Y "$1")
    if (( zeit >= $2 - 1 )); then          # Dateidatum = Downloadzeit → Server sendet kein Datum
        quelle=keine
        zeit=$jetzt
        exif=$(file -b "$1" | grep -oE 'datetime=[0-9]{4}:[0-9]{2}:[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' | head -n 1)
        if [[ -n $exif ]]; then
            exif=${exif#datetime=}
            if exif=$(date -d "${exif:0:4}-${exif:5:2}-${exif:8:2} ${exif:11}" +%s 2>/dev/null); then
                zeit=$exif quelle=exif
            fi
        fi
    fi
    (( zeit > jetzt )) && zeit=$jetzt      # Uhren in der Zukunft kappen
    echo "$zeit $quelle"
}

installiere_bild() { # tmpdatei name endung → atomar nach ZIEL_VERZ, alte Endungen weg
    chmod 644 "$1"
    mv -f "$1" "$ZIEL_VERZ/$2.$3"   # atomar: die Slideshow sieht nie ein halbes Bild
    local alt
    for alt in "${BILD_ENDUNGEN[@]}"; do
        [[ $3 != "$alt" ]] && rm -f "$ZIEL_VERZ/$2.$alt"
    done
    return 0
}
