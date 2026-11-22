<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Contrast;

/**
 * Farben der Gebiete – ohne style-Attribute auf der Website (CSP):
 *  - Design-Farben des Kits (Kit-Vertrag --kit-*): Klasse gk-c-{name}, folgen hell/dunkel automatisch.
 *  - Palette (Okabe-Ito, auch bei Farbsehschwäche unterscheidbar, seit 0.2.1 um Indigo, Weinrot, Oliv und Grasgrün erweitert):
 *    Klasse gk-c-p1 … gk-c-p12, hell mit dem Hintergrund des Kits gemischt (color-mix, Anteil MIX). Im Dunkeln setzt die
 *    erzeugte Stylesheet-Datei (sheet()) je Palettenfarbe einen Wert, auf dem die Beschriftung mindestens 4,5:1 erreicht.
 *  - Automatisch (assign()): Palette ohne Grau (sähe aus wie „nicht zugeordnet“); Nachbarn (gemeinsame Grenze der
 *    verschmolzenen Gebiete) bekommen verschiedene Farben, wiederholt wird erst, wenn alle Farben vergeben sind.
 *  - eigene Farbe #RRGGBB: hell als SVG-Attribut fill (Darstellungsattribut, kein style), dunkel über eine erzeugte
 *    Stylesheet-Datei (sheet()) mit der Dunkel-Regel des Kits. Dunkel-Wert: eigenes Feld „im Dunkeln“ oder automatisch
 *    abgeleitet (dark()) – mit dem Dunkel-Hintergrund gemischt und so weit abgedunkelt, dass die Beschriftung in der Karte
 *    (Schriftfarbe des Kits im Dunkeln) mindestens 4,5:1 erreicht.
 * Farben in CSS: assets/css/gebietskarten.css (Variablen --gk-*, alle vom Kit überschreibbar).
 */
final class Colors
{
    /** Mindestkontrast der Beschriftung (Namen in der Karte) zur Fläche */
    public const MIN_LABEL = 4.5;

    /** In dieser Anfrage bereits eingebundene Dateien der Dunkel-Regeln */
    private static array $sheets = [];

    public static function reset(): void
    {
        self::$sheets = [];
    }

    /** @return array<string, string> Schlüssel → Beschriftung */
    public static function tokens(): array
    {
        return ['accent' => __('Akzent (Design)'), 'accent-soft' => __('Akzent hell (Design)'), 'accent-deep' => __('Akzent kräftig (Design)'),
            'surface' => __('Fläche (Design)'), 'muted' => __('Gedämpft (Design)')];
    }

    public const PALETTE = ['p1' => '#E69F00', 'p2' => '#56B4E9', 'p3' => '#009E73', 'p4' => '#F0E442',
        'p5' => '#0072B2', 'p6' => '#D55E00', 'p7' => '#CC79A7', 'p8' => '#999999',
        'p9' => '#332288', 'p10' => '#882255', 'p11' => '#999933', 'p12' => '#66AA33'];

    /** Anteil der Palettenfarbe beim Mischen mit dem hellen Hintergrund (Prozent, sonst 78) – dunkle Töne heller, damit die Beschriftung lesbar bleibt */
    public const MIX = ['p5' => 68, 'p9' => 50, 'p10' => 55];

    /** Reihenfolge der automatischen Farben – ohne Grau (p8 bleibt von Hand wählbar) */
    public const AUTO = ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p9', 'p10', 'p11', 'p12'];

    /** @return array<string, string> */
    public static function paletteLabels(): array
    {
        return ['p1' => __('Orange'), 'p2' => __('Himmelblau'), 'p3' => __('Blaugrün'), 'p4' => __('Gelb'),
            'p5' => __('Blau'), 'p6' => __('Zinnoberrot'), 'p7' => __('Purpur'), 'p8' => __('Grau'),
            'p9' => __('Indigo'), 'p10' => __('Weinrot'), 'p11' => __('Oliv'), 'p12' => __('Grasgrün')];
    }

    public static function mixOf(string $key): int
    {
        return self::MIX[$key] ?? 78;
    }

    /** Auswahl für Formulare: '' = automatisch (Palette der Reihe nach) */
    public static function options(): array
    {
        $o = ['' => __('Automatisch (Palette)')];
        foreach (self::tokens() as $k => $l) $o[$k] = $l;
        foreach (self::paletteLabels() as $k => $l) $o[$k] = __('Palette: {name}', ['name' => $l]);
        $o['own'] = __('Eigene Farbe …');
        return $o;
    }

