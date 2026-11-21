# KLXM Studio – Gebietskarten (`gebietskarten`)

Flächen einer freien Kartengrundlage zu **Gebieten** zusammenfassen und auf der Website zeigen – Regionalgruppen eines Vereins,
die Kreise eines Verbands, Vertriebsgebiete. Je Gebiet: Name, Farbe, Link, Infotext, Bild und Kontakt. Ausgabe als
serverseitig gerendertes, zugängliches SVG ohne Kartenbibliothek, ohne Kacheln und ohne Anfragen an Dritte.

Gebietskarten sind **keine Straßenkarten**: Für Standorte, Adressen und Zoomen gibt es die Karten des Cores (`Core\Maps`, MapLibre).
Hier geht es nur um Flächen und ihre Zuordnung.

## Installation

```bash
composer require klxm/studio-gebietskarten   # oder das Repository nach extensions/gebietskarten klonen
php bin/console extensions:publish           # gebaute Dateien nach public/assets/ext/gebietskarten
php bin/console migrate --all                # Tabellen gebietskarten_maps, gebietskarten_regions
php bin/console gebietskarten:daten --land=05   # nur für die Ebene „Gemeinden“: Kartendaten je Land nachladen
```

Einschalten je Website unter **Administration → Funktionen & Erweiterungen** oder `'extensions' => ['gebietskarten']` in
`config/sites/{key}.php`. Recht: „Gebietskarten bearbeiten“ (`gebietskarten.edit`). Benötigt KLXM Studio ≥ 1.0.0.

## Funktionen

| Bereich | Was |
|---|---|
| Kartengrundlage | Deutschland: Bundesländer, Kreise und kreisfreie Städte (im Paket), Gemeinden je Land (zum Nachladen). Ausschnitt: einzelne Länder oder ganz Deutschland. |
| Verwaltung | **Gebietskarten** im Menü: Karte anlegen, Gebiete bilden per Klick auf die Karte oder per Liste mit Suche (Tastatur), Angaben je Gebiet mit Linkauswahl und Mediathek, Vorschau hell/dunkel mit den Farben des Kits, Kartendaten nachladen. |
| Datentabelle | Gebiete statt hier aus einer Datentabelle: ein Eintrag = ein Gebiet, Flächen als Namen oder amtliche Schlüssel in einem Feld. Auch geteilte Tabellen im Netzwerk – jede Website hebt „ihr“ Gebiet hervor (Feld „Website“). |
| Block „Gebietskarte“ | Übersicht, ein Gebiet hervorheben, Gebiet dieser Seite oder Website; Klick öffnet den Link, ein Info-Fenster oder nichts. Liste der Gebiete als Alternative, Quellenvermerk unter jeder Karte. |
| Barrierefreiheit | Jedes Gebiet ein Link bzw. Sprungziel mit Namen, sichtbarer Fokus, Liste für Screenreader und ohne JavaScript, Namen in der Karte mit Kontrast-Rand. |
| CSP | Keine `style`-Attribute, keine Inline-Skripte: Farben als Klassen (Kit-Vertrag `--kit-*`) bzw. SVG-Attribut `fill`; Skript (unter 2 KB) nur beim Info-Fenster. |
| Grafik | „Als Grafik weiterbearbeiten“: Karte als SVG in die Mediathek und – wenn das Paket `grafik` aktiv ist – gleich in den Grafik-Editor. |

Befehle: `gebietskarten:daten` (Kartendaten nachladen, `--land=05,06`, `--from=URL|Ordner`, `--list`, `--remove`) und
`gebietskarten:selftest`. Doku in der Verwaltung: Handbuch → Gebietskarten, Entwicklerhandbuch → Gebietskarten (Erweiterung).

## Kartendaten, Quelle und Lizenz

