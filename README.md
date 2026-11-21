# Gebietskarten – Datenpakete zum Nachladen (Branch `data`)

Gemeinden je Bundesland für die Erweiterung „Gebietskarten“ von KLXM Studio (`klxm/studio-gebietskarten`). Nicht Teil des
Pakets – `php bin/console gebietskarten:daten` bzw. Gebietskarten → Kartendaten lädt einzelne Länder von hier
(`https://raw.githubusercontent.com/KLXM-Studio/studio-gebietskarten/data/de/gemeinden/`) und prüft jede Datei gegen die
SHA-256 in `de/gemeinden/manifest.json`.

- **Inhalt:** `de/gemeinden/{01–16}.topo.json` (TopoJSON, WGS84, quantisiert; Merkmale id = AGS, ars, name, bez, l, k, x, y;
  `arcIds` = Bogen-Nummern der Gesamt-Topologie, damit Gebiete über Landesgrenzen lückenlos verschmelzen), `manifest.json`.
- **Quelle:** Bundesamt für Kartographie und Geodäsie (BKG), Verwaltungsgebiete 1:250 000 (VG250), Ebenen, Stand 01.01.2026.
- **Lizenz:** Datenlizenz Deutschland – Namensnennung – Version 2.0 (https://www.govdata.de/dl-de/by-2-0).
- **Quellenvermerk:** © BKG (2026) dl-de/by-2-0, Datenquellen:
  https://sgx.geodatenzentrum.de/web_public/gdz/datenquellen/datenquellen_vg_nuts.pdf – Daten verändert (vereinfacht, nur
  Landflächen, umprojiziert, Merkmale reduziert).
- **Erzeugt mit** `tools/kartendaten/build.mjs` im Branch `main` (mapshaper, Vereinfachung 120 m).