    /** Gültiger Wert: Token, Palette, #rrggbb (klein) oder '' */
    public static function clean(string $c): string
    {
        $c = strtolower(trim($c));
        if ($c === '' || isset(self::tokens()[$c]) || isset(self::PALETTE[$c])) return $c;
        return self::hex($c);
    }

    /** #rgb/#rrggbb → #rrggbb (klein), sonst '' */
    public static function hex(string $c): string
    {
        $c = strtolower(trim($c));
        if (preg_match('~^#([0-9a-f]{3})$~', $c, $m)) return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        return preg_match('~^#[0-9a-f]{6}$~', $c) ? $c : '';
    }

    /** Wirksame Farbe eines Gebiets ohne Blick auf Nachbarn: '' → automatische Palette nach Position (ohne Grau). Karten: assign() */
    public static function effective(string $c, int $index): string
    {
        $c = self::clean($c);
        return $c !== '' ? $c : self::AUTO[$index % count(self::AUTO)];
    }

    /**
     * Wirksame Farben aller Gebiete einer Karte. Feste Farben bleiben; „Automatisch“ bekommt der Reihe nach eine Palettenfarbe
     * (ohne Grau) – zuerst die der Position, außer ein Nachbar (gemeinsame Grenze, Render::geometry()['neighbors']) hat sie
     * schon. Sind alle Farben vergeben, wiederholt sich eine Farbe nur bei Gebieten ohne gemeinsame Grenze (Graphfärbung,
     * Farben möglichst gleich oft). Ohne Konflikt gilt wie bisher: Position 1 → p1, 2 → p2, …
     * @param list<array> $regions  Gebiete mit 'key' und 'color'
     * @param array<string, list<string>> $neighbors  Schlüssel → Schlüssel der Nachbarn
     * @return array<string, string> Schlüssel → wirksame Farbe
     */
    public static function assign(array $regions, array $neighbors = []): array
    {
        $out = [];
        $count = [];
        foreach ($regions as $r) {
            $c = self::clean((string) ($r['color'] ?? ''));
            if ($c === '') continue;
            $out[(string) $r['key']] = $c;
            $count[$c] = ($count[$c] ?? 0) + 1;
        }
        $n = count(self::AUTO);
        foreach (array_values($regions) as $i => $r) {
            $key = (string) $r['key'];
            if (isset($out[$key])) continue;
            $taken = [];
            foreach ($neighbors[$key] ?? [] as $k) if (isset($out[$k])) $taken[$out[$k]] = true;
            $best = self::AUTO[$i % $n];
            $score = null;
            for ($j = 0; $j < $n; $j++) {
                $c = self::AUTO[($i + $j) % $n];
                // zuerst: kein Nachbar hat die Farbe; dann die seltenste Farbe; dann die Nähe zur Position
                $s = [isset($taken[$c]) ? 1 : 0, $count[$c] ?? 0, $j];
                if ($score === null || $s < $score) { $best = $c; $score = $s; }
            }
            $out[$key] = $best;
            $count[$best] = ($count[$best] ?? 0) + 1;
        }
        $ordered = [];
        foreach ($regions as $r) $ordered[(string) $r['key']] = $out[(string) $r['key']];
        return $ordered;
    }

    /** Dunkel-Wert einer Palettenfarbe (Beschriftung ≥ MIN_LABEL), '' bei anderen Farben. $kit: Preview::kit(true) */
    public static function paletteDark(string $key, array $kit): string
    {
        return isset(self::PALETTE[$key]) ? self::dark(self::PALETTE[$key], $kit) : '';
    }

    /** Ungefähre Farbe im Dunkeln (Kontrast-Anzeige der Verwaltung): Palette und eigene Farben wie auf der Website */
    public static function approxDark(string $effective, string $own, array $kitDark): string
    {
        if (isset(self::PALETTE[$effective])) return self::paletteDark($effective, $kitDark);
        if ($effective !== '' && $effective[0] === '#') return self::darkOf($effective, $own, $kitDark);
        return self::approx($effective, $kitDark);
    }

    /**
     * Dunkel-Wert einer eigenen Farbe: wie die Palette mit dem Dunkel-Hintergrund gemischt (78 %), dann – falls nötig – Richtung
     * Hintergrund abgedunkelt, bis die Schriftfarbe im Dunkeln darauf MIN_LABEL erreicht. $kit: Preview::kit(true).
     */
    public static function dark(string $light, array $kit): string
    {
        $light = self::hex($light);
        if ($light === '') return '';
        $bg = self::hex((string) ($kit['bg'] ?? '')) ?: '#16191d';
        $ink = self::hex((string) ($kit['ink'] ?? '')) ?: '#eef1f4';
        $c = Contrast::mix($light, $bg, 78);
        return strtolower(\Core\Design::readable($c, strtoupper($ink), self::MIN_LABEL, strtoupper($bg)));
    }