| Datei | Inhalt | Größe |
|---|---|---|
| `data/de/laender.topo.json` | 16 Bundesländer | ca. 25 KB |
| `data/de/kreise.topo.json` | 401 Kreise und kreisfreie Städte | ca. 250 KB |
| Branch `data`: `de/gemeinden/{01–16}.topo.json` + `manifest.json` | 10 939 Gemeinden (inkl. gemeindefreier Gebiete), je Land 4 KB (Berlin) bis 1,2 MB (Bayern), zusammen ca. 5,7 MB | Nachladen |

- **Quelle:** Bundesamt für Kartographie und Geodäsie (BKG), *Verwaltungsgebiete 1:250 000 – VG250, Ebenen*, Stand 01.01.2026,
  [daten.gdz.bkg.bund.de](https://daten.gdz.bkg.bund.de/produkte/vg/vg250_ebenen_0101/aktuell/) (`vg250_01-01.utm32s.shape.ebenen.zip`).
- **Lizenz:** [Datenlizenz Deutschland – Namensnennung – Version 2.0](https://www.govdata.de/dl-de/by-2-0) (`dl-de/by-2-0`).
- **Quellenvermerk** (Nutzungsbedingungen des BKG für VG250, Stand 19.08.2025): `© BKG (2026) dl-de/by-2-0, Datenquellen:
  https://sgx.geodatenzentrum.de/web_public/gdz/datenquellen/datenquellen_vg_nuts.pdf` – im Web „BKG“ und „dl-de/by-2-0“ verlinkt –
  plus Veränderungshinweis. Die Erweiterung setzt ihn automatisch unter jede Karte und in jeden SVG-Export
  („Kartengrundlage: © BKG (2026) dl-de/by-2-0, Datenquellen, vereinfacht“).
- **Aufbereitung** (`tools/kartendaten/build.mjs`, mapshaper – nur für die Pflege, nicht im Paket): nur Landflächen, im Quellsystem
  topologieerhaltend vereinfacht (Länder 1500 m, Kreise 600 m, Gemeinden 120 m), WGS84, quantisiert. Gemeinden: eine Topologie für
  ganz Deutschland, je Land aufgeteilt mit den Bogen-Nummern der Gesamt-Topologie (`arcIds`) – Gebiete über Landesgrenzen verschmelzen lückenlos.

**Nachladen:** `php bin/console gebietskarten:daten` bzw. Gebietskarten → Kartendaten lädt aus dem Branch `data` dieses Repositorys
(`https://raw.githubusercontent.com/KLXM-Studio/studio-gebietskarten/data/de/gemeinden/`), prüft jede Datei gegen die SHA-256 im
`manifest.json` und legt sie unter `storage/gebietskarten/de/gemeinden/` ab (für alle Websites der Installation, nicht in der Sicherung).
Eigene Quelle: `'gebietskarten' => ['data_url' => 'https://…/' oder '/pfad/zum/ordner']` in der Konfiguration.

**Kartendaten neu erzeugen** (neuer Stand des BKG): ZIP laden und entpacken, dann
`cd tools/kartendaten && pnpm install && node build.mjs <ordner-mit-VG250_LAN.shp> <jahr>` – schreibt `data/de/` und
`build/data/de/gemeinden/` (Inhalt für den Branch `data`).

## English

**Area maps for KLXM Studio.** Combine the shapes of an open base map (Germany: states, districts, municipalities from the BKG VG250
dataset) into areas such as regional groups, districts of an association or sales territories, add a name, color, link, text, image
and contact per area, and show them with the “Area map” block: a server-rendered, accessible SVG without any map library or third-party
requests (strict CSP, no style attributes). Areas can also come from a data table, including shared tables across a network. Map data:
© BKG (2026) dl-de/by-2-0 (Data licence Germany – attribution – version 2.0), simplified; the attribution is added below every map
automatically. Municipalities are downloaded per state on demand (`php bin/console gebietskarten:daten`). MIT licensed.

## Lizenz

MIT (siehe `LICENSE`) für den Code. Kartendaten: dl-de/by-2-0 (siehe oben und `THIRD-PARTY-NOTICES.md`).
