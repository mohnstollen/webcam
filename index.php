<?php
/*
 * Webcam-Slideshow für die Tablet-Anzeige (Nachfolger von webcam8.php)
 *
 * Die Bilder lädt webcam-cli.php (Cron) nach ./bilder/. Die Seite läuft komplett im
 * Browser weiter (kein Neuladen pro Bild), holt die Bilderliste regelmäßig neu
 * und merkt sich Reihenfolge und Position im localStorage des Tablets.
 *
 * Bedienung:  Tippen links/rechts = zurück/weiter, Mitte = Steuerung ein/aus,
 *             Wischen = zurück/weiter, Tastatur: ← → Leertaste F
 */
declare(strict_types=1);
require __DIR__ . '/inc/webcam.php';

/** Alle aktiven Webcams mit aktuellem Bild, dazu die Favoriten */
function daten(): array
{
    $bilder = [];
    $favoriten = [];
    try {
        $cams = alleWebcams('aktiv = 1 AND datei IS NOT NULL');
    } catch (Throwable $e) {
        $cams = [];                       // Datenbank (noch) nicht eingerichtet → leere Slideshow
    }
    foreach ($cams as $c) {
        $pfad = BILD_VERZ . '/' . $c['datei'];
        if (!is_file($pfad)) {
            continue;
        }
        $zeit = (int) filemtime($pfad);    // = Aufnahmezeit (vom Download gesetzt)
        $bilder[] = [
            'datei' => $c['datei'],
            'titel' => $c['titel'] !== '' ? $c['titel'] : $c['name'],
            'zeit'  => $zeit,
            // ?v= sorgt dafür, dass das Tablet nie ein veraltetes Bild aus dem Cache zeigt
            'url'   => BILD_URL . '/' . rawurlencode($c['datei']) . '?v=' . $zeit,
        ];
        if ($c['favorit']) {
            $favoriten[] = $c['datei'];
        }
    }
    return ['bilder' => $bilder, 'favoriten' => $favoriten, 'jetzt' => time()];
}

function favoriten(): array
{
    return daten()['favoriten'];
}

