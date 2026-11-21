# Changelog – Gebietskarten (`klxm/studio-gebietskarten`)

## 0.2.1 (2026-11-21)

- **Automatische Farben ohne Grau, Nachbarn verschieden:** Die automatische Palette vergibt kein Grau mehr (sah aus wie „nicht zugeordnet“;
  von Hand weiter wählbar) und ist um Indigo, Weinrot, Oliv und Grasgrün erweitert (`p9`–`p12`, elf automatische Farben). Gebiete mit
  gemeinsamer Grenze bekommen verschiedene Farben (Graphfärbung über die Bögen der verschmolzenen Gebiete, `Colors::assign()`,
  `Render::neighbors()`); wiederholt wird erst, wenn alle Farben vergeben sind. Bis sieben Gebiete bleiben die Farben wie bisher.
- **Palette lesbar hell und dunkel:** Blau, Indigo und Weinrot hell stärker aufgehellt; im Dunkeln setzt die erzeugte Datei `farben-….css`
  je Palettenfarbe einen Wert, auf dem die Beschriftung mindestens 4,5:1 erreicht (bisher nur bei eigenen Farben). Die Vorschau „Dunkel“
  der Verwaltung und die Kontrastanzeige im Formular zeigen dieselben Werte.
- Selbsttest erweitert (Palette, Nachbarn bei 11 und 16 Gebieten, Kontrast, Dunkel-Regeln).

## 0.2.0 (2026-11-21)

- **Zuordnung per Klick auch bei Karten aus einer Datentabelle:** Klick auf die Fläche bzw. die Liste (Tastatur) schreibt das Feld mit den
  Flächen in die Einträge – über `Core\Data\Entries::save()` (Prüfung, Bearbeitungssperre, Version mit Notiz, Aktionsprotokoll, Seiten-Cache),
  Rechte wie beim Bearbeiten (`data.edit` je Tabelle, `EntryEdit::reason()`: veröffentlicht nur mit `data.publish`, fremde Einträge geteilter
  Tabellen und externe Quellen nie), Status bleibt. Gesperrte Einträge sind markiert, ihre Flächen unveränderbar. Felder: Mehrfachauswahl,
  Text, Textfeld; Schreibweise wie in der Tabelle (Namen oder Schlüssel), Werte außerhalb der Karte bleiben (`EntryAssign`).
- **Gebiete sortieren** per Ziehen, Pfeil-Knöpfen oder `Alt`+`↑/↓` (Route `…/reihenfolge`, Ansage per Live-Region); Liste, Legende und
  automatische Palettenfarben folgen sofort.
- **Eigene Farben mit Dunkel-Variante:** optionales Feld „Eigene Farbe im Dunkeln“ (Spalte `color_dark`, Bindung `color_dark`), sonst
  automatisch abgeleitet, sodass die Beschriftung im Dunkeln mindestens 4,5:1 erreicht. Kontrast hell/dunkel mit Hinweis im Formular.
  Ausgabe ohne Inline-Stile: Dunkel-Regel als erzeugte Datei im Medienordner unter der Dunkel-Regel des Kits (`design.dark`).
- **Vorschau näher am Kit:** `Preview::kit()` löst die Farben wie die Website auf – Stylesheet des Kits (Kit-Vertrag `--kit-*`, Dunkel-Regel)
  plus Design-Werte der Website (`Core\Design::css()`), `var()`, `color-mix()`, `light-dark()`. Hinweis, wenn der Dunkelmodus der Website aus ist.
- **Quellenvermerk überall gleich** nach den BKG-Bedingungen: „Kartengrundlage: © BKG (Jahr) dl-de/by-2-0, Datenquellen, vereinfacht“ (Web mit
  Links) bzw. mit Adressen (Mediathek, SVG-Export, Grafik-Editor: zweizeilig plus `<desc>`, übersteht die SVG-Bereinigung). Auch auf
  „Kartendaten“. Ältere Exporte mit „© GeoBasis-DE / BKG“ bitte neu exportieren.
- Selbsttest erweitert (76 Prüfungen), Handbuch, Entwicklerhandbuch, englische Texte.

## 0.1.0 (2026-11-21)

Erste Fassung.

- **Kartengrundlage Deutschland** aus BKG VG250 (Stand 01.01.2026, dl-de/by-2-0): Bundesländer und Kreise im Paket, Gemeinden je Land
  zum Nachladen (`gebietskarten:daten`, Knopf unter Gebietskarten → Kartendaten; SHA-256-Prüfung, Ablage unter `storage/gebietskarten/`).
  Aufbereitung mit `tools/kartendaten/build.mjs` (mapshaper, topologieerhaltend vereinfacht, eine Topologie für alle Gemeinden).
- **Verschmelzen ohne Lücken** über gemeinsame Bögen (`Topo::merge`), auch über Landesgrenzen; Vereinfachen je Bogen nach dem Projizieren;
  Zwischenspeicher je Karte und Zuordnung.
- **Datenmodell** (`Core\Db\Table`): Karten (Grundlage, Ebene, Ausschnitt, Darstellung) und Gebiete (Name, Farbe, Link, Infotext, Bild,
  Kontakt, Flächen). Jede Fläche gehört höchstens zu einem Gebiet.
- **Datentabellen-Bindung:** ein Eintrag = ein Gebiet, Flächen als Namen oder amtliche Schlüssel; Name, Farbe, Link, Text, Bild, Kontakt
  und Website aus Feldern; geteilte Tabellen im Netzwerk („Gebiet dieser Website“).
- **Verwaltung** „Gebietskarten“: Karte anlegen, Gebiete per Klick auf die Karte oder per Liste mit Suche (Tastatur), Angaben je Gebiet
  (Linkauswahl, Mediathek, Rich-Text), Vorschau hell/dunkel, Kartendaten, Hinweise zu fehlenden Daten und nicht passenden Angaben.
- **Block „Gebietskarte“:** Übersicht, ein Gebiet, Gebiet dieser Seite oder Website; Klick: Link, Info-Fenster oder nichts; Liste der
  Gebiete; Quellenvermerk mit Links (BKG, Lizenz, Datenquellen) und Veränderungshinweis. Keine `style`-Attribute, keine Inline-Skripte,
  Farben über den Kit-Vertrag (`--kit-*`) bzw. das SVG-Attribut `fill`; Skript nur beim Info-Fenster (unter 2 KB).
- **Als Grafik weiterbearbeiten:** bereinigtes SVG in die Mediathek, mit dem Paket `grafik` direkt in den Grafik-Editor.
- Handbuch, Entwicklerhandbuch, englische Texte (Verwaltung und Website), Selbsttest `gebietskarten:selftest`.
