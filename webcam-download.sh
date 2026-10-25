#!/usr/bin/env bash
#
# webcam-download.sh – lädt die aktuellen Webcam-Bilder für die Slideshow (index.php)
#
# Neufassung von picsdownload2.sh (erstellt 2018-04-20).
#
# Jede Webcam aus webcams.txt bekommt einen festen, sprechenden Dateinamen
# (z. B. bilder/erfurt-fischmarkt.jpg). Schlägt ein Download fehl, bleibt das
# letzte gute Bild stehen, bis es älter als MAX_ALTER_MIN ist. Ändert sich ein
# Bild nicht (eingefrorene Kamera), wird es nicht neu gestempelt und fällt nach
# MAX_ALTER_MIN ebenfalls heraus.
#
# Cron-Beispiel (alle 10 Minuten):
#   */10 * * * * /var/www/html/webcam/webcam-download.sh >> /var/log/webcam-download.log 2>&1
#
# Benötigt: bash >= 4.3, curl, file, flock; ffmpeg nur für mjpeg:-Quellen.
# Alle Einstellungen unten lassen sich per Umgebungsvariable überschreiben.

set -uo pipefail

SKRIPT_VERZ=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

LISTE=${LISTE:-$SKRIPT_VERZ/webcams.txt}            # Liste der Webcams
ZIEL_VERZ=${ZIEL_VERZ:-/var/www/html/webcam/bilder} # hier liest index.php die Bilder
PARALLEL=${PARALLEL:-8}                             # gleichzeitige Downloads
TIMEOUT=${TIMEOUT:-20}                              # Sekunden pro Download
MAX_ALTER_MIN=${MAX_ALTER_MIN:-1440}                # ältere Bilder werden entfernt (24 h)
MIN_BYTES=${MIN_BYTES:-2000}                        # kleinere Dateien gelten als defekt
# Aktueller Firefox (ESR). Manche Server (z. B. Holzhau, Zittau) liefern ohne
# Browser-Kennung nichts aus. Bei Bedarf auf eine aktuelle Version anheben.
USER_AGENT=${USER_AGENT:-"Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0"}

BILD_ENDUNGEN=(jpg png gif webp)

# ------------------------------------------------------------------------------