    /** Dunkel-Wert eines Gebiets mit eigener Farbe: eigenes Feld oder automatisch. '' bei Design- und Palettenfarben. */
    public static function darkOf(string $effective, string $own, array $kitDark): string
    {
        if ($effective === '' || $effective[0] !== '#') return '';
        return self::hex($own) ?: self::dark($effective, $kitDark);
    }

    /**
     * CSS-Klasse und Attribute für eine wirksame Farbe. Eigene Farbe: Klasse gk-c-own + gk-x-{hell}-{dunkel} (Dunkel-Regel in
     * sheet()), fill = heller Wert. Palette: Klasse gk-c-pN, Dunkel-Wert für die Datei bzw. die Vorschau der Verwaltung.
     * @return array{class: string, fill: ?string, dark: ?string}
     */
    public static function paint(string $effective, string $dark = ''): array
    {
        $d = self::hex($dark);
        if ($effective !== '' && $effective[0] === '#') {
            return ['class' => 'gk-c-own' . ($d !== '' && $d !== $effective ? ' ' . self::pairClass($effective, $d) : ''), 'fill' => $effective, 'dark' => $d !== '' ? $d : null];
        }
        return ['class' => 'gk-c-' . $effective, 'fill' => null, 'dark' => isset(self::PALETTE[$effective]) && $d !== '' ? $d : null];
    }

    public static function pairClass(string $light, string $dark): string
    {
        return 'gk-x-' . substr($light, 1) . '-' . substr($dark, 1);
    }

    /** Ungefähre Farbe für Vorschau-Zwecke (Verwaltung, Export als Grafik) */
    public static function approx(string $effective, array $kit = []): string
    {
        if ($effective !== '' && $effective[0] === '#') return $effective;
        if (isset(self::PALETTE[$effective])) return strtolower(Contrast::mix(self::PALETTE[$effective], $kit['bg'] ?? '#ffffff', self::mixOf($effective)));
        $accent = $kit['accent'] ?? '#2f6f8f';
        $bg = $kit['bg'] ?? '#ffffff';
        return strtolower(match ($effective) {
            'accent' => $accent,
            'accent-soft' => Contrast::mix($accent, $bg, 45),
            'accent-deep' => Contrast::mix($accent, $kit['ink'] ?? '#1a1a1a', 70),
            'surface' => $kit['surface'] ?? '#eef1f4',
            'muted' => Contrast::mix($kit['muted'] ?? '#6b7280', $bg, 45),
            default => '#cccccc',
        });
    }

    /** Kontrast der Beschriftung (Schriftfarbe des Kits) auf einer Fläche */
    public static function labelContrast(string $fill, array $kit): float
    {
        $fill = self::hex($fill);
        $ink = self::hex((string) ($kit['ink'] ?? ''));
        return $fill !== '' && $ink !== '' ? Contrast::ratio($fill, $ink) : 21.0;
    }

    // ---------------------------------------------------------------- Dunkel-Regeln als Datei (CSP: style-src 'self')

    /**
     * Selektoren, unter denen das Kit seine Dunkel-Werte setzt – wie Core\Design::css(): 'dark' => ['media', 'scope', 'force',
     * 'manual']. Ohne Dunkel-Angabe des Kits: null (eigene Farben bleiben hell).
     * @return array{media: string, scope: string, extra: list<string>}|null
     */
    public static function darkRule(): ?array
    {
        try {
            $d = (array) (\Core\Design::def()['dark'] ?? []);
        } catch (\Throwable) {
            return null;
        }
        $scope = trim((string) ($d['scope'] ?? ''));
        if ($scope === '' || !preg_match('~^[a-z0-9_.:#\[\]="\'() -]+$~i', $scope)) return null;
        $m = (array) ($d['manual'] ?? []);
        $light = (string) preg_replace('~[^a-z0-9_-]~i', '', (string) ($m['light'] ?? ''));
        $forced = (string) preg_replace('~[^a-z0-9_-]~i', '', (string) ($m['dark'] ?? ''));
        $extra = [];
        if ($forced !== '') $extra[] = $scope . '.' . $forced;
        // 'force' (Vorschau des Style-Editors): Klasse bzw. attr=wert am <html>
        $f = trim((string) ($d['force'] ?? ''));
        if (preg_match('~^([a-z0-9_-]+)=([a-z0-9_-]+)$~i', $f, $fm)) $extra[] = 'html[' . $fm[1] . '="' . $fm[2] . '"]';
        elseif (preg_match('~^[a-z0-9_-]+$~i', $f)) $extra[] = 'html.' . $f;
        $media = trim((string) ($d['media'] ?? ''));
        return ['media' => preg_match('~^[a-z0-9() :,-]*$~i', $media) ? $media : '', 'scope' => $scope . ($light !== '' ? ':not(.' . $light . ')' : ''), 'extra' => $extra];
    }

