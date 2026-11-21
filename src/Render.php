<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

/**
 * Ausgabe einer Gebietskarte als serverseitig gerendertes SVG – ohne Kartenbibliothek, CSP-sauber (keine style-Attribute,
 * Farben über Klassen bzw. das Darstellungsattribut fill), jedes Gebiet als Link bzw. Knopf mit zugänglichem Namen, darunter
 * die Liste der Gebiete als Alternative (Screenreader, ohne JavaScript) und der Quellenvermerk der Kartendaten.
 *
 *   Render::html($map, ['mode' => 'overview'|'highlight'|'current'|'site', 'highlight' => 'r12', 'action' => 'link'|'info'|'none'])
 *   Render::geometry($map, $regions, $editor)   Pfade je Gebiet (zwischengespeichert unter storage/cache/gebietskarten)
 *   Render::svg($map, $o)                        bereinigtes SVG zum Weiterbearbeiten (Grafik-Editor, Mediathek)
 */
final class Render
{
    /** Ändert sich die Berechnung, ändert sich der Schlüssel des Zwischenspeichers */
    private const VERSION = 3;
    private const WIDTH = 1000;

    private static int $count = 0;
    private static bool $css = false;
    private static bool $js = false;

    /**
     * Geometrie einer Karte. Gebiete mit 'units' (eigene) bzw. 'raw' (Datentabelle, wird hier aufgelöst).
     * @param list<array> $regions
     * @return array{w: int, h: int, regions: array<string, string>, rest: string, units: array<string, string>, lines: string,
     *               labels: array<string, array{0: float, 1: float}>, owner: array<string, string>, unitsOf: array<string, list<string>>,
     *               unmatched: array<string, list<string>>, missing: list<string>, names: array<string, string>, empty: bool}
     */
    public static function geometry(array $map, array $regions, bool $editor = false): array
    {
        $assign = [];
        foreach ($regions as $r) $assign[$r['key']] = $r['units'] ?: ($r['raw'] ?? []);
        $files = [];
        $missing = [];
        foreach (Bases::files($map['base'], $map['level'], $map['scope'], $missing) as $f) $files[] = $f . '@' . filemtime($f);
        $key = md5(json_encode([self::VERSION, site()->key, $map['base'], $map['level'], $map['scope'], $map['options']['fit'], $map['options']['rest'],
            $map['source'], $assign, $editor, $files]) ?: '');
        $cache = Gebietskarten::cacheDir() . '/' . $key . '.json';
        if (is_file($cache) && is_array($c = json_decode((string) file_get_contents($cache), true)) && isset($c['w'])) return $c;

        $data = Bases::units($map['base'], $map['level'], $map['scope']);
        $units = $data['units'];
        $owner = [];
        $unitsOf = [];
        $unmatched = [];
        foreach ($regions as $r) {
            [$ids, $miss] = isset($r['raw']) && !$r['units'] ? Bound::resolve($r['raw'], $units) : [$r['units'], []];
            $unitsOf[$r['key']] = [];
            foreach ($ids as $id) {
                if (!isset($units[$id])) { $miss[] = $id; continue; }
                if (isset($owner[$id])) continue;            // jede Einheit genau einem Gebiet (das erste gewinnt)
                $owner[$id] = $r['key'];
                $unitsOf[$r['key']][] = $id;
            }
            if ($miss) $unmatched[$r['key']] = array_values(array_unique($miss));
        }
        $out = ['w' => self::WIDTH, 'h' => (int) round(self::WIDTH * 0.6), 'regions' => [], 'rest' => '', 'units' => [], 'lines' => '', 'labels' => [],
            'owner' => $owner, 'unitsOf' => $unitsOf, 'unmatched' => $unmatched, 'missing' => $data['missing'],
            'names' => $editor ? array_map(fn($u) => $u['name'], $units) : [], 'empty' => !$units];
        if (!$units) return $out;

        // Ausschnitt: alle Einheiten oder nur die Gebiete (dann nur Nachbarn im Rahmen zeigen)
        $fitRegions = !$editor && $map['options']['fit'] === 'regions' && $owner;
        $b = Topo::bounds($fitRegions ? array_intersect_key($units, $owner) : $units, $data['arcs']);
        if (!$b) return $out;
        if ($fitRegions) {
            $padX = ($b[2] - $b[0]) * 0.04;
            $padY = ($b[3] - $b[1]) * 0.04;
            $b = [$b[0] - $padX, $b[1] - $padY, $b[2] + $padX, $b[3] + $padY];
        }
        $drawn = [];
        foreach ($units as $id => $u) {
            if (!isset($owner[$id]) && !$editor && !$map['options']['rest']) continue;
            if ($fitRegions && !isset($owner[$id]) && !self::touches($u, $data['arcs'], $b)) continue;
            $drawn[$id] = $u;
        }
        $p = Topo::projection($b, self::WIDTH);
        $out['w'] = $p['width'];
        $out['h'] = $p['height'];
        $arcs = Topo::project($data['arcs'], Topo::arcCount($drawn), $p['fn'], $editor ? 0.35 : 0.5);

        foreach ($unitsOf as $k => $ids) {
            $us = array_intersect_key($drawn, array_flip($ids));
            if (!$us) continue;
            $rings = Topo::merge($us, $arcs);
            $out['regions'][$k] = Topo::path($rings);
            $out['labels'][$k] = self::labelPoint($us, $rings, $p['fn']);
        }
        $rest = array_diff_key($drawn, $owner);
        if ($rest && !$editor) $out['rest'] = Topo::path(Topo::merge($rest, $arcs));
        if ($editor) {
            foreach ($drawn as $id => $u) $out['units'][(string) $id] = Topo::path(Topo::merge([$u], $arcs));
        }
        // Grenzen der Einheiten innerhalb eines Gebiets bzw. innerhalb des Rests (zwischen Gebieten zeichnet der Rand der Fläche)
        $sides = [];
        foreach ($drawn as $id => $u) {
            foreach ($u['polys'] as $poly) foreach ($poly as $ring) foreach ($ring as $r) $sides[$r < 0 ? ~$r : $r][] = $owner[$id] ?? '';
        }
        $out['lines'] = Topo::lines(Topo::mesh($drawn, $arcs, fn(int $a): bool => count($sides[$a] ?? []) === 2 && $sides[$a][0] === $sides[$a][1]));

        if (!is_dir(Gebietskarten::cacheDir())) @mkdir(Gebietskarten::cacheDir(), 0775, true);
        @file_put_contents($cache, json_encode($out), LOCK_EX);
        return $out;
    }

