# Changelog – Gebietskarten (`klxm/studio-gebietskarten`)

## 0.7.1 (2026-11-22)

- **Karten aus einer Datentabelle in Website-Vorlagen:** Nach dem Regionswechsel bekommt das gebundene Feld der Einträge (z. B. „Gebiet“ der
  Regionalgruppen) genau die Beispiel-Kreise des neuen Landes – die Kreise der Quell-Region fallen weg, die Karte zeigt die Flächen. Vorher
  blieben die alten Werte stehen und die Karte leer.
- **Neutrale Namen:** Beim Export heißen Einträge, die an Flächen hängen, nach ihrer Lage („Regionalgruppe Nord/Mitte/Süd“, eigene Gebiete
  „Gebiet Nord …“) – auch in Texten und Rollen; nach dem Regionswechsel heißen Demo-Einträge nach ihrem ersten Beispiel-Kreis
  („Regionalgruppe Darmstadt“, Titel, Adresse, Nennungen in Eintrag und Seiten).
- **Ortsnamen der Quell-Region** für die Prüfung der Texte (Filter `blueprint.areas` des Cores): Kreise und kreisfreie Städte des Ausschnitts
  und die Namen der Gebiete; Felder mit Flächen werden nicht geprüft.
- Selbsttest für Karten aus einer Datentabelle (Ortsnamen, neutrale Namen, Feld ersetzt, Umbenennen, Karte zeigt Flächen).

## 0.7.0 (2026-11-22)

- **Website-Vorlagen des Cores** (`Core\Blueprint`, Filter `blueprint.*`): Karten und Gebiete gehen mit einer Vorlage mit (ohne
  Ansprechpersonen und Bilder) und behalten ihre IDs, damit Blöcke „Gebietskarte“ weiter passen.
- **Gebiet der neuen Website:** Wählt sie in der Schnelleinrichtung ihr Land (z. B. Hessen bzw. Schlüssel `06`), zeigt jede Karte mit Kreisen
  oder Gemeinden das neue Land; die Beispiel-Gebiete bekommen je zwei Kreise, der Rest bleibt grau. Je Karte entsteht eine Aufgabe
  „Gebiete zuordnen“ mit Link auf die Karte. Platzhalter der Vorlage in Titeln, Namen und Infotexten werden gefüllt.
- **Region der Quelle:** Der Ausschnitt der Karte meldet dem Export das Land der Quelle – so werden „NRW“ und „Nordrhein-Westfalen“ in Texten
  zu Platzhaltern.
- Selbsttest „Website-Vorlagen“ (Export, Quelle, Regionswechsel, Platzhalter, Aufgabe).

## 0.6.0 (2026-11-22)

- **Mitte eines Gebiets als Kartenpunkt** (Filter `data.nearby_point` des Cores): Einträge ohne Kartenfeld, die Gebiet einer Karte mit
  Datentabelle sind (z. B. Regionalgruppen), bekommen die Mitte ihrer Flächen. „Zuständige zuerst, dann im Umkreis“ zeigt damit nach der
  zuständigen auch die benachbarten Gruppen; Termine und Kontakte ohne eigenen Ort erben den Punkt über ihre Verknüpfung (Core).
- **Gemeindedaten für „In deiner Nähe“:** Der Editor einer Karte mit Datentabelle weist darauf hin, wenn für Orte unterhalb der Kreisebene
  (z. B. Gevelsberg) die Gemeindedaten der Länder im Ausschnitt fehlen – mit Knopf „Gemeindedaten laden“. Kurzform auf der Kommandozeile:
  `gebietskarten:data gemeinden --land=05` (wie `gebietskarten:daten --land=05`).
- `Nearby::pointOf()`; Selbsttest für zuständige Gebiete und die Mitte eines Gebiets.

## 0.5.0 (2026-11-22)

- **„In deiner Nähe“ des Cores** (Filter `data.nearby`, Core-Stufe „Netzwerk“): Besucher geben einen Ort ein, die Erweiterung findet die
  Fläche ohne Anfragen an Dritte – Gemeinde (wenn nachgeladen), Kreis oder Land, auch „Kreis Wesel“ oder den amtlichen Schlüssel – bzw. bei
  einem Kartenpunkt (PLZ über die Geokodierung des Cores) die Fläche, in der er liegt. Sie liefert den Beschriftungspunkt als Kartenpunkt und
  alle Gebiete von Karten mit Datentabelle, die die Fläche enthalten (Gemeinde → Kreis → Gebiet) – als Einträge „tabelle:id“ und mit dem Feld
  „Website“ die zuständige Website. So zeigt der Core zuerst Termine und Ansprechpersonen der Regionalgruppe bzw. beim Verband die
  Ansprechpartner des zuständigen Landesvereins. Feld „Website“: Schlüssel oder Host der Website.
- `Nearby::byName()`, `byPoint()`, `regionsFor()`, `siteKey()`; Selbsttest „In deiner Nähe“ in `gebietskarten:selftest`.
- Braucht für die Anbindung Core mit `Core\Data\Nearby` (ältere Core-Versionen: Filter wird nicht angemeldet).

## 0.4.0 (2026-11-22)

- **Abschnitt „Darstellung“ im Karteneditor:** Die Redaktion stellt das Aussehen je Karte selbst ein; die Vorschau zeigt jede Änderung sofort
  (Entwurf, hell/dunkel) – den Hover-Effekt beim Überfahren mit der Maus. Gespeichert mit „Darstellung speichern“ (Route `POST …/{id}/darstellung`).
  Schriftgröße, Stil und Großbuchstaben der Beschriftungen sind von „Einstellungen der Karte“ hierher umgezogen. Kits liefern nur Standardwerte (`--gk-*`).
