// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
/**
 * Kartendaten Deutschland aus BKG VG250 (Verwaltungsgebiete 1:250 000, Ebenen, Stand 01.01.) aufbereiten.
 * Nur für die Pflege des Pakets – nicht Teil der Auslieferung (.gitattributes export-ignore).
 *
 * Quelle: https://daten.gdz.bkg.bund.de/produkte/vg/vg250_ebenen_0101/aktuell/vg250_01-01.utm32s.shape.ebenen.zip
 * Lizenz: Datenlizenz Deutschland – Namensnennung – Version 2.0 (dl-de/by-2-0). Quellenvermerk laut „Nutzungsbedingungen und
 * Quellenvermerk VG250“ (BKG, Stand 19.08.2025): „© BKG (Jahr des letzten Datenbezugs) dl-de/by-2-0, Datenquellen: …“, im Web „BKG“ und
 * „dl-de/by-2-0“ verlinkt, dazu ein Veränderungshinweis (hier: vereinfacht).
 *
 * Aufruf:  cd tools/kartendaten && pnpm install && node build.mjs <ordner-mit-VG250_*.shp> [jahr]
 *
 * Ergebnis (TopoJSON, WGS84, topologieerhaltend vereinfacht – gemeinsame Grenzen bestehen aus denselben Bögen, damit
 * Klxm\Gebietskarten\Topo die Flächen eines Gebiets lückenlos verschmelzen kann; Koordinaten quantisiert):
 *   ../../data/de/laender.topo.json            Bundesländer (im Paket)
 *   ../../data/de/kreise.topo.json             Kreise und kreisfreie Städte (im Paket)
 *   ../../build/data/de/gemeinden/{SN_L}.topo.json + manifest.json   Gemeinden je Land (Datenpaket zum Nachladen,
 *                                               Branch „data“ des Repositorys – siehe README)
 * Merkmale je Fläche: id (AGS), ars (ARS), name (GEN), bez (BEZ), l (Land, 2 Stellen), k (Kreis, 5 Stellen, nur Gemeinden),
 * x/y (Punkt im Inneren für Beschriftungen, Grad).
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import mapshaper from 'mapshaper';

const here = path.dirname(fileURLToPath(import.meta.url));
const pkg = path.resolve(here, '../..');
const [src, year = String(new Date().getFullYear())] = process.argv.slice(2);
if (!src || !fs.existsSync(path.join(src, 'VG250_LAN.shp'))) {
  console.error('Aufruf: node build.mjs <ordner-mit-VG250_LAN.shp> [jahr]');
  process.exit(1);
}
const out = path.join(pkg, 'data/de');
const pack = path.join(pkg, 'build/data/de/gemeinden');
fs.mkdirSync(out, { recursive: true });
fs.mkdirSync(pack, { recursive: true });

const LAENDER = { '01': 'Schleswig-Holstein', '02': 'Hamburg', '03': 'Niedersachsen', '04': 'Bremen', '05': 'Nordrhein-Westfalen',
  '06': 'Hessen', '07': 'Rheinland-Pfalz', '08': 'Baden-Württemberg', '09': 'Bayern', '10': 'Saarland', '11': 'Berlin',
  '12': 'Brandenburg', '13': 'Mecklenburg-Vorpommern', '14': 'Sachsen', '15': 'Sachsen-Anhalt', '16': 'Thüringen' };

const run = cmd => new Promise((ok, fail) => mapshaper.runCommands(cmd, err => (err ? fail(err) : ok())));
const q = p => JSON.stringify(p);
// Felder: AGS → id, Land/Kreis aus dem Schlüssel, Punkt im Inneren (nach dem Umprojizieren, 4 Stellen ≈ 10 m)
const each = (withKreis) => `-each "id=AGS, ars=ARS, name=GEN, bez=BEZ, l=AGS.slice(0,2)${withKreis ? ', k=AGS.slice(0,5)' : ''}"`;
const fields = withKreis => `-filter-fields id,ars,name,bez,l${withKreis ? ',k' : ''}`;
const label = `-each "x=Math.round(this.innerX*1e4)/1e4, y=Math.round(this.innerY*1e4)/1e4"`;

/**
 * Vereinfachen im Quellsystem (UTM 32, Meter): interval = kleinste Strukturgröße in Metern (Visvalingam, gewichtet),
 * keep-shapes = keine Fläche verschwindet; danach WGS84 und Quantisierung (Schritte über den Ausschnitt).
 */
async function layer(file, interval, withKreis, target, quant = 1e5) {
  await run(`-i ${q(path.join(src, file))} -filter "GF==4" ${each(withKreis)} ${fields(withKreis)}`
    + ` -simplify interval=${interval} keep-shapes -proj wgs84 ${label}`
    + ` -rename-layers einheiten -o ${q(target)} format=topojson quantization=${quant} force`);
}