    /** Liegt eine Einheit (grob, über ihre Bögen) im Ausschnitt? */
    private static function touches(array $u, array $arcs, array $b): bool
    {
        foreach ($u['polys'] as $poly) foreach ($poly[0] ?? [] as $r) {
            foreach ($arcs[$r < 0 ? ~$r : $r] ?? [] as [$x, $y]) {
                if ($x >= $b[0] && $x <= $b[2] && $y >= $b[1] && $y <= $b[3]) return true;
            }
        }
        return false;
    }

    /** Beschriftungspunkt: der Punkt im Inneren einer Einheit, der dem Schwerpunkt der größten Fläche am nächsten liegt */
    private static function labelPoint(array $units, array $rings, callable $proj): array
    {
        usort($rings, fn($a, $b) => abs(Topo::area($b)) <=> abs(Topo::area($a)));
        $c = [0.0, 0.0];
        if ($rings) {
            $r = $rings[0];
            $cx = $cy = $a = 0.0;
            for ($i = 0, $n = count($r) - 1; $i < $n; $i++) {
                $f = $r[$i][0] * $r[$i + 1][1] - $r[$i + 1][0] * $r[$i][1];
                $a += $f;
                $cx += ($r[$i][0] + $r[$i + 1][0]) * $f;
                $cy += ($r[$i][1] + $r[$i + 1][1]) * $f;
            }
            $c = $a != 0.0 ? [$cx / (3 * $a), $cy / (3 * $a)] : $r[0];
        }
        $best = null;
        $bd = INF;
        foreach ($units as $u) {
            $pt = $proj($u['x'], $u['y']);
            $d = ($pt[0] - $c[0]) ** 2 + ($pt[1] - $c[1]) ** 2;
            if ($d < $bd) { $bd = $d; $best = $pt; }
        }
        return $best ?? [round($c[0], 1), round($c[1], 1)];
    }

