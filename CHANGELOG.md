# Changelog – Gebietskarten (`klxm/studio-gebietskarten`)

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
