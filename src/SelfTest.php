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
 * Status, Version, Protokoll). Legt Testdaten (Karten, Tabelle, zwei Konten) an und entfernt sie danach wieder.
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
        echo "Reihenfolge\n";
        self::order();
        echo "Quellenvermerk (überall gleich)\n";
        self::attribution();
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
