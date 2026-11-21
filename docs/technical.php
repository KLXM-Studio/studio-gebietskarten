<?php /** Entwicklerhandbuch · Gebietskarten (Erweiterung „gebietskarten“, Paket klxm/studio-gebietskarten) – Extension::docs('technical'), nach „Medien“ */ ?>
  <p class="lead">Gebietskarten ist ein eigenes Paket (<code>klxm/studio-gebietskarten</code>, Composer-Typ <code>klxm-studio-extension</code>, Namensraum
    <code>Klxm\Gebietskarten</code>, MIT). Es fasst Flächen einer Kartengrundlage zu Gebieten zusammen und gibt sie als serverseitig gerendertes SVG aus –
    ohne Kartenbibliothek, ohne Kacheln, ohne Anfragen an Dritte. Abgegrenzt von <code>Core\Maps</code> (Straßenkarten mit MapLibre).</p>

  <h3 id="gebietskarten-aufbau">Aufbau und Andockstellen</h3>
  <ul>
    <li><b>Dateien:</b> <code>extension.php</code> (Manifest), <code>src/</code> – <code>Topo</code> (TopoJSON lesen, verschmelzen, projizieren,
      vereinfachen, SVG-Pfade), <code>Bases</code> (Grundlagen, Ebenen, Quellenvermerk), <code>DataPacks</code> (Nachladen), <code>Repo</code>
      (Tabellen), <code>Bound</code> (Datentabellen), <code>EntryAssign</code> (Zuordnung per Klick in Einträge), <code>Render</code> (Ausgabe),
      <code>Colors</code> (Farben, Dunkel-Werte), <code>Preview</code> (Kit-Farben wie auf der Website), <code>AdminController</code>,
      <code>SelfTest</code>; <code>blocks/gebietskarte.php</code>, <code>views/admin/</code>, <code>data/de/</code> (Länder, Kreise),
      <code>assets/</code> (Quellen) und <code>public/</code> (gebaut, <code>extensions:publish</code>).</li>
    <li><b>Integrationspunkte:</b> <code>feature()</code> + <code>permissions()</code> (<code>gebietskarten.edit</code>), <code>table()</code>
      (<code>gebietskarten_maps</code>, <code>gebietskarten_regions</code>), <code>blocks()</code> (Typ <code>gebietskarte</code>), <code>adminPage()</code>
      (Art <code>content</code>), <code>adminAssets()</code> (Editor-Skript nur auf der Bearbeiten-Seite), <code>routes()</code> (alle unter
      <code>/admin/gebietskarten</code> mit Recht, CSRF über den Router), <code>health()</code>, <code>backupSkip()</code> (nachladbare Daten und Zwischenspeicher),
      <code>docs()</code>, <code>command()</code>.</li>
    <li><b>Befehle:</b> <code>gebietskarten:daten [--land=05,06] [--from=URL|Ordner] [--list] [--remove]</code>, <code>gebietskarten:selftest</code>.</li>
  </ul>

  <h3 id="gebietskarten-geometrie">Geometrie: lückenlos verschmelzen</h3>
  <p>Die Kartendaten sind TopoJSON: Jede Grenze zwischen zwei Flächen ist genau ein <i>Bogen</i>, beide Nachbarn verweisen darauf.
    <code>Topo::merge($einheiten, $boegen)</code> zählt die Bögen eines Gebiets – Bögen, die zweimal vorkommen, liegen innen und fallen weg; der Rest wird an den
    Endpunkten zu Ringen verkettet (wie <code>topojson.merge</code>). Vereinfacht wird nach dem Projizieren je Bogen (Douglas-Peucker, Endpunkte fest,
    ca. 0,5 px), damit Nachbarn dieselbe Grenze behalten. Die Gemeinden aller Länder stammen aus <b>einer</b> Topologie und tragen in <code>arcIds</code>
    die Nummern der Gesamt-Topologie – Gebiete über Landesgrenzen hinweg verschmelzen ebenso. Projektion: abstandstreue Zylinderprojektion mit
    cos(mittlere Breite), Breite 1000 Einheiten. Ergebnis je Karte und Zuordnung in <code>storage/cache/gebietskarten/</code>.</p>

  <h3 id="gebietskarten-ausgabe">Ausgabe (CSP, Zugänglichkeit, Farben)</h3>
  <pre><code>use Klxm\Gebietskarten\{Repo, Render};
