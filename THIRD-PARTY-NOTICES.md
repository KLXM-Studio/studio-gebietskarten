# Third-party notices – KLXM Studio Gebietskarten (`klxm/studio-gebietskarten`)

The extension code is MIT licensed (see `LICENSE`). It bundles no third-party code: the browser scripts and styles in `assets/`
and `public/` are written for this package.

## Map data

| Data | Files | Source | License | Attribution |
|---|---|---|---|---|
| Verwaltungsgebiete 1:250 000 (VG250), levels, as of 2026-01-01 – states, districts (shipped), municipalities (download on demand, branch `data`) | `data/de/laender.topo.json`, `data/de/kreise.topo.json`, `de/gemeinden/*.topo.json` | Bundesamt für Kartographie und Geodäsie (BKG), https://daten.gdz.bkg.bund.de/produkte/vg/vg250_ebenen_0101/aktuell/ | Datenlizenz Deutschland – Namensnennung – Version 2.0 (`dl-de/by-2-0`), https://www.govdata.de/dl-de/by-2-0 | `© BKG (2026) dl-de/by-2-0, Datenquellen: https://sgx.geodatenzentrum.de/web_public/gdz/datenquellen/datenquellen_vg_nuts.pdf` – with the change note “vereinfacht” (simplified, reprojected to WGS84, land areas only, attributes reduced) |

The attribution is rendered automatically below every map (provider, licence and data sources linked) and written into every SVG
export and the credit of the media file. Summary of the licence (§ 2): the source note must name the provider, the licence
(“dl-de/by-2-0” with a link to the licence text) and refer to the dataset; changes must be marked (§ 3).

## Tools (not shipped)

`tools/kartendaten/` (excluded from archives via `.gitattributes`) uses **mapshaper** (MPL-2.0, https://github.com/mbloch/mapshaper)
as a development dependency to prepare the map data. It is not part of the package.
