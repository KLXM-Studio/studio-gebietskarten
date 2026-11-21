<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

/**
 * Farben der Gebiete – ohne style-Attribute auf der Website (CSP):
 *  - Design-Farben des Kits (Kit-Vertrag --kit-*): Klasse gk-c-{name}, folgen hell/dunkel automatisch.
 *  - Palette (Okabe-Ito, auch bei Farbsehschwäche unterscheidbar): Klasse gk-c-p1 … gk-c-p8, mit dem Hintergrund des Kits
 *    gemischt (color-mix) – hell auf hellem, gedämpft auf dunklem Grund.
 *  - eigene Farbe #RRGGBB: als SVG-Attribut fill (Darstellungsattribut, kein style) – gilt hell und dunkel gleich.
 * Farben in CSS: assets/css/gebietskarten.css (Variablen --gk-*, alle vom Kit überschreibbar).
 */
final class Colors
{
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
        if (preg_match('~^#([0-9a-f]{3})$~', $c, $m)) return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        return preg_match('~^#[0-9a-f]{6}$~', $c) ? $c : '';
    }

    /** Wirksame Farbe eines Gebiets: '' → Palette nach Position */
    public static function effective(string $c, int $index): string
    {
        $c = self::clean($c);
        return $c !== '' ? $c : 'p' . (($index % count(self::PALETTE)) + 1);
    }

    /** CSS-Klasse und Attribute für eine wirksame Farbe. @return array{class: string, fill: ?string} */
    public static function paint(string $effective): array
    {
        if ($effective !== '' && $effective[0] === '#') return ['class' => 'gk-c-own', 'fill' => $effective];
        return ['class' => 'gk-c-' . $effective, 'fill' => null];
    }

    /** Ungefähre Farbe für Vorschau-Zwecke (Verwaltung, Export als Grafik) */
    public static function approx(string $effective, array $kit = []): string
    {
        if ($effective !== '' && $effective[0] === '#') return $effective;
        if (isset(self::PALETTE[$effective])) return \Core\Contrast::mix(self::PALETTE[$effective], $kit['bg'] ?? '#ffffff', 78);
        $accent = $kit['accent'] ?? '#2f6f8f';
        $bg = $kit['bg'] ?? '#ffffff';
        return match ($effective) {
            'accent' => $accent,
            'accent-soft' => \Core\Contrast::mix($accent, $bg, 45),
            'accent-deep' => \Core\Contrast::mix($accent, $kit['ink'] ?? '#1a1a1a', 70),
            'surface' => $kit['surface'] ?? '#eef1f4',
            'muted' => \Core\Contrast::mix($kit['muted'] ?? '#6b7280', $bg, 45),
            default => '#cccccc',
        };
    }
}