    public static function flushCache(): void
    {
        foreach (glob(Gebietskarten::cacheDir() . '/*.json') ?: [] as $f) @unlink($f);
    }

    /**
     * Gebiet, das auf dieser Seite hervorgehoben wird. mode: highlight (fester Schlüssel), current (Gebiet, dessen Link auf diese
     * Seite oder eine übergeordnete zeigt bzw. dessen Eintrag gerade angezeigt wird), site (Feld „Website“ der Datentabelle).
     */
    public static function activeKey(array $regions, string $mode, string $highlight = ''): ?string
    {
        $keys = array_column($regions, 'key');
        if ($mode === 'highlight') return in_array($highlight, $keys, true) ? $highlight : null;
        if ($mode === 'site') {
            $me = array_merge([strtolower(site()->key)], array_map(fn($h) => strtolower(preg_replace('~:\d+$~', '', (string) $h) ?? ''), site()->hosts()));
            foreach ($regions as $r) {
                $s = preg_replace('~^https?://|/.*$~', '', (string) ($r['site'] ?? '')) ?? '';
                if ($s !== '' && in_array($s, $me, true)) return $r['key'];
            }
            return null;
        }
        if ($mode !== 'current') return null;
        $ent = app()->entry;
        if ($ent) {
            foreach ($regions as $r) {
                if (($r['entry']['table'] ?? null) === ($ent['table']['handle'] ?? '') && ($r['entry']['id'] ?? 0) === (int) ($ent['entry']['id'] ?? -1)) return $r['key'];
            }
        }
        $page = app()->currentPage;
        if (!$page) return null;
        $ids = array_map(fn($p) => (int) $p['id'], \Core\Pages::ancestors($page));
        $ids[] = (int) $page['id'];
        $here = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        // eigene Seite vor übergeordneter: tiefster Treffer gewinnt
        $best = null;
        $depth = -1;
        foreach ($regions as $r) {
            $l = (string) $r['link'];
            if (preg_match('~^page:(\d+)~', $l, $m) && ($pos = array_search((int) $m[1], $ids, true)) !== false && $pos > $depth) {
                $best = $r['key'];
                $depth = (int) $pos;
            } elseif ($l !== '' && $l[0] === '/' && $here && rtrim(url($l), '/') === rtrim($here, '/') && $depth < count($ids)) {
                $best = $r['key'];
                $depth = count($ids);
            }
        }
        return $best;
    }

