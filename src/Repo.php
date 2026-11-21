<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Db\Table;

/**
 * Tabellen und Zugriffe: Karten (gebietskarten_maps) und ihre Gebiete (gebietskarten_regions) in der Datenbank der Website.
 *
 * Karte: Titel, Grundlage (de), Ebene (laender|kreise|gemeinden), Ausschnitt (Länder, leer = alle), Quelle der Gebiete
 * ('own' = hier gepflegt, 'table' = aus einer Datentabelle, siehe Bound) und Darstellung (options, JSON).
 * Gebiet: Name, Farbe (Token oder #RRGGBB), Link (Core-Linkauswahl, z. B. page:12), Infotext (Rich-Text), Bild (Mediathek),
 * Kontakt (mehrzeilig), Einheiten (AGS, kommagetrennt) und Reihenfolge.
 */
final class Repo
{
    public const MAPS = 'gebietskarten_maps';
    public const REGIONS = 'gebietskarten_regions';

    /** Standard der Darstellung */
    public const OPTIONS = [
        'rest' => true,        // nicht zugeordnete Einheiten im Ausschnitt zeigen
        'fit' => 'scope',      // Ausschnitt: scope (alle Einheiten) | regions (nur Gebiete)
        'lines' => true,       // Grenzen der Einheiten innerhalb der Gebiete
        'labels' => false,     // Namen der Gebiete in der Karte
        'list' => true,        // Liste der Gebiete unter der Karte sichtbar (sonst nur für Screenreader)
    ];

    /** @return array<string, callable(Table): mixed> */
    public static function tables(): array
    {
        return [
            self::MAPS => fn(Table $t) => $t->id()
                ->column('title', 'string', ['length' => 160, 'null' => false, 'default' => ''])
                ->column('base', 'string', ['length' => 16, 'default' => 'de'])
                ->column('level', 'string', ['length' => 16, 'default' => 'kreise'])
                ->column('scope', 'string', ['length' => 191, 'default' => ''])
                ->column('source', 'string', ['length' => 12, 'default' => 'own'])
                ->column('binding', 'json')
                ->column('options', 'json')
                ->column('created_at', 'datetime')->column('updated_at', 'datetime'),
            self::REGIONS => fn(Table $t) => $t->id()
                ->column('map_id', 'int', ['null' => false])
                ->column('name', 'string', ['length' => 160, 'null' => false, 'default' => ''])
                ->column('color', 'string', ['length' => 16, 'default' => ''])
                ->column('link', 'string', ['length' => 191, 'default' => ''])
                ->column('text', 'text')
                ->column('media_id', 'int')
                ->column('contact', 'text')
                ->column('units', 'longtext')
                ->column('sort', 'int', ['default' => 0])
                ->column('created_at', 'datetime')->column('updated_at', 'datetime')
                ->index(['map_id', 'sort']),
        ];
    }

    /** @return list<array> */
    public static function maps(): array
    {
        return array_map([self::class, 'decodeMap'], app()->db->fetchAll('SELECT * FROM ' . self::MAPS . ' ORDER BY title, id'));
    }

    public static function map(int $id): ?array
    {
        $m = app()->db->fetch('SELECT * FROM ' . self::MAPS . ' WHERE id = ?', [$id]);
        return $m ? self::decodeMap($m) : null;
    }

    private static function decodeMap(array $m): array
    {
        $m['id'] = (int) $m['id'];
        $m['scope'] = Bases::cleanScope((string) $m['base'], (string) $m['scope']);
        $m['options'] = array_replace(self::OPTIONS, array_intersect_key((array) (json_decode((string) ($m['options'] ?? ''), true) ?: []), self::OPTIONS));
        $m['binding'] = (array) (json_decode((string) ($m['binding'] ?? ''), true) ?: []);
        return $m;
    }

