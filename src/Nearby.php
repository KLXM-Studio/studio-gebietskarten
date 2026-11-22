<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

/**
 * „In deiner Nähe“ des Cores (Filter data.nearby, Core\Data\Nearby): Ort der Besucher-Eingabe über die Kartengrundlage auflösen –
 * ohne Anfragen an Dritte.
 *
 *  1. Fläche finden: Name einer Gemeinde (wenn deren Kartendaten nachgeladen sind), eines Kreises oder Landes („Wesel“, „Kreis
 *     Wesel“), amtlicher Schlüssel (AGS) – oder, wenn der Core schon einen Kartenpunkt hat (PLZ über die Geokodierung), die
 *     Fläche, in der der Punkt liegt (Kreise, sonst Länder).
 *  2. Kartenpunkt: Beschriftungspunkt der Fläche (Mitte der größten freien Fläche), falls noch keiner da ist.
 *  3. Zuständige Gebiete: alle Gebiete von Karten mit Datentabelle, deren Flächen die gefundene enthalten (Gemeinde → Kreis → Land)
 *     oder in ihr liegen – als „tabelle:id“ und ihre Website (Feld „Website“ der Karte). So findet der Core die Termine bzw.
 *     Ansprechpersonen der Regionalgruppe, der Verband den zuständigen Landesverein.
 */
final class Nearby
{
    /** Filter data.nearby: $v = ['point' => ?, 'label' => '', 'regions' => [], 'sites' => []], $ctx = ['q' => …, 'point' => …] */
    public static function resolve(array $v, array $ctx): ?array
    {
        if (!Gebietskarten::on()) return null;
        $q = trim((string) ($ctx['q'] ?? ''));
        $unit = $q !== '' ? self::byName($q) : null;
        if (!$unit && is_array($ctx['point'] ?? null)) $unit = self::byPoint((float) $ctx['point']['lat'], (float) $ctx['point']['lng']);
        if (!$unit) return null;
        $v['label'] = $v['label'] ?? '';
        if ($v['label'] === '') $v['label'] = trim($unit['name']);
        if (empty($v['point']) && $unit['y'] != 0.0 && $unit['x'] != 0.0) $v['point'] = ['lat' => $unit['y'], 'lng' => $unit['x']];
        [$regions, $sites] = self::regionsFor((string) $unit['id']);
        $v['regions'] = array_values(array_unique([...(array) ($v['regions'] ?? []), ...$regions]));
        $v['sites'] = array_values(array_unique([...(array) ($v['sites'] ?? []), ...$sites]));
        return $v;
    }

    /** Ebenen mit Daten: Gemeinden (nachgeladene Länder), Kreise, Länder. @return list<array{0: string, 1: array}> */
    private static function levels(): array
    {
        $out = [];
        foreach (['gemeinden', 'kreise', 'laender'] as $level) {
            try {
                $files = Bases::files('de', $level, []);
                if ($files) $out[] = [$level, Topo::collect($files)];
            } catch (\Throwable) {
                // Ebene ohne lesbare Daten – übergehen
            }
        }
        return $out;
    }

    /** Fläche nach Name oder Schlüssel (feinste Ebene zuerst). @return array{id: string, name: string, x: float, y: float}|null */
    public static function byName(string $q): ?array
    {
        $n = Bound::norm($q);
        foreach (self::levels() as [, $d]) {
            if (preg_match('~^\d{2,8}$~', $q) && isset($d['units'][$q])) return self::unit($d['units'][$q]);
            [$ids] = Bound::resolve([$q], $d['units']);
            if ($ids) return self::unit($d['units'][$ids[0]]);
            foreach ($d['units'] as $u) if (Bound::norm($u['name']) === $n) return self::unit($u);
        }
        return null;
    }

    /** Fläche, in der ein Punkt liegt (Kreise, sonst Länder; Gemeinden, wenn geladen). */
    public static function byPoint(float $lat, float $lng): ?array
    {
        foreach (self::levels() as [, $d]) {
            foreach ($d['units'] as $u) if (self::contains($u, $d['arcs'], $lng, $lat)) return self::unit($u);
        }
        return null;
    }

    private static function unit(array $u): array
    {
        return ['id' => (string) $u['id'], 'name' => (string) $u['name'], 'x' => (float) $u['x'], 'y' => (float) $u['y']];
    }

    /** Punkt in Fläche (gerade/ungerade über alle Ringe – Löcher zählen mit) */
    public static function contains(array $u, array $arcs, float $x, float $y): bool
    {
        $in = false;
        foreach ((array) $u['polys'] as $poly) {
            foreach ((array) $poly as $ring) {
                $pts = self::ring((array) $ring, $arcs);
                for ($i = 0, $j = count($pts) - 1; $i < count($pts); $j = $i++) {
                    [$xi, $yi] = $pts[$i];
                    [$xj, $yj] = $pts[$j];
                    if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi) $in = !$in;
                }
            }
        }
        return $in;
    }

    /** Ring aus Bögen (negativ = umgekehrt) */
    private static function ring(array $refs, array $arcs): array
    {
        $pts = [];
        foreach ($refs as $r) {
            $r = (int) $r;
            $a = $r >= 0 ? ($arcs[$r] ?? []) : array_reverse($arcs[~$r] ?? []);
            if ($pts && $a) array_shift($a);
            array_push($pts, ...$a);
        }
        return $pts;
    }

    /**
     * Gebiete (Karten mit Datentabelle), die die Fläche $ags enthalten oder in ihr liegen. @return array{0: list<string>, 1: list<string>}
     */
    public static function regionsFor(string $ags): array
    {
        $regions = [];
        $sites = [];
        foreach (Repo::maps() as $map) {
            if (($map['source'] ?? 'own') !== 'table' || !($t = Bound::table($map))) continue;
            try {
                $units = Bases::units((string) $map['base'], (string) $map['level'], (array) $map['scope'])['units'];
            } catch (\Throwable) {
                continue;
            }
            foreach (Bound::regionsOf($map) as $r) {
                [$ids] = Bound::resolve((array) $r['raw'], $units);
                foreach ($ids as $id) {
                    $id = (string) $id;
                    if ($id === $ags || str_starts_with($ags, $id) || str_starts_with($id, $ags)) {
                        $regions[] = $t['handle'] . ':' . (int) $r['id'];
                        if ($r['site'] !== '' && ($sk = self::siteKey((string) $r['site'])) !== null) $sites[] = $sk;
                        break;
                    }
                }
            }
        }
        return [array_values(array_unique($regions)), array_values(array_unique($sites))];
    }

    /** Feld „Website“: Schlüssel oder Host (ohne Port, auch mit https://) → Schlüssel der Website dieser Installation */
    public static function siteKey(string $v): ?string
    {
        $v = strtolower(trim((string) preg_replace('~^https?://~i', '', trim($v)), '/'));
        $sites = \Core\Sites::all();
        if (isset($sites[$v])) return $v;
        $host = (string) preg_replace('~:\d+$~', '', $v);
        foreach ($sites as $key => $cfg) {
            foreach ((array) ($cfg['hosts'] ?? []) as $h) if (strtolower((string) preg_replace('~:\d+$~', '', (string) $h)) === $host) return (string) $key;
        }
        return null;
    }
}
