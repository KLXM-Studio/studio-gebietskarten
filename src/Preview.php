<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Contrast;

/**
 * Grundfarben des Kits für die Vorschau in der Verwaltung (hell/dunkel), die Editor-Karte, den Dunkel-Wert eigener Farben
 * und den Export als Grafik. Die Website liest die Farben über den Kit-Vertrag (--kit-*); die Verwaltung lädt das Kit nicht.
 * Darum werden die Werte hier so aufgelöst wie im Browser der Website:
 *   1. Stylesheet des Kits (public/assets/kits/{kit}/css/site.css, mit Vererbung): Variablen an :root/html/:where(*) (hell)
 *      und unter der Dunkel-Regel des Kits (Design → 'dark': media + scope, Schalter-Klasse) – darunter die Zuordnung
 *      :where(*){--kit-accent:var(--f-a) …}
 *   2. darüber die Design-Werte der Website (Core\Design::css(): Werte aus Verwaltung → Design samt Ableitungen)
 *   3. var(), color-mix() (sRGB-Näherung), light-dark(), #rgb/#rrggbb, rgb() auflösen.
 * Kits ohne Vertrag: Design-Werte der üblichen Token-Namen (accent, background, ink …), sonst neutrale Werte.
 */
final class Preview
{
    public const KEYS = ['accent', 'bg', 'ink', 'text', 'muted', 'surface', 'line'];

    private static array $memo = [];

    /** @return array{accent: string, bg: string, ink: string, text: string, muted: string, surface: string, line: string, source?: string} */
    public static function kit(bool $dark): array
    {
        $key = ($dark ? 'd' : 'l') . ':' . (function_exists('site') ? site()->key : '');
        return self::$memo[$key] ??= self::build($dark);
    }

    /** Neue Anfrage bzw. geänderte Design-Werte (Selbsttest) */
    public static function reset(): void
    {
        self::$memo = [];
    }

    private static function build(bool $dark): array
    {
        $base = $dark
            ? ['accent' => '#7cc4e4', 'bg' => '#16191d', 'ink' => '#eef1f4', 'text' => '#dfe3e8', 'muted' => '#a7b0ba', 'surface' => '#22272d', 'line' => '#3a4149']
            : ['accent' => '#2f6f8f', 'bg' => '#ffffff', 'ink' => '#1b1f24', 'text' => '#2c3138', 'muted' => '#5b6470', 'surface' => '#eef1f4', 'line' => '#d0d6dd'];
        try {
            $vars = self::variables($dark);
        } catch (\Throwable) {
            $vars = [];
        }
        $found = 0;
        foreach (self::KEYS as $k) {
            if (!isset($vars['--kit-' . $k])) continue;
            $c = self::resolve($vars['--kit-' . $k], $vars, $dark);
            if ($c !== null) { $base[$k] = $c; $found++; }
        }
        if ($found >= 3) return $base + ['source' => 'kit'];
        return self::tokens($base, $dark);
    }

    /** Rückfall: Design-Tokens nach üblichen Namen */
    private static function tokens(array $base, bool $dark): array
    {
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
        return $base + ['source' => 'tokens'];
    }

    /**
     * Wirksame Variablen der Website (hell bzw. dunkel): Kit-Stylesheet, dann Design-Datei. @return array<string, string>
     */
    public static function variables(bool $dark): array
    {
        $css = '';
        $theme = app()->theme;
        foreach (array_reverse(array_keys($theme->chain)) as $k) {
            $f = \Core\Kit::publicDir((string) $k) . '/css/site.css';
            if (is_file($f)) $css .= (string) file_get_contents($f) . "\n";
        }
        $rule = self::darkSelectors();
        $vars = self::scan($css, $dark, $rule);
        if (\Core\Design::enabled()) $vars = array_replace($vars, self::scan(\Core\Design::css(), $dark, $rule));
        return $vars;
    }

    /** Dunkel-Selektoren des Kits ohne :not(…): scope, scope.{manual}, force. @return list<string> */
    private static function darkSelectors(): array
    {
        $r = Colors::darkRule();
        if (!$r) return [];
        $out = [self::plain($r['scope'])];
        foreach ($r['extra'] as $s) $out[] = self::plain($s);
        return array_values(array_unique($out));
    }

    private static function plain(string $sel): string
    {
        return strtolower(trim((string) preg_replace('~:not\([^)]*\)|\s+~', '', $sel)));
    }