function sendeJson(array $daten): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- API -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['favorit'])) {
    $st = db()->prepare('UPDATE webcams SET favorit = ? WHERE datei = ?');
    $st->execute([($_POST['an'] ?? '1') === '1' ? 1 : 0, (string) $_POST['favorit']]);
    if ($st->rowCount() === 0) {
        http_response_code(404);
        sendeJson(['fehler' => 'Bild nicht gefunden']);
    }
    sendeJson(['favoriten' => favoriten()]);
}
if (isset($_GET['liste'])) {
    sendeJson(daten());
}

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#000000">
<title>Webcams</title>
<style>
  html, body {
    margin: 0; height: 100%; overflow: hidden;
    background: #000; color: #fff;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    -webkit-user-select: none; user-select: none;
    -webkit-tap-highlight-color: transparent; touch-action: manipulation;
  }
  /* Vollfläche: top/right/bottom/left statt 100vh, damit Browserleisten nichts abschneiden */
  #buehne, .ebene, .ebene img, .hg { position: fixed; top: 0; right: 0; bottom: 0; left: 0; }
  #buehne { overflow: hidden; cursor: pointer; }
  .ebene { opacity: 0; transition: opacity .9s ease; }
  .ebene.aktiv { opacity: 1; }
  /* unscharfer Hintergrund aus demselben Bild füllt die Ränder statt schwarzer Balken */
  .hg {
    top: -40px; right: -40px; bottom: -40px; left: -40px;
    background: #000 center / cover no-repeat;
    filter: blur(30px) brightness(.45); -webkit-filter: blur(30px) brightness(.45);
  }
  .ohne-hg .hg { display: none; }
  .ebene img {
    width: 100%; height: 100%;
    object-fit: contain;                 /* ganzes Bild, maximal groß */
  }
  .ebene img.fuellen { object-fit: cover; } /* Bildschirm füllen, Ränder minimal beschnitten */

  /* Bildunterschrift – immer sichtbar, dezent */
  #unterschrift {
    position: fixed; left: 0; right: 0; bottom: 0;
    padding: 40px max(16px, env(safe-area-inset-right)) max(12px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
    background: linear-gradient(transparent, rgba(0,0,0,.65));
    display: flex; align-items: flex-end; justify-content: space-between; gap: 12px;
    pointer-events: none; text-shadow: 0 1px 3px #000;
  }
  #titel { font-size: 20px; font-size: clamp(16px, 2.6vmin, 30px); font-weight: 600; }
  #stand { font-size: 14px; font-size: clamp(12px, 1.8vmin, 20px); opacity: .85; margin-top: 2px; }
  #stand.alt { color: #ffb347; opacity: 1; }
  #zaehler { font-size: 14px; font-size: clamp(12px, 1.8vmin, 20px); opacity: .7; white-space: nowrap; }
  #fav-marke { color: #ff5c93; }

  #fortschritt { position: fixed; left: 0; bottom: 0; height: 3px; width: 0; background: rgba(255,255,255,.55); }

  /* Steuerung – blendet sich nach einigen Sekunden aus */
  #steuerung {
    position: fixed; left: 50%; top: 50%;
    -webkit-transform: translate(-50%, -50%); transform: translate(-50%, -50%);
    display: flex; flex-wrap: wrap; justify-content: center; gap: 10px;
    width: 90%; max-width: 720px; padding: 16px; box-sizing: border-box;
    background: rgba(20,20,20,.75); border-radius: 18px;
    -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
    opacity: 0; pointer-events: none; transition: opacity .3s;
  }
  #steuerung.zeigen { opacity: 1; pointer-events: auto; }
  #steuerung button {
    flex: 1 1 110px; min-height: 64px; padding: 8px;
    border: 0; border-radius: 12px; background: rgba(255,255,255,.12); color: #fff;
    font: inherit; font-size: 15px; cursor: pointer;
  }
  #steuerung button span { display: block; font-size: 26px; line-height: 1.1; }
  #steuerung button:active { background: rgba(255,255,255,.3); }
  #steuerung button.an { background: #c2185b; }

  #meldung {
    position: fixed; left: 50%; top: 24px; max-width: 80%;
    -webkit-transform: translateX(-50%); transform: translateX(-50%);
    padding: 10px 18px; border-radius: 10px; background: rgba(0,0,0,.8);
    font-size: 16px; text-align: center; opacity: 0; transition: opacity .3s; pointer-events: none;
  }
  #meldung.zeigen { opacity: 1; }
  #pause-marke { position: fixed; top: 16px; right: 20px; font-size: 28px; opacity: .8; display: none; }
  .pausiert #pause-marke { display: block; }
</style>
</head>
<body>
<div id="buehne">
  <div class="ebene"><div class="hg"></div><img alt=""></div>
  <div class="ebene"><div class="hg"></div><img alt=""></div>
</div>

<div id="unterschrift">
  <div>
    <div id="titel">Webcams</div>
    <div id="stand"></div>
  </div>
  <div id="zaehler"></div>
</div>
<div id="fortschritt"></div>
<div id="pause-marke">⏸</div>
<div id="meldung"></div>

<div id="steuerung">
  <button data-aktion="zurueck"><span>◀</span>Zurück</button>
  <button data-aktion="pause" id="btn-pause"><span>⏸</span>Pause</button>
  <button data-aktion="weiter"><span>▶</span>Weiter</button>
  <button data-aktion="favorit" id="btn-favorit"><span>♥</span>Merken</button>
  <button data-aktion="nurfav" id="btn-nurfav"><span>★</span>Nur Favoriten</button>
  <button data-aktion="modus" id="btn-modus"><span>⤢</span>Auto</button>
  <button data-aktion="mischen"><span>🔀</span>Neu mischen</button>
  <button data-aktion="vollbild" id="btn-vollbild"><span>⛶</span>Vollbild</button>
  <button data-aktion="verwaltung"><span>⚙</span>Verwaltung</button>
