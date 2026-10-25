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
# In webcams.status wird pro Webcam festgehalten, wann sie zuletzt ein neues
# Bild geliefert hat – Grundlage für "webcam-verwalten.sh defekt".
#
# Cron-Beispiel (alle 10 Minuten):
#   */10 * * * * /var/www/html/webcam/webcam-download.sh >> /var/log/webcam-download.log 2>&1
#
# Benötigt: bash >= 4.3, curl, file, flock; ffmpeg nur für mjpeg:-Quellen.
# Einstellungen: webcam-funktionen.sh bzw. hier unten, jeweils per Umgebungsvariable überschreibbar.

set -uo pipefail

SKRIPT_VERZ=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=webcam-funktionen.sh
source "$SKRIPT_VERZ/webcam-funktionen.sh"

PARALLEL=${PARALLEL:-8}                             # gleichzeitige Downloads
MAX_ALTER_MIN=${MAX_ALTER_MIN:-1440}                # ältere Bilder werden entfernt (24 h)

# ------------------------------------------------------------------------------

melde() { # status name [grund url]
    printf '%s\t%s\t%s\t%s\n' "$1" "$2" "${3:-}" "${4:-}" >> "$ARBEIT/ergebnis"
}

hole_bild() { # name quelle
    local name=$1 typ url rc endung
    local tmp="$ARBEIT/$name.tmp"
    read -r typ url < <(zerlege_quelle "$2")

    beschaffe "$typ" "$url" "$tmp"
    rc=$?
    if (( rc != 0 )) || [[ ! -s $tmp ]]; then
        (( rc == 0 )) && rc=22
        melde FEHLER "$name" "$(curl_fehler "$rc")" "$url"
        return
    fi
    if ! endung=$(pruefe_bild "$tmp"); then
        melde FEHLER "$name" "$endung" "$url"
        return
    fi
    if [[ -f $ZIEL_VERZ/$name.$endung ]] && cmp -s "$tmp" "$ZIEL_VERZ/$name.$endung"; then
        melde GLEICH "$name"   # Zeitstempel bleibt → Alter der Aufnahme bleibt sichtbar
        return
    fi
    installiere_bild "$tmp" "$name" "$endung"
    melde OK "$name"
}

# webcams.status: name, erstmals gesehen, zuletzt erreichbar, zuletzt neues Bild
# (jeweils Unix-Zeit, 0 = nie), letzte Meldung – tabgetrennt
aktualisiere_status() {
    local jetzt n e o u f st grund
    declare -A erst=() ok=() neu=() info=()
    jetzt=$(date +%s)
    if [[ -r $STATUS ]]; then
        while IFS=$'\t' read -r n e o u f; do
            erst[$n]=$e ok[$n]=$o neu[$n]=$u info[$n]=$f
        done < "$STATUS"
    fi
    while IFS=$'\t' read -r st n grund _; do
        case $st in
            OK)     ok[$n]=$jetzt neu[$n]=$jetzt info[$n]= ;;
            GLEICH) ok[$n]=$jetzt info[$n]="Bild unverändert"
                    # Bild kam von außerhalb (z. B. "webcam-verwalten.sh neu") → zählt als neu
                    [[ ${neu[$n]:-0} == 0 ]] && neu[$n]=$jetzt ;;
            FEHLER) info[$n]=$grund ;;
        esac
    done < "$ARBEIT/ergebnis"
    for n in "${!IN_LISTE[@]}"; do
        printf '%s\t%s\t%s\t%s\t%s\n' "$n" "${erst[$n]:-$jetzt}" "${ok[$n]:-0}" "${neu[$n]:-0}" "${info[$n]:-}"
    done | sort > "$ARBEIT/status" && cat "$ARBEIT/status" > "$STATUS"
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
    if [[ ! $name =~ $NAME_MUSTER || -z ${quelle:-} ]]; then
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
aktualisiere_status

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
