// Postleitzahlen → Kreis (AGS, 5 Stellen), Orte und Punkt für „In deiner Nähe“ (offline, ohne Geokodierung)
// Quelle: GeoNames Postal Codes DE (CC BY 4.0), https://download.geonames.org/export/zip/DE.zip
// Aufruf: node tools/kartendaten/plz.mjs <Pfad zu DE.txt> [Ziel, Standard data/de/plz.json]
// Ergebnis: {"_":{Quelle, Stand}, "47495":[["05170","Rheinberg",51.5465,6.5952]], …} – je PLZ ein Eintrag je Kreis (Orte mit „, “,
// Punkt = Mittel der Orte im Kreis), nach Anzahl der Orte absteigend (PLZ über Kreisgrenzen: alle Kreise).
import { readFileSync, writeFileSync } from 'node:fs';
const [src, dest = 'data/de/plz.json'] = process.argv.slice(2);
if (!src) { console.error('Pfad zu DE.txt fehlt'); process.exit(1); }
const by = new Map();
let skipped = 0;
for (const line of readFileSync(src, 'utf8').split('\n')) {
  const c = line.split('\t');
  if (c.length < 11 || c[0] !== 'DE') continue;
  const [, plz, place, , , , , , ags, lat, lng] = c;
  if (!/^\d{5}$/.test(plz) || !/^\d{5}$/.test(ags)) { skipped++; continue; }
  const k = by.get(plz) ?? new Map(); by.set(plz, k);
  const g = k.get(ags) ?? { places: new Set(), lat: 0, lng: 0, n: 0 }; k.set(ags, g);
  g.places.add(place); g.lat += +lat; g.lng += +lng; g.n++;
}
const out = { _: { source: 'GeoNames Postal Codes DE, https://www.geonames.org (CC BY 4.0)', date: new Date().toISOString().slice(0, 10) } };
for (const plz of [...by.keys()].sort()) {
  out[plz] = [...by.get(plz)].sort((a, b) => b[1].n - a[1].n)
    .map(([ags, g]) => [ags, [...g.places].slice(0, 4).join(', '), +(g.lat / g.n).toFixed(4), +(g.lng / g.n).toFixed(4)]);
}
writeFileSync(dest, JSON.stringify(out));
console.log(`${by.size} Postleitzahlen, ${skipped} Zeilen ohne PLZ/Kreisschlüssel übersprungen → ${dest}`);