    /**
     * Karte als HTML (figure mit SVG, Liste der Gebiete und Quellenvermerk).
     * $o: mode, highlight, action (link|info|none), title (Überschrift für Screenreader, sonst Titel der Karte), assets (bool),
     *     preview (Verwaltung: ohne Links, ohne Skript), list (bool|null – null = Einstellung der Karte)
     */
    public static function html(array $map, array $o = []): string
    {
        $regions = Bound::regionsOf($map);
        $geo = self::geometry($map, $regions);
        $id = 'gk-' . (int) $map['id'] . '-' . ++self::$count;
        $preview = !empty($o['preview']);
        $action = $preview ? 'none' : (in_array($o['action'] ?? 'link', ['link', 'info', 'none'], true) ? (string) ($o['action'] ?? 'link') : 'link');
        $active = self::activeKey($regions, (string) ($o['mode'] ?? 'overview'), (string) ($o['highlight'] ?? ''));
        $title = trim((string) ($o['title'] ?? '')) ?: (string) $map['title'];
        $noun = Bases::unitNoun($map['base'], $map['level'], false);
        $nouns = Bases::unitNoun($map['base'], $map['level'], true);
        $showList = array_key_exists('list', $o) && $o['list'] !== null ? (bool) $o['list'] : (bool) $map['options']['list'];

        $svg = '<svg class="gk-svg" viewBox="0 0 ' . $geo['w'] . ' ' . $geo['h'] . '" role="group" aria-labelledby="' . $id . '-t" focusable="false" xmlns="http://www.w3.org/2000/svg">'
            . '<title id="' . $id . '-t">' . e(lt('Karte: {title}', ['title' => $title]) . ($active ? ' – ' . lt('hervorgehoben: {name}', ['name' => self::nameOf($regions, $active)]) : '')) . '</title>';
        if ($geo['rest'] !== '') $svg .= '<path class="gk-rest" d="' . $geo['rest'] . '" fill-rule="evenodd" aria-hidden="true"/>';
        $svg .= '<g class="gk-areas">';
        $order = $regions;
        usort($order, fn($a, $b) => ($a['key'] === $active) <=> ($b['key'] === $active));   // hervorgehobenes Gebiet zuletzt (Rand oben)
        $paints = self::paints($regions);
        $pairs = [];
        foreach ($paints as $pt) if ($pt['dark']) $pairs[] = [(string) $pt['fill'], $pt['dark']];
        // Verwaltung: Dunkel-Wert für die Vorschau „Dunkel“ als Variable (dort sind Inline-Stile erlaubt, auf der Website nie)
        $pv = fn(array $pt): string => $preview && $pt['dark'] ? ' style="--gk-d:' . e($pt['dark']) . '"' : '';
        foreach ($order as $r) {
            $d = $geo['regions'][$r['key']] ?? '';
            if ($d === '') continue;
            $paint = $paints[$r['key']];
            $cls = 'gk-area ' . $paint['class'] . ($r['key'] === $active ? ' is-active' : '');
            $path = '<path d="' . $d . '" fill-rule="evenodd"' . ($paint['fill'] ? ' fill="' . e($paint['fill']) . '"' : '') . '/>';
            $name = (string) $r['name'];
            $href = $action === 'link' && $r['link'] !== '' ? link_href($r['link']) : '';
            if ($href !== '' && $href !== '#') {
                $svg .= '<a class="' . $cls . '" href="' . e($href) . '" aria-label="' . e($name) . '"' . ($r['key'] === $active ? ' aria-current="true"' : '') . ext_attrs($r['link']) . $pv($paint) . '>'
                    . '<title>' . e($name) . '</title>' . $path . '</a>';
            } elseif ($action === 'info') {
                $svg .= '<a class="' . $cls . '" href="#' . $id . '-' . e($r['key']) . '" aria-label="' . e(lt('{name}: Informationen', ['name' => $name])) . '" data-gk-info="' . e($r['key']) . '" aria-haspopup="dialog"' . $pv($paint) . '>'
                    . '<title>' . e($name) . '</title>' . $path . '</a>';
            } else {
                $svg .= '<g class="' . $cls . '"' . $pv($paint) . '><title>' . e($name) . '</title>' . $path . '</g>';
            }
        }
        $svg .= '</g>';
        if ($map['options']['lines'] && $geo['lines'] !== '') $svg .= '<path class="gk-lines" d="' . $geo['lines'] . '" aria-hidden="true"/>';
        if ($map['options']['labels']) {
            $svg .= '<g class="gk-labels" aria-hidden="true">';
            foreach ($regions as $r) {
                if (!isset($geo['labels'][$r['key']])) continue;
                [$x, $y] = $geo['labels'][$r['key']];
                $svg .= '<text x="' . $x . '" y="' . $y . '"' . ($r['key'] === $active ? ' class="is-active"' : '') . '>' . e($r['name']) . '</text>';
            }
            $svg .= '</g>';
        }
        $svg .= '</svg>';

        // Liste: Name (Link) – mit Info-Fenster zusätzlich die Angaben je Gebiet (Ziel der Links in der Karte, ohne JavaScript)
        $items = '';
        foreach ($regions as $r) {
            if (($geo['regions'][$r['key']] ?? '') === '' && !$preview) continue;
            $paint = $paints[$r['key']];
            $sw = '<svg class="gk-swatch ' . $paint['class'] . '" viewBox="0 0 10 10" aria-hidden="true" focusable="false"' . $pv($paint) . '><rect width="10" height="10" rx="2"' . ($paint['fill'] ? ' fill="' . e($paint['fill']) . '"' : '') . '/></svg>';
            $href = $r['link'] !== '' && !$preview ? link_href($r['link']) : '';
            $name = $href !== '' && $href !== '#' && $action !== 'none'
                ? '<a href="' . e($href) . '"' . ($r['key'] === $active ? ' aria-current="true"' : '') . ext_attrs($r['link']) . '>' . e($r['name']) . '</a>'
                : '<span>' . e($r['name']) . '</span>';
            $n = count($geo['unitsOf'][$r['key']] ?? []);
            $meta = $n ? '<span class="gk-item__n">' . e($n === 1 ? lt('1 {noun}', ['noun' => $noun]) : lt('{n} {noun}', ['n' => $n, 'noun' => $nouns])) . '</span>' : '';
            $info = $action === 'info' ? self::info($r, $id, $href) : '';
            $items .= '<li class="gk-item' . ($r['key'] === $active ? ' is-active' : '') . '" id="' . $id . '-' . e($r['key']) . '">' . $sw . $name . $meta . $info . '</li>';
        }
        $cap = '<ul class="gk-list' . ($showList ? '' : ' gk-sr') . '" aria-label="' . e(lt('Gebiete')) . '">' . $items . '</ul>'
            . self::sourceNote($map);

        $empty = '';
        if (($geo['empty'] || $geo['missing']) && (is_editing() || $preview)) {
            $empty = '<p class="gk-note">' . e($geo['missing'] ? __('Kartendaten fehlen ({list}) – Gebietskarten → Kartendaten laden.', ['list' => implode(', ', array_map(fn($sn) => Bases::LAENDER[$sn] ?? $sn, $geo['missing']))]) : __('Für diese Karte sind keine Kartendaten vorhanden.')) . '</p>';
        }
        $pop = $action === 'info' ? '<div class="gk-pop" role="dialog" aria-modal="false" hidden data-gk-pop><button type="button" class="gk-pop__close" data-gk-close aria-label="' . e(lt('Schließen')) . '"><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3 3l10 10M13 3L3 13"/></svg></button><div class="gk-pop__body" data-gk-body></div></div>' : '';

        return (($o['assets'] ?? true) ? self::assets($action === 'info') . Colors::sheet($pairs) : '')
            . '<figure class="gk gk--' . e($map['level']) . ($active ? ' gk--focus' : '') . ($preview ? ' gk--preview' : '') . '" id="' . $id . '" data-gk-action="' . e($action) . '">'
            . $empty . '<div class="gk-map">' . $svg . $pop . '</div>'
            . '<figcaption class="gk-cap">' . $cap . '</figcaption></figure>';
    }

