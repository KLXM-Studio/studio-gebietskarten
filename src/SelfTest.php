<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Data\Entries;
use Core\Data\Tables;

/**
 * php bin/console gebietskarten:selftest [--site=…] – Verschmelzen ohne Lücken (Beispiel und echte Kreise), Projektion,
 * Zuordnung (jede Fläche genau einem Gebiet), Datentabellen-Bindung (Schlüssel und Namen), Ausgabe ohne style-Attribute und
 * Inline-Skripte, Zugänglichkeit des SVG (Titel, Namen der Links, Liste), Quellenvermerk (überall gleich), Nachladen mit
 * Prüfsumme; seit 0.2.0: Reihenfolge, eigene Farben im Dunkeln (Kontrast der Beschriftung, Dunkel-Regel als Datei),
 * Kit-Farben wie auf der Website (Stylesheet + Design-Werte), Zuordnung per Klick in Einträge einer Datentabelle (Rechte,
 * Status, Version, Protokoll); seit 0.3.0 Beschriftungen (Pol der Unzugänglichkeit, Größe nach Fläche, Text mit Zeilenumbruch,
 * Stil und Kontrast, Hinweislinie, Telefon-Regel, Bestandskarten unverändert, Text aus Einträgen); seit 0.4.0 Darstellung
 * (Hover dezent/animiert, Schatten, Pills hell/dunkel mit Kontrast und Telefon-Skalierung, Töne der Akzentfarbe mit Nachbarn
 * verschieden, Bestandskarten ohne zusätzliche Auszeichnung). Legt Testdaten (Karten,
 * Tabelle, zwei Konten) an und entfernt sie danach wieder.
 */
final class SelfTest
{
    private static int $ok = 0;
    private static int $fail = 0;

    private static function t(bool $cond, string $what, string $detail = ''): void
    {
        if ($cond) { self::$ok++; echo "  ✓ $what\n"; return; }
        self::$fail++;
        echo "  ✗ $what" . ($detail !== '' ? " – $detail" : '') . "\n";
    }

    public static function run(): int
    {
        self::$ok = self::$fail = 0;
        echo "Geometrie (Beispiel 2 × 2)\n";
        self::grid();
        echo "Kartendaten Deutschland\n";
        self::realData();
        echo "In deiner Nähe (Filter data.nearby des Cores)\n";
        self::nearby();
        echo "In deiner Nähe (Filter data.nearby des Cores)\n";
        self::nearby();
        echo "Zuordnung, Ausgabe, Zugänglichkeit\n";
        $mapId = 0;
        try {
            $mapId = self::output();
        } catch (\Throwable $e) {
            self::t(false, 'Ausgabe', $e->getMessage());
        } finally {
            if ($mapId) Repo::deleteMap($mapId);
        }
        echo "Datentabellen-Bindung\n";
        self::bound();
        echo "Farben im Dunkeln, Kit-Farben\n";
        self::colors();
        echo "Palette: ohne Grau, Nachbarn verschieden, Kontrast\n";
        self::palette();
        echo "Reihenfolge\n";
        self::order();
        echo "Quellenvermerk (überall gleich)\n";
        self::attribution();
        echo "Beschriftungen (Lage, Text, Größe, Stil, Hinweislinie, Bestandskarten)\n";
        self::labels();
        echo "Darstellung (Hover, Schatten, Pills, Töne der Akzentfarbe)\n";
        self::look();
        echo "Zuordnung per Klick in Einträge (Datentabelle)\n";
        self::entryAssign();
        echo "Nachladen (Prüfsumme)\n";
        self::packs();
        printf("\n%d bestanden, %d fehlgeschlagen\n", self::$ok, self::$fail);
        return self::$fail ? 1 : 0;
    }

