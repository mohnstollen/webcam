#!/usr/bin/env bash
#
# webcam-verwalten.sh – Webcams in webcams.txt testen, hinzufügen, abschalten, entfernen
#
# Getestet wird mit genau denselben Funktionen wie in webcam-download.sh
# (gleicher curl-Aufruf, User-Agent, Referer, Bildprüfung).
# Vor jeder Änderung wird webcams.txt nach webcams.txt.bak gesichert.

set -uo pipefail

SKRIPT_VERZ=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=webcam-funktionen.sh
source "$SKRIPT_VERZ/webcam-funktionen.sh"

PROG=$(basename "$0")
TEST_BILD=
trap 'rm -f "${TEST_BILD:-}"' EXIT

hilfe() {
    cat <<EOF
Aufruf: $PROG BEFEHL …

  test URL                   Download testen, ohne etwas einzutragen
  neu NAME URL [Titel …]     testen und nur bei Erfolg in webcams.txt eintragen
  defekt [TAGE]              Webcams ohne neues Bild seit TAGE Tagen (Standard: 2)
                             oder ohne jeden erfolgreichen Download
  aufraeumen [TAGE]          defekte Webcams einzeln durchgehen und entscheiden
  aus NAME …                 ausschalten (Zeile wird mit "#aus DATUM" auskommentiert)
  an NAME …                  ausgeschaltete Webcam wieder einschalten
  entfernen NAME …           Zeile ganz aus webcams.txt löschen

URL darf mit html: (Bild aus Webseite) oder mjpeg: (Videostream) beginnen.
NAME: nur a-z, 0-9, - und _ – wird zum Dateinamen, z. B. erfurt-fischmarkt.

Beispiele:
  $PROG test https://www.foto-webcam.eu/webcam/zugspitze/current/1200.jpg
  $PROG neu zugspitze https://www.foto-webcam.eu/webcam/zugspitze/current/1200.jpg Zugspitze
  $PROG aufraeumen
EOF
}

fehler() { echo "Fehler: $*" >&2; exit 1; }

zeit_text() { # unixzeit → Datum oder "nie"
    if (( $1 > 0 )); then date -d "@$1" '+%d.%m.%Y %H:%M'; else echo "nie"; fi
}

ist_aktiv() { awk -v n="$1" '$1 == n {f = 1} END {exit !f}' "$LISTE"; }
ist_aus()   { grep -qE "^#aus [0-9-]+ +$1([[:space:]]|$)" "$LISTE"; }
quelle_von() { awk -v n="$1" '$1 == n {print $2; exit}' "$LISTE"; }

aendere_liste() { # awk-Argumente → webcams.txt neu schreiben (Rechte/Besitzer bleiben)
    local tmp
    tmp=$(mktemp) || exit 1
    if ! awk "$@" "$LISTE" > "$tmp"; then rm -f "$tmp"; exit 1; fi
    cp -p "$LISTE" "$LISTE.bak"
    cat "$tmp" > "$LISTE"
    rm -f "$tmp"
}

# Liefert die Adresse statt eines Bildes etwas anderes? Kopfzeilen ansehen.
diagnose() { # url
    local kopf code
    kopf=$(curl -s -o /dev/null -D - --max-time 5 --user-agent "$USER_AGENT" \
                --referer "$(herkunft "$1")/" "$1" 2>/dev/null | tr -d '\r')
    code=$(awk 'toupper($1) ~ /^HTTP\// {c = $2} END {print c}' <<<"$kopf")
    [[ -n $code ]] && echo "  HTTP-Status: $code"
    if grep -qi '^content-type: *multipart/x-mixed-replace' <<<"$kopf"; then
        echo "  Die Adresse liefert einen MJPEG-Videostream → Vorschlag: mjpeg:$1"
    fi
}

