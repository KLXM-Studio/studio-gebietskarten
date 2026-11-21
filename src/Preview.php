<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

/**
 * Grundfarben des Kits für die Vorschau in der Verwaltung (hell/dunkel) und den Export als Grafik. Die Website liest die Farben
 * über den Kit-Vertrag (--kit-*); die Verwaltung lädt das Kit nicht – darum hier die Design-Werte der üblichen Token-Namen
 * (accent, background, ink, text, muted, surface, line) samt Dunkel-Werten, sonst neutrale Werte.
 */
final class Preview
{
    /** @return array{accent: string, bg: string, ink: string, text: string, muted: string, surface: string, line: string} */
    public static function kit(bool $dark): array
    {
        $base = $dark
            ? ['accent' => '#7cc4e4', 'bg' => '#16191d', 'ink' => '#eef1f4', 'text' => '#dfe3e8', 'muted' => '#a7b0ba', 'surface' => '#22272d', 'line' => '#3a4149']
            : ['accent' => '#2f6f8f', 'bg' => '#ffffff', 'ink' => '#1b1f24', 'text' => '#2c3138', 'muted' => '#5b6470', 'surface' => '#eef1f4', 'line' => '#d0d6dd'];
        try {
            $v = \Core\Design::values();
            $t = \Core\Design::tokens();
        } catch (\Throwable) {
            return $base;
        }
        $map = ['accent' => ['accent', 'primary', 'brand'], 'bg' => ['background', 'bg', 'page_bg'], 'ink' => ['ink', 'heading', 'text'],
            'text' => ['text', 'ink'], 'muted' => ['muted', 'text_muted'], 'surface' => ['surface', 'tint', 'muted_bg'], 'line' => ['line', 'border']];
        foreach ($map as $k => $names) {
            foreach ($names as $n) {
                if (($t[$n]['type'] ?? '') !== 'color') continue;
                $c = $dark ? ($v[$n . '@dark'] ?? $t[$n]['dark'] ?? null) : ($v[$n] ?? $t[$n]['default'] ?? null);
                if (is_string($c) && preg_match('~^#[0-9a-f]{6}$~i', $c)) { $base[$k] = strtolower($c); break; }
            }
        }
        return $base;
    }

    /** Variablen des Kit-Vertrags für die Vorschau-Fläche (Verwaltung erlaubt Inline-Stile) */
    public static function vars(array $kit): string
    {
        return '--kit-accent:' . $kit['accent'] . ';--kit-bg:' . $kit['bg'] . ';--kit-ink:' . $kit['ink'] . ';--kit-text:' . $kit['text']
            . ';--kit-muted:' . $kit['muted'] . ';--kit-surface:' . $kit['surface'] . ';--kit-line:' . $kit['line']
            . ';background:' . $kit['bg'] . ';color:' . $kit['text'];
    }
}