    /** Karte anlegen oder ändern. @return int ID */
    public static function saveMap(?int $id, array $in): int
    {
        $base = Bases::valid((string) ($in['base'] ?? 'de'), (string) ($in['level'] ?? '')) ? (string) ($in['base'] ?? 'de') : 'de';
        $level = Bases::valid($base, (string) ($in['level'] ?? '')) ? (string) $in['level'] : 'kreise';
        $opt = [];
        foreach (self::OPTIONS as $k => $def) {
            $v = $in['options'][$k] ?? $def;
            $opt[$k] = is_bool($def) ? filter_var($v, FILTER_VALIDATE_BOOL) : (in_array($v, ['scope', 'regions'], true) ? $v : $def);
        }
        $row = [
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 160) ?: __('Gebietskarte'),
            'base' => $base, 'level' => $level,
            'scope' => implode(',', Bases::cleanScope($base, $in['scope'] ?? [])),
            'source' => ($in['source'] ?? 'own') === 'table' ? 'table' : 'own',
            'binding' => json_encode(Bound::cleanBinding((array) ($in['binding'] ?? [])), JSON_UNESCAPED_UNICODE),
            'options' => json_encode($opt),
            'updated_at' => now(),
        ];
        if ($id) {
            app()->db->update(self::MAPS, $row, 'id = ?', [$id]);
        } else {
            $id = (int) app()->db->insert(self::MAPS, $row + ['created_at' => now()]);
        }
        Render::flushCache();
        \Core\PageCache::clear();
        return $id;
    }

    public static function deleteMap(int $id): void
    {
        app()->db->transaction(function () use ($id): void {
            app()->db->query('DELETE FROM ' . self::REGIONS . ' WHERE map_id = ?', [$id]);
            app()->db->query('DELETE FROM ' . self::MAPS . ' WHERE id = ?', [$id]);
        });
        Render::flushCache();
        \Core\PageCache::clear();
    }

    /** Gebiete einer eigenen Karte (ohne Datentabelle). @return list<array> */
    public static function regions(int $mapId): array
    {
        return array_map([self::class, 'decodeRegion'], app()->db->fetchAll('SELECT * FROM ' . self::REGIONS . ' WHERE map_id = ? ORDER BY sort, id', [$mapId]));
    }

    public static function region(int $id): ?array
    {
        $r = app()->db->fetch('SELECT * FROM ' . self::REGIONS . ' WHERE id = ?', [$id]);
        return $r ? self::decodeRegion($r) : null;
    }

    private static function decodeRegion(array $r): array
    {
        return ['id' => (int) $r['id'], 'key' => 'r' . (int) $r['id'], 'map_id' => (int) $r['map_id'], 'name' => (string) $r['name'],
            'color' => Colors::clean((string) $r['color']), 'link' => (string) $r['link'], 'text' => (string) ($r['text'] ?? ''),
            'media_id' => $r['media_id'] !== null ? (int) $r['media_id'] : null, 'contact' => (string) ($r['contact'] ?? ''),
            'units' => self::unitList((string) ($r['units'] ?? '')), 'sort' => (int) $r['sort'], 'entry' => null];
    }

    /** „05170, 05154“ bzw. Liste → saubere Liste von Schlüsseln (Ziffern, eindeutig, Reihenfolge bleibt). @return list<string> */
    public static function unitList(array|string $v): array
    {
        $list = is_array($v) ? $v : preg_split('~[\s,;]+~', $v, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ((array) $list as $x) {
            $x = trim((string) $x);
            if (preg_match('~^\d{2,12}$~', $x)) $out[$x] = true;
        }
        return array_keys($out);
    }

    /** Gebiet speichern. Eine Einheit gehört höchstens zu einem Gebiet der Karte – andere Gebiete geben sie ab. @return int ID */
    public static function saveRegion(int $mapId, ?int $id, array $in): int
    {
        $units = self::unitList($in['units'] ?? []);
        $row = [
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 160) ?: __('Neues Gebiet'),
            'color' => Colors::clean((string) ($in['color'] ?? '')),
            'link' => mb_substr(trim((string) ($in['link'] ?? '')), 0, 191),
            'text' => \Core\Sanitizer::block((string) ($in['text'] ?? '')),
            'media_id' => ($mid = (int) ($in['media_id'] ?? 0)) > 0 ? $mid : null,
            'contact' => mb_substr(trim((string) ($in['contact'] ?? '')), 0, 2000),
            'units' => implode(',', $units),
            'updated_at' => now(),
        ];
        app()->db->transaction(function () use ($mapId, &$id, $row, $units): void {
            if ($id) {
                app()->db->update(self::REGIONS, $row, 'id = ? AND map_id = ?', [$id, $mapId]);
            } else {
                $sort = (int) app()->db->fetchValue('SELECT COALESCE(MAX(sort), 0) + 1 FROM ' . self::REGIONS . ' WHERE map_id = ?', [$mapId]);
                $id = (int) app()->db->insert(self::REGIONS, $row + ['map_id' => $mapId, 'sort' => $sort, 'created_at' => now()]);
            }
            if ($units) self::release($mapId, (int) $id, $units);
        });
        self::touch($mapId);
        return (int) $id;
    }

    /**
     * Zuordnung aus der Verwaltung (Klick auf die Karte, Liste): je Gebiet die vollständige Liste der Einheiten.
     * @param array<int, list<string>> $byRegion
     */
    public static function saveAssignment(int $mapId, array $byRegion): void
    {
        $seen = [];
        app()->db->transaction(function () use ($mapId, $byRegion, &$seen): void {
            foreach (self::regions($mapId) as $r) {
                $units = [];
                foreach (self::unitList($byRegion[$r['id']] ?? []) as $u) {
                    if (isset($seen[$u])) continue;   // jede Einheit genau einem Gebiet
                    $units[] = $seen[$u] = $u;
                }
                app()->db->update(self::REGIONS, ['units' => implode(',', $units), 'updated_at' => now()], 'id = ?', [$r['id']]);
            }
        });
        self::touch($mapId);
    }

    /** Einheiten aus allen anderen Gebieten der Karte entfernen */
    private static function release(int $mapId, int $keepId, array $units): void
    {
        $drop = array_flip($units);
        foreach (self::regions($mapId) as $r) {
            if ($r['id'] === $keepId) continue;
            $left = array_values(array_filter($r['units'], fn($u) => !isset($drop[$u])));
            if (count($left) !== count($r['units'])) app()->db->update(self::REGIONS, ['units' => implode(',', $left)], 'id = ?', [$r['id']]);
        }
    }

    public static function deleteRegion(int $mapId, int $id): void
    {
        app()->db->query('DELETE FROM ' . self::REGIONS . ' WHERE id = ? AND map_id = ?', [$id, $mapId]);
        self::touch($mapId);
    }

    /** @param list<int> $ids */
    public static function reorder(int $mapId, array $ids): void
    {
        app()->db->transaction(function () use ($mapId, $ids): void {
            foreach (array_values($ids) as $i => $id) app()->db->update(self::REGIONS, ['sort' => $i + 1], 'id = ? AND map_id = ?', [(int) $id, $mapId]);
        });
        self::touch($mapId);
    }

    private static function touch(int $mapId): void
    {
        app()->db->update(self::MAPS, ['updated_at' => now()], 'id = ?', [$mapId]);
        Render::flushCache();
        \Core\PageCache::clear();
    }

    /** Auswahl für den Block: Karte. @return array<string, string> */
    public static function mapOptions(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;
        try {
            $memo = [];
            foreach (app()->db->fetchAll('SELECT id, title FROM ' . self::MAPS . ' ORDER BY title, id') as $m) $memo[(string) $m['id']] = (string) $m['title'];
        } catch (\Throwable) {
            $memo = [];
        }
        return $memo;
    }

    /** Auswahl für den Block: Gebiet zum Hervorheben („Karte › Gebiet“, Schlüssel {karte}:{gebiet}). @return array<string, string> */
    public static function regionOptions(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = [];
        try {
            foreach (self::maps() as $m) {
                foreach (Bound::regionsOf($m) as $r) $memo[$m['id'] . ':' . $r['key']] = $m['title'] . ' › ' . $r['name'];
            }
        } catch (\Throwable) {
        }
        return $memo;
    }

    /** @return array{maps: int, regions: int} */
    public static function counts(): array
    {
        return ['maps' => (int) app()->db->fetchValue('SELECT COUNT(*) FROM ' . self::MAPS),
            'regions' => (int) app()->db->fetchValue('SELECT COUNT(*) FROM ' . self::REGIONS)];
    }
}