echo Render::html(Repo::map(3), ['mode' => 'current', 'action' => 'info']);   // mode: overview|highlight|current|site, action: link|info|none
$svg = Render::svg(Repo::map(3));                                               // eigenständiges SVG (feste Farben, Quellenvermerk)</code></pre>
  <ul>
    <li><code>&lt;figure class="gk"&gt;</code> mit <code>&lt;svg role="group" aria-labelledby&gt;</code> und <code>&lt;title&gt;</code>; Gebiete mit Link als
      <code>&lt;a class="gk-area" aria-label&gt;</code>, beim Info-Fenster mit Sprungziel <code>#…-r{id}</code> in der Liste (ohne JavaScript nutzbar), sonst
      <code>&lt;g&gt;</code>. Darunter <code>&lt;ul class="gk-list"&gt;</code> (sichtbar oder <code>gk-sr</code>) und <code>&lt;p class="gk-src"&gt;</code> mit dem Quellenvermerk.</li>
    <li>Keine <code>style</code>-Attribute: Farben als Klassen <code>gk-c-accent|accent-soft|accent-deep|surface|muted|p1…p12</code> (Variable <code>--gk-c</code>),
      eigene Farben hell als SVG-Darstellungsattribut <code>fill</code> plus Klasse <code>gk-c-own gk-x-{hell}-{dunkel}</code>. Alle Farben aus dem Kit-Vertrag (<code>--kit-*</code>) mit Rückfall; eigene
      Variablen <code>--gk-bg, --gk-ink, --gk-muted, --gk-edge, --gk-rest, --gk-focus, --gk-dim</code> – Kits steuern über sie nach.</li>
    <li><b>Automatische Farben</b> (seit 0.2.1): <code>Colors::assign($regions, $geo['neighbors'])</code> – Palette <code>Colors::AUTO</code> ohne Grau
      (<code>p8</code> nur von Hand), Nachbarn aus gemeinsamen Bögen der Topologie (<code>Render::neighbors()</code>, im Zwischenspeicher der Geometrie)
      bekommen verschiedene Farben (gierige Graphfärbung: Position, dann seltenste freie Farbe); ohne Konflikt gilt weiter Position 1 → <code>p1</code>.
      Hell mischt das CSS mit <code>--kit-bg</code> (Anteil <code>Colors::MIX</code>, sonst 78 %), im Dunkeln setzt die erzeugte Datei
      <code>farben-….css</code> je benutzter Palettenfarbe <code>--gk-c</code> auf <code>Colors::paletteDark()</code> (Beschriftung ≥ 4,5:1).</li>
    <li><b>Eigene Farben im Dunkeln</b> (Spalte <code>color_dark</code>, Bindung <code>color_dark</code>): Wert aus dem Feld oder <code>Colors::dark()</code> –
      78 % mit dem Dunkel-Hintergrund gemischt, dann mit <code>Core\Design::readable()</code> Richtung Hintergrund, bis die Schriftfarbe im Dunkeln
      (<code>--kit-ink</code>) darauf 4,5:1 erreicht. Die Dunkel-Regel steht in einer erzeugten Datei im Medienordner der Website
      (<code>media/gebietskarten/farben-{prüfsumme}.css</code>, wie die Design-Datei des Cores; <code>style-src 'self'</code>) unter der Dunkel-Regel des Kits
      (<code>design.dark</code>: <code>media</code> + <code>scope</code>, Schalter-Klasse <code>manual.dark</code>, <code>force</code>) – ohne Dunkel-Angabe des Kits bleiben
      eigene Farben hell. Ältere Dateien werden nach sieben Tagen entfernt. In der Vorschau der Verwaltung trägt jede Fläche <code>--gk-d</code> inline.</li>
    <li>Stylesheet <code>css/gebietskarten.css</code> einmal je Seite; Skript <code>js/gebietskarten.js</code> (unter 2 KB) nur beim Info-Fenster.
      Position des Fensters per CSSOM.</li>
  </ul>

  <h3 id="gebietskarten-kitfarben">Kit-Farben in der Verwaltung (<code>Preview::kit()</code>)</h3>
  <p>Die Verwaltung lädt das Kit nicht. <code>Preview::kit($dark)</code> löst die Werte deshalb wie der Browser der Website auf: Stylesheet des Kits
    (<code>public/assets/kits/{kit}/css/site.css</code>, Eltern-Kits zuerst) – Variablen an <code>:root</code>, <code>html</code>, <code>body</code>, <code>*</code>,
    <code>:where(*)</code> außerhalb von <code>@media</code>, im Dunkeln zusätzlich unter den Dunkel-Selektoren des Kits –, darüber <code>Core\Design::css()</code>
    (Werte der Website samt Ableitungen), dann <code>var()</code>, <code>color-mix()</code> (sRGB-Näherung), <code>light-dark()</code>, <code>#rgb</code>, <code>rgb()</code>.
    Ergebnis: <code>accent, bg, ink, text, muted, surface, line</code> plus <code>source</code> (<code>kit</code> | <code>tokens</code>). Kits ohne
    Kit-Vertrag: Design-Tokens nach üblichen Namen. Genutzt für die Vorschau, die Editor-Karte, den Dunkel-Wert eigener Farben und den SVG-Export.</p>

  <h3 id="gebietskarten-daten-tech">Kartendaten und Nachladen</h3>
  <ul>
    <li><b>Quelle:</b> BKG VG250 (Ebenen, Stand 01.01.), Datenlizenz Deutschland – Namensnennung 2.0. Aufbereitet mit <code>tools/kartendaten/build.mjs</code>
      (mapshaper, nur für die Pflege, <code>export-ignore</code>): nur Landflächen (GF 4), im Quellsystem topologieerhaltend vereinfacht (Länder 1500 m,
      Kreise 600 m, Gemeinden 120 m), WGS84, quantisiert (1e5). Merkmale: <code>id</code> (AGS), <code>ars</code>, <code>name</code>, <code>bez</code>,
      <code>l</code> (Land), <code>k</code> (Kreis), <code>x</code>/<code>y</code> (Punkt im Inneren für Beschriftungen).</li>
    <li><b>Im Paket:</b> <code>data/de/laender.topo.json</code>, <code>data/de/kreise.topo.json</code>. <b>Nachladen:</b> Gemeinden je Land aus dem Branch
      <code>data</code> des Repositorys (<code>DataPacks::SOURCE</code>, <code>manifest.json</code> mit SHA-256) nach <code>storage/gebietskarten/de/gemeinden/</code>
      (für alle Websites). Eigene Quelle: <code>'gebietskarten' =&gt; ['data_url' =&gt; …]</code> in der Konfiguration (Adresse oder lokaler Ordner).
      Abruf über <code>Core\Http\Fetch</code> (SSRF-Schutz, Größenlimit), jede Datei wird geprüft (Prüfsumme, Topologie, nur Gemeinden des Landes).</li>
    <li><b>Weitere Grundlagen</b> (Welt, andere Länder) kommen in <code>Bases::all()</code> dazu – mit eigener Quelle, Lizenz und eigenem Vermerk.</li>
  </ul>

  <h3 id="gebietskarten-bindung">Datentabellen und Netzwerk</h3>
  <p>Karten mit Quelle <code>table</code> bilden je veröffentlichtem Eintrag ein Gebiet (<code>Bound::regionsOf()</code>). Bindung
    (<code>binding</code>, JSON): <code>table</code>, <code>units</code> (Pflicht), <code>name</code>, <code>color</code>, <code>link</code>, <code>text</code>,
    <code>image</code>, <code>contact</code>, <code>site</code>. <code>Bound::resolve()</code> nimmt Schlüssel (auch ohne führende Null) und Namen
    (ohne Groß-/Kleinschreibung, „Kreis …“, Zusätze nach Komma). Geteilte Tabellen (<code>Core\Data\Shared</code>) gehen über <code>Entries</code> wie lokale;
    <code>site</code> vergleicht mit dem Schlüssel und den Hosts der Website.</p>
  <p><b>Zuordnung per Klick</b> (<code>EntryAssign</code>, Route <code>POST /admin/gebietskarten/{id}/zuordnung</code> wie bei eigenen Karten, JSON
    <code>{"regions": {"{eintrag}": ["05170", …]}}</code>): nur mit <code>can('data.edit', $tabelle)</code> und einem Feld vom Typ <code>multiselect</code>,
    <code>text</code> oder <code>textarea</code>; je Eintrag <code>Core\Data\EntryEdit::reason()</code> (veröffentlicht nur mit <code>data.publish</code>, fremde
    Einträge geteilter Tabellen und externe Quellen nie). Gespeichert wird nur, was sich geändert hat, mit <code>Entries::save($t, $id, [$feld =&gt; …, 'status' =&gt;
    bisher], $notiz)</code> – Prüfung, Bearbeitungssperre, Version (<code>Revisions</code>), <code>EntrySaved</code> (Aktionsprotokoll), Seiten-Cache wie im Core.
    <code>EntryAssign::value()</code> behält Werte außerhalb der Karte, schreibt neue Flächen in der Schreibweise der Tabelle (Mehrheit: Namen oder Schlüssel;
    mehrdeutige Namen als Schlüssel; Mehrfachauswahl nur über vorhandene Optionen) und trennt wie bisher (Zeile, Semikolon, Komma). Antwort: <code>saved</code>,
    <code>errors</code> (je Eintrag), <code>owner</code> (neue Zuordnung), <code>preview</code>.</p>
  <p><b>Reihenfolge</b> eigener Gebiete: <code>POST /admin/gebietskarten/{id}/reihenfolge</code> mit <code>ids[]</code> (Formular oder JSON); JSON-Antwort mit
    <code>order</code>, <code>colors</code> (Palette nach Position) und <code>preview</code>. Editor: HTML-Drag-and-Drop, Knöpfe, <kbd>Alt</kbd>+<kbd>↑/↓</kbd>,
    Ansage über eine Live-Region. Karten aus Datentabellen folgen der Sortierung der Tabelle.</p>
  <p><b>Quellenvermerk</b> an einer Stelle: <code>Bases::attribution()</code> (Anbieter, Jahr, Lizenz, Adressen, Veränderungshinweis) →
    <code>Render::attributionHtml()</code> (unter jeder Karte, Vorschau, Kartendaten) und <code>Render::attributionText()</code> (Mediathek, <code>&lt;desc&gt;</code>
    und zweizeiliger Text im SVG-Export – Links überstehen die SVG-Bereinigung nicht, darum mit Adressen).</p>
  <p>Selbsttest: <code>php bin/console gebietskarten:selftest --site=…</code> (Verschmelzen am Beispiel und an echten Kreisen, über die Landesgrenze,
    Zuordnung, Datentabelle, Ausgabe ohne <code>style</code> und Inline-Skript, Namen und Liste, Quellenvermerk überall gleich, Nachladen mit Prüfsumme;
    seit 0.2.0 Dunkel-Werte und Kontrast, Dunkel-Regel als Datei, Auflösen der Kit-Farben, Reihenfolge und die Zuordnung in Einträge mit Rollen „Autorin“ und
    „Redaktion“ – Version, Protokoll, Status).</p>
