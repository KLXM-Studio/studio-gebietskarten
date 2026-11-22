<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

/**
 * Geometrie ohne Kartenbibliothek: TopoJSON lesen (quantisiert, delta-kodiert), Flächen zu Gebieten verschmelzen,
 * projizieren, vereinfachen und als SVG-Pfad ausgeben.
 *
 * Verschmelzen über Bögen (wie topojson.merge): Jede Grenze zwischen zwei Einheiten ist genau ein Bogen. Kommt ein Bogen in
 * einem Gebiet zweimal vor, liegt er innen und fällt weg; die übrigen Bögen werden an ihren Endpunkten wieder zu Ringen
 * verkettet. So entstehen keine Lücken oder Überlappungen – auch nicht nach dem Vereinfachen, weil beide Nachbarn denselben
 * (vereinfachten) Bogen nutzen. Dateien einer Ebene, die aus einer Gesamt-Topologie aufgeteilt wurden (Gemeinden je Land),
 * tragen die Nummern der Bögen der Gesamt-Topologie in „arcIds“ – Gebiete über Landesgrenzen hinweg verschmelzen ebenso.
 *
 * Einheit: ['id', 'name', 'bez', 'l', 'k', 'x', 'y', 'polys' => list<list<list<int>>>] – Bogen-Verweise global, ~i = umgekehrt.
 */
final class Topo
{
    /** @var array<string, array{units: array<string, array>, arcs: array<int, list<array{0: float, 1: float}>>, meta: array}> */
    private static array $files = [];

    /** Eine Datei lesen (je Anfrage einmal). @return array{units: array<string, array>, arcs: array<int, list<array{0: float, 1: float}>>, meta: array} */
    public static function load(string $file): array
    {
        if (isset(self::$files[$file])) return self::$files[$file];
        $raw = is_file($file) ? (string) file_get_contents($file) : '';
        $t = json_decode($raw, true);
        if (!is_array($t) || ($t['type'] ?? '') !== 'Topology') throw new \RuntimeException('Keine TopoJSON-Datei: ' . basename($file));
        return self::$files[$file] = self::decode($t);
    }

    /** TopoJSON (Array) in Einheiten und absolute Bögen (Länge, Breite) umsetzen */
    public static function decode(array $t): array
    {
        $sx = (float) ($t['transform']['scale'][0] ?? 1);
        $sy = (float) ($t['transform']['scale'][1] ?? 1);
        $tx = (float) ($t['transform']['translate'][0] ?? 0);
        $ty = (float) ($t['transform']['translate'][1] ?? 0);
        $quant = isset($t['transform']);
        $ids = is_array($t['arcIds'] ?? null) ? array_map('intval', $t['arcIds']) : null;
        $arcs = [];
        foreach ((array) ($t['arcs'] ?? []) as $i => $arc) {
            $x = $y = 0;
            $pts = [];
            foreach ((array) $arc as $p) {
                if ($quant) {
                    $x += (int) $p[0];
                    $y += (int) $p[1];
                    $pts[] = [round($x * $sx + $tx, 6), round($y * $sy + $ty, 6)];
                } else {
                    $pts[] = [round((float) $p[0], 6), round((float) $p[1], 6)];
                }
            }
            $arcs[$ids[$i] ?? $i] = $pts;
        }
        $g = static fn(int $r): int => $ids === null ? $r : ($r < 0 ? ~$ids[~$r] : $ids[$r]);
        $units = [];
        $layer = (array) (reset($t['objects']) ?: []);
        foreach ((array) ($layer['geometries'] ?? []) as $geo) {
            $p = (array) ($geo['properties'] ?? []);
            $id = (string) ($p['id'] ?? $geo['id'] ?? '');
            if ($id === '') continue;
            $polys = match ($geo['type'] ?? '') {
                'Polygon' => [(array) $geo['arcs']],
                'MultiPolygon' => (array) $geo['arcs'],
                default => [],
            };
            $polys = array_map(fn($poly) => array_map(fn($ring) => array_map(fn($r) => $g((int) $r), (array) $ring), (array) $poly), $polys);
            $units[$id] = ['id' => $id, 'name' => (string) ($p['name'] ?? $id), 'bez' => (string) ($p['bez'] ?? ''),
                'l' => (string) ($p['l'] ?? ''), 'k' => (string) ($p['k'] ?? ''), 'x' => (float) ($p['x'] ?? 0), 'y' => (float) ($p['y'] ?? 0),
                'polys' => $polys];
        }
        return ['units' => $units, 'arcs' => $arcs, 'meta' => (array) ($t['meta'] ?? [])];
    }