- **Hover- und Fokus-Effekt:** *keiner* (Standard), *dezent* (übrige Gebiete treten zurück, nur CSS mit `:has()`) oder *animiert*: Das Gebiet hebt sich
  weich federnd (`cubic-bezier(.34, 1.56, .64, 1)`, Faktor `--gk-lift` 1,05 um den Pol der Unzugänglichkeit) mit mitwachsendem Schatten hervor, samt inneren
  Grenzen (Kopie in einer Ebene über den Flächen und unter den Beschriftungen) – der Name geht mit. Tastaturfokus wie Hover (mit Fokusrand), auf
  Touch-Geräten keine Hover-Animation, bei `prefers-reduced-motion` keine Bewegung, nur ein ruhiger Schatten. Nur Klassen und CSSOM, keine `style`-Attribute.
- **Schatten unter der Karte** an/aus, im Dunkeln kräftiger (erzeugte Datei unter der Dunkel-Regel des Kits).
- **Beschriftungsstil „Pill“:** *Pill hell* (Fläche und Schrift des Kits, feiner Rand) und *Pill dunkel* (Akzent dunkel, helle Schrift) – je Karte und je
  Gebiet; abgerundetes Rechteck hinter dem Text (einzeilig `rx` = Höhe/2, mehrzeilig 0,6 em), Maße aus der Textschätzung plus Innenabstand – auch für
  Platzprüfung, Größe „automatisch“ und Hinweislinie. Kontrastprüfung und Hinweise wie bei den anderen Stilen. Auf dem Telefon skaliert die Pill als Ganzes.
  **Schatten der Pills** an/aus (`--gk-pill-shadow`), wächst beim Anheben mit.
- **Automatische Farben: „Töne der Akzentfarbe“** als Alternative zur Palette – sechs Abstufungen der Akzentfarbe des Kits (hell und dunkel passend),
  Nachbargebiete verschieden (Graphfärbung über die Topologie). Töne, auf denen weder helle noch dunkle Schrift 4,5:1 erreicht, werden leicht angepasst.
  Für Karten ohne Farbfeld (z. B. Datentabellen).
- **Bestandskarten unverändert:** Standard „keiner“, ohne Schatten, Palette wie bisher – Ausgabe Zeichen für Zeichen gleich (geprüft an sechs Karten,
  Pixelvergleich Desktop/Telefon, hell/dunkel).
- Selbsttest erweitert (141 Prüfungen), Handbuch, Entwicklerhandbuch, englische Texte.

## 0.3.0 (2026-11-22)

- **Beschriftungen im Karteneditor:** neuer Abschnitt „Beschriftungen“ mit der Karte wie auf der Website (hell/dunkel). Je Gebiet: zeigen/ausblenden
  (auch einzelne Gebiete bei ausgeschalteten Namen), eigener Text (Kurzname, Zeilenumbruch, höchstens 3 Zeilen), Lage per Ziehen (Maus, Stift, Finger)
  oder Pfeiltasten (Umschalt = 10er-Schritte), „Lage zurücksetzen“, **Hinweislinie** mit eigenem, verschiebbarem Ankerpunkt für kleine Gebiete.
  Jede Änderung zeichnet die Karte sofort neu (Entwurf), gespeichert wird mit „Beschriftungen speichern“.
- **Gestaltung je Karte und je Gebiet:** Schriftgröße S/M/L oder automatisch nach Fläche (größte, die ganz ins Gebiet passt), Stil „automatisch
  hell/dunkel“ (bessere Schriftfarbe zur Gebietsfarbe, hell und im Dunkeln getrennt), „Schrift des Kits mit Kontrastrand“ (bisher), dunkel, hell,
  Etikett mit Hintergrund; Großbuchstaben. **Kontrastprüfung** hell/dunkel mit Warnung unter 4,5:1 und Hinweis, wenn der Text nicht ins Gebiet passt.
- **Automatische Lage:** Pol der Unzugänglichkeit (Mitte der größten freien Fläche, polylabel) statt Schwerpunkt-Näherung. **Bestandskarten bleiben
  unverändert** (Optionen ohne `label_mode` → bisherige Lage, Größe M, Schrift des Kits; Ausgabe Zeichen für Zeichen wie 0.2.1, Pixelvergleich
  Desktop/Telefon, hell/dunkel); der Knopf „Automatisch neu setzen“ stellt eine Karte bewusst auf die neue Lage um.
- **Telefon-Regel** (Karten mit neuer Lage): unter 36rem Kartenbreite Schrift 1,6-fach im Kartenmaß, sonst nächstkleinere Größe, ohne Platz (und mit
  Hinweislinie) ausgeblendet – per Container-Abfrage, ohne Skript.
- **Datentabellen:** Lage und Gestaltung je Eintrag in der Karte (Kartenmetadaten); Text optional aus einem Feld der Einträge (Bindung „Beschriftung in
  der Karte“, Text oder Textfeld) – im Editor änderbar, geschrieben mit `Entries::save()` (Rechte, Version mit Notiz, Protokoll, Status bleibt).
- Ausgabe weiter reines SVG ohne `style`-Attribute und Skripte (Klassen, `tspan`, Etikett als SVG-Filter mit Farbe per CSS, Dunkel-Ton in der erzeugten
  Datei `farben-….css`); SVG-Export übernimmt Lage, Text, Größe und Ton (Namen nur noch, wenn sie auch auf der Website erscheinen).
- Neue Spalte `gebietskarten_maps.labels` (`php bin/console migrate --all`), Route `POST …/{id}/beschriftung` und `…/beschriftung/automatisch`.
- Selbsttest erweitert (114 Prüfungen), Handbuch, Entwicklerhandbuch, englische Texte.

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