    /** Vier Quadrate mit gemeinsamen Bögen: Verschmelzen ergibt einen Ring, die Fläche bleibt gleich */
    private static function grid(): void
    {
        // Knoten auf einem 3 × 3-Gitter; Bögen: waagerecht/senkrecht je Kante
        $p = fn(int $x, int $y): array => [$x, $y];
        $arcs = [];
        $id = [];
        $edge = function (array $a, array $b) use (&$arcs, &$id): int {
            $k = implode(',', $a) . '|' . implode(',', $b);
            $r = implode(',', $b) . '|' . implode(',', $a);
            if (isset($id[$k])) return $id[$k];
            if (isset($id[$r])) return ~$id[$r];
            $arcs[] = [$a, $b];
            return $id[$k] = count($arcs) - 1;
        };
        $geoms = [];
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as $i => [$x, $y]) {
            $ring = [$edge($p($x, $y), $p($x + 1, $y)), $edge($p($x + 1, $y), $p($x + 1, $y + 1)), $edge($p($x + 1, $y + 1), $p($x, $y + 1)), $edge($p($x, $y + 1), $p($x, $y))];
            $geoms[] = ['type' => 'Polygon', 'arcs' => [$ring], 'properties' => ['id' => '0' . ($i + 1), 'name' => 'Q' . ($i + 1), 'l' => '01', 'x' => $x + .5, 'y' => $y + .5]];
        }
        $d = Topo::decode(['type' => 'Topology', 'arcs' => $arcs, 'objects' => ['e' => ['type' => 'GeometryCollection', 'geometries' => $geoms]]]);
        self::t(count($d['units']) === 4 && count($d['arcs']) === 12, '4 Flächen, 12 gemeinsame Bögen');
        $all = Topo::merge($d['units'], $d['arcs']);
        self::t(count($all) === 1 && abs(abs(Topo::area($all[0])) - 4.0) < 1e-9, 'alle vier → ein Ring mit Fläche 4 (keine Lücke)');
        $two = Topo::merge([$d['units']['01'], $d['units']['02']], $d['arcs']);
        self::t(count($two) === 1 && abs(abs(Topo::area($two[0])) - 2.0) < 1e-9, 'zwei Nachbarn → ein Ring mit Fläche 2');
        $diag = Topo::merge([$d['units']['01'], $d['units']['04']], $d['arcs']);
        self::t(count($diag) === 2, 'über Eck → zwei getrennte Ringe');
        self::t(count(Topo::mesh($d['units'], $d['arcs'])) === 4, 'innere Grenzen: 4 Bögen');
        $simp = Topo::simplify([[0, 0], [1, 0.01], [2, 0], [3, 0.02], [4, 0]], 0.5);
        self::t($simp === [[0, 0], [4, 0]], 'Vereinfachen behält die Endpunkte');
        $pr = Topo::projection([0.0, 0.0, 2.0, 2.0], 1000, 10);
        self::t($pr['width'] === 1000 && ($pr['fn'])(0.0, 2.0) === [10.0, 10.0], 'Projektion: Norden oben, Rand 10');
    }

    /** „In deiner Nähe“: Fläche nach Name/Schlüssel/Punkt, Kartenpunkt (ohne Netz) */
    private static function nearby(): void
    {
        $w = Nearby::byName('Wesel');
        self::t(($w['id'] ?? '') === '05170', 'Name → Kreis (Wesel = 05170)', (string) json_encode($w));
        self::t((Nearby::byName('Kreis Wesel')['id'] ?? '') === '05170', 'Zusatz „Kreis“ wird erkannt');
        self::t((Nearby::byName('Nordrhein-Westfalen')['id'] ?? '') === '05', 'Name → Land');
        self::t((Nearby::byName('05170')['id'] ?? '') === '05170', 'Amtlicher Schlüssel');
        self::t(Nearby::byName('Quatschhausen') === null, 'Unbekannter Ort → nichts');
        $p = Nearby::byPoint(51.643, 6.6034);
        self::t(($p['id'] ?? '') === '05170', 'Punkt → Kreis, in dem er liegt', (string) json_encode($p));
        self::t(Nearby::byPoint(40.0, 2.0) === null, 'Punkt außerhalb → nichts');
        $v = Nearby::resolve(['point' => null, 'label' => '', 'regions' => [], 'sites' => []], ['q' => 'Wesel', 'point' => null]);
        self::t(!Gebietskarten::on() || (abs((float) ($v['point']['lat'] ?? 0) - 51.643) < 0.01 && ($v['label'] ?? '') === 'Wesel'), 'Filter: Kartenpunkt und Name aus der Fläche');
    }

    private static function realData(): void
    {
        $f = Gebietskarten::dir() . '/data/de/kreise.topo.json';
        self::t(is_file($f) && filesize($f) < 300 * 1024, 'Kreise im Paket (< 300 KB)', is_file($f) ? (string) filesize($f) : 'fehlt');
        self::t(is_file(Gebietskarten::dir() . '/data/de/laender.topo.json'), 'Bundesländer im Paket');
        $d = Bases::units('de', 'kreise', ['05']);
        self::t(count($d['units']) === 53, 'NRW: 53 Kreise und kreisfreie Städte', (string) count($d['units']));
        $sum = 0.0;
        foreach ($d['units'] as $u) foreach (Topo::merge([$u], $d['arcs']) as $ring) $sum += abs(Topo::area($ring));
        $rings = Topo::merge($d['units'], $d['arcs']);
        $merged = 0.0;
        foreach ($rings as $ring) $merged += abs(Topo::area($ring));
        self::t(abs($merged - $sum) / $sum < 1e-6, 'NRW verschmolzen: Fläche = Summe der Kreise (lückenlos)', sprintf('%.6f / %.6f', $merged, $sum));
        self::t(count($rings) <= 3, 'NRW verschmolzen: ein Umriss', (string) count($rings));
        $de = Bases::units('de', 'laender', []);
        self::t(count($de['units']) === 16, '16 Bundesländer');
        $att = Bases::attribution('de', 'kreise');
        self::t((bool) preg_match('~^© BKG \(\d{4}\) dl-de/by-2-0$~', $att['text']) && $att['providerUrl'] === 'https://www.bkg.bund.de', 'Quellenvermerk „© BKG (Jahr) dl-de/by-2-0“', $att['text']);
        $miss = [];
        $files = Bases::files('de', 'gemeinden', ['05', '06'], $miss);
        if (count($files) === 2) {
            $g = Topo::collect($files);
            $border = array_filter($g['units'], fn($u) => in_array($u['k'], ['05970', '06534'], true));   // Siegen-Wittgenstein | Marburg-Biedenkopf
            $sum = 0.0;
            foreach ($border as $u) foreach (Topo::merge([$u], $g['arcs']) as $r) $sum += abs(Topo::area($r));
            $m = 0.0;
            foreach (Topo::merge($border, $g['arcs']) as $r) $m += abs(Topo::area($r));
            self::t($sum > 0 && abs($m - $sum) / $sum < 1e-6, 'Gemeinden über die Landesgrenze NRW/Hessen verschmolzen (gemeinsame Bögen)');
        } else {
            echo "  – Gemeinden NRW/Hessen nicht geladen (gebietskarten:daten --land=05,06) – Test über die Landesgrenze übersprungen\n";
        }
    }

    private static function output(): int
    {
        $id = Repo::saveMap(null, ['title' => 'Selbsttest <Karte>', 'base' => 'de', 'level' => 'kreise', 'scope' => ['05'], 'source' => 'own',
            'options' => ['labels' => true] + Repo::OPTIONS]);
        $a = Repo::saveRegion($id, null, ['name' => 'Nord & West', 'units' => '05170, 05154, 05166', 'color' => 'accent', 'link' => '/kontakt']);
        $b = Repo::saveRegion($id, null, ['name' => 'Süd', 'units' => ['05166', '05162'], 'color' => '#336699', 'text' => '<p>Info <script>x</script></p>', 'contact' => "Erika Musterfrau\nerika@example.org"]);
        $ra = Repo::region($a);
        $rb = Repo::region($b);
        self::t($ra['units'] === ['05170', '05154'] && $rb['units'] === ['05166', '05162'], 'Fläche wechselt zum neuen Gebiet (jede Fläche genau einem Gebiet)');
        self::t(!str_contains($rb['text'], '<script'), 'Infotext bereinigt');
        Repo::saveAssignment($id, [$a => ['05170', '05162'], $b => ['05162', '05166', 'x1']]);
        self::t(Repo::region($b)['units'] === ['05166'] && Repo::region($a)['units'] === ['05170', '05162'], 'Zuordnung: doppelte Flächen und ungültige Schlüssel fallen weg');
        Repo::saveAssignment($id, [$a => ['05170', '05154'], $b => ['05166', '05162']]);

        $map = (array) Repo::map($id);
        Render::reset();
        $html = Render::html($map, ['action' => 'link']);
        self::t(!preg_match('~\sstyle\s*=~i', $html), 'Website: keine style-Attribute');
        self::t(!preg_match('~<script(?![^>]*\bsrc=)~i', $html) && !preg_match('~\son[a-z]+\s*=~i', $html), 'Website: keine Inline-Skripte oder Event-Handler');
        self::t(str_contains($html, 'role="group"') && (bool) preg_match('~<svg[^>]+aria-labelledby="([^"]+)".*<title id="\1">Karte: Selbsttest &lt;Karte&gt;~s', $html), 'SVG: Gruppe mit Titel (escaped)');
        self::t(substr_count($html, '<a class="gk-area') === 1 && str_contains($html, 'aria-label="Nord &amp; West"'), 'Gebiet mit Link als <a> mit zugänglichem Namen');
        self::t((bool) preg_match('~<g class="gk-area gk-c-own gk-x-336699-([0-9a-f]{6})"><title>Süd</title><path [^>]*fill="\#336699"~', $html, $dm), 'eigene Farbe: hell als SVG-Attribut fill, Klasse mit Dunkel-Wert');
        $dk = '#' . ($dm[1] ?? '');
        self::t(Colors::labelContrast($dk, Preview::kit(true)) >= Colors::MIN_LABEL, 'eigene Farbe: Dunkel-Wert automatisch, Beschriftung ≥ 4,5:1', $dk);
        $sheet = preg_match('~<link rel="stylesheet" href="([^"]*/gebietskarten/farben-[0-9a-f]{12}\.css)">~', $html, $lm) ? $lm[1] : '';
        $file = $sheet !== '' ? site()->mediaDir('gebietskarten/' . basename($sheet)) : '';
        $rule = Colors::darkRule();
        self::t($sheet !== '' && is_file($file) && str_contains((string) file_get_contents($file), '.gk-x-336699-' . substr($dk, 1) . ' path')
            && (!$rule || str_contains((string) file_get_contents($file), $rule['scope'])), 'Dunkel-Regel als Datei im Medienordner (Dunkel-Regel des Kits, keine Inline-Stile)', $sheet);
        self::t(str_contains($html, 'gk-c-accent'), 'Design-Farbe als Klasse');
        self::t(substr_count($html, '<li class="gk-item') === 2 && str_contains($html, 'aria-label="Gebiete"'), 'Liste der Gebiete als Alternative');
        self::t((bool) preg_match('~class="gk-src">Kartengrundlage: © <a href="https://www\.bkg\.bund\.de"[^>]*>BKG</a> \(\d{4}\) <a href="https://www\.govdata\.de/dl-de/by-2-0"[^>]*>dl-de/by-2-0</a>, <a href="https://sgx\.geodatenzentrum\.de/[^"]+"[^>]*>Datenquellen</a>, vereinfacht</p>~', $html), 'Quellenvermerk unter der Karte (BKG, Lizenz, Datenquellen verlinkt, Veränderungshinweis)');
        self::t(str_contains($html, 'css/gebietskarten.css') && !str_contains($html, 'js/gebietskarten.js'), 'nur CSS, kein Skript ohne Info-Fenster');
        self::t(str_contains($html, '<g class="gk-labels" aria-hidden="true">'), 'Beschriftung aria-hidden');

        $hl = Render::html($map, ['mode' => 'highlight', 'highlight' => 'r' . $b, 'action' => 'info', 'assets' => true]);
        self::t(str_contains($hl, 'gk--focus') && (bool) preg_match('~class="gk-area gk-c-own[^"]* is-active"~', $hl), 'Hervorheben: Gebiet markiert, andere gedämpft');
        $pre = Render::html($map, ['preview' => true, 'assets' => false]);
        self::t(str_contains($pre, 'style="--gk-d:' . $dk . '"') && !str_contains($pre, 'farben-'), 'Vorschau der Verwaltung: Dunkel-Wert als Variable, keine Datei nötig');
        self::t(str_contains($hl, 'js/gebietskarten.js') && str_contains($hl, 'data-gk-pop') && str_contains($hl, 'href="#gk-' . $id), 'Info-Fenster: Skript bei Bedarf, Sprungziel ohne JavaScript');
        self::t(!preg_match('~\sstyle\s*=~i', $hl) && str_contains($hl, 'erika@example.org'), 'Info-Fenster: Kontakt, keine style-Attribute');
        $geo = Render::geometry($map, Bound::regionsOf($map));
        self::t(isset($geo['regions']['r' . $a], $geo['regions']['r' . $b]) && $geo['rest'] !== '', 'Geometrie: zwei Gebiete und Rest');
        self::t(Render::activeKey(Bound::regionsOf($map), 'highlight', 'r999') === null, 'unbekanntes Gebiet wird nicht hervorgehoben');
        $svg = Render::svg($map);
        $x = @simplexml_load_string($svg);
        self::t($x !== false && !str_contains($svg, 'class="') && str_contains($svg, '© BKG ('), 'Export als SVG: gültig, feste Farben, Quellenvermerk');
        return $id;
    }

    private static function bound(): void
    {
        $units = Bases::units('de', 'kreise', ['05'])['units'];
        [$ids, $miss] = Bound::resolve(['Wesel', 'Kreis Kleve', '5162', 'Unbekanntstadt', 'Düsseldorf', 'Kreis Wesel'], $units);
        self::t($ids === ['05170', '05154', '05162', '05111'] && $miss === ['Unbekanntstadt'], 'Werte: Name, „Kreis …“, Schlüssel ohne Null, unbekannt gemeldet', json_encode([$ids, $miss]) ?: '');
        $h = 'gk_selftest_' . bin2hex(random_bytes(3));
        $mapId = 0;
        try {
            [$def, $err] = Tables::validate(['name' => 'Selbsttest Regionalgruppen', 'handle' => $h, 'fields' => [
                ['label' => 'Name', 'type' => 'text', 'required' => 1],
                ['label' => 'Kreise', 'type' => 'textarea'],
                ['label' => 'Website', 'type' => 'text'],
            ]]);
            self::t($err === [], 'Datentabelle angelegt', json_encode($err) ?: '');
            if ($err) return;
            Tables::create($def);
            $t = Tables::find($h);
            [$e1] = Entries::save($t, null, ['name' => 'Gruppe Niederrhein', 'kreise' => "Wesel\nKleve", 'website' => site()->key, 'status' => 'published']);
            [$e2] = Entries::save($t, null, ['name' => 'Gruppe Rhein', 'kreise' => '05111; 05162', 'status' => 'published']);
            self::t($e1 > 0 && $e2 > 0, 'Einträge gespeichert');
            $mapId = Repo::saveMap(null, ['title' => 'Selbsttest Tabelle', 'level' => 'kreise', 'scope' => ['05'], 'source' => 'table',
                'binding' => ['table' => $h, 'units' => 'kreise', 'site' => 'website']]);
            $map = (array) Repo::map($mapId);
            $regions = Bound::regionsOf($map);
            self::t(count($regions) === 2 && $regions[0]['key'] === 'e' . $e1, 'je Eintrag ein Gebiet');
            $geo = Render::geometry($map, $regions);
            self::t(($geo['unitsOf']['e' . $e1] ?? []) === ['05170', '05154'] && ($geo['unitsOf']['e' . $e2] ?? []) === ['05111', '05162'], 'Flächen aus dem Feld aufgelöst');
            self::t(Render::activeKey($regions, 'site') === 'e' . $e1, 'Gebiet dieser Website über das Feld „Website“');
            Render::reset();
            $html = Render::html($map);
            self::t(substr_count($html, 'class="gk-area ') === 2 && !preg_match('~\sstyle\s*=~i', $html), 'Ausgabe aus der Tabelle');
        } catch (\Throwable $e) {
            self::t(false, 'Datentabellen-Bindung', $e->getMessage());
        } finally {
            if ($mapId) Repo::deleteMap($mapId);
            if ($x = Tables::find($h)) Tables::delete($x);
        }
    }

    /** Dunkel-Werte eigener Farben, Kontrastprüfung, Kit-Farben wie auf der Website */
    private static function colors(): void
    {
        $kit = ['bg' => '#111418', 'ink' => '#f3f4f8'];
        $ok = true;
        $worst = '';
        foreach (['#f0e442', '#ffffff', '#e69f00', '#2e7d9a', '#000000', '#ff00aa', '#9fd3ff'] as $c) {
            $d = Colors::dark($c, $kit);
            if (Colors::labelContrast($d, $kit) < Colors::MIN_LABEL || !preg_match('~^#[0-9a-f]{6}$~', $d)) { $ok = false; $worst .= "{$c}→{$d} "; }
        }
        self::t($ok, 'automatische Dunkel-Werte: Beschriftung ≥ 4,5:1 (auch Gelb und Weiß)', $worst);
        self::t(Colors::darkOf('#336699', '#abcdef', $kit) === '#abcdef' && Colors::darkOf('accent', '', $kit) === '', 'eigenes Feld „im Dunkeln“ geht vor, Design-Farben ohne Dunkel-Wert');
        $p = Colors::paint('#336699', '#336699');
        self::t($p['class'] === 'gk-c-own', 'gleicher Wert hell und dunkel: keine Dunkel-Regel');
        $css = Colors::sheetCss([['#336699', '#1a2b3c']], ['media' => '(prefers-color-scheme: dark)', 'scope' => 'html.has-dark:not(.scheme-light)', 'extra' => ['html.has-dark.scheme-dark', 'html.is-dark']]);
        self::t(str_starts_with($css, '@media (prefers-color-scheme: dark){') && str_contains($css, 'html.has-dark:not(.scheme-light) .gk-x-336699-1a2b3c path')
            && str_contains($css, 'html.has-dark.scheme-dark .gk-x-336699-1a2b3c rect{fill:#1a2b3c}') && str_contains($css, 'html.is-dark .gk-x-'), 'Dunkel-Regel: Gerät, Schalter des Kits und Vorschau des Style-Editors');

        // Auflösen wie im Browser: Variablen des Kits, Kit-Vertrag, Dunkel-Regel, color-mix, light-dark
        $sel = ['html.has-dark', 'html.has-dark.scheme-dark'];
        $kitCss = ':root{--x-a:#123456;--x-bg:#fff}:where(*){--kit-accent:var(--x-a);--kit-bg:var(--x-bg);--kit-surface:color-mix(in oklab,var(--x-a) 50%,#ffffff);'
            . '--kit-ink:light-dark(#111111,#eeeeee)}.btn{--x-a:#ff0000}@media (min-width:40em){:root{--x-a:#00ff00}}'
            . '@media (prefers-color-scheme:dark){html.has-dark:not(.scheme-light){--x-a:#abcdef;--x-bg:#000}}';
        $l = Preview::scan($kitCss, false, $sel);
        $d = Preview::scan($kitCss, true, $sel);
        self::t(Preview::resolve($l['--kit-accent'], $l, false) === '#123456' && Preview::resolve($d['--kit-accent'], $d, true) === '#abcdef',
            'Kit-Farben: Variablen hell/dunkel aufgelöst, fremde Regeln und andere @media ignoriert');
        self::t(Preview::resolve($l['--kit-surface'], $l, false) === strtolower(\Core\Contrast::mix('#123456', '#ffffff', 50)) && Preview::resolve($l['--kit-bg'], $l, false) === '#ffffff'
            && Preview::resolve($d['--kit-ink'], $d, true) === '#eeeeee', 'Kit-Farben: color-mix, #fff, light-dark', (string) Preview::resolve($l['--kit-surface'], $l, false));
        Preview::reset();
        $k = Preview::kit(false);
        $vals = \Core\Design::enabled() ? Preview::variables(false) : [];
        self::t(!isset($vals['--kit-accent']) || (($k['source'] ?? '') === 'kit' && $k['accent'] === Preview::resolve($vals['--kit-accent'], $vals, false)),
            'Vorschau: Akzent des Kits samt Design-Werten der Website (' . app()->theme->name . ': ' . $k['accent'] . ')');
    }

    /** Automatische Farben (seit 0.2.1): kein Grau, Nachbarn verschieden (Graphfärbung), lesbare Beschriftung hell und dunkel */
    private static function palette(): void
    {
        self::t(!in_array('p8', Colors::AUTO, true) && count(Colors::AUTO) >= 11 && count(array_unique(array_map(fn($k) => Colors::PALETTE[$k], Colors::AUTO))) === count(Colors::AUTO),
            'automatische Palette: ohne Grau, mindestens 11 verschiedene Farben');
        // bis 7 Gebiete wie bisher (Position → p1 … p7)
        $few = array_map(fn($i) => ['key' => 'r' . $i, 'color' => ''], range(1, 7));
        self::t(array_values(Colors::assign($few)) === ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'], 'bis 7 Gebiete: Farben wie bisher nach Position');

        // 11 Gebiete aus den Kreisen in NRW: alle verschieden, kein Grau
        $d = Bases::units('de', 'kreise', ['05']);
        $ids = array_keys($d['units']);
        $owner = $regions = [];
        foreach (array_chunk($ids, (int) ceil(count($ids) / 11)) as $i => $chunk) {
            $regions[] = ['key' => 'g' . $i, 'color' => ''];
            foreach ($chunk as $id) $owner[$id] = 'g' . $i;
        }
        $nb = Render::neighbors($owner, $d['units']);
        $eff = Colors::assign($regions, $nb);
        self::t(count($regions) === 11 && count(array_unique($eff)) === 11 && !in_array('p8', $eff, true), '11 Gebiete: 11 verschiedene Farben, kein Grau', implode(',', $eff));
        self::t(isset($nb['g0']) && in_array('g0', $nb[$nb['g0'][0]] ?? [], true), 'Nachbarn aus gemeinsamen Grenzen (in beide Richtungen)');

        // 16 Länder als einzelne Gebiete (mehr Gebiete als Farben): Nachbarn nie gleich, feste Farben bleiben
        $de = Bases::units('de', 'laender', []);
        $owner = $regions = [];
        foreach (array_keys($de['units']) as $id) {
            $owner[$id] = 'l' . $id;
            $regions[] = ['key' => 'l' . $id, 'color' => $id === '05' ? 'p3' : ''];
        }
        $nb = Render::neighbors($owner, $de['units']);
        $eff = Colors::assign($regions, $nb);
        $clash = [];
        foreach ($nb as $k => $list) foreach ($list as $o) if ($eff[$k] === $eff[$o] && $k < $o) $clash[] = "$k/$o";
        self::t(!$clash && !in_array('p8', $eff, true), '16 Länder: keine zwei Nachbarn mit derselben Farbe', implode(' ', $clash));
        self::t($eff['l05'] === 'p3' && !in_array('p3', array_map(fn($o) => $eff[$o], $nb['l05'] ?? []), true), 'feste Farbe bleibt, Nachbarn meiden sie');
        $counts = array_count_values($eff);
        self::t(max($counts) <= 2, 'Farben gleichmäßig verteilt (höchstens zweimal bei 16 Gebieten)', json_encode($counts) ?: '');

        // Kontrast der Beschriftung: hell (Mischung wie im CSS) und im Dunkeln (Wert der erzeugten Datei)
        $bad = [];
        foreach ([[Preview::kit(false), Preview::kit(true)], [['bg' => '#ffffff', 'ink' => '#1b1f24'], ['bg' => '#16191d', 'ink' => '#eef1f4']]] as [$kl, $kd]) {
            foreach (Colors::AUTO as $k) {
                $l = Colors::approx($k, $kl);
                $dk = Colors::paletteDark($k, $kd);
                if (Colors::labelContrast($l, $kl) < Colors::MIN_LABEL) $bad[] = "$k hell $l";
                if (Colors::labelContrast($dk, $kd) < Colors::MIN_LABEL) $bad[] = "$k dunkel $dk";
            }
        }
        self::t(!$bad, 'Palette: Beschriftung hell und dunkel ≥ 4,5:1 (Kit der Website und Standard)', implode(', ', $bad));
        $css = Colors::sheetCss([], ['media' => '(prefers-color-scheme: dark)', 'scope' => 'html.has-dark', 'extra' => []], ['p9' => '#2d2070']);
        self::t(str_contains($css, 'html.has-dark .gk-c-p9{--gk-c:#2d2070}'), 'Dunkel-Regel der Palette als Variable --gk-c (Fläche und Legende)');
        $css = file_get_contents(Gebietskarten::dir() . '/assets/css/gebietskarten.css') ?: '';
        $missing = array_filter(array_keys(Colors::PALETTE), fn($k) => !str_contains($css, '.gk-c-' . $k . ' { --gk-c: color-mix(in oklab, ' . strtolower(Colors::PALETTE[$k]) . ' ' . Colors::mixOf($k) . '%'));
        self::t(!$missing, 'Stylesheet: jede Palettenfarbe mit demselben Anteil wie in PHP', implode(',', $missing));
    }

    /** Reihenfolge der Gebiete (Route …/reihenfolge): Liste, Legende und automatische Farben folgen */
    private static function order(): void
    {
        $id = Repo::saveMap(null, ['title' => 'Selbsttest Reihenfolge', 'level' => 'laender', 'source' => 'own']);
        try {
            $a = Repo::saveRegion($id, null, ['name' => 'Eins', 'units' => '05']);
            $b = Repo::saveRegion($id, null, ['name' => 'Zwei', 'units' => '06']);
            $c = Repo::saveRegion($id, null, ['name' => 'Drei', 'units' => '07']);
            Repo::reorder($id, [$c, $a, $b]);
            $names = array_column(Repo::regions($id), 'name');
            self::t($names === ['Drei', 'Eins', 'Zwei'], 'neue Reihenfolge gespeichert', implode(',', $names));
            Render::reset();
            $html = Render::html((array) Repo::map($id));
            self::t((bool) preg_match('~gk-c-p1.*?Drei.*gk-c-p2.*?Eins~s', substr($html, (int) strpos($html, 'gk-list'))), 'Liste und Palette folgen der Reihenfolge');
            if (Colors::darkRule()) {
                $f = preg_match('~href="[^"]*/gebietskarten/(farben-[0-9a-f]{12}\.css)"~', $html, $m) ? site()->mediaDir('gebietskarten/' . $m[1]) : '';
                $want = '.gk-c-p1{--gk-c:' . Colors::paletteDark('p1', Preview::kit(true)) . '}';
                self::t($f !== '' && str_contains((string) @file_get_contents($f), $want), 'Palette im Dunkeln: lesbare Werte in der erzeugten Datei', $want);
            }
            Repo::reorder($id, [$b, 999999]);
            self::t(array_column(Repo::regions($id), 'name')[0] === 'Zwei', 'fremde IDs werden ignoriert');
        } finally {
            Repo::deleteMap($id);
        }
    }

    /** Quellenvermerk: dieselbe Fassung unter der Karte, als Text (Mediathek), im SVG-Export (Grafik-Editor), auf „Kartendaten“ */
    private static function attribution(): void
    {
        $id = Repo::saveMap(null, ['title' => 'Selbsttest Vermerk', 'level' => 'kreise', 'scope' => ['10'], 'source' => 'own']);
        try {
            Repo::saveRegion($id, null, ['name' => 'Saar', 'units' => '10041', 'color' => '#2e7d9a', 'color_dark' => '#1d4f61']);
            $map = (array) Repo::map($id);
            $a = Bases::attribution('de', 'kreise');
            $text = Render::sourceText($map);
            self::t($text === 'Kartengrundlage: © BKG (' . $a['year'] . ') dl-de/by-2-0, Datenquellen: https://sgx.geodatenzentrum.de/web_public/gdz/datenquellen/datenquellen_vg_nuts.pdf, vereinfacht',
                'Text: „© BKG (Jahr) dl-de/by-2-0, Datenquellen: …, vereinfacht“', $text);
            $note = strip_tags(Render::sourceNote($map));
            self::t($note === 'Kartengrundlage: © BKG (' . $a['year'] . ') dl-de/by-2-0, Datenquellen, vereinfacht', 'HTML: gleicher Wortlaut, Links statt Adresse', $note);
            self::t(strip_tags(Render::attributionHtml('de', 'laender', 'x')) === $note && str_contains(Render::attributionHtml('de', 'laender', 'x'), 'class="x"'), 'eine Fassung für alle Ebenen und Seiten (auch „Kartendaten“)');
            $svg = Render::svg($map, Preview::kit(false));
            $ok = \Core\Svg::clean($svg, ['editor' => true])['svg'];
            self::t(!str_contains($svg, 'GeoBasis') && str_contains($svg, '<desc>' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</desc>')
                && str_contains($svg, '© BKG (' . $a['year'] . ') dl-de/by-2-0, vereinfacht</tspan>') && str_contains($svg, 'datenquellen_vg_nuts.pdf · Lizenz: https://www.govdata.de/dl-de/by-2-0</tspan>'),
                'SVG-Export: Vermerk zweizeilig + <desc>, kein „GeoBasis-DE“');
            self::t(str_contains($ok, '© BKG (' . $a['year'] . ') dl-de/by-2-0, vereinfacht') && str_contains($ok, 'datenquellen_vg_nuts.pdf'), 'SVG-Export: Vermerk übersteht die SVG-Bereinigung (Mediathek, Grafik-Editor)');
            self::t(str_contains($svg, 'fill="#2e7d9a"') && !str_contains($svg, 'class='), 'SVG-Export: feste helle Farben');
        } finally {
            Repo::deleteMap($id);
        }
    }

    /**
     * Zuordnung per Klick bei Karten aus einer Datentabelle: Feld der Einträge über Entries::save – Schreibweise der Tabelle,
     * Werte außerhalb der Karte bleiben, Rechte (Autorin ohne Veröffentlichungsrecht), Version mit Notiz, Protokoll.
     */
    /** Beschriftungen (seit 0.3.0) */
    private static function labels(): void
    {
        // Geometrie: U-Form – Schwerpunkt liegt außerhalb, der Pol innen mit dem größten Abstand zum Rand
        $u = [[[0, 0], [30, 0], [30, 30], [20, 30], [20, 10], [10, 10], [10, 30], [0, 30], [0, 0]]];
        $segs = Labels::segments($u);
        [$px, $py, $pd] = Labels::pole($u, 0.1);
        self::t(Labels::dist(15, 13.6, $segs) < 0 && Labels::dist($px, $py, $segs) > 0 && $pd >= 5 && $pd < 6.5, 'Pol der Unzugänglichkeit: innen, auch wo der Schwerpunkt außerhalb liegt (U-Form)', "$px,$py,$pd");
        [$qx, $qy] = Labels::pole([[[0, 0], [100, 0], [100, 60], [0, 60], [0, 0]]], 0.1);
        self::t(abs($qx - 50) < 1 && abs($qy - 30) < 1, 'Pol eines Rechtecks: Mitte');
        $sq = Labels::segments(Labels::parse(Topo::path([[[0, 0], [100, 0], [100, 100], [0, 100], [0, 0]]])));
        self::t(count($sq) === 4 && Labels::boxInside($sq, 50, 50, 60, 20) && !Labels::boxInside($sq, 50, 50, 120, 20) && !Labels::boxInside($sq, 95, 50, 20, 10), 'Platz: Text ganz im Gebiet oder nicht (aus dem SVG-Pfad)');
        $proj = [5.8, 52.5, 270.1, 433.2, 16.0, 16.0];
        $xy = Labels::toSvg($proj, [7.4, 51.36]);
        $ll = Labels::toGeo($proj, $xy[0], $xy[1]);
        self::t(abs($ll[0] - 7.4) < 0.001 && abs($ll[1] - 51.36) < 0.001, 'Lage als Länge/Breite ↔ Karte (unabhängig vom Ausschnitt)');
        $c = Labels::cleanAll(['r1' => ['show' => 'on', 'text' => " Ober-\r\nbayern \n\n Süd\nx\ny", 'pos' => [7.1, 51.2], 'anchor' => [7, 51], 'size' => 'xl', 'style' => 'evil', 'caps' => 'on'],
            'x9' => ['show' => 'on'], 'r2' => ['pos' => [500, 1], 'anchor' => [7, 51]], 'r3' => []]);
        self::t($c === ['r1' => ['show' => 'on', 'text' => "Ober-\nbayern\nSüd", 'pos' => [7.1, 51.2], 'caps' => 'on']], 'Einstellungen bereinigt (höchstens 3 Zeilen, Anker nur mit Hinweislinie, unbekannte Werte und Schlüssel fallen weg)', json_encode($c, JSON_UNESCAPED_UNICODE) ?: '');

        $ids = [];
        try {
            // Bestandskarte: Optionen ohne label_mode (wie vor 0.3.0) → bisherige Lage, M, Schrift des Kits
            $old = (int) app()->db->insert(Repo::MAPS, ['title' => 'Selbsttest Bestand', 'base' => 'de', 'level' => 'kreise', 'scope' => '05', 'source' => 'own',
                'binding' => '{}', 'options' => json_encode(['rest' => true, 'fit' => 'scope', 'lines' => true, 'labels' => true, 'list' => true]), 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $old;
            $ra = Repo::saveRegion($old, null, ['name' => 'Nord', 'units' => '05170, 05154, 05166, 05158']);
            Repo::saveRegion($old, null, ['name' => 'Hagen', 'units' => '05914']);
            $m = (array) Repo::map($old);
            self::t($m['options']['label_mode'] === 'legacy' && $m['options']['label_size'] === 'm' && $m['options']['label_style'] === 'kit' && !$m['options']['label_caps'], 'Bestandskarte: bisherige Lage, Größe M, Schrift des Kits');
            Render::reset();
            $html = Render::html($m);
            $geo = Render::geometry($m, Bound::regionsOf($m));
            [$lx, $ly] = $geo['labels']['r' . $ra];
            self::t(str_contains($html, '<g class="gk-labels" aria-hidden="true"><text x="' . $lx . '" y="' . $ly . '">Nord</text>') && !str_contains($html, 'gk--lab2'), 'Bestandskarte: Ausgabe wie vor 0.3.0 (gleiche Lage, gleiche Auszeichnung)');
            Repo::saveMap($old, ['title' => 'Selbsttest Bestand', 'level' => 'kreise', 'scope' => ['05'], 'options' => $m['options']]);
            self::t(((array) Repo::map($old))['options']['label_mode'] === 'legacy', 'Einstellungen speichern ändert die Lage nicht');
            Repo::saveLabels($old, ['r' . $ra => ['pos' => [6.5, 51.6], 'text' => 'N']], 'legacy');
            Repo::saveLabels($old, Labels::resetPositions(((array) Repo::map($old))['labels']), 'pole');
            $m = (array) Repo::map($old);
            self::t($m['options']['label_mode'] === 'pole' && $m['labels'] === ['r' . $ra => ['text' => 'N']], '„Automatisch neu setzen“: neue Lage, eigene Texte bleiben');

            // Neue Karte: Pol, Größe nach Fläche, Stil automatisch
            $new = Repo::saveMap(null, ['title' => 'Selbsttest Beschriftung', 'level' => 'kreise', 'scope' => ['05'], 'options' => ['labels' => true] + Repo::OPTIONS]);
            $ids[] = $new;
            $big = Repo::saveRegion($new, null, ['name' => 'Münsterland', 'units' => '05515, 05554, 05558, 05566, 05570', 'color' => 'p4']);
            $small = Repo::saveRegion($new, null, ['name' => 'Hagen', 'units' => '05914', 'color' => '#0b2a5b']);
            $third = Repo::saveRegion($new, null, ['name' => 'Rheinland mit sehr langem Namen', 'units' => '05315, 05362, 05382']);
            $m = (array) Repo::map($new);
            self::t($m['options']['label_mode'] === 'pole' && $m['options']['label_size'] === 'auto' && $m['options']['label_style'] === 'auto', 'neue Karte: Pol der Unzugänglichkeit, Größe und Stil automatisch');
            $regions = Bound::regionsOf($m);
            $geo = Render::geometry($m, $regions);
            $lay = Labels::layout($m, $regions, $geo, Colors::assign($regions, $geo['neighbors']), false, true);
            $B = $lay['r' . $big];
            $S = $lay['r' . $small];
            self::t($B['size'] === 'l' && $B['fits'] && $S['size'] === 's', 'Größe nach Fläche: großes Gebiet L, Hagen S', $B['size'] . '/' . $S['size']);
            self::t(Labels::boxInside(Labels::segments(Labels::parse($geo['regions']['r' . $big])), $B['x'], $B['y'], $B['w'], $B['h']), 'Text liegt ganz im Gebiet');
            self::t($B['toneL'] === 'd' && $S['toneL'] === 'l' && $S['cL'] >= Colors::MIN_LABEL && $B['cL'] >= Colors::MIN_LABEL, 'Stil automatisch: dunkle Schrift auf Gelb, helle auf Dunkelblau (Kontrast ≥ 4,5:1)', "{$B['toneL']} {$B['cL']} / {$S['toneL']} {$S['cL']}");
            self::t(!$S['fitsPhone'] && $B['fitsPhone'] !== null, 'Telefon-Regel: Hagen hat zu wenig Platz');

            Repo::saveLabels($new, [
                'r' . $small => ['leader' => true, 'pos' => [7.9, 51.2], 'style' => 'tag'],
                'r' . $third => ['text' => "Rheinland\nSüd", 'caps' => 'on', 'size' => 'm'],
                'r' . $big => ['show' => 'off'],
            ]);
            $m = (array) Repo::map($new);
            Render::reset();
            $html = Render::html($m, ['action' => 'link']);
            $geo = Render::geometry($m, Bound::regionsOf($m));
            [$sx, $sy] = Labels::toSvg($geo['proj'], [7.9, 51.2]);
            self::t(!preg_match('~\sstyle\s*=~i', $html) && !preg_match('~<script(?![^>]*\bsrc=)~i', $html), 'Website: keine style-Attribute, keine Inline-Skripte (auch mit allen Beschriftungs-Optionen)');
            self::t(str_contains($html, 'gk--lab2') && str_contains($html, '<g class="gk-labels" aria-hidden="true">') && str_contains($html, '<title>Hagen</title>'), 'Beschriftungen aria-hidden, Namen an den Gebieten zugänglich');
            self::t((bool) preg_match('~<path class="gk-leader gk-lh" d="M[\d. ]+L[\d. ]+"/><circle class="gk-anchor gk-lh" [^>]+/><text x="' . preg_quote((string) $sx, '~') . '" y="' . preg_quote((string) $sy, '~') . '" class="[^"]*gk-tag[^"]*" filter="url\(#gk-' . $new . '-\d+-tag\)">Hagen</text>~', $html)
                && str_contains($html, '<feFlood class="gk-tagbg"'), 'Hinweislinie mit Ankerpunkt, Lage von Hand, Etikett (Filter), auf dem Telefon ausgeblendet');
            self::t((bool) preg_match('~<text x="[\d.]+" y="[\d.]+" class="[^"]*gk-lc[^"]*"><tspan x="[\d.]+" dy="-0\.575em">Rheinland</tspan><tspan x="[\d.]+" dy="1\.15em">Süd</tspan></text>~', $html), 'Zeilenumbruch als tspan, Großbuchstaben als Klasse');
            self::t(!str_contains($html, '>Münsterland</text>'), 'je Gebiet ausblenden');
            Repo::saveMap($new, ['title' => 'Selbsttest Beschriftung', 'level' => 'kreise', 'scope' => ['05'], 'options' => ['labels' => false] + $m['options']]);
            Repo::saveLabels($new, ['r' . $big => ['show' => 'on']]);
            Render::reset();
            $html = Render::html((array) Repo::map($new));
            self::t(str_contains($html, 'Münsterland</text>') && !str_contains($html, 'Hagen</text>'), 'Namen der Karte aus, einzelnes Gebiet trotzdem an');
            $ed = Render::html((array) Repo::map($new), ['labelEdit' => true, 'assets' => false]);
            self::t(str_contains($ed, 'gk-labels--edit') && !str_contains($ed, 'gk-labels" aria-hidden') && preg_match_all('~<rect class="gk-lab__box"[^>]+tabindex="0" role="button"[^>]+aria-label="[^"]+"~', $ed) === 3
                && str_contains($ed, 'data-gk-proj="'), 'Editor: alle Beschriftungen fokussierbar (Name, Rolle), auch ausgeblendete; Projektion für das Ziehen');
            $svg = Render::svg((array) Repo::map($new));
            self::t(@simplexml_load_string($svg) !== false && str_contains($svg, '>Münsterland</tspan>') && !str_contains($svg, 'class="'), 'SVG-Export: Beschriftungen mit festen Farben, gültig');
            $css = Colors::sheetCss([], ['media' => '(prefers-color-scheme: dark)', 'scope' => 'html.has-dark', 'extra' => []], [], true);
            self::t(str_contains($css, 'html.has-dark .gk-labels .gk-tdk-l{fill:var(--gk-lab-light)'), 'Stil automatisch im Dunkeln: Regel in der erzeugten Datei');
            Repo::deleteRegion($new, $big);
            self::t(!isset(((array) Repo::map($new))['labels']['r' . $big]), 'Gebiet gelöscht: Beschriftung entfernt');
        } catch (\Throwable $e) {
            self::t(false, 'Beschriftungen', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            foreach ($ids as $id) Repo::deleteMap($id);
        }

        // Datentabelle: Text aus einem Feld der Einträge, Lage in der Karte; ohne Recht kein Schreiben
        $h = 'gk_selftest_' . bin2hex(random_bytes(3));
        $mapId = 0;
        try {
            [$def, $err] = Tables::validate(['name' => 'Selbsttest Beschriftung', 'handle' => $h, 'fields' => [
                ['label' => 'Name', 'type' => 'text', 'required' => 1], ['label' => 'Kreise', 'type' => 'textarea'], ['label' => 'Kurz', 'type' => 'text'],
            ]]);
            if ($err) { self::t(false, 'Datentabelle angelegt', json_encode($err) ?: ''); return; }
            Tables::create($def);
            $t = (array) Tables::find($h);
            [$e1] = Entries::save($t, null, ['name' => 'Regionalgruppe Niederrhein', 'kreise' => 'Wesel, Kleve', 'kurz' => 'NR', 'status' => 'published']);
            $mapId = Repo::saveMap(null, ['title' => 'Selbsttest Tabelle Beschriftung', 'level' => 'kreise', 'scope' => ['05'], 'source' => 'table',
                'binding' => ['table' => $h, 'units' => 'kreise', 'label' => 'kurz'], 'options' => ['labels' => true] + Repo::OPTIONS]);
            $map = (array) Repo::map($mapId);
            Repo::saveLabels($mapId, ['e' . $e1 => ['pos' => [6.4, 51.6]], 'e999' => ['show' => 'on']]);
            $map = (array) Repo::map($mapId);
            self::t(array_keys($map['labels']) === ['e' . $e1], 'Kartenmetadaten: Lage je Eintrag, fremde Schlüssel fallen weg');
            Render::reset();
            $html = Render::html($map);
            self::t(str_contains($html, '>NR</text>'), 'Text aus dem Feld „Kurz“ des Eintrags');
            $res = Labels::saveEntryTexts($map, Bound::regionsOf($map), ['e' . $e1 => 'Niederrhein']);
            self::t($res['saved'] === 0 && isset($res['errors']['e' . $e1]), 'ohne Recht „Daten bearbeiten“: Text im Eintrag bleibt');
        } catch (\Throwable $e) {
            self::t(false, 'Beschriftung aus Datentabelle', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            if ($mapId) Repo::deleteMap($mapId);
            if ($x = Tables::find($h)) Tables::delete($x);
        }
    }

    /** Darstellung (seit 0.4.0): Optionen, Hover-Effekte, Schatten, Pills, Töne der Akzentfarbe */
    private static function look(): void
    {
        $o = Repo::look(Repo::OPTIONS, ['hover' => 'evil', 'shadow' => 'yes', 'pill_shadow' => '0', 'palette' => 'accent', 'label_style' => 'pill-dark', 'label_mode' => 'legacy', 'fit' => 'regions']);
        self::t($o['hover'] === 'none' && $o['shadow'] === true && $o['pill_shadow'] === false && $o['palette'] === 'accent' && $o['label_style'] === 'pill-dark'
            && $o['label_mode'] === 'pole' && $o['fit'] === 'scope', 'Darstellung bereinigt (unbekannte Werte → Standard, nur Optionen der Darstellung)');
        $css = (string) file_get_contents(Gebietskarten::dir() . '/assets/css/gebietskarten.css');
        $adm = (string) file_get_contents(Gebietskarten::dir() . '/assets/css/gebietskarten-admin.css');
        $ids = [];
        try {
            // Bestandskarte (Optionen ohne die neuen Schlüssel): keine zusätzliche Auszeichnung
            $old = (int) app()->db->insert(Repo::MAPS, ['title' => 'Selbsttest Darstellung alt', 'base' => 'de', 'level' => 'kreise', 'scope' => '05', 'source' => 'own',
                'binding' => '{}', 'options' => json_encode(['rest' => true, 'fit' => 'scope', 'lines' => true, 'labels' => true, 'list' => true, 'label_mode' => 'pole', 'label_size' => 'auto', 'label_style' => 'auto', 'label_caps' => false]),
                'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $old;
            Repo::saveRegion($old, null, ['name' => 'Nord', 'units' => '05170, 05154']);
            $m = (array) Repo::map($old);
            self::t($m['options']['hover'] === 'none' && !$m['options']['shadow'] && !$m['options']['pill_shadow'] && $m['options']['palette'] === 'default', 'Bestandskarte: Darstellung wie bisher (kein Hover-Effekt, kein Schatten, Palette)');
            Render::reset();
            $html = Render::html($m);
            self::t(!preg_match('~gk--hover|gk--shadow|gk--pillshadow|data-gk-pole|gk-lk|gk-pill~', $html) && !str_contains($html, 'js/gebietskarten.js'), 'Bestandskarte: keine neuen Klassen, Attribute oder Skripte');

            // animiert + Schatten + Pill hell + Töne
            $id = Repo::saveMap(null, ['title' => 'Selbsttest Darstellung', 'level' => 'kreise', 'scope' => ['05'], 'options' => ['labels' => true] + Repo::OPTIONS]);
            $ids[] = $id;
            $names = ['Nord', 'Ost', 'Süd', 'West', 'Mitte'];
            foreach ([['05170', '05154', '05166'], ['05515', '05554', '05558'], ['05374', '05378', '05382'], ['05358', '05362', '05366'], ['05911', '05913', '05914']] as $i => $u) {
                Repo::saveRegion($id, null, ['name' => $names[$i], 'units' => implode(',', $u), 'link' => '/kontakt']);
            }
            Repo::saveLook($id, ['hover' => 'lift', 'shadow' => true, 'pill_shadow' => true, 'palette' => 'accent', 'label_style' => 'pill-light']);
            Repo::saveLabels($id, [Repo::regions($id)[3]['key'] => ['text' => "West mit\nZeilenumbruch"]]);
            $m = (array) Repo::map($id);
            self::t($m['options']['hover'] === 'lift' && $m['options']['shadow'] && $m['options']['palette'] === 'accent' && $m['options']['label_mode'] === 'pole', 'Darstellung gespeichert, Lage der Beschriftung bleibt');
            Repo::saveMap($id, ['title' => 'Selbsttest Darstellung', 'level' => 'kreise', 'scope' => ['05'], 'options' => $m['options']]);
            self::t(((array) Repo::map($id))['options']['hover'] === 'lift', 'Einstellungen speichern behält die Darstellung');
            Render::reset();
            $html = Render::html($m, ['action' => 'link']);
            $regions = Bound::regionsOf($m);
            self::t(!preg_match('~\sstyle\s*=~i', $html) && !preg_match('~<script(?![^>]*\bsrc=)~i', $html), 'Website: keine style-Attribute, keine Inline-Skripte');
            self::t((bool) preg_match('~<figure class="gk [^"]*gk--hover-lift gk--shadow gk--pillshadow"~', $html) && str_contains($html, 'js/gebietskarten.js'), 'animiert: Klassen an der Karte, Skript geladen');
            self::t(preg_match_all('~<a class="gk-area [^"]*" data-gk-k="(r\d+)" data-gk-pole="[\d.]+ [\d.]+" href=~', $html, $am) === 5, 'animiert: Schlüssel und Pol an jedem Gebiet', (string) count($am[1] ?? []));
            self::t(preg_match_all('~<g class="gk-lk" data-gk-k="(r\d+)">~', $html, $lm) === 5 && !array_diff($lm[1], $am[1]), 'Beschriftungen in Hüllen mit dem Schlüssel ihres Gebiets');
            self::t(preg_match_all('~<g class="gk-pill gk-pill-l[^"]*"><rect class="gk-pill__bg" x="[\d.]+" y="[\d.]+" width="([\d.]+)" height="([\d.]+)" rx="([\d.]+)"/><text~', $html, $pm, PREG_SET_ORDER) === 5, 'Pill hell: Rechteck hinter dem Text');
            $geo = Render::geometry($m, $regions);
            $lay = Labels::layout($m, $regions, $geo, Colors::assign($regions, $geo['neighbors'], 'accent'), false, true);
            $okRx = true;
            foreach ($lay as $l) {
                $n = count($l['lines']);
                [$bw, $bh] = Labels::box($l['lines'], $l['fs'], $l['caps']);
                if ($l['w'] <= $bw || $l['h'] <= $bh || abs(Labels::pillRadius($n, $l['fs'], $l['h']) - ($n > 1 ? 0.6 * $l['fs'] : $l['h'] / 2)) > 0.11) $okRx = false;
            }
            self::t($okRx, 'Pill: Maße aus box() plus Innenabstand, rx = Höhe/2 (mehrzeilig 0,6 em)');
            $multi = array_values(array_filter($lay, fn($l) => count($l['lines']) > 1));
            self::t($multi && $multi[0]['lines'] === ['West mit', 'Zeilenumbruch'], 'Pill mehrzeilig');
            self::t(!array_filter($lay, fn($l) => $l['cL'] < Colors::MIN_LABEL || $l['cD'] < Colors::MIN_LABEL), 'Pill hell: Kontrast hell und dunkel ≥ 4,5:1 (Fläche und Schrift des Kits)');
            $effs = Colors::assign($regions, $geo['neighbors'], 'accent');
            self::t(!array_diff($effs, array_keys(Colors::TONES)), 'Töne der Akzentfarbe statt Palette', implode(',', $effs));
            self::t((bool) preg_match('~class="gk-area (gk-c-a\d|gk-c-own gk-x-)~', $html), 'Töne als Klasse (bzw. feste Farbe, wenn für den Kontrast angepasst)');
            $sheet = preg_match('~<link rel="stylesheet" href="([^"]*/gebietskarten/farben-[0-9a-f]{12}\.css)">~', $html, $sm) ? site()->mediaDir('gebietskarten/' . basename($sm[1])) : '';
            self::t(!Colors::darkRule() || ($sheet !== '' && str_contains((string) file_get_contents($sheet), Colors::DARK_SHADOWS)), 'Schatten im Dunkeln kräftiger (erzeugte Datei unter der Dunkel-Regel)');
            self::t(str_contains($adm, '.gk-prevbox[data-scheme="dark"] .gk { ' . Colors::DARK_SHADOWS . '; }'), 'Vorschau „Dunkel“ der Verwaltung: dieselben Schatten');
            $svg = Render::svg($m);
            self::t(@simplexml_load_string($svg) !== false && substr_count($svg, '<rect x=') >= 5 && str_contains($svg, ' rx="'), 'SVG-Export: Pills als Rechteck mit runden Ecken');

            // dezent + Pill dunkel
            Repo::saveLook($id, ['hover' => 'subtle', 'label_style' => 'pill-dark', 'palette' => 'default']);
            Render::reset();
            $html = Render::html((array) Repo::map($id), ['action' => 'link']);
            self::t(str_contains($html, 'gk--hover-subtle') && !str_contains($html, 'data-gk-pole') && !str_contains($html, 'js/gebietskarten.js') && substr_count($html, 'gk-pill gk-pill-d') === 5, 'dezent: nur CSS (kein Skript, keine Pole), Pill dunkel');
            self::t((bool) preg_match('~\.gk--hover-subtle \.gk-areas:has\(> \.gk-area:hover\) > \.gk-area:not\(:hover\) path \{ fill-opacity~', $css)
                && str_contains($css, '.gk--hover-subtle .gk-areas:has(> .gk-area:focus-visible)'), 'dezent: übrige Gebiete abdunkeln bei Hover und Tastaturfokus');
        } catch (\Throwable $e) {
            self::t(false, 'Darstellung', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            foreach ($ids as $x) Repo::deleteMap($x);
        }

        // Pill dunkel: Kontrast mit heller Schrift für verschiedene Akzentfarben (auch Gelb), hell und dunkel
        $bad = [];
        foreach (['#2f6f8f', '#7cc4e4', '#f0c000', '#ffff00', '#66aa33', '#ffffff', (string) Preview::kit(false)['accent'], (string) Preview::kit(true)['accent']] as $a) {
            [, $c] = Labels::tone('pill-dark', '#cccccc', ['accent' => $a, 'bg' => '#ffffff', 'ink' => '#1b1f24']);
            if ($c < Colors::MIN_LABEL) $bad[] = "$a $c";
        }
        self::t(!$bad, 'Pill dunkel: helle Schrift ≥ 4,5:1 für jede Akzentfarbe', implode(', ', $bad));
        self::t(str_contains($css, '--gk-pill-dark-bg: color-mix(in srgb, var(--kit-accent, #2f6f8f) ' . Labels::PILL_DARK . '%, #000)'), 'Pill dunkel: CSS mischt wie PHP');
        // Telefon: Pill-Gruppe skaliert um ihre Mitte (Telefon-Größe / Größe)
        $miss = [];
        foreach (['l' => ['l', 'm', 's'], 'm' => ['m', 's'], 's' => ['s']] as $sz => $phones) foreach ($phones as $ph) {
            $f = rtrim(rtrim(number_format(Labels::PHONE[$ph] / Labels::SIZES[$sz], 4, '.', ''), '0'), '.');
            $f = str_starts_with($f, '0.') ? substr($f, 1) : $f;
            if (!str_contains($css, '.gk-pz-' . $sz . $ph . ' { transform: scale(' . $f . '); }')) $miss[] = $sz . $ph . '=' . $f;
        }
        self::t(!$miss, 'Telefon: Pill skaliert mit dem Verhältnis der Schriftgrößen', implode(' ', $miss));

        // Töne der Akzentfarbe: Nachbarn verschieden (16 Länder), Kontrast, CSS wie PHP
        $de = Bases::units('de', 'laender', []);
        $owner = $regions = [];
        foreach (array_keys($de['units']) as $uid) { $owner[$uid] = 'l' . $uid; $regions[] = ['key' => 'l' . $uid, 'color' => '']; }
        $nb = Render::neighbors($owner, $de['units']);
        $eff = Colors::assign($regions, $nb, 'accent');
        $clash = [];
        foreach ($nb as $k => $list) foreach ($list as $x) if ($eff[$k] === $eff[$x] && $k < $x) $clash[] = "$k/$x";
        self::t(!$clash && !array_diff($eff, array_keys(Colors::TONES)), '16 Länder in Tönen der Akzentfarbe: keine zwei Nachbarn gleich', implode(' ', $clash));
        $bad = [];
        $kits = [[Preview::kit(false), 'Website hell'], [Preview::kit(true), 'Website dunkel']];
        foreach (['#2f6f8f', '#c0392b', '#f0c000', '#6a1b9a', '#7cc4e4'] as $a) {
            $kits[] = [['accent' => $a, 'bg' => '#ffffff', 'ink' => '#1b1f24'], "$a hell"];
            $kits[] = [['accent' => $a, 'bg' => '#16191d', 'ink' => '#eef1f4'], "$a dunkel"];
        }
        foreach ($kits as [$kit, $name]) foreach (array_keys(Colors::TONES) as $k) {
            $c = Colors::approx($k, $kit);
            if (max(\Core\Contrast::ratio($c, Labels::DARK), \Core\Contrast::ratio($c, Labels::LIGHT)) < Colors::MIN_LABEL) $bad[] = "$name $k $c";
        }
        self::t(!$bad, 'Töne: Beschriftung „automatisch“ erreicht immer ≥ 4,5:1 (angepasst, wo nötig)', implode(', ', $bad));
        $miss = array_filter(array_keys(Colors::TONES), fn($k) => !str_contains($css, '.gk-c-' . $k . ' { --gk-c: color-mix(in srgb, var(--kit-accent, #2f6f8f) ' . Colors::TONES[$k][1] . '%, var(--kit-' . Colors::TONES[$k][0] . ','));
        self::t(!$miss, 'Stylesheet: Töne mit denselben Anteilen wie in PHP', implode(',', $miss));
        $p = Render::paints([['key' => 'r1', 'color' => ''], ['key' => 'r2', 'color' => '']], [], 'accent');
        self::t((bool) preg_match('~^gk-c-(a\d|own gk-x-)~', $p['r1']['class']) && $p['r1']['class'] !== $p['r2']['class'], 'Farben je Gebiet: Töne verschieden');
    }

    private static function entryAssign(): void
    {
        $units = Bases::units('de', 'kreise', ['05'])['units'];
        $f = ['name' => 'k', 'type' => 'textarea'];
        [$v] = EntryAssign::value($f, "Wesel\nKleve\nLandkreis Fulda", ['Wesel', 'Kleve', 'Landkreis Fulda'], ['05170', '05111'], $units, 'names');
        self::t($v === "Wesel\nLandkreis Fulda\nDüsseldorf", 'Textfeld: Namen, entfernt Kleve, ergänzt Düsseldorf, Fulda (andere Karte) bleibt', json_encode($v) ?: '');
        [$v] = EntryAssign::value(['name' => 'k', 'type' => 'text'], '05170, 05154', ['05170', '05154'], ['05170', '05162'], $units, 'keys');
        self::t($v === '05170, 05162', 'Text: Schlüssel, Komma', (string) $v);
        $ms = ['name' => 'k', 'type' => 'multiselect', 'options' => ['wesel' => 'Wesel', 'kleve' => 'Kreis Kleve', 'x' => 'Unbekannt']];
        [$v, $miss] = EntryAssign::value($ms, ['wesel'], ['wesel'], ['05170', '05154', '05111'], $units, 'names');
        self::t($v === ['wesel', 'kleve'] && $miss === ['05111'], 'Mehrfachauswahl: nur vorhandene Optionen, fehlende gemeldet', json_encode([$v, $miss]) ?: '');
        self::t(EntryAssign::style([['raw' => ['05170', '05154', 'Kleve']]]) === 'keys' && EntryAssign::style([]) === 'names', 'Schreibweise nach Mehrheit der Einträge');

        $h = 'gk_selftest_' . bin2hex(random_bytes(3));
        $mapId = 0;
        // Rollen vorübergehend setzen wie die Selbsttests des Cores (Auth per Reflection), danach zurückstellen
        $auth = app()->auth;
        $saved = [];
        foreach (['loaded', 'user', 'role'] as $prop) $saved[$prop] = (new \ReflectionProperty($auth, $prop))->getValue($auth);
        $as = function (?string $role) use ($auth): void {
            $r = $role !== null ? \Core\Permissions::role($role) : null;
            (new \ReflectionProperty($auth, 'loaded'))->setValue($auth, true);
            (new \ReflectionProperty($auth, 'user'))->setValue($auth, $r ? ['id' => 0, 'email' => 'selbsttest@example.test', 'name' => 'Selbsttest', 'role' => $role, 'appearance' => null, 'locale' => null] : null);
            (new \ReflectionProperty($auth, 'role'))->setValue($auth, $r);
        };
        try {
            [$def, $err] = Tables::validate(['name' => 'Selbsttest Zuordnung', 'handle' => $h, 'fields' => [
                ['label' => 'Name', 'type' => 'text', 'required' => 1],
                ['label' => 'Kreise', 'type' => 'textarea'],
            ]]);
            if ($err) { self::t(false, 'Datentabelle angelegt', json_encode($err) ?: ''); return; }
            Tables::create($def);
            $t = (array) Tables::find($h);
            [$e1] = Entries::save($t, null, ['name' => 'Nord', 'kreise' => "Wesel\nKleve", 'status' => 'published']);
            [$e2] = Entries::save($t, null, ['name' => 'Süd', 'kreise' => 'Köln', 'status' => 'published']);
            $mapId = Repo::saveMap(null, ['title' => 'Selbsttest Klick', 'level' => 'kreise', 'scope' => ['05'], 'source' => 'table', 'binding' => ['table' => $h, 'units' => 'kreise']]);
            $map = (array) Repo::map($mapId);

            $as(null);
            $res = EntryAssign::save($map, [$e1 => ['05170']]);
            self::t($res['saved'] === 0 && $res['errors'], 'ohne Anmeldung: nichts gespeichert (Recht „Daten bearbeiten“)');
            $as('author');
            $res = EntryAssign::save($map, [$e1 => ['05170', '05111']]);
            self::t($res['saved'] === 0 && isset($res['errors'][$e1]) && str_contains($res['errors'][$e1], 'Veröffentlichte'), 'Autorin: veröffentlichte Einträge bleiben unverändert (Regel des Cores)', json_encode($res, JSON_UNESCAPED_UNICODE) ?: '');

            $as('editor');
            // Aktionsprotokoll: der Selbsttest-Lauf setzt es aus (Activity::pause) – für diese Prüfung kurz einschalten
            $paused = (bool) (new \ReflectionProperty(\Core\Activity::class, 'off'))->getValue();
            \Core\Activity::pause(false);
            // Klick: Köln wandert von „Süd“ zu „Nord“, Kleve fällt weg, Düsseldorf kommt dazu
            $res = EntryAssign::save($map, [$e1 => ['05170', '05315', '05111'], $e2 => []]);
            \Core\Activity::pause($paused);
            $n1 = Entries::find($t, (int) $e1);
            $n2 = Entries::find($t, (int) $e2);
            self::t($res['saved'] === 2 && !$res['errors'] && $n1['kreise'] === "Wesel\nKöln\nDüsseldorf" && trim((string) $n2['kreise']) === '', 'Redaktion: Feld der Einträge geschrieben (Namen, je Zeile)', json_encode([$res, $n1['kreise'] ?? null, $n2['kreise'] ?? null], JSON_UNESCAPED_UNICODE) ?: '');
            self::t(($n1['status'] ?? '') === 'published' && ($n2['status'] ?? '') === 'published', 'Status der Einträge bleibt');
            $revs = \Core\Data\Revisions::list($t, (int) $e1);
            self::t((bool) array_filter($revs, fn($r) => str_contains((string) ($r['note'] ?? ''), 'Gebietskarte „Selbsttest Klick“')), 'Version mit Notiz „Gebietskarte …: Flächen per Klick zugeordnet“');
            $log = (int) app()->db->fetchValue('SELECT COUNT(*) FROM activity_log WHERE entity_id = ? AND tbl = ?', [(int) $e1, $h]);
            self::t($log > 0, 'Eintrag im Aktionsprotokoll');
            Render::reset();
            $geo = Render::geometry((array) Repo::map($mapId), Bound::regionsOf((array) Repo::map($mapId)));
            self::t(($geo['unitsOf']['e' . $e1] ?? []) === ['05170', '05315', '05111'] && ($geo['unitsOf']['e' . $e2] ?? null) === [], 'Karte zeigt die neue Zuordnung');
            $res = EntryAssign::save((array) Repo::map($mapId), [$e1 => ['05170', '05315', '05111']]);
            self::t($res['saved'] === 0 && !$res['errors'], 'unverändert: nichts gespeichert');
        } catch (\Throwable $e) {
            self::t(false, 'Zuordnung in Einträge', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            foreach ($saved as $prop => $v) (new \ReflectionProperty($auth, $prop))->setValue($auth, $v);
            app()->db->query('DELETE FROM activity_log WHERE tbl = ?', [$h]);
            if ($mapId) Repo::deleteMap($mapId);
            if ($x = Tables::find($h)) Tables::delete($x);
        }
    }

    private static function packs(): void
    {
        $tmp = sys_get_temp_dir() . '/gk-selftest-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0700, true);
        $sn = null;
        foreach (array_keys(Bases::LAENDER) as $s) if (!is_file(DataPacks::file('de', 'gemeinden', $s))) { $sn = str_pad((string) $s, 2, '0', STR_PAD_LEFT); break; }
        $sn ??= '10';
        $body = json_encode(['type' => 'Topology', 'arcs' => [[[0, 0], [1, 0], [1, 1], [0, 0]]],
            'objects' => ['einheiten' => ['type' => 'GeometryCollection', 'geometries' => [['type' => 'Polygon', 'arcs' => [[0]], 'properties' => ['id' => $sn . '000000', 'name' => 'Test', 'l' => $sn]]]]]]) ?: '';
        file_put_contents("$tmp/$sn.topo.json", $body);
        file_put_contents("$tmp/manifest.json", json_encode(['files' => [$sn => ['file' => "$sn.topo.json", 'sha256' => str_repeat('0', 64)]]]));
        $had = is_file(DataPacks::file('de', 'gemeinden', $sn));
        $r = DataPacks::install([$sn], $tmp);
        self::t(isset($r['failed'][$sn]) && !$r['ok'], 'falsche Prüfsumme wird abgelehnt');
        if (!$had) {
            file_put_contents("$tmp/manifest.json", json_encode(['files' => [$sn => ['file' => "$sn.topo.json", 'sha256' => hash('sha256', $body)]]]));
            $r = DataPacks::install([$sn], $tmp);
            self::t($r['ok'] === [$sn] && is_file(DataPacks::file('de', 'gemeinden', $sn)), 'richtige Prüfsumme: geladen');
            DataPacks::remove([$sn]);
            self::t(!is_file(DataPacks::file('de', 'gemeinden', $sn)), 'wieder entfernt');
        }
        $r = DataPacks::install(['99'], $tmp);
        self::t(isset($r['failed']['99']), 'unbekanntes Land abgelehnt');
        foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    }
}