    /**
     * Quellenvermerk der Kartendaten – eine Fassung für alle Stellen (unter jeder Karte, Vorschau, Kartendaten, SVG-Export,
     * Mediathek), nach den Nutzungsbedingungen des BKG für VG250 (Stand 19.08.2025): „© BKG (Jahr) dl-de/by-2-0, Datenquellen“
     * plus Veränderungshinweis. HTML: BKG, Lizenz und Datenquellen verlinkt (Pflicht für die Darstellung auf Webseiten):
     * „Kartengrundlage: © BKG (2026) dl-de/by-2-0, Datenquellen, vereinfacht“.
     */
    public static function sourceNote(array $map): string
    {
        return self::attributionHtml((string) $map['base'], (string) $map['level']);
    }

    public static function attributionHtml(string $base, string $level, string $class = 'gk-src'): string
    {
        $a = Bases::attribution($base, $level);
        $ln = fn(string $href, string $text): string => '<a href="' . e($href) . '" target="_blank" rel="noopener">' . e($text) . '</a>';
        return '<p class="' . e($class) . '">' . e(lt('Kartengrundlage')) . ': © ' . $ln($a['providerUrl'], $a['provider']) . ' (' . e($a['year']) . ') '
            . $ln($a['url'], $a['license']) . ', ' . $ln($a['sources'], lt('Datenquellen')) . ', ' . e(lt('vereinfacht')) . '</p>';
    }