teste() { # quelle → bei Erfolg: Bild in $TEST_BILD, Endung in $TEST_ENDUNG
    local typ url rc info masse breite bild_url
    read -r typ url < <(zerlege_quelle "$1")
    rm -f "${TEST_BILD:-}"
    TEST_BILD=$(mktemp) || exit 1
    echo "Teste $url …"

    beschaffe "$typ" "$url" "$TEST_BILD"
    rc=$?
    if (( rc != 0 )) || [[ ! -s $TEST_BILD ]]; then
        (( rc == 0 )) && rc=22
        echo "✘ Download fehlgeschlagen: $(curl_fehler "$rc")"
        [[ $typ == bild ]] && diagnose "$url"
        return 1
    fi
    if ! TEST_ENDUNG=$(pruefe_bild "$TEST_BILD"); then
        echo "✘ $TEST_ENDUNG"
        if [[ $typ == bild && $TEST_ENDUNG == "kein Bild (text/"* ]] \
           && bild_url=$(finde_bild "$url" < "$TEST_BILD"); then
            echo "  Die Adresse liefert eine Webseite, darin gefunden: $bild_url"
            echo "  → Vorschlag: diese Bild-URL direkt verwenden oder html:$url"
        fi
        return 1
    fi

    info=$(file -b "$TEST_BILD")
    masse=$(grep -oE '[0-9]+ ?x ?[0-9]+' <<<"$info" | tail -n 1 | tr -d ' ')
    echo "✔ Bild OK: ${TEST_ENDUNG^^}, ${masse:-?} Pixel, $(( $(stat -c %s "$TEST_BILD") / 1024 )) KB"
    breite=${masse%%x*}
    if [[ $breite =~ ^[0-9]+$ ]] && (( breite < 640 )); then
        echo "  Hinweis: geringe Auflösung – auf dem Tablet wird das Bild unscharf."
    fi
    return 0
}

