<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Contrast;

/**
 * Beschriftungen der Gebiete (seit 0.3.0): je Gebiet an/aus, eigener Text (Kurzname, Zeilenumbruch), Lage per Ziehen bzw.
 * Tastatur, Hinweislinie für kleine Gebiete; je Karte Schriftgröße (S/M/L oder automatisch nach Fläche), Stil (automatisch
 * hell/dunkel nach Gebietsfarbe, Schrift des Kits, dunkel, hell, Etikett) und Großbuchstaben – je Gebiet abweichend.
 *
 * Gespeichert je Karte in gebietskarten_maps.labels (JSON, Schlüssel = Gebiet r{id} bzw. e{eintrag}) – so auch für Karten aus
 * einer Datentabelle („Kartenmetadaten“); den Text kann dort ein Feld der Einträge liefern (Bindung „label“).
 * Lage als Länge/Breite (unabhängig vom Ausschnitt), Ausgabe als reines SVG ohne style-Attribute (Klassen, x/y, tspan).
 *
 * Automatische Lage: „Pol der Unzugänglichkeit“ (polylabel – der Punkt im Inneren mit dem größten Abstand zum Rand) statt des
 * Schwerpunkts. Bestandskarten (vor 0.3.0, Option label_mode = legacy) behalten ihre bisherige Lage, bis jemand „Automatisch
 * neu setzen“ wählt.
 *
 * Telefon-Regel (nur label_mode = pole): Ist die Karte schmaler als 36rem, wird die Schrift im Kartenmaß 1,6-fach (S 22, M 29,
 * L 37 statt 14/18/23) – sonst wäre sie auf dem Telefon kaum 7 px groß. Passt die Beschriftung so nicht ganz in ihr Gebiet,
 * nimmt sie die nächstkleinere Größe (Klasse gk-lp-m/-s); passt auch S nicht (und bei Hinweislinien immer), entfällt sie dort
 * (Klasse gk-lh). Die Namen bleiben über die Links der Gebiete und die Liste zugänglich.
 */
final class Labels
{
    /** Schriftgröße in Karteneinheiten (Breite der Karte 1000) */
    public const SIZES = ['s' => 14, 'm' => 18, 'l' => 23];
    /** Schriftgröße bei schmaler Karte (Container unter 36rem) */
    public const PHONE = ['s' => 22, 'm' => 29, 'l' => 37];
    public const LINE = 1.15;
    public const MAX_LINES = 3;
    public const MAX_TEXT = 120;
    /** Schriftfarben der Stile „dunkel“ und „hell“ (CSS: --gk-lab-dark, --gk-lab-light) */
    public const DARK = '#16191d';
    public const LIGHT = '#ffffff';
    public const STYLES = ['auto', 'kit', 'dark', 'light', 'tag'];
    public const SIZE_KEYS = ['auto', 's', 'm', 'l'];
    /** Versatz des Textes zum Ankerpunkt bei neuer Hinweislinie */
    private const LEADER = [64.0, -44.0];

    /** @return array<string, string> */
    public static function styleOptions(bool $inherit = false): array
    {
        return ($inherit ? ['' => __('wie Karte')] : []) + [
            'auto' => __('Automatisch hell oder dunkel (nach Gebietsfarbe)'), 'kit' => __('Schrift des Kits mit Kontrastrand'),
            'dark' => __('Dunkel mit hellem Rand'), 'light' => __('Hell mit dunklem Rand'), 'tag' => __('Etikett mit Hintergrund')];
    }

    /** @return array<string, string> */
    public static function sizeOptions(bool $inherit = false): array
    {
        return ($inherit ? ['' => __('wie Karte')] : []) + ['auto' => __('Automatisch nach Fläche'), 's' => __('Klein (S)'), 'm' => __('Mittel (M)'), 'l' => __('Groß (L)')];
    }

    // ---------------------------------------------------------------- Einstellungen