    /**
     * Quellenvermerk als Text – gleicher Wortlaut, Links als Adresse (SVG-Export, Mediathek, Grafik-Editor):
     * „Kartengrundlage: © BKG (2026) dl-de/by-2-0, Datenquellen: https://sgx.geodatenzentrum.de/…, vereinfacht“
     */
    public static function sourceText(array $map): string
    {
        return self::attributionText((string) $map['base'], (string) $map['level']);
    }

    public static function attributionText(string $base, string $level): string
    {
        $a = Bases::attribution($base, $level);
        return lt('Kartengrundlage') . ': ' . $a['text'] . ', ' . lt('Datenquellen') . ': ' . $a['sources'] . ', ' . lt('vereinfacht');
    }

    /** Angaben eines Gebiets (Info-Fenster bzw. Liste) */
    private static function info(array $r, string $id, string $href): string
    {
        $h = '<div class="gk-info" data-gk-card>';
        $h .= '<p class="gk-info__title" id="' . $id . '-' . e($r['key']) . '-h">' . e($r['name']) . '</p>';
        if ($r['media_id'] && ($img = img((int) $r['media_id'], '(min-width: 40em) 20em, 90vw', ['class' => 'gk-info__img']))) $h .= $img;
        if (trim(strip_tags((string) $r['text'])) !== '') $h .= '<div class="gk-info__text">' . rich((string) $r['text']) . '</div>';
        if (trim((string) $r['contact']) !== '') $h .= '<p class="gk-info__contact">' . nl2br(e(trim((string) $r['contact'])), false) . '</p>';
        if ($href !== '' && $href !== '#') $h .= '<p class="gk-info__more"><a href="' . e($href) . '"' . ext_attrs($r['link']) . '>' . e(lt('Mehr zu {name}', ['name' => $r['name']])) . '</a></p>';
        return $h . '</div>';
    }

    /**
     * Farbe je Gebiet (Klasse, fill, Dunkel-Wert). Dunkel-Wert eigener Farben: Feld „im Dunkeln“ oder automatisch aus den
     * Dunkel-Farben des Kits (Preview::kit(true), nur wenn nötig berechnet).
     * @return array<string, array{class: string, fill: ?string, dark: ?string}>
     */
    public static function paints(array $regions): array
    {
        $out = [];
        $kitDark = null;
        foreach (array_values($regions) as $i => $r) {
            $eff = Colors::effective((string) $r['color'], $i);
            $dark = '';
            if ($eff !== '' && $eff[0] === '#') {
                $own = Colors::hex((string) ($r['color_dark'] ?? ''));
                $dark = $own !== '' ? $own : Colors::dark($eff, $kitDark ??= Preview::kit(true));
            }
            $out[$r['key']] = Colors::paint($eff, $dark);
        }
        return $out;
    }

    private static function nameOf(array $regions, string $key): string
    {
        foreach ($regions as $r) if ($r['key'] === $key) return (string) $r['name'];
        return '';
    }

    /** Stylesheet einmal je Seite, Skript nur mit Info-Fenster (Besucher laden sonst nichts) */
    public static function assets(bool $js): string
    {
        $out = '';
        if (!self::$css) {
            self::$css = true;
            $out .= '<link rel="stylesheet" href="' . e(Gebietskarten::asset('css/gebietskarten.css')) . '">';
        }
        if ($js && !self::$js) {
            self::$js = true;
            $out .= '<script src="' . e(Gebietskarten::asset('js/gebietskarten.js')) . '" defer></script>';
        }
        return $out;
    }