cmd_neu() {
    (( $# >= 2 )) || fehler "Aufruf: $PROG neu NAME URL [Titel …]"
    local name=$1 quelle=$2 roh vorhanden
    shift 2
    local titel="$*"

    [[ $name =~ $NAME_MUSTER ]] \
        || fehler "Ungültiger Name '$name' – erlaubt sind a-z, 0-9, - und _ (keine Großbuchstaben/Umlaute)"
    [[ $quelle =~ ^((html|mjpeg):)?https?:// ]] \
        || fehler "Die URL muss mit http:// oder https:// beginnen (davor optional html: oder mjpeg:)"
    ist_aktiv "$name" && fehler "Der Name '$name' ist schon vergeben."
    ist_aus "$name" && fehler "'$name' ist ausgeschaltet in der Liste – wieder einschalten mit: $PROG an $name"

    roh=${quelle#html:}
    roh=${roh#mjpeg:}
    vorhanden=$(sed -E 's/^#aus [0-9-]+ +//' "$LISTE" | awk -v u="$roh" '
        $1 !~ /^#/ && NF >= 2 { q = $2; sub(/^(html|mjpeg):/, "", q); if (q == u) { print $1; exit } }')
    [[ -n $vorhanden ]] && fehler "Diese URL ist bereits als '$vorhanden' eingetragen."

    teste "$quelle" || { echo "Nicht eingetragen."; exit 1; }

    cp -p "$LISTE" "$LISTE.bak"
    [[ -n $(tail -c 1 "$LISTE") ]] && echo >> "$LISTE"
    grep -q '^# --- Neu hinzugefügt' "$LISTE" \
        || printf '\n# --- Neu hinzugefügt ------------------------------------------------------------\n' >> "$LISTE"
    printf '%-30s %s%s\n' "$name" "$quelle" "${titel:+  $titel}" >> "$LISTE"
    echo "Eingetragen: $name"

    # Bild gleich bereitstellen, damit es sofort in der Slideshow erscheint
    if mkdir -p "$ZIEL_VERZ" 2>/dev/null && installiere_bild "$TEST_BILD" "$name" "$TEST_ENDUNG" 2>/dev/null; then
        [[ -n $titel ]] && printf '%s\t%s\n' "$name" "$titel" >> "$ZIEL_VERZ/titel.tsv" 2>/dev/null
        echo "Das Bild ist ab sofort in der Slideshow (das Tablet holt die Liste alle 5 Minuten neu)."
    else
        echo "Das Bild erscheint nach dem nächsten Lauf von webcam-download.sh."
    fi
}

ermittle_defekte() { # tage → DEFEKTE: "zuletzt_neu<TAB>name<TAB>meldung", älteste zuerst
    local grenze=$(( $(date +%s) - $1 * 86400 ))
    [[ -r $STATUS ]] || fehler "Noch keine Statusdaten ($STATUS) – erst webcam-download.sh laufen lassen."
    mapfile -t DEFEKTE < <(awk -F'\t' -v g="$grenze" '
        NR == FNR { if ($0 !~ /^#/ && NF) { split($0, f, /[ \t]+/); aktiv[f[1]] = 1 }; next }
        ($1 in aktiv) && ($4 == 0 || ($4 < g && $2 < g)) { print $4 "\t" $1 "\t" $5 }
    ' "$LISTE" "$STATUS" | sort -n)
}

cmd_defekt() {
    local tage=${1:-2} neu name info
    [[ $tage =~ ^[0-9]+$ ]] || fehler "TAGE muss eine Zahl sein."
    ermittle_defekte "$tage"
    if (( ${#DEFEKTE[@]} == 0 )); then
        echo "Alle Webcams haben in den letzten $tage Tagen neue Bilder geliefert."
        return
    fi
    printf '%-30s %-18s %s\n' "NAME" "ZULETZT NEU" "LETZTE MELDUNG"
    for zeile in "${DEFEKTE[@]}"; do
        IFS=$'\t' read -r neu name info <<<"$zeile"
        printf '%-30s %-18s %s\n' "$name" "$(zeit_text "$neu")" "$info"
    done
    echo
    echo "${#DEFEKTE[@]} Webcam(s) ohne neues Bild seit $tage Tagen. Bearbeiten mit: $PROG aufraeumen $tage"
}

cmd_aufraeumen() {
    local tage=${1:-2} neu name info antwort nr=0
    [[ $tage =~ ^[0-9]+$ ]] || fehler "TAGE muss eine Zahl sein."
    ermittle_defekte "$tage"
    if (( ${#DEFEKTE[@]} == 0 )); then
        echo "Nichts zu tun – alle Webcams liefern."
        return
    fi
    for zeile in "${DEFEKTE[@]}"; do
        IFS=$'\t' read -r neu name info <<<"$zeile"
        nr=$((nr + 1))
        echo
        echo "[$nr/${#DEFEKTE[@]}] $name"
        echo "  URL:         $(quelle_von "$name")"
        echo "  zuletzt neu: $(zeit_text "$neu")"
        echo "  Meldung:     ${info:--}"
        while true; do
            read -rp "  [a]usschalten, [e]ntfernen, [t]esten, [b]ehalten, [q] beenden? " antwort < /dev/tty || return
            case ${antwort,,} in
                a) cmd_aus "$name"; break ;;
                e) cmd_entfernen "$name"; break ;;
                t) teste "$(quelle_von "$name")" | sed 's/^/  /' ;;
                b|"") break ;;
                q) return ;;
            esac
        done
    done
}

cmd_aus() {
    (( $# )) || fehler "Aufruf: $PROG aus NAME …"
    local n
    for n; do
        if ! ist_aktiv "$n"; then echo "Nicht (mehr) aktiv in der Liste: $n"; continue; fi
        aendere_liste -v n="$n" -v d="$(date +%F)" '$1 == n {print "#aus " d " " $0; next} {print}'
        rm -f "$ZIEL_VERZ/$n".{jpg,png,gif,webp} 2>/dev/null
        echo "Ausgeschaltet: $n"
    done
}

cmd_an() {
    (( $# )) || fehler "Aufruf: $PROG an NAME …"
    local n
    for n; do
        if ! ist_aus "$n"; then echo "Nicht ausgeschaltet: $n"; continue; fi
        aendere_liste -v n="$n" '
            match($0, /^#aus [0-9-]+ +/) { rest = substr($0, RLENGTH + 1); split(rest, f, /[ \t]+/)
                                          if (f[1] == n) { print rest; next } }
            {print}'
        echo "Eingeschaltet: $n (Bild kommt mit dem nächsten Download-Lauf)"
    done
}

cmd_entfernen() {
    (( $# )) || fehler "Aufruf: $PROG entfernen NAME …"
    local n
    for n; do
        if ! ist_aktiv "$n" && ! ist_aus "$n"; then echo "Nicht in der Liste: $n"; continue; fi
        aendere_liste -v n="$n" '
            { z = $0; aus = sub(/^#aus [0-9-]+ +/, "", z); split(z, f, /[ \t]+/)
              if (f[1] == n && (aus || $1 == n)) next; print }'
        rm -f "$ZIEL_VERZ/$n".{jpg,png,gif,webp} 2>/dev/null
        echo "Entfernt: $n"
    done
}

# ------------------------------------------------------------------------------

befehl=${1:-hilfe}
(( $# )) && shift
case $befehl in
    test|neu|aus|an|entfernen|aufraeumen|defekt)
        [[ -r $LISTE ]] || fehler "Webcam-Liste nicht lesbar: $LISTE" ;;&
    neu|aus|an|entfernen|aufraeumen)
        [[ -w $LISTE ]] || fehler "Keine Schreibrechte für $LISTE" ;;&
    test)       (( $# == 1 )) || fehler "Aufruf: $PROG test URL"
                teste "$1" ;;
    neu)        cmd_neu "$@" ;;
    defekt)     cmd_defekt "$@" ;;
    aufraeumen) cmd_aufraeumen "$@" ;;
    aus)        cmd_aus "$@" ;;
    an)         cmd_an "$@" ;;
    entfernen)  cmd_entfernen "$@" ;;
    hilfe|-h|--help) hilfe ;;
    *)          hilfe; exit 1 ;;
esac