    /**
     * Einstellungen eines Gebiets bereinigen – nur abweichende Werte bleiben:
     * show on|off, text (Zeilen), pos/anchor [Länge, Breite], leader true, size, style, caps on|off.
     */
    public static function clean(mixed $in): array
    {
        if (!is_array($in)) return [];
        $out = [];
        $show = (string) ($in['show'] ?? '');
        if ($show === 'on' || $show === 'off') $out['show'] = $show;
        $text = self::cleanText((string) ($in['text'] ?? ''));
        if ($text !== '') $out['text'] = $text;
        foreach (['pos', 'anchor'] as $k) {
            $p = $in[$k] ?? null;
            if (is_array($p) && count($p) === 2 && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)) {
                [$lon, $lat] = [(float) $p[0], (float) $p[1]];
                if ($lon >= -180 && $lon <= 180 && $lat >= -90 && $lat <= 90) $out[$k] = [round($lon, 5), round($lat, 5)];
            }
        }
        if (filter_var($in['leader'] ?? false, FILTER_VALIDATE_BOOL)) $out['leader'] = true;
        else unset($out['anchor']);
        $size = (string) ($in['size'] ?? '');
        if (in_array($size, self::SIZE_KEYS, true)) $out['size'] = $size;
        $style = (string) ($in['style'] ?? '');
        if (in_array($style, self::STYLES, true)) $out['style'] = $style;
        $caps = (string) ($in['caps'] ?? '');
        if ($caps === 'on' || $caps === 'off') $out['caps'] = $caps;
        return $out;
    }

    /**
     * Alle Gebiete einer Karte. $keys: erlaubte Schlüssel (Gebiete der Karte), null = alle der Form r12/e34.
     * @return array<string, array>
     */
    public static function cleanAll(mixed $in, ?array $keys = null): array
    {
        if (!is_array($in)) return [];
        $allow = $keys !== null ? array_flip(array_map('strval', $keys)) : null;
        $out = [];
        foreach ($in as $k => $v) {
            $k = (string) $k;
            if (!preg_match('~^[re]\d{1,10}$~', $k) || ($allow !== null && !isset($allow[$k]))) continue;
            $c = self::clean($v);
            if ($c) $out[$k] = $c;
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    /** Text: höchstens drei Zeilen, ohne Steuerzeichen, Zeilen getrimmt */
    public static function cleanText(string $t): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $t);
        $t = (string) preg_replace('~[^\P{C}\n]~u', '', $t);
        $lines = array_values(array_filter(array_map(fn($l) => trim((string) preg_replace('~\s+~u', ' ', $l)), explode("\n", $t)), fn($l) => $l !== ''));
        return mb_substr(implode("\n", array_slice($lines, 0, self::MAX_LINES)), 0, self::MAX_TEXT);
    }

    /** @return list<string> */
    public static function lines(string $text): array
    {
        $t = self::cleanText($text);
        return $t === '' ? [] : explode("\n", $t);
    }

    /** Stellt eine Bestandskarte (label_mode legacy) auf die neue automatische Lage um: Lage und Anker aller Gebiete entfallen */
    public static function resetPositions(array $labels): array
    {
        foreach ($labels as $k => $s) {
            unset($s['pos'], $s['anchor']);
            if ($s) $labels[$k] = $s; else unset($labels[$k]);
        }
        return $labels;
    }

    // ---------------------------------------------------------------- Geometrie

    /**
     * Pol der Unzugänglichkeit (polylabel, Mapbox): Punkt im Inneren mit dem größten Abstand zum Rand – über alle Ringe eines
     * Gebiets (gerade/ungerade, Löcher und Inseln eingeschlossen). Rückgabe [x, y, Abstand].
     * @param list<list<array{0: float, 1: float}>> $rings
     * @return array{0: float, 1: float, 2: float}
     */
    public static function pole(array $rings, ?float $precision = null): array
    {
        $segs = self::segments($rings);
        if (!$segs) return [0.0, 0.0, 0.0];
        [$minX, $minY, $maxX, $maxY] = self::extent($segs);
        $w = $maxX - $minX;
        $h = $maxY - $minY;
        $cell = min($w, $h);
        if ($cell <= 0) return [round($minX, 1), round($minY, 1), 0.0];
        $precision ??= max(0.5, $cell / 80);
        $mk = static function (float $x, float $y, float $half) use ($segs): array {
            $d = self::dist($x, $y, $segs);
            return [$x, $y, $half, $d, $d + $half * M_SQRT2];
        };
        $q = new \SplPriorityQueue();
        $q->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $half = $cell / 2;
        for ($x = $minX; $x < $maxX; $x += $cell) {
            for ($y = $minY; $y < $maxY; $y += $cell) {
                $c = $mk($x + $half, $y + $half, $half);
                $q->insert($c, $c[4]);
            }
        }
        // Start: Schwerpunkt des größten Rings bzw. Mitte des Rahmens
        $best = $mk($minX + $w / 2, $minY + $h / 2, 0);
        $big = null;
        foreach ($rings as $r) if ($big === null || abs(Topo::area($r)) > abs(Topo::area($big))) $big = $r;
        if ($big && ($c = self::centroid($big)) !== null) {
            $cc = $mk($c[0], $c[1], 0);
            if ($cc[3] > $best[3]) $best = $cc;
        }
        for ($n = 0; !$q->isEmpty() && $n < 6000; $n++) {
            $c = $q->extract();
            if ($c[3] > $best[3]) $best = $c;
            if ($c[4] - $best[3] <= $precision) continue;
            $hh = $c[2] / 2;
            foreach ([[-1, -1], [1, -1], [-1, 1], [1, 1]] as [$sx, $sy]) {
                $k = $mk($c[0] + $sx * $hh, $c[1] + $sy * $hh, $hh);
                $q->insert($k, $k[4]);
            }
        }
        return [round($best[0], 1), round($best[1], 1), round(max(0.0, $best[3]), 1)];
    }

    /** @return array{0: float, 1: float}|null */
    private static function centroid(array $r): ?array
    {
        $cx = $cy = $a = 0.0;
        for ($i = 0, $n = count($r) - 1; $i < $n; $i++) {
            $f = $r[$i][0] * $r[$i + 1][1] - $r[$i + 1][0] * $r[$i][1];
            $a += $f;
            $cx += ($r[$i][0] + $r[$i + 1][0]) * $f;
            $cy += ($r[$i][1] + $r[$i + 1][1]) * $f;
        }
        return $a != 0.0 ? [$cx / (3 * $a), $cy / (3 * $a)] : null;
    }

    /** Ringe → Strecken [x1, y1, x2, y2] @return list<array{0: float, 1: float, 2: float, 3: float}> */
    public static function segments(array $rings): array
    {
        $out = [];
        foreach ($rings as $r) {
            $n = count($r);
            if ($n < 3) continue;
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                if ($r[$i] === $r[$j]) continue;
                $out[] = [(float) $r[$j][0], (float) $r[$j][1], (float) $r[$i][0], (float) $r[$i][1]];
            }
        }
        return $out;
    }

    /** SVG-Pfad (Topo::path: M x yLx y…Z) → Ringe */
    public static function parse(string $d): array
    {
        $rings = [];
        foreach (explode('Z', $d) as $part) {
            $part = ltrim(trim($part), 'M');
            if ($part === '') continue;
            $ring = [];
            foreach (explode('L', $part) as $pt) {
                $xy = explode(' ', trim($pt));
                if (count($xy) === 2) $ring[] = [(float) $xy[0], (float) $xy[1]];
            }
            if (count($ring) >= 3) $rings[] = $ring;
        }
        return $rings;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function extent(array $segs): array
    {
        $b = [INF, INF, -INF, -INF];
        foreach ($segs as [$x1, $y1]) {
            if ($x1 < $b[0]) $b[0] = $x1;
            if ($y1 < $b[1]) $b[1] = $y1;
            if ($x1 > $b[2]) $b[2] = $x1;
            if ($y1 > $b[3]) $b[3] = $y1;
        }
        return $b;
    }

    /** Abstand zum Rand, positiv innen (gerade/ungerade über alle Ringe) */
    public static function dist(float $x, float $y, array $segs): float
    {
        $in = false;
        $min = INF;
        foreach ($segs as [$ax, $ay, $bx, $by]) {
            if ((($ay > $y) !== ($by > $y)) && ($x < ($bx - $ax) * ($y - $ay) / ($by - $ay) + $ax)) $in = !$in;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $px = $ax;
            $py = $ay;
            if ($dx != 0.0 || $dy != 0.0) {
                $t = (($x - $ax) * $dx + ($y - $ay) * $dy) / ($dx * $dx + $dy * $dy);
                if ($t > 1) { $px = $bx; $py = $by; }
                elseif ($t > 0) { $px += $dx * $t; $py += $dy * $t; }
            }
            $d = ($x - $px) ** 2 + ($y - $py) ** 2;
            if ($d < $min) $min = $d;
        }
        return ($in ? 1 : -1) * sqrt($min);
    }

    /** Liegt das Rechteck (Mitte cx/cy, Breite w, Höhe h) ganz im Gebiet? */
    public static function boxInside(array $segs, float $cx, float $cy, float $w, float $h): bool
    {
        if (!$segs || self::dist($cx, $cy, $segs) <= 0) return false;
        $r = [$cx - $w / 2, $cy - $h / 2, $cx + $w / 2, $cy + $h / 2];
        foreach ($segs as [$x1, $y1, $x2, $y2]) {
            if (max($x1, $x2) < $r[0] || min($x1, $x2) > $r[2] || max($y1, $y2) < $r[1] || min($y1, $y2) > $r[3]) continue;
            if (self::clips($x1, $y1, $x2, $y2, $r)) return false;
        }
        return true;
    }

    /** Schneidet die Strecke das Rechteck? (Liang-Barsky) */
    private static function clips(float $x1, float $y1, float $x2, float $y2, array $r): bool
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $t0 = 0.0;
        $t1 = 1.0;
        foreach ([[-$dx, $x1 - $r[0]], [$dx, $r[2] - $x1], [-$dy, $y1 - $r[1]], [$dy, $r[3] - $y1]] as [$p, $q]) {
            if ($p == 0.0) {
                if ($q < 0) return false;
                continue;
            }
            $t = $q / $p;
            if ($p < 0) {
                if ($t > $t1) return false;
                if ($t > $t0) $t0 = $t;
            } else {
                if ($t < $t0) return false;
                if ($t < $t1) $t1 = $t;
            }
        }
        return true;
    }

    /** Geschätzte Maße des Textes (ohne Messen im Browser – Website ohne Skript) @return array{0: float, 1: float} */
    public static function box(array $lines, float $fs, bool $caps): array
    {
        $chars = 0;
        foreach ($lines as $l) $chars = max($chars, mb_strlen($l));
        $w = $chars * $fs * ($caps ? 0.66 : 0.54) + 4;
        $h = (count($lines) - 1) * self::LINE * $fs + $fs * 0.95;
        return [round($w, 1), round($h, 1)];
    }

    // ---------------------------------------------------------------- Projektion (Länge/Breite ↔ Karte)

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $p [minLon, maxLat, kx, s, ox, pad] @return array{0: float, 1: float} */
    public static function toSvg(array $p, array $ll): array
    {
        return [round(($ll[0] - $p[0]) * $p[2] + $p[4], 1), round(($p[1] - $ll[1]) * $p[3] + $p[5], 1)];
    }

    /** @return array{0: float, 1: float} [Länge, Breite] */
    public static function toGeo(array $p, float $x, float $y): array
    {
        return [round(($x - $p[4]) / $p[2] + $p[0], 5), round($p[1] - ($y - $p[5]) / $p[3], 5)];
    }

    // ---------------------------------------------------------------- Lage, Größe, Farbe

    /** Darstellung der Karte (Standard je Gebiet) */
    public static function defaults(array $map): array
    {
        $o = $map['options'];
        return ['show' => (bool) $o['labels'], 'size' => (string) $o['label_size'], 'style' => (string) $o['label_style'],
            'caps' => (bool) $o['label_caps'], 'mode' => (string) $o['label_mode']];
    }

    /** Text eines Gebiets: eigener Text, Feld der Datentabelle (Bindung „label“), sonst Name */
    public static function text(array $region, array $s): string
    {
        $t = self::cleanText((string) ($s['text'] ?? ''));
        if ($t === '') $t = self::cleanText((string) ($region['label_field'] ?? ''));
        return $t !== '' ? $t : self::cleanText((string) $region['name']);
    }

    /**
     * Lage und Gestaltung aller Beschriftungen. $all: auch ausgeschaltete (Editor). $contrast: Kontrast hell/dunkel berechnen
     * (Editor; bei Stil „automatisch“ immer).
     * @param array<string, string> $effs  wirksame Farben (Colors::assign)
     * @return array<string, array>
     */
    public static function layout(array $map, array $regions, array $geo, array $effs, bool $all = false, bool $contrast = false): array
    {
        $def = self::defaults($map);
        $legacy = $def['mode'] !== 'pole';
        $proj = $geo['proj'] ?? null;
        $settings = (array) ($map['labels'] ?? []);
        $W = (float) $geo['w'];
        $H = (float) $geo['h'];
        $out = [];
        $kitL = $kitD = null;
        foreach ($regions as $r) {
            $k = (string) $r['key'];
            $d = (string) ($geo['regions'][$k] ?? '');
            if ($d === '') continue;
            $s = (array) ($settings[$k] ?? []);
            $visible = ($s['show'] ?? '') === 'on' || (($s['show'] ?? '') === '' && $def['show']);
            if (!$visible && !$all) continue;
            $auto = $legacy ? ($geo['labels'][$k] ?? null) : ($geo['poles'][$k] ?? null);
            if (!$auto) continue;
            $lines = self::lines(self::text($r, $s));
            if (!$lines) continue;
            $caps = isset($s['caps']) ? $s['caps'] === 'on' : $def['caps'];
            $leader = !empty($s['leader']);
            $segs = null;
            $anchor = null;
            if ($leader) {
                $anchor = isset($s['anchor']) && $proj ? self::toSvg($proj, $s['anchor']) : [(float) $auto[0], (float) $auto[1]];
            }
            $manual = isset($s['pos']) && $proj;
            if ($manual) $pos = self::toSvg($proj, $s['pos']);
            elseif ($leader) $pos = [$anchor[0] + self::LEADER[0], $anchor[1] + self::LEADER[1]];
            else $pos = [(float) $auto[0], (float) $auto[1]];
            $pos = [round(max(0.0, min($W, $pos[0])), 1), round(max(0.0, min($H, $pos[1])), 1)];

            $sizeKey = (string) ($s['size'] ?? $def['size']);
            $fits = true;
            $segs = $leader ? [] : self::segments(self::parse($d));
            if ($sizeKey === 'auto') {
                $sizeKey = $leader ? 'm' : 's';     // Text neben dem Gebiet: Mittel
                $fits = $leader;
                if (!$leader) {
                    foreach (['l', 'm', 's'] as $sk) {
                        [$bw, $bh] = self::box($lines, self::SIZES[$sk], $caps);
                        if (self::boxInside($segs, $pos[0], $pos[1], $bw, $bh)) { $sizeKey = $sk; $fits = true; break; }
                    }
                }
            } elseif (!$leader) {
                [$bw, $bh] = self::box($lines, self::SIZES[$sizeKey] ?? 18, $caps);
                $fits = self::boxInside($segs, $pos[0], $pos[1], $bw, $bh);
            }
            if (!isset(self::SIZES[$sizeKey])) $sizeKey = 'm';
            $fs = self::SIZES[$sizeKey];
            [$bw, $bh] = self::box($lines, $fs, $caps);
            // Telefon: gleiche oder nächstkleinere Größe in Telefon-Schrift, die ganz ins Gebiet passt – sonst ausblenden
            $phone = null;
            if (!$leader && !$legacy) {
                foreach (array_slice(['l', 'm', 's'], array_search($sizeKey, ['l', 'm', 's'], true) ?: 0) as $sk) {
                    [$pw, $ph] = self::box($lines, self::PHONE[$sk], $caps);
                    if (self::boxInside($segs, $pos[0], $pos[1], $pw, $ph)) { $phone = $sk; break; }
                }
            }
            $fitsPhone = $phone !== null;
            // Hinweislinie: vom Anker bis an den Rand des Textes
            $end = null;
            if ($leader && $anchor) {
                $dx = $anchor[0] - $pos[0];
                $dy = $anchor[1] - $pos[1];
                $t = min($dx != 0.0 ? ($bw / 2 + 3) / abs($dx) : INF, $dy != 0.0 ? ($bh / 2 + 3) / abs($dy) : INF);
                if ($t < 1) $end = [round($pos[0] + $dx * $t, 1), round($pos[1] + $dy * $t, 1)];
            }

            $style = (string) ($s['style'] ?? $def['style']);
            if (!in_array($style, self::STYLES, true)) $style = 'kit';
            $info = ['key' => $k, 'name' => (string) $r['name'], 'visible' => $visible, 'lines' => $lines, 'caps' => $caps, 'size' => $sizeKey, 'fs' => $fs,
                'x' => $pos[0], 'y' => $pos[1], 'w' => $bw, 'h' => $bh, 'manual' => $manual, 'leader' => $leader,
                'ax' => $anchor[0] ?? null, 'ay' => $anchor[1] ?? null, 'end' => $end, 'anchorManual' => $leader && isset($s['anchor']),
                'fits' => $fits, 'fitsPhone' => $fitsPhone, 'phone' => $phone, 'style' => $style, 'toneL' => $style, 'toneD' => $style, 'cL' => null, 'cD' => null];

            if ($style === 'auto' || $contrast) {
                $kitL ??= Preview::kit(false);
                $kitD ??= Preview::kit(true);
                $eff = (string) ($effs[$k] ?? '');
                // Text mit Hinweislinie steht neben dem Gebiet: Hintergrund der Seite
                $fillL = $leader ? (string) $kitL['bg'] : Colors::approx($eff, $kitL);
                $fillD = $leader ? (string) $kitD['bg'] : Colors::approxDark($eff, (string) ($r['color_dark'] ?? ''), $kitD);
                [$info['toneL'], $info['cL']] = self::tone($style, $fillL, $kitL);
                [$info['toneD'], $info['cD']] = self::tone($style, $fillD, $kitD);
            }
            $out[$k] = $info;
        }
        return $out;
    }

    /**
     * Schriftfarbe auf einer Fläche: [Ton, Kontrast]. Ton: d (dunkel), l (hell), kit, tag. „auto“ wählt den besseren Kontrast.
     * @return array{0: string, 1: float}
     */
    public static function tone(string $style, string $fill, array $kit): array
    {
        $fill = Colors::hex($fill) ?: '#cccccc';
        $ink = Colors::hex((string) ($kit['ink'] ?? '')) ?: '#1b1f24';
        $bg = Colors::hex((string) ($kit['bg'] ?? '')) ?: '#ffffff';
        return match ($style) {
            'tag' => ['tag', round(Contrast::ratio($bg, $ink), 2)],
            'kit' => ['kit', round(Contrast::ratio($ink, $fill), 2)],
            'dark' => ['d', round(Contrast::ratio(self::DARK, $fill), 2)],
            'light' => ['l', round(Contrast::ratio(self::LIGHT, $fill), 2)],
            default => (static function () use ($fill): array {
                $d = Contrast::ratio(self::DARK, $fill);
                $l = Contrast::ratio(self::LIGHT, $fill);
                return $l > $d ? ['l', round($l, 2)] : ['d', round($d, 2)];
            })(),
        };
    }

    /** Werden Dunkel-Regeln für die Töne gebraucht? (Stil „automatisch“ mit anderem Ton im Dunkeln) */
    public static function needsDarkTones(array $layout): bool
    {
        foreach ($layout as $l) if ($l['style'] === 'auto' && $l['toneD'] !== $l['toneL']) return true;
        return false;
    }

    // ---------------------------------------------------------------- Ausgabe

    /**
     * Beschriftungen als SVG (Website bzw. Editor). Website: <g class="gk-labels" aria-hidden="true"> – die Namen stehen an den
     * Links der Gebiete und in der Liste. Editor ($edit): jede Beschriftung fokussierbar und verschiebbar, Ankerpunkte eigene
     * Griffe. Bestandskarten ohne eigene Angaben ergeben dieselbe Ausgabe wie vor 0.3.0.
     */
    public static function svg(array $layout, string $id, bool $pole, ?string $active, bool $edit = false): string
    {
        if (!$layout) return '';
        $tag = false;
        foreach ($layout as $l) if ($l['toneL'] === 'tag') $tag = true;
        $s = $tag ? '<defs><filter id="' . $id . '-tag" x="-0.08" y="-0.16" width="1.16" height="1.32" color-interpolation-filters="sRGB">'
            . '<feFlood class="gk-tagbg" result="bg"/><feMerge><feMergeNode in="bg"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>' : '';
        $s .= $edit ? '<g class="gk-labels gk-labels--edit">' : '<g class="gk-labels" aria-hidden="true">';
        $anchors = '';
        foreach ($layout as $l) {
            $k = $l['key'];
            $hide = $pole && !$l['fitsPhone'] ? ' gk-lh' : '';
            $cls = [];
            if ($l['size'] !== 'm') $cls[] = 'gk-ls-' . $l['size'];
            if ($l['toneL'] === 'd' || $l['toneL'] === 'l') $cls[] = 'gk-tone-' . $l['toneL'];
            if ($l['toneL'] === 'tag') $cls[] = 'gk-tag';
            if ($l['style'] === 'auto' && $l['toneD'] !== $l['toneL']) $cls[] = 'gk-tdk-' . $l['toneD'];
            if ($l['caps']) $cls[] = 'gk-lc';
            if ($hide !== '') $cls[] = 'gk-lh';
            elseif ($pole && $l['phone'] !== null && $l['phone'] !== $l['size']) $cls[] = 'gk-lp-' . $l['phone'];
            if ($k === $active) $cls[] = 'is-active';
            $n = count($l['lines']);
            $text = '<text x="' . self::num($l['x']) . '" y="' . self::num($l['y']) . '"' . ($cls ? ' class="' . implode(' ', $cls) . '"' : '')
                . ($l['toneL'] === 'tag' ? ' filter="url(#' . $id . '-tag)"' : '') . '>';
            if ($n === 1) {
                $text .= e($l['lines'][0]);
            } else {
                foreach ($l['lines'] as $i => $line) {
                    $dy = $i === 0 ? -($n - 1) / 2 * self::LINE : self::LINE;
                    $text .= '<tspan x="' . self::num($l['x']) . '" dy="' . self::num(round($dy, 3), 3) . 'em">' . e($line) . '</tspan>';
                }
            }
            $text .= '</text>';
            $lead = '';
            if ($l['leader'] && $l['ax'] !== null) {
                if ($l['end']) $lead .= '<path class="gk-leader' . $hide . '" d="M' . self::num($l['ax']) . ' ' . self::num($l['ay']) . 'L' . self::num($l['end'][0]) . ' ' . self::num($l['end'][1]) . '"/>';
                $lead .= '<circle class="gk-anchor' . $hide . '" cx="' . self::num($l['ax']) . '" cy="' . self::num($l['ay']) . '" r="3.5"/>';
            }
            if (!$edit) {
                $s .= $lead . $text;
                continue;
            }
            $name = $l['name'];
            // Griff = Kasten um den Text (fokussierbar, Name per aria-label); Text und Linie gehören zur Gruppe
            $s .= '<g class="gk-lab' . ($l['visible'] ? '' : ' is-off') . ($l['fits'] ? '' : ' is-tight') . '" data-gk-lab="' . e($k) . '" data-x="' . self::num($l['x']) . '" data-y="' . self::num($l['y']) . '">'
                . $lead
                . '<rect class="gk-lab__box" x="' . self::num($l['x'] - $l['w'] / 2 - 4) . '" y="' . self::num($l['y'] - $l['h'] / 2 - 4) . '" width="' . self::num($l['w'] + 8) . '" height="' . self::num($l['h'] + 8) . '" rx="4"'
                . ' tabindex="0" role="button" aria-roledescription="' . e(__('verschiebbare Beschriftung')) . '" aria-label="' . e(self::ariaName($l)) . '" aria-describedby="' . $id . '-labhelp"/>'
                . $text . '</g>';
            if ($l['leader'] && $l['ax'] !== null) {
                $anchors .= '<circle class="gk-anchor-h" data-gk-anchor="' . e($k) . '" data-x="' . self::num($l['ax']) . '" data-y="' . self::num($l['ay']) . '" cx="' . self::num($l['ax']) . '" cy="' . self::num($l['ay']) . '" r="8"'
                    . ' tabindex="0" role="button" aria-roledescription="' . e(__('verschiebbarer Ankerpunkt')) . '" aria-label="' . e(__('Ankerpunkt der Hinweislinie „{name}“', ['name' => $name])) . '" aria-describedby="' . $id . '-labhelp"/>';
            }
        }
        $s .= '</g>';
        if ($anchors !== '') $s .= '<g class="gk-anchors">' . $anchors . '</g>';
        return $s;
    }

    /** Zugänglicher Name im Editor: sichtbarer Text zuerst (WCAG 2.5.3), dann Gebiet und Zustand */
    private static function ariaName(array $l): string
    {
        $text = implode(' ', $l['lines']);
        $name = $text === $l['name'] ? $text : $text . ' – ' . __('Beschriftung von „{name}“', ['name' => $l['name']]);
        return $name . ($l['visible'] ? '' : ' (' . __('ausgeblendet') . ')');
    }

    /** Beschriftungen für den SVG-Export (feste Farben, ohne Klassen, Etikett als Rechteck) */
    public static function export(array $layout, array $kit): string
    {
        $x = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $s = '<g id="namen" font-family="system-ui, sans-serif" font-weight="600" text-anchor="middle" paint-order="stroke">';
        foreach ($layout as $l) {
            [$fill, $stroke] = match ($l['toneL']) {
                'd' => [self::DARK, self::LIGHT],
                'l' => [self::LIGHT, self::DARK],
                'tag' => [$kit['bg'], 'none'],
                default => [$kit['ink'], $kit['bg']],
            };
            if ($l['leader'] && $l['ax'] !== null) {
                if ($l['end']) $s .= '<path d="M' . self::num($l['ax']) . ' ' . self::num($l['ay']) . 'L' . self::num($l['end'][0]) . ' ' . self::num($l['end'][1]) . '" fill="none" stroke="' . $kit['ink'] . '" stroke-width="1.2"/>';
                $s .= '<circle cx="' . self::num($l['ax']) . '" cy="' . self::num($l['ay']) . '" r="3.5" fill="' . $kit['ink'] . '" stroke="' . $kit['bg'] . '" stroke-width="1.5"/>';
            }
            if ($l['toneL'] === 'tag') {
                $s .= '<rect x="' . self::num($l['x'] - $l['w'] / 2 - 4) . '" y="' . self::num($l['y'] - $l['h'] / 2 - 3) . '" width="' . self::num($l['w'] + 8) . '" height="' . self::num($l['h'] + 6) . '" rx="3" fill="' . $kit['ink'] . '"/>';
            }
            $n = count($l['lines']);
            // Grundlinie wie auf der Website (dominant-baseline: central), ohne CSS: Versatz um ca. 0,35 em
            $s .= '<text x="' . self::num($l['x']) . '" y="' . self::num($l['y']) . '" font-size="' . $l['fs'] . '" fill="' . $fill . '"' . ($stroke !== 'none' ? ' stroke="' . $stroke . '" stroke-width="3"' : '')
                . ($l['caps'] ? ' letter-spacing="0.04em"' : '') . '>';
            foreach ($l['lines'] as $i => $line) {
                $dy = ($i === 0 ? -($n - 1) / 2 * self::LINE + 0.35 : self::LINE);
                $s .= '<tspan x="' . self::num($l['x']) . '" dy="' . self::num(round($dy, 3), 3) . 'em">' . $x($l['caps'] ? mb_strtoupper($line) : $line) . '</tspan>';
            }
            $s .= '</text>';
        }
        return $s . '</g>';
    }

    private static function num(float $v, int $dec = 1): string
    {
        $s = rtrim(rtrim(number_format($v, $dec, '.', ''), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    // ---------------------------------------------------------------- Datentabelle: Text in den Einträgen

    /** Feld für den Text der Beschriftung (Bindung „label“, Text oder Textfeld) – null = keins oder nicht schreibbar */
    public static function field(?array $table, array $map): ?array
    {
        $name = (string) ($map['binding']['label'] ?? '');
        if (!$table || $name === '') return null;
        $f = \Core\Data\Tables::field($table, $name);
        return $f && in_array($f['type'] ?? '', ['text', 'textarea'], true) ? $f : null;
    }

    /**
     * Warum lässt sich der Text dieses Eintrags nicht hier ändern? null = darf (Recht „Daten bearbeiten“, Regeln des Cores je
     * Eintrag wie bei der Zuordnung per Klick).
     */
    public static function textReason(?array $table, array $map, array $region): ?string
    {
        if (!$table || !self::field($table, $map)) return __('Das Feld für die Beschriftung lässt sich nur als Text oder Textfeld hier pflegen.');
        if (!can('data.edit', $table['handle'])) return __('Für die Tabelle „{table}“ fehlt Ihrer Rolle das Recht „Daten bearbeiten“.', ['table' => $table['name']]);
        $e = \Core\Data\Entries::find($table, (int) $region['id']);
        return $e ? EntryAssign::reason($table, $e) : __('Eintrag nicht gefunden.');
    }

    /**
     * Texte in die Einträge schreiben (Bindung „label“): nur geänderte, mit den Regeln des Cores (Version mit Notiz, Protokoll,
     * Status bleibt). Feld vom Typ Text: nur die erste Zeile. Leerer Text leert das Feld (dann gilt der Name).
     * @param array<string, string> $texts  Gebiet (e{id}) → Text
     * @return array{saved: int, errors: array<string, string>}
     */
    public static function saveEntryTexts(array $map, array $regions, array $texts): array
    {
        $t = Bound::table($map);
        $field = self::field($t, $map);
        $saved = 0;
        $errors = [];
        if (!$t || !$field) return ['saved' => 0, 'errors' => []];
        foreach ($regions as $r) {
            $k = (string) $r['key'];
            if (!array_key_exists($k, $texts)) continue;
            $want = self::cleanText((string) $texts[$k]);
            if ($field['type'] === 'text') $want = explode("\n", $want)[0];
            $now = self::cleanText((string) ($r['label_field'] ?? ''));
            if ($want === $now || ($want === self::cleanText((string) $r['name']) && $now === '')) continue;
            if (($why = self::textReason($t, $map, $r)) !== null) { $errors[$k] = $r['name'] . ': ' . $why; continue; }
            $e = (array) \Core\Data\Entries::find($t, (int) $r['id']);
            [$id, $errs] = \Core\Data\Entries::save($t, (int) $r['id'], [$field['name'] => $want, 'status' => $e['status'] ?? 'published'],
                __('Gebietskarte „{title}“: Beschriftung geändert', ['title' => $map['title']]));
            if ($errs || !$id) { $errors[$k] = $r['name'] . ': ' . implode(' ', array_map('strval', $errs)); continue; }
            $saved++;
        }
        return ['saved' => $saved, 'errors' => $errors];
    }
}
