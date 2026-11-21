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
 * Inline-Skripte, Zugänglichkeit des SVG (Titel, Namen der Links, Liste), Quellenvermerk, Nachladen mit Prüfsumme.
 * Legt Testdaten an und entfernt sie danach wieder.
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
        self::t(str_contains($html, '<g class="gk-area gk-c-own"') && str_contains($html, 'fill="#336699"'), 'eigene Farbe als SVG-Attribut fill');
        self::t(str_contains($html, 'gk-c-accent'), 'Design-Farbe als Klasse');
        self::t(substr_count($html, '<li class="gk-item') === 2 && str_contains($html, 'aria-label="Gebiete"'), 'Liste der Gebiete als Alternative');
        self::t((bool) preg_match('~class="gk-src">Kartengrundlage: © <a href="https://www\.bkg\.bund\.de"[^>]*>BKG</a> \(\d{4}\) <a href="https://www\.govdata\.de/dl-de/by-2-0"[^>]*>dl-de/by-2-0</a>, <a href="https://sgx\.geodatenzentrum\.de/[^"]+"[^>]*>Datenquellen</a>, vereinfacht</p>~', $html), 'Quellenvermerk unter der Karte (BKG, Lizenz, Datenquellen verlinkt, Veränderungshinweis)');
        self::t(str_contains($html, 'css/gebietskarten.css') && !str_contains($html, 'js/gebietskarten.js'), 'nur CSS, kein Skript ohne Info-Fenster');
        self::t(str_contains($html, '<g class="gk-labels" aria-hidden="true">'), 'Beschriftung aria-hidden');

        $hl = Render::html($map, ['mode' => 'highlight', 'highlight' => 'r' . $b, 'action' => 'info', 'assets' => true]);
        self::t(str_contains($hl, 'gk--focus') && str_contains($hl, 'gk-c-own is-active'), 'Hervorheben: Gebiet markiert, andere gedämpft');
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