</div>

<script>
(function () {
  'use strict';

  var CFG = {
    dauer: <?= ANZEIGE_SEKUNDEN ?> * 1000, // Anzeigedauer pro Bild
    listeAlle: 5 * 60 * 1000,             // Bilderliste neu holen
    seiteNeuNach: 6 * 3600 * 1000,        // Seite gelegentlich ganz neu laden (Speicher, Updates)
    steuerungAus: 6000,                   // Steuerung blendet sich aus
    altNachMin: 120,                      // Stand-Angabe orange, wenn Bild älter
    autoFuellenMax: 0.10,                 // "Auto": füllen, wenn höchstens 10 % abgeschnitten würden
    unscharferHintergrund: true           // auf sehr schwachen Tablets ggf. false
  };
  var MODI = { auto: 'Auto', ganz: 'Ganzes Bild', fuellen: 'Füllen' };

  var $ = function (id) { return document.getElementById(id); };
  var ebenen = [].map.call(document.querySelectorAll('.ebene'), function (el) {
    return { el: el, img: el.querySelector('img'), hg: el.querySelector('.hg') };
  });

  var bilder = {};          // datei -> {datei, titel, zeit, url}
  var favoriten = [];
  var zeitversatz = 0;      // Serverzeit - Tabletzeit (s)
  var zustand = { reihenfolge: [], pos: 0, nurFav: false, modus: 'auto' };
  var aktiv = 0, ladeId = 0, fehlversuche = 0, richtung = 1;
  var pausiert = false, timer = null, steuerTimer = null, meldungTimer = null;
  var angezeigt = null;     // aktuell sichtbares Bild

  if (!CFG.unscharferHintergrund) document.body.classList.add('ohne-hg');

  // ---- Speicher (localStorage kann fehlen/gesperrt sein) ----------------------
  function merke() {
    try { localStorage.setItem('webcamSlideshow', JSON.stringify(zustand)); } catch (e) {}
  }
  function erinnere() {
    try {
      var z = JSON.parse(localStorage.getItem('webcamSlideshow') || 'null');
      if (z && Array.isArray(z.reihenfolge)) {
        zustand.reihenfolge = z.reihenfolge;
        zustand.pos = z.pos | 0;
        zustand.nurFav = !!z.nurFav;
        if (MODI[z.modus]) zustand.modus = z.modus;
      }
    } catch (e) {}
  }

  // ---- Liste & Reihenfolge ---------------------------------------------------
  function uebernehme(d) {
    bilder = {};
    d.bilder.forEach(function (b) { bilder[b.datei] = b; });
    favoriten = d.favoriten || [];
    zeitversatz = d.jetzt - Date.now() / 1000;
  }

  function kandidaten() {
    var alle = Object.keys(bilder);
    if (!zustand.nurFav) return alle;
    return alle.filter(function (f) { return favoriten.indexOf(f) >= 0; });
  }

  function mische(a) {
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1)), t = a[i];
      a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function neuerZyklus() {
    zustand.reihenfolge = mische(kandidaten());
    zustand.pos = 0;
    merke();
  }

  // Reihenfolge an eine geänderte Liste anpassen, ohne den Durchlauf neu zu beginnen:
  // verschwundene Bilder raus, neue zufällig in den noch ausstehenden Teil einfügen.
  function abgleichen() {
    var erlaubt = {}, drin = {};
    kandidaten().forEach(function (f) { erlaubt[f] = true; });
    var aktuell = zustand.reihenfolge[zustand.pos];
    var r = zustand.reihenfolge.filter(function (f) {
      if (!erlaubt[f] || drin[f]) return false;
      drin[f] = true;
      return true;
    });
    var pos = r.indexOf(aktuell);
    if (pos < 0) pos = Math.min(zustand.pos, r.length - 1);
    if (pos < 0) pos = 0;
    Object.keys(erlaubt).forEach(function (f) {
      if (drin[f]) return;
      var p = pos + 1 + Math.floor(Math.random() * (r.length - pos));
      r.splice(Math.min(p, r.length), 0, f);
    });
    zustand.reihenfolge = r;
    zustand.pos = pos;
    merke();
  }

  function ladeListe() {
    return fetch('?liste=1', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { uebernehme(d); abgleichen(); });
  }

  // ---- Anzeige ---------------------------------------------------------------
  function passeAn(e) {
    var img = e.img;
    if (!img.naturalWidth) return;
    var fuellen = zustand.modus === 'fuellen';
    if (zustand.modus === 'auto') {
      var arBild = img.naturalWidth / img.naturalHeight;
      var arSchirm = window.innerWidth / window.innerHeight;
      var verlust = 1 - Math.min(arBild / arSchirm, arSchirm / arBild);
      fuellen = verlust <= CFG.autoFuellenMax;
    }
    if (fuellen) img.classList.add('fuellen'); else img.classList.remove('fuellen');
  }

  function zeige() {
    var r = zustand.reihenfolge;
    if (!r.length) { leer(); return; }
    if (zustand.pos >= r.length) zustand.pos = 0;
    var b = bilder[r[zustand.pos]];
    var id = ++ladeId;
    var e = ebenen[1 - aktiv];
    stoppeTimer();

    e.img.onload = function () {
      if (id !== ladeId) return;
      fehlversuche = 0;
      passeAn(e);
      e.hg.style.backgroundImage = 'url("' + b.url + '")';
      e.el.classList.add('aktiv');
      ebenen[aktiv].el.classList.remove('aktiv');
      aktiv = 1 - aktiv;
      angezeigt = b;
      beschrifte();
      merke();
      starteTimer();
      vorladen();
    };
    e.img.onerror = function () {
      if (id !== ladeId) return;
      // Bild fehlt/defekt → überspringen (in der zuletzt gewählten Richtung)
      if (++fehlversuche >= r.length) { leer('Keines der Bilder lässt sich laden.'); return; }
      schritt(richtung, true);
    };
    e.img.src = b.url;
  }

  function vorladen() {
    var r = zustand.reihenfolge;
    if (r.length > 1) new Image().src = bilder[r[(zustand.pos + 1) % r.length]].url;
  }

  function beschrifte() {
    if (!angezeigt) return;
    var b = angezeigt, istFav = favoriten.indexOf(b.datei) >= 0;
    $('titel').innerHTML = '';
    $('titel').appendChild(document.createTextNode(b.titel + ' '));
    if (istFav) {
      var m = document.createElement('span');
      m.id = 'fav-marke'; m.textContent = '♥';
      $('titel').appendChild(m);
    }
    var alterMin = Math.max(0, Math.round((Date.now() / 1000 + zeitversatz - b.zeit) / 60));
    var d = new Date((b.zeit - zeitversatz) * 1000);
    var uhr = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    var text;
    if (alterMin < 1) text = 'gerade eben';
    else if (alterMin < 60) text = 'vor ' + alterMin + ' Min.';
    else if (alterMin < 48 * 60) text = 'vor ' + Math.floor(alterMin / 60) + ' Std. ' + (alterMin % 60) + ' Min.';
    else text = 'vor ' + Math.floor(alterMin / 1440) + ' Tagen';
    if (alterMin >= 24 * 60) uhr = d.getDate() + '.' + (d.getMonth() + 1) + '. ' + uhr;
    $('stand').textContent = 'Stand ' + uhr + ' Uhr · ' + text;
    $('stand').className = alterMin > CFG.altNachMin ? 'alt' : '';
    $('zaehler').textContent = (zustand.pos + 1) + ' / ' + zustand.reihenfolge.length +
      (zustand.nurFav ? ' ★' : '');
    $('btn-favorit').className = istFav ? 'an' : '';
    $('btn-favorit').lastChild.nodeValue = istFav ? 'Gemerkt' : 'Merken';
  }

  function leer(text) {
    stoppeTimer();
    angezeigt = null;
    ebenen.forEach(function (e) { e.el.classList.remove('aktiv'); });
    $('titel').textContent = text || (zustand.nurFav
      ? 'Noch keine Favoriten – mit ♥ Merken markieren.'
      : 'Keine Webcam-Bilder vorhanden – siehe ⚙ Verwaltung.');
    $('stand').textContent = '';
    $('zaehler').textContent = '';
  }

  // ---- Ablauf ----------------------------------------------------------------
  function stoppeTimer() {
    clearTimeout(timer);
    var f = $('fortschritt');
    f.style.transition = 'none';
    f.style.width = '0';
  }

  function starteTimer() {
    stoppeTimer();
    if (pausiert) return;
    var f = $('fortschritt');
    void f.offsetWidth;                  // Reflow, damit die Animation neu startet
    f.style.transition = 'width ' + CFG.dauer + 'ms linear';
    f.style.width = '100%';
    timer = setTimeout(function () { schritt(1); }, CFG.dauer);
  }

  function schritt(n, nachFehler) {
    var r = zustand.reihenfolge;
    richtung = n;
    if (!nachFehler) fehlversuche = 0;
    if (!r.length) { leer(); return; }
    var pos = zustand.pos + n;
    if (pos >= r.length) {
      // Durchlauf beendet → frische Liste holen und neu mischen
      ladeListe().catch(function () {}).then(function () { neuerZyklus(); zeige(); });
      return;
    }
    zustand.pos = (pos + r.length) % r.length;
    zeige();
  }

  // ---- Bedienung -------------------------------------------------------------
  function meldung(text) {
    var m = $('meldung');
    m.textContent = text;
    m.classList.add('zeigen');
    clearTimeout(meldungTimer);
    meldungTimer = setTimeout(function () { m.classList.remove('zeigen'); }, 2500);
  }

  function steuerung(zeigen) {
    var s = $('steuerung');
    clearTimeout(steuerTimer);
    if (zeigen === undefined) zeigen = !s.classList.contains('zeigen');
    if (zeigen) {
      s.classList.add('zeigen');
      steuerTimer = setTimeout(function () { s.classList.remove('zeigen'); }, CFG.steuerungAus);
    } else {
      s.classList.remove('zeigen');
    }
  }

  function setzePause(p) {
    pausiert = p;
    document.body.classList.toggle('pausiert', p);
    $('btn-pause').innerHTML = p ? '<span>▶</span>Abspielen' : '<span>⏸</span>Pause';
    if (p) stoppeTimer(); else starteTimer();
  }

  function setzeModus(m) {
    zustand.modus = m;
    $('btn-modus').lastChild.nodeValue = MODI[m];
    ebenen.forEach(passeAn);
    merke();
  }

  function favoritUmschalten() {
    if (!angezeigt) return;
    var datei = angezeigt.datei, an = favoriten.indexOf(datei) < 0;
    var body = 'favorit=' + encodeURIComponent(datei) + '&an=' + (an ? 1 : 0);
    fetch('', {
      method: 'POST', credentials: 'same-origin', body: body,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        favoriten = d.favoriten;
        beschrifte();
        meldung(an ? '♥ Gemerkt: ' + angezeigt.titel : 'Aus Favoriten entfernt');
        if (zustand.nurFav) abgleichen();
      })
      .catch(function () { meldung('Speichern fehlgeschlagen'); });
  }

  function vollbild() {
    var d = document, el = d.documentElement;
    if (d.fullscreenElement || d.webkitFullscreenElement) {
      (d.exitFullscreen || d.webkitExitFullscreen).call(d);
    } else {
      var req = el.requestFullscreen || el.webkitRequestFullscreen;
      if (req) req.call(el);
    }
  }
  if (!(document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen)) {
    $('btn-vollbild').style.display = 'none';
  }

  var aktionen = {
    zurueck: function () { schritt(-1); },
    weiter: function () { schritt(1); },
    pause: function () { setzePause(!pausiert); },
    favorit: favoritUmschalten,
    nurfav: function () {
      zustand.nurFav = !zustand.nurFav;
      $('btn-nurfav').className = zustand.nurFav ? 'an' : '';
      neuerZyklus();
      meldung(zustand.nurFav ? '★ Nur Favoriten' : 'Alle Webcams');
      zeige();
    },
    modus: function () {
      var namen = Object.keys(MODI);
      setzeModus(namen[(namen.indexOf(zustand.modus) + 1) % namen.length]);
      meldung('Darstellung: ' + MODI[zustand.modus]);
    },
    mischen: function () {
      ladeListe().catch(function () {}).then(function () {
        neuerZyklus();
        meldung('Neu gemischt – ' + zustand.reihenfolge.length + ' Bilder');
        zeige();
      });
    },
    vollbild: vollbild,
    verwaltung: function () { location.href = 'admin.php'; }
  };

  $('steuerung').addEventListener('click', function (ev) {
    ev.stopPropagation();
    var btn = ev.target.closest ? ev.target.closest('button') : null;
    if (!btn) return;
    aktionen[btn.getAttribute('data-aktion')]();
    steuerung(true);                     // Ausblende-Timer neu starten
  });

  // Tippen: linkes Viertel zurück, rechtes Viertel weiter, Mitte Steuerung
  var wischStart = null, gewischt = false;
  $('buehne').addEventListener('touchstart', function (ev) {
    var t = ev.touches[0];
    wischStart = { x: t.clientX, y: t.clientY };
    gewischt = false;
  }, { passive: true });
  $('buehne').addEventListener('touchend', function (ev) {
    if (!wischStart) return;
    var t = ev.changedTouches[0], dx = t.clientX - wischStart.x, dy = t.clientY - wischStart.y;
    wischStart = null;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
      gewischt = true;
      schritt(dx < 0 ? 1 : -1);
    }
  });
  $('buehne').addEventListener('click', function (ev) {
    if (gewischt) { gewischt = false; return; }
    var x = ev.clientX / window.innerWidth;
    if (x < 0.25) schritt(-1);
    else if (x > 0.75) schritt(1);
    else steuerung();
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowRight') schritt(1);
    else if (ev.key === 'ArrowLeft') schritt(-1);
    else if (ev.key === ' ') { ev.preventDefault(); aktionen.pause(); }
    else if (ev.key === 'f' || ev.key === 'F') vollbild();
  });

  window.addEventListener('resize', function () { ebenen.forEach(passeAn); });

  // Bildschirm wach halten (nur wo unterstützt, i. d. R. HTTPS nötig)
  function wachHalten() {
    if ('wakeLock' in navigator) navigator.wakeLock.request('screen').catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      wachHalten();
      ladeListe().then(beschrifte).catch(function () {});
    }
  });

  // ---- Start -----------------------------------------------------------------
  uebernehme(<?= json_encode(daten(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
  erinnere();
  $('btn-nurfav').className = zustand.nurFav ? 'an' : '';
  setzeModus(zustand.modus);
  if (zustand.reihenfolge.length && zustand.pos < zustand.reihenfolge.length) abgleichen();
  if (!zustand.reihenfolge.length) neuerZyklus();
  zeige();
  wachHalten();

  setInterval(function () {
    ladeListe().then(function () {
      if (!angezeigt) zeige(); else beschrifte();
    }).catch(function () {});
  }, CFG.listeAlle);
  setInterval(beschrifte, 30000);        // "vor X Min." aktuell halten
  setTimeout(function () { location.replace(location.pathname); }, CFG.seiteNeuNach);
})();
</script>
</body>
</html>
