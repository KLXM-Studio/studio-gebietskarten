<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Contrast;

/**
 * Farben der Gebiete – ohne style-Attribute auf der Website (CSP):
 *  - Design-Farben des Kits (Kit-Vertrag --kit-*): Klasse gk-c-{name}, folgen hell/dunkel automatisch.
 *  - Palette (Okabe-Ito, auch bei Farbsehschwäche unterscheidbar): Klasse gk-c-p1 … gk-c-p8, mit dem Hintergrund des Kits
 *    gemischt (color-mix) – hell auf hellem, gedämpft auf dunklem Grund.
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
        'p5' => '#0072B2', 'p6' => '#D55E00', 'p7' => '#CC79A7', 'p8' => '#999999'];

    /** @return array<string, string> */
    public static function paletteLabels(): array
    {
        return ['p1' => __('Orange'), 'p2' => __('Himmelblau'), 'p3' => __('Blaugrün'), 'p4' => __('Gelb'),
            'p5' => __('Blau'), 'p6' => __('Zinnoberrot'), 'p7' => __('Purpur'), 'p8' => __('Grau')];
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

    /** Wirksame Farbe eines Gebiets: '' → Palette nach Position */
    public static function effective(string $c, int $index): string
    {
        $c = self::clean($c);
        return $c !== '' ? $c : 'p' . (($index % count(self::PALETTE)) + 1);
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
     * sheet()), fill = heller Wert. @return array{class: string, fill: ?string, dark: ?string}
     */
    public static function paint(string $effective, string $dark = ''): array
    {
        if ($effective !== '' && $effective[0] === '#') {
            $d = self::hex($dark);
            return ['class' => 'gk-c-own' . ($d !== '' && $d !== $effective ? ' ' . self::pairClass($effective, $d) : ''), 'fill' => $effective, 'dark' => $d !== '' ? $d : null];
        }
        return ['class' => 'gk-c-' . $effective, 'fill' => null, 'dark' => null];
    }

    public static function pairClass(string $light, string $dark): string
    {
        return 'gk-x-' . substr($light, 1) . '-' . substr($dark, 1);
    }

    /** Ungefähre Farbe für Vorschau-Zwecke (Verwaltung, Export als Grafik) */
    public static function approx(string $effective, array $kit = []): string
    {
        if ($effective !== '' && $effective[0] === '#') return $effective;
        if (isset(self::PALETTE[$effective])) return strtolower(Contrast::mix(self::PALETTE[$effective], $kit['bg'] ?? '#ffffff', 78));
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
     * CSS der Dunkel-Regeln für Farbpaare
     * @param list<array{0: string, 1: string}> $pairs [hell, dunkel]
     * @param array{media: string, scope: string, extra: list<string>}|null $rule
     */
    public static function sheetCss(array $pairs, ?array $rule): string
    {
        if (!$pairs || !$rule) return '';
        $body = static function (string $pre) use ($pairs): string {
            $out = [];
            foreach ($pairs as [$l, $d]) {
                $c = '.' . self::pairClass($l, $d);
                $out[] = $pre . ' ' . $c . ' path,' . $pre . ' ' . $c . ' rect{fill:' . $d . '}';
            }
            return implode("\n", $out);
        };
        $css = $rule['media'] !== '' ? '@media ' . $rule['media'] . "{\n" . $body($rule['scope']) . "\n}\n" : $body($rule['scope']) . "\n";
        foreach ($rule['extra'] as $sel) $css .= $body($sel) . "\n";
        return $css;
    }

    /**
     * <link> auf die Dunkel-Regeln der eigenen Farben einer Karte. Die Datei liegt im Medienordner der Website
     * (gebietskarten/farben-{prüfsumme}.css, wie die Design-Datei des Cores) – keine Inline-Stile auf der Website.
     * @param list<array{0: string, 1: string}> $pairs [hell, dunkel]
     */
    public static function sheet(array $pairs): string
    {
        $clean = [];
        foreach ($pairs as [$l, $d]) {
            [$l, $d] = [self::hex((string) $l), self::hex((string) $d)];
            if ($l !== '' && $d !== '' && $l !== $d) $clean[$l . $d] = [$l, $d];
        }
        if (!$clean) return '';
        ksort($clean);
        $pairs = array_values($clean);
        $css = self::sheetCss($pairs, self::darkRule());
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