    /**
     * Benutzerdefinierte Eigenschaften aus einem Stylesheet: hell = Regeln für :root, html, body, * und :where(*) außerhalb von
     * @media; dunkel zusätzlich die Regeln unter den Dunkel-Selektoren des Kits (in @media … dark oder als Schalter-Klasse).
     * Spätere Angaben gewinnen. @param list<string> $darkSel @return array<string, string>
     */
    public static function scan(string $css, bool $dark, array $darkSel): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $out = [];
        $len = strlen($css);
        $stack = [];        // offene @-Regeln (Kopf)
        $i = 0;
        $start = 0;
        while ($i < $len) {
            $ch = $css[$i];
            if ($ch === '{') {
                $head = trim(substr($css, $start, $i - $start));
                if ($head !== '' && $head[0] === '@') {
                    $stack[] = strtolower($head);
                    $start = $i + 1;
                    $i++;
                    continue;
                }
                $end = strpos($css, '}', $i);
                if ($end === false) break;
                $body = substr($css, $i + 1, $end - $i - 1);
                $sels = array_map([self::class, 'plain'], explode(',', $head));
                $inMedia = $stack !== [];
                $inDarkMedia = (bool) array_filter($stack, fn($h) => str_contains($h, 'prefers-color-scheme') && str_contains($h, 'dark'));
                $otherMedia = $inMedia && !$inDarkMedia;
                $isRoot = (bool) array_intersect($sels, [':root', 'html', 'body', '*', ':where(*)', ':root:where(*)']);
                $lightHit = !$inMedia && $isRoot;
                $darkHit = $dark && !$otherMedia && ((bool) array_intersect($sels, $darkSel) || ($inDarkMedia && $isRoot && $darkSel));
                if ($lightHit || $darkHit) {
                    if (preg_match_all('~(--[a-z0-9_-]+)\s*:\s*([^;]+)~i', $body, $mm, PREG_SET_ORDER)) {
                        foreach ($mm as $m) $out[strtolower($m[1])] = trim(str_replace('!important', '', $m[2]));
                    }
                }
                $i = $end + 1;
                $start = $i;
                continue;
            }
            if ($ch === '}') {
                array_pop($stack);
                $start = $i + 1;
            } elseif ($ch === ';' && $stack === []) {
                $start = $i + 1;      // @import, @charset …
            }
            $i++;
        }
        return $out;
    }

    /** Wert einer Variablen zu #rrggbb auflösen (null = nicht bestimmbar) */
    public static function resolve(string $v, array $vars, bool $dark, int $depth = 0): ?string
    {
        if ($depth > 12) return null;
        $v = trim($v);
        if (preg_match('~^#([0-9a-f]{3,8})$~i', $v, $m)) {
            $h = strtolower($m[1]);
            if (strlen($h) === 3 || strlen($h) === 4) return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            return strlen($h) >= 6 ? '#' . substr($h, 0, 6) : null;
        }
        $lv = strtolower($v);
        if ($lv === 'white' || $lv === '#fff') return '#ffffff';
        if ($lv === 'black') return '#000000';
        if (preg_match('~^var\(\s*(--[a-z0-9_-]+)\s*(?:,(.*))?\)$~is', $v, $m)) {
            $name = strtolower($m[1]);
            if (isset($vars[$name])) {
                $r = self::resolve($vars[$name], $vars, $dark, $depth + 1);
                if ($r !== null) return $r;
            }
            return isset($m[2]) && trim($m[2]) !== '' ? self::resolve($m[2], $vars, $dark, $depth + 1) : null;
        }
        if (preg_match('~^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)~i', $v, $m)) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }
        if (preg_match('~^light-dark\((.*)\)$~is', $v, $m) && count($a = self::args($m[1])) === 2) {
            return self::resolve($a[$dark ? 1 : 0], $vars, $dark, $depth + 1);
        }
        if (preg_match('~^color-mix\((.*)\)$~is', $v, $m)) {
            $a = self::args($m[1]);
            if (count($a) !== 3) return null;
            [$c1, $p1] = self::stop($a[1]);
            [$c2, $p2] = self::stop($a[2]);
            $x = self::resolve($c1, $vars, $dark, $depth + 1);
            $y = self::resolve($c2, $vars, $dark, $depth + 1);
            if ($x === null || $y === null) return null;
            $p = $p1 ?? ($p2 !== null ? 100 - $p2 : 50);
            return strtolower(Contrast::mix($x, $y, max(0.0, min(100.0, $p))));
        }
        return null;
    }

    /** Argumente auf oberster Ebene (Komma, Klammern beachten) @return list<string> */
    private static function args(string $s): array
    {
        $out = [];
        $depth = 0;
        $cur = '';
        foreach (str_split($s) as $c) {
            if ($c === '(') $depth++;
            if ($c === ')') $depth--;
            if ($c === ',' && $depth === 0) { $out[] = trim($cur); $cur = ''; continue; }
            $cur .= $c;
        }
        $out[] = trim($cur);
        return $out;
    }

    /** „var(--a) 45%“ → [Farbe, 45.0] @return array{0: string, 1: ?float} */
    private static function stop(string $s): array
    {
        if (preg_match('~^(.*?)\s+([\d.]+)%$~s', trim($s), $m)) return [$m[1], (float) $m[2]];
        if (preg_match('~^([\d.]+)%\s+(.*)$~s', trim($s), $m)) return [$m[2], (float) $m[1]];
        return [trim($s), null];
    }

    /** Ist der Dunkelmodus der Website eingeschaltet? (Klasse der Dunkel-Regel am <html>, z. B. has-dark) */
    public static function darkOn(): bool
    {
        $r = Colors::darkRule();
        if (!$r) return false;
        if (!preg_match('~^html\.([a-z0-9_-]+)~i', self::plain($r['scope']), $m)) return true;
        try {
            return in_array($m[1], preg_split('~\s+~', \Core\Design::classes()) ?: [], true);
        } catch (\Throwable) {
            return true;
        }
    }

    /** Variablen des Kit-Vertrags für die Vorschau-Fläche (Verwaltung erlaubt Inline-Stile) */
    public static function vars(array $kit): string
    {
        return '--kit-accent:' . $kit['accent'] . ';--kit-bg:' . $kit['bg'] . ';--kit-ink:' . $kit['ink'] . ';--kit-text:' . $kit['text']
            . ';--kit-muted:' . $kit['muted'] . ';--kit-surface:' . $kit['surface'] . ';--kit-line:' . $kit['line']
            . ';background:' . $kit['bg'] . ';color:' . $kit['text'];
    }
}