herkunft() { # "https://host:port" einer URL
    [[ $1 =~ ^(https?://[^/?#]+) ]] && printf '%s' "${BASH_REMATCH[1]}"
}

lade() { # url ziel   (ziel "-" = stdout)
    curl --fail --silent --location --compressed \
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

finde_bild() { # HTML auf stdin, Seiten-URL als $1 → absolute URL des Webcam-JPGs
    local seite=$1 kandidaten src basis
    kandidaten=$(grep -oiE "<img[^>]*src=[\"'][^\"']+" | sed -E "s/.*src=[\"']//I" | grep -iE '\.jpe?g([?#]|$)')
    src=$(grep -i 'cam' <<<"$kandidaten" | head -n 1)
    [[ -n $src ]] || src=$(head -n 1 <<<"$kandidaten")
    [[ -n $src ]] || return 1
    src=${src//&amp;/&}
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

beschaffe() { # typ url ziel
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

melde() { # status name [grund url]
    printf '%s\t%s\t%s\t%s\n' "$1" "$2" "${3:-}" "${4:-}" >> "$ARBEIT/ergebnis"
}

hole_bild() { # name quelle
    local name=$1 quelle=$2 typ=bild url rc mime endung ziel alt
    local tmp="$ARBEIT/$name.tmp"
    case $quelle in
        mjpeg:*) typ=mjpeg url=${quelle#mjpeg:} ;;
        html:*)  typ=html  url=${quelle#html:} ;;
        *)       url=$quelle ;;
    esac
    url=$(ersetze_platzhalter "$url")

    beschaffe "$typ" "$url" "$tmp"
    rc=$?
    if (( rc != 0 )) || [[ ! -s $tmp ]]; then
        (( rc == 0 )) && rc=22
        melde FEHLER "$name" "$(curl_fehler "$rc")" "$url"
        return
    fi

    mime=$(file -b --mime-type "$tmp")
    case $mime in
        image/jpeg) endung=jpg ;;
        image/png)  endung=png ;;
        image/gif)  endung=gif ;;
        image/webp) endung=webp ;;
        *) melde FEHLER "$name" "kein Bild ($mime)" "$url"; return ;;
    esac
    if (( $(stat -c %s "$tmp") < MIN_BYTES )); then
        melde FEHLER "$name" "Datei zu klein" "$url"
        return
    fi

    ziel="$ZIEL_VERZ/$name.$endung"
    if [[ -f $ziel ]] && cmp -s "$tmp" "$ziel"; then
        melde GLEICH "$name"   # Zeitstempel bleibt → Alter der Aufnahme bleibt sichtbar
        return
    fi
    chmod 644 "$tmp"
    mv -f "$tmp" "$ziel"       # atomar: die Slideshow sieht nie ein halbes Bild
    for alt in "${BILD_ENDUNGEN[@]}"; do
        [[ $endung != "$alt" ]] && rm -f "$ZIEL_VERZ/$name.$alt"
    done
    melde OK "$name"
}

# ------------------------------------------------------------------------------

for prog in curl file flock; do
    command -v "$prog" >/dev/null || { echo "Programm fehlt: $prog" >&2; exit 1; }
done
[[ -r $LISTE ]] || { echo "Webcam-Liste nicht lesbar: $LISTE" >&2; exit 1; }
mkdir -p "$ZIEL_VERZ" || exit 1

# Nur eine Instanz gleichzeitig (Cron-Überlappung)
exec 9>"${TMPDIR:-/tmp}/webcam-download.lock"
flock -n 9 || { echo "webcam-download.sh läuft bereits – Abbruch."; exit 0; }

# Arbeitsverzeichnis auf demselben Dateisystem wie das Ziel, damit mv atomar ist
ARBEIT=$(mktemp -d "$ZIEL_VERZ/.download.XXXXXX") || exit 1
trap 'rm -rf "$ARBEIT"' EXIT
: > "$ARBEIT/ergebnis"
: > "$ARBEIT/titel.tsv"

declare -A IN_LISTE=()
anzahl=0
while IFS= read -r zeile; do
    zeile=${zeile%$'\r'}
    read -r name quelle titel <<<"$zeile"
    [[ -z ${name:-} || $name == \#* ]] && continue
    if [[ ! $name =~ ^[a-z0-9][a-z0-9_-]*$ || -z ${quelle:-} ]]; then
        echo "WARNUNG: ungültige Zeile in $(basename "$LISTE"): $zeile"
        continue
    fi
    if [[ -n ${IN_LISTE[$name]:-} ]]; then
        echo "WARNUNG: Name doppelt, zweiter Eintrag ignoriert: $name"
        continue
    fi
    IN_LISTE[$name]=1
    printf '%s\t%s\n' "$name" "${titel:-}" >> "$ARBEIT/titel.tsv"
    anzahl=$((anzahl + 1))

    hole_bild "$name" "$quelle" &
    while (( $(jobs -rp | wc -l) >= PARALLEL )); do wait -n; done
done < "$LISTE"
wait

if (( anzahl == 0 )); then
    echo "Keine Webcams in $LISTE gefunden – es wird nichts gelöscht." >&2
    exit 1
fi

chmod 644 "$ARBEIT/titel.tsv"
mv -f "$ARBEIT/titel.tsv" "$ZIEL_VERZ/titel.tsv"

# Aufräumen: Bilder, die nicht mehr in der Liste stehen
for datei in "$ZIEL_VERZ"/*; do
    [[ -f $datei ]] || continue
    basis=${datei##*/}
    [[ " ${BILD_ENDUNGEN[*]} " == *" ${basis##*.} "* ]] || continue
    if [[ -z ${IN_LISTE[${basis%.*}]:-} ]]; then
        rm -f "$datei" && echo "Entfernt (nicht mehr in der Liste): $basis"
    fi
done

ok=$(grep -c '^OK' "$ARBEIT/ergebnis")
gleich=$(grep -c '^GLEICH' "$ARBEIT/ergebnis")
fehler=$(grep -c '^FEHLER' "$ARBEIT/ergebnis")

# … und veraltete Bilder – aber nur, wenn überhaupt etwas geklappt hat
# (fällt das Netz aus, bleiben die letzten Bilder lieber stehen)
if (( ok + gleich > 0 )); then
    find "$ZIEL_VERZ" -maxdepth 1 -type f \
         \( -name '*.jpg' -o -name '*.png' -o -name '*.gif' -o -name '*.webp' \) \
         -mmin +"$MAX_ALTER_MIN" -print -delete | sed 's|.*/|Entfernt (veraltet): |'
fi

echo "$(date '+%F %T')  $anzahl Webcams: $ok aktualisiert, $gleich unverändert, $fehler Fehler"
sort -t $'\t' -k2,2 "$ARBEIT/ergebnis" | while IFS=$'\t' read -r status name grund url; do
    [[ $status == FEHLER ]] && printf '  FEHLER  %-30s %-28s %s\n' "$name" "$grund" "$url"
done
exit 0