// Kopfangaben (Quelle, Lizenz, Stand) in jede Datei
function stamp(file, meta) {
  const t = JSON.parse(fs.readFileSync(file, 'utf8'));
  t.meta = meta;
  fs.writeFileSync(file, JSON.stringify(t));
  return fs.statSync(file).size;
}
const meta = (ebene, extra = {}) => ({
  grundlage: 'de', ebene, quelle: 'BKG VG250 (Verwaltungsgebiete 1:250 000, Ebenen), Stand 01.01.' + year,
  vermerk: `© BKG (${year}) dl-de/by-2-0`, jahr: year, anbieter: 'BKG', anbieter_url: 'https://www.bkg.bund.de',
  lizenz: 'Datenlizenz Deutschland – Namensnennung – Version 2.0', lizenz_kurz: 'dl-de/by-2-0', lizenz_url: 'https://www.govdata.de/dl-de/by-2-0',
  datenquellen_url: 'https://sgx.geodatenzentrum.de/web_public/gdz/datenquellen/datenquellen_vg_nuts.pdf', veraendert: 'vereinfacht', ...extra,
});

const kb = n => (n / 1024).toFixed(1) + ' KB';
let size = await (async () => { await layer('VG250_LAN.shp', 1500, false, path.join(out, 'laender.topo.json')); return stamp(path.join(out, 'laender.topo.json'), meta('laender')); })();
console.log('laender.topo.json', kb(size));
await layer('VG250_KRS.shp', 600, false, path.join(out, 'kreise.topo.json'));
size = stamp(path.join(out, 'kreise.topo.json'), meta('kreise'));
console.log('kreise.topo.json ', kb(size));

// Gemeinden: EINE Topologie für ganz Deutschland (einmal vereinfacht, eine Quantisierung), dann je Land aufgeteilt. Jede
// Datei behält die Nummern der Bögen der Gesamt-Topologie (arcIds) – so passen Grenzen zwischen zwei Ländern exakt
// aufeinander, und ein Gebiet aus Gemeinden mehrerer Länder verschmilzt ohne Spalt.
const manifest = { grundlage: 'de', ebene: 'gemeinden', stand: '01.01.' + year, vermerk: `© BKG (${year}) dl-de/by-2-0`,
  lizenz: 'Datenlizenz Deutschland – Namensnennung – Version 2.0', lizenz_url: 'https://www.govdata.de/dl-de/by-2-0', files: {} };
const tmp = path.join(pkg, 'build/tmp');
fs.mkdirSync(tmp, { recursive: true });
const all = path.join(tmp, 'gemeinden.topo.json');
await run(`-i ${q(path.join(src, 'VG250_GEM.shp'))} -filter "GF==4" ${each(true)} ${fields(true)}`
  + ` -simplify interval=120 keep-shapes -proj wgs84 ${label}`
  + ` -rename-layers einheiten -o ${q(all)} format=topojson quantization=1e5 force`);
const topo = JSON.parse(fs.readFileSync(all, 'utf8'));
const geoms = topo.objects.einheiten.geometries;
const arcRefs = g => (g.type === 'MultiPolygon' ? g.arcs.flat(2) : g.type === 'Polygon' ? g.arcs.flat() : []);
for (const sn of Object.keys(LAENDER).sort()) {
  const name = LAENDER[sn];
  const mine = geoms.filter(g => g.properties.l === sn);
  const used = [...new Set(mine.flatMap(arcRefs).map(a => (a < 0 ? ~a : a)))].sort((a, b) => a - b);
  const local = new Map(used.map((g, i) => [g, i]));
  const re = a => (a < 0 ? ~local.get(~a) : local.get(a));
  const remap = g => ({ ...g, arcs: g.type === 'MultiPolygon' ? g.arcs.map(p => p.map(r => r.map(re))) : g.arcs.map(r => r.map(re)) });
  const part = { type: 'Topology', transform: topo.transform, arcs: used.map(i => topo.arcs[i]), arcIds: used,
    objects: { einheiten: { type: 'GeometryCollection', geometries: mine.map(remap) } },
    meta: meta('gemeinden', { land: sn, land_name: name, topologie: 'de-gemeinden-' + year }) };
  const file = path.join(pack, sn + '.topo.json');
  const body = JSON.stringify(part);
  fs.writeFileSync(file, body);
  manifest.files[sn] = { name, file: sn + '.topo.json', bytes: body.length, sha256: crypto.createHash('sha256').update(body).digest('hex'), count: mine.length };
  console.log(`gemeinden/${sn}.topo.json`, name.padEnd(24), String(mine.length).padStart(5), 'Gemeinden', kb(body.length));
}
fs.writeFileSync(path.join(pack, 'manifest.json'), JSON.stringify(manifest, null, 1) + '\n');
fs.rmSync(tmp, { recursive: true, force: true });
console.log('manifest.json →', pack);