    /** Neue Anfrage (Selbsttest): Stylesheet und Skript erneut ausgeben */
    public static function reset(): void
    {
        self::$css = self::$js = false;
        self::$count = 0;
        Colors::reset();
        Preview::reset();
    }

    /**
     * Bereinigtes, eigenständiges SVG (Farben als feste Werte, Namen als Text, Quellenvermerk) – zum Weiterbearbeiten im
     * Grafik-Editor bzw. als Datei der Mediathek. $kit: ['accent', 'bg', 'ink', 'surface', 'line', 'muted'] (#RRGGBB).
     */
    public static function svg(array $map, array $kit = [], ?string $active = null): string
    {
        $kit += ['accent' => '#2f6f8f', 'bg' => '#ffffff', 'ink' => '#1b1f24', 'surface' => '#eef1f4', 'line' => '#d0d6dd', 'muted' => '#5b6470'];
        $regions = Bound::regionsOf($map);
        $geo = self::geometry($map, $regions);
        $index = array_flip(array_column($regions, 'key'));
        $h = $geo['h'] + 50;
        $x = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $geo['w'] . ' ' . $h . '" width="' . $geo['w'] . '" height="' . $h . '">'
            . '<title>' . $x((string) $map['title']) . '</title><desc>' . $x(self::sourceText($map)) . '</desc>';
        if ($geo['rest'] !== '') $s .= '<path id="rest" d="' . $geo['rest'] . '" fill="' . $kit['surface'] . '" stroke="' . $kit['bg'] . '" stroke-width="1" fill-rule="evenodd"/>';
        $s .= '<g id="gebiete">';
        foreach ($regions as $r) {
            if (($geo['regions'][$r['key']] ?? '') === '') continue;
            $fill = Colors::approx(Colors::effective($r['color'], $index[$r['key']]), $kit);
            $s .= '<path id="' . $x($r['key']) . '" d="' . $geo['regions'][$r['key']] . '" fill="' . $fill . '" stroke="' . $kit['bg'] . '" stroke-width="' . ($r['key'] === $active ? 3 : 1.5) . '" fill-rule="evenodd"><title>' . $x((string) $r['name']) . '</title></path>';
        }
        $s .= '</g>';
        if ($map['options']['lines'] && $geo['lines'] !== '') $s .= '<path id="grenzen" d="' . $geo['lines'] . '" fill="none" stroke="' . $kit['bg'] . '" stroke-opacity="0.6" stroke-width="0.6"/>';
        $s .= '<g id="namen" font-family="system-ui, sans-serif" font-size="15" font-weight="600" text-anchor="middle" fill="' . $kit['ink'] . '" stroke="' . $kit['bg'] . '" stroke-width="3" paint-order="stroke">';
        foreach ($regions as $r) {
            if (!isset($geo['labels'][$r['key']])) continue;
            $s .= '<text x="' . $geo['labels'][$r['key']][0] . '" y="' . $geo['labels'][$r['key']][1] . '">' . $x((string) $r['name']) . '</text>';
        }
        // Quellenvermerk: derselbe Wortlaut wie unter der Karte (Links als Adresse – der Grafik-Editor und <img> kennen keine Links),
        // dazu Lizenz und Vermerk in <desc>; zweizeilig, damit er in die Breite passt
        $a = Bases::attribution($map['base'], $map['level']);
        $line1 = lt('Kartengrundlage') . ': ' . $a['text'] . ', ' . lt('vereinfacht');
        $line2 = lt('Datenquellen') . ': ' . $a['sources'] . ' · ' . lt('Lizenz') . ': ' . $a['url'];
        $s .= '</g><text id="quelle" x="' . ($geo['w'] - 12) . '" y="' . ($h - 24) . '" font-family="system-ui, sans-serif" font-size="11" text-anchor="end" fill="' . $kit['muted'] . '">'
            . '<tspan x="' . ($geo['w'] - 12) . '">' . $x($line1) . '</tspan><tspan x="' . ($geo['w'] - 12) . '" dy="14">' . $x($line2) . '</tspan></text></svg>';
        return $s;
    }
}