    /**
     * Mehrere Dateien einer Ebene zusammenführen (Gemeinden mehrerer Länder). $keep: fn(array $unit): bool
     * @param list<string> $files
     * @return array{units: array<string, array>, arcs: array<int, list<array{0: float, 1: float}>>, meta: array}
     */
    public static function collect(array $files, ?callable $keep = null): array
    {
        $units = $arcs = [];
        $meta = [];
        foreach ($files as $f) {
            $d = self::load($f);
            $meta = $meta ?: $d['meta'];
            foreach ($d['units'] as $id => $u) {
                if ($keep && !$keep($u)) continue;
                $units[$id] = $u;
            }
            $arcs += $d['arcs'];
        }
        uasort($units, fn($a, $b) => strcoll($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
        return ['units' => $units, 'arcs' => $arcs, 'meta' => $meta];
    }

    /** Bogen-Nummern (ohne Richtung) der Einheiten mit Anzahl. @param iterable<array> $units @return array<int, int> */
    public static function arcCount(iterable $units): array
    {
        $n = [];
        foreach ($units as $u) {
            foreach ($u['polys'] as $poly) foreach ($poly as $ring) foreach ($ring as $r) {
                $a = $r < 0 ? ~$r : $r;
                $n[$a] = ($n[$a] ?? 0) + 1;
            }
        }
        return $n;
    }

    /**
     * Einheiten verschmelzen: Ringe aus den Bögen, die nur einmal vorkommen. $arcs = projizierte (und vereinfachte) Bögen.
     * @param iterable<array> $units
     * @param array<int, list<array{0: float, 1: float}>> $arcs
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function merge(iterable $units, array $arcs): array
    {
        $refs = [];
        $count = [];
        foreach ($units as $u) {
            foreach ($u['polys'] as $poly) foreach ($poly as $ring) foreach ($ring as $r) {
                $a = $r < 0 ? ~$r : $r;
                $count[$a] = ($count[$a] ?? 0) + 1;
                $refs[$a] = $r;
            }
        }
        $parts = [];
        foreach ($count as $a => $n) {
            if ($n !== 1 || !isset($arcs[$a])) continue;
            $pts = $arcs[$a];
            $parts[] = $refs[$a] < 0 ? array_reverse($pts) : $pts;
        }
        return self::stitch($parts);
    }

    /**
     * Gerichtete Linienstücke an gleichen Endpunkten zu geschlossenen Ringen verketten.
     * @param list<list<array{0: float, 1: float}>> $parts
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function stitch(array $parts): array
    {
        $key = static fn(array $p): string => $p[0] . ',' . $p[1];
        $byStart = [];
        foreach ($parts as $i => $pts) {
            if (count($pts) < 2) continue;
            $byStart[$key($pts[0])][] = $i;
        }
        $used = [];
        $rings = [];
        foreach ($parts as $i => $pts) {
            if (isset($used[$i]) || count($pts) < 2) continue;
            $used[$i] = true;
            $ring = $pts;
            $start = $key($pts[0]);
            $end = $key($pts[count($pts) - 1]);
            for ($guard = 0; $end !== $start && $guard < 100000; $guard++) {
                $next = null;
                foreach ($byStart[$end] ?? [] as $j) {
                    if (!isset($used[$j])) { $next = $j; break; }
                }
                if ($next === null) break;   // offenes Stück (sollte bei sauberer Topologie nicht vorkommen)
                $used[$next] = true;
                array_pop($ring);
                array_push($ring, ...$parts[$next]);
                $end = $key($ring[count($ring) - 1]);
            }
            if (count($ring) >= 4) $rings[] = $ring;
        }
        return $rings;
    }

    /**
     * Innere Grenzen: Bögen, die zwei der Einheiten gemeinsam haben (Linien zwischen Einheiten). $only: nur Bögen, für die
     * fn(int $arc): bool gilt (z. B. Grenzen zwischen Einheiten verschiedener Gebiete).
     * @param iterable<array> $units
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function mesh(iterable $units, array $arcs, ?callable $only = null): array
    {
        $out = [];
        foreach (self::arcCount($units) as $a => $n) {
            if ($n < 2 || !isset($arcs[$a]) || ($only && !$only($a))) continue;
            $out[] = $arcs[$a];
        }
        return $out;
    }

    /** Ausdehnung (Länge/Breite) der Bögen der Einheiten. @return array{0: float, 1: float, 2: float, 3: float}|null minLon, minLat, maxLon, maxLat */
    public static function bounds(iterable $units, array $arcs): ?array
    {
        $b = null;
        foreach (self::arcCount($units) as $a => $_) {
            foreach ($arcs[$a] ?? [] as [$x, $y]) {
                if ($b === null) { $b = [$x, $y, $x, $y]; continue; }
                if ($x < $b[0]) $b[0] = $x;
                if ($y < $b[1]) $b[1] = $y;
                if ($x > $b[2]) $b[2] = $x;
                if ($y > $b[3]) $b[3] = $y;
            }
        }
        return $b;
    }

    /**
     * Projektion für den Ausschnitt: abstandstreue Zylinderprojektion mit cos(mittlere Breite) – für Länder- und
     * Regionalkarten genau genug und ohne Bibliothek. Liefert fn(lon, lat): [x, y] und die Maße des Bildes.
     * @param array{0: float, 1: float, 2: float, 3: float} $b
     * @return array{fn: \Closure(float, float): array{0: float, 1: float}, width: int, height: int, params: array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}}
     */
    public static function projection(array $b, int $width = 1000, int $pad = 16): array
    {
        [$minX, $minY, $maxX, $maxY] = $b;
        $k = cos(deg2rad(($minY + $maxY) / 2));
        $w = max(1e-9, ($maxX - $minX) * $k);
        $h = max(1e-9, $maxY - $minY);
        $s = ($width - 2 * $pad) / $w;
        $height = (int) ceil($h * $s + 2 * $pad);
        // Sehr hohe Ausschnitte (z. B. ein schmales Land) nicht über das 1,4-Fache der Breite wachsen lassen
        if ($height > $width * 1.4) {
            $s = ($width * 1.4 - 2 * $pad) / $h;
            $height = (int) ceil($width * 1.4);
        }
        $ox = ($width - $w * $s) / 2;
        $fn = static fn(float $x, float $y): array => [round(($x - $minX) * $k * $s + $ox, 1), round(($maxY - $y) * $s + $pad, 1)];
        // Kennwerte zum Umrechnen in beide Richtungen (Labels::toSvg/toGeo, Editor): [minLon, maxLat, kx, s, ox, pad]
        return ['fn' => $fn, 'width' => $width, 'height' => $height, 'params' => [$minX, $maxY, $k * $s, $s, $ox, (float) $pad]];
    }

    /**
     * Bögen projizieren und vereinfachen (Douglas-Peucker, Endpunkte bleiben – Nachbarn behalten dieselbe Grenze).
     * @param array<int, list<array{0: float, 1: float}>> $arcs
     * @param array<int, int> $need  Bogen-Nummern, die gebraucht werden
     * @return array<int, list<array{0: float, 1: float}>>
     */
    public static function project(array $arcs, array $need, callable $proj, float $tolerance = 0.6): array
    {
        $out = [];
        foreach ($need as $a => $_) {
            if (!isset($arcs[$a])) continue;
            $pts = [];
            $last = null;
            foreach ($arcs[$a] as [$x, $y]) {
                $p = $proj($x, $y);
                if ($p !== $last) $pts[] = $last = $p;
            }
            if (count($pts) === 1) $pts[] = $pts[0];
            $out[$a] = $tolerance > 0 && count($pts) > 2 ? self::simplify($pts, $tolerance) : $pts;
        }
        return $out;
    }

    /** Douglas-Peucker (iterativ). Geschlossene Bögen (Inseln) behalten mindestens drei Stützpunkte. @param list<array{0: float, 1: float}> $pts */
    public static function simplify(array $pts, float $tol): array
    {
        $n = count($pts);
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $closed = $pts[0] === $pts[$n - 1];
        if ($closed && $n > 3) {
            // Ring: den am weitesten entfernten Punkt als zweiten Anker nehmen
            $far = 1; $best = -1.0;
            for ($i = 1; $i < $n - 1; $i++) {
                $d = ($pts[$i][0] - $pts[0][0]) ** 2 + ($pts[$i][1] - $pts[0][1]) ** 2;
                if ($d > $best) { $best = $d; $far = $i; }
            }
            $keep[$far] = true;
            $stack = [[0, $far], [$far, $n - 1]];
        } else {
            $stack = [[0, $n - 1]];
        }
        $t2 = $tol * $tol;
        while ($stack) {
            [$a, $b] = array_pop($stack);
            if ($b - $a < 2) continue;
            [$ax, $ay] = $pts[$a];
            [$bx, $by] = $pts[$b];
            $dx = $bx - $ax; $dy = $by - $ay;
            $len = $dx * $dx + $dy * $dy;
            $max = -1.0; $idx = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                [$px, $py] = $pts[$i];
                if ($len == 0.0) {
                    $d = ($px - $ax) ** 2 + ($py - $ay) ** 2;
                } else {
                    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $len;
                    $t = max(0.0, min(1.0, $t));
                    $d = ($px - $ax - $t * $dx) ** 2 + ($py - $ay - $t * $dy) ** 2;
                }
                if ($d > $max) { $max = $d; $idx = $i; }
            }
            if ($max > $t2) {
                $keep[$idx] = true;
                $stack[] = [$a, $idx];
                $stack[] = [$idx, $b];
            }
        }
        $out = [];
        foreach ($pts as $i => $p) if ($keep[$i]) $out[] = $p;
        if ($closed && count($out) < 4) {
            // Kleine Insel: nicht zu einer Linie zusammenfallen lassen
            $step = max(1, intdiv($n - 1, 3));
            $out = [$pts[0], $pts[min($n - 2, $step)], $pts[min($n - 2, 2 * $step)], $pts[$n - 1]];
        }
        return $out;
    }

    /** Ringe als SVG-Pfad (M … Z). @param list<list<array{0: float, 1: float}>> $rings */
    public static function path(array $rings): string
    {
        $d = '';
        foreach ($rings as $ring) {
            $pts = [];
            $last = null;
            foreach ($ring as $p) {
                if ($p === $last) continue;
                $pts[] = self::num($p[0]) . ' ' . self::num($p[1]);
                $last = $p;
            }
            if (count($pts) > 1 && $pts[0] === $pts[count($pts) - 1]) array_pop($pts);
            if (count($pts) >= 3) $d .= 'M' . implode('L', $pts) . 'Z';
        }
        return $d;
    }

    /** Linienzüge als SVG-Pfad (ohne Schließen). @param list<list<array{0: float, 1: float}>> $lines */
    public static function lines(array $lines): string
    {
        $d = '';
        foreach ($lines as $l) {
            if (count($l) < 2) continue;
            $d .= 'M' . implode('L', array_map(fn($p) => self::num($p[0]) . ' ' . self::num($p[1]), $l));
        }
        return $d;
    }

    /** Fläche eines Rings (Vorzeichen = Umlaufsinn) */
    public static function area(array $ring): float
    {
        $a = 0.0;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $a += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
        }
        return $a / 2;
    }

    private static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** Zwischenspeicher der Anfrage leeren (Selbsttest, nach dem Nachladen von Kartendaten) */
    public static function flush(): void
    {
        self::$files = [];
    }
}