    /**
     * CSS der Dunkel-Regeln für Farbpaare und Palettenfarben
     * @param list<array{0: string, 1: string}> $pairs [hell, dunkel]
     * @param array{media: string, scope: string, extra: list<string>}|null $rule
     * @param array<string, string> $palette  Palettenfarbe (p1 …) → Dunkel-Wert
     */
    public static function sheetCss(array $pairs, ?array $rule, array $palette = [], bool $labelTones = false): string
    {
        if ((!$pairs && !$palette && !$labelTones) || !$rule) return '';
        $body = static function (string $pre) use ($pairs, $palette, $labelTones): string {
            $out = [];
            foreach ($pairs as [$l, $d]) {
                $c = '.' . self::pairClass($l, $d);
                $out[] = $pre . ' ' . $c . ' path,' . $pre . ' ' . $c . ' rect{fill:' . $d . '}';
            }
            foreach ($palette as $k => $d) $out[] = $pre . ' .gk-c-' . $k . '{--gk-c:' . $d . '}';
            // Beschriftung „automatisch hell/dunkel“: im Dunkeln anderer Ton als hell (Labels::tone mit den Dunkel-Farben)
            if ($labelTones) {
                $out[] = $pre . ' .gk-labels .gk-tdk-d{fill:var(--gk-lab-dark);stroke:var(--gk-lab-light);stroke-width:4px}';
                $out[] = $pre . ' .gk-labels .gk-tdk-l{fill:var(--gk-lab-light);stroke:var(--gk-lab-dark);stroke-width:3px}';
            }
            return implode("\n", $out);
        };
        $css = $rule['media'] !== '' ? '@media ' . $rule['media'] . "{\n" . $body($rule['scope']) . "\n}\n" : $body($rule['scope']) . "\n";
        foreach ($rule['extra'] as $sel) $css .= $body($sel) . "\n";
        return $css;
    }

    /**
     * <link> auf die Dunkel-Regeln der eigenen Farben und Palettenfarben einer Karte. Die Datei liegt im Medienordner der
     * Website (gebietskarten/farben-{prüfsumme}.css, wie die Design-Datei des Cores) – keine Inline-Stile auf der Website.
     * @param list<array{0: string, 1: string}> $pairs [hell, dunkel]
     * @param array<string, string> $palette  Palettenfarbe → Dunkel-Wert
     * @param bool $labelTones  Regeln für Beschriftungen mit anderem Ton im Dunkeln (Labels::needsDarkTones)
     */
    public static function sheet(array $pairs, array $palette = [], bool $labelTones = false): string
    {
        $clean = [];
        foreach ($pairs as [$l, $d]) {
            [$l, $d] = [self::hex((string) $l), self::hex((string) $d)];
            if ($l !== '' && $d !== '' && $l !== $d) $clean[$l . $d] = [$l, $d];
        }
        $pal = [];
        foreach ($palette as $k => $d) {
            $d = self::hex((string) $d);
            if (isset(self::PALETTE[$k]) && $d !== '') $pal[(string) $k] = $d;
        }
        if (!$clean && !$pal && !$labelTones) return '';
        ksort($clean);
        ksort($pal, SORT_NATURAL);
        $pairs = array_values($clean);
        $css = self::sheetCss($pairs, self::darkRule(), $pal, $labelTones);
        if ($css === '') return '';
        $name = 'farben-' . substr(sha1($css), 0, 12) . '.css';
        $dir = site()->mediaDir('gebietskarten');
        if (!is_file("$dir/$name")) {
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            // Ältere Fassungen erst nach einer Woche löschen: zwischengespeicherte Seiten verweisen noch darauf
            foreach (glob("$dir/farben-*.css") ?: [] as $old) {
                if (filemtime($old) < time() - 7 * 86400) @unlink($old);
            }
            @file_put_contents("$dir/$name", "/* Gebietskarten: eigene Farben im Dunkeln – automatisch erzeugt */\n" . $css, LOCK_EX);
        }
        if (!is_file("$dir/$name") || isset(self::$sheets[$name])) return '';
        self::$sheets[$name] = true;
        return '<link rel="stylesheet" href="' . e(site()->mediaUrl('gebietskarten/' . $name)) . '">';
    }
}
