<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Klxm\Gebietskarten\Gebietskarten as G;

/**
 * Website-Vorlagen des Cores (Core\Blueprint, ab Core mit den Filtern blueprint.*): Karten und Gebiete gehen mit der Vorlage mit,
 * die Region der neuen Website setzt den Ausschnitt.
 *
 *   blueprint.source   Region der Quelle aus dem Ausschnitt der ersten Karte (ein Land) – damit werden „NRW“ & Co. in Texten Platzhalter
 *   blueprint.export   Abschnitt „gebietskarten“: Karten (Einstellungen, Darstellung, Beschriftung) und eigene Gebiete (Name, Farbe, Link,
 *                      Infotext, Flächen) – ohne Kontakt und Bild (Personen, Mediathek der Quelle)
 *   blueprint.apply    Karten und Gebiete mit ihren IDs anlegen (Blöcke verweisen auf die Karte), nur in eine Website ohne Karten
 *   blueprint.region   Ausschnitt jeder Karte (Kreise, Gemeinden) auf das neue Land; die Gebiete bekommen beispielhaft Kreise des Landes
 *                      (übrige grau), dazu je Karte eine Aufgabe „Gebiete zuordnen“
 */
final class Blueprint
{
    /** Beispiel-Flächen je Gebiet nach einem Regionswechsel */
    public const EXAMPLE_UNITS = 2;

    public static function source(mixed $v, array $ctx): ?array
    {
        $v = is_array($v) ? $v : [];
        if (!empty($v['region'])) return null;
        foreach (Repo::maps() as $m) {
            if (count($m['scope']) === 1) return $v + ['region' => (string) $m['scope'][0]];
        }
        return null;
    }

    public static function export(mixed $sections, array $ctx): ?array
    {
        $sections = is_array($sections) ? $sections : [];
        $maps = app()->db->fetchAll('SELECT * FROM ' . Repo::MAPS . ' ORDER BY id');
        if (!$maps) return null;
        $regions = [];
        foreach (app()->db->fetchAll('SELECT * FROM ' . Repo::REGIONS . ' ORDER BY map_id, sort, id') as $r) {
            $r['contact'] = '';      // Ansprechpersonen gehören der Quelle
            $r['media_id'] = null;   // Bilder liegen in der Mediathek der Quelle
            $regions[] = $r;
        }
        return $sections + [G::NAME => ['version' => 1, 'maps' => $maps, 'regions' => $regions]];
    }

    public static function apply(mixed $lines, array $ctx): ?array
    {
        $lines = is_array($lines) ? $lines : [];
        $data = $ctx['sections'][G::NAME] ?? null;
        if (!is_array($data) || !G::on()) return null;
        $db = app()->db;
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM ' . Repo::MAPS) > 0) return [...$lines, __('Gebietskarten: Die Website hat schon Karten – nichts übernommen.')];
        $cols = fn(string $t) => array_flip($db->columns($t));
        $mc = $cols(Repo::MAPS);
        $rc = $cols(Repo::REGIONS);
        $nm = $nr = 0;
        foreach ((array) ($data['maps'] ?? []) as $m) {
            if (!is_array($m) || (int) ($m['id'] ?? 0) <= 0) continue;
            $db->insert(Repo::MAPS, array_intersect_key(['created_at' => now(), 'updated_at' => now()] + $m, $mc));
            $nm++;
        }
        foreach ((array) ($data['regions'] ?? []) as $r) {
            if (!is_array($r) || (int) ($r['id'] ?? 0) <= 0) continue;
            $db->insert(Repo::REGIONS, array_intersect_key(['contact' => '', 'media_id' => null] + $r, $rc));
            $nr++;
        }
        self::fill((array) ($ctx['values'] ?? []));
        Render::flushCache();
        return [...$lines, __('Gebietskarten: {maps} Karten, {regions} Gebiete übernommen.', ['maps' => $nm, 'regions' => $nr])];
    }

    /** Platzhalter der Vorlage ({{region.short}} …) in Titeln, Namen und Infotexten füllen – über den Core (Placeholders::fill) */
    public static function fill(array $values): void
    {
        if (!$values || !class_exists(\Core\Blueprint\Placeholders::class)) return;
        $db = app()->db;
        foreach ($db->fetchAll('SELECT id, title FROM ' . Repo::MAPS) as $m) {
            $t = \Core\Blueprint\Placeholders::fill((string) $m['title'], $values);
            if ($t !== $m['title']) $db->update(Repo::MAPS, ['title' => $t], 'id = ?', [(int) $m['id']]);
        }
        foreach ($db->fetchAll('SELECT id, name, text FROM ' . Repo::REGIONS) as $r) {
            $upd = [];
            foreach (['name', 'text'] as $c) {
                $v = \Core\Blueprint\Placeholders::fill((string) $r[$c], $values);
                if ($v !== (string) $r[$c]) $upd[$c] = $v;
            }
            if ($upd) $db->update(Repo::REGIONS, $upd, 'id = ?', [(int) $r['id']]);
        }
    }

    /** Region der neuen Website: ctx code = Landesschlüssel („06“) bzw. null (freie Angabe – dann nur die Aufgabe) */
    public static function region(mixed $lines, array $ctx): ?array
    {
        $lines = is_array($lines) ? $lines : [];
        if (!G::on()) return null;
        $code = (string) ($ctx['code'] ?? '');
        self::fill((array) ($ctx['values'] ?? []));
        $maps = Repo::maps();
        if (!$maps) return null;
        foreach ($maps as $m) {
            $task = ['task' => __('Gebiete zuordnen: {title}', ['title' => (string) $m['title']]), 'link' => G::ADMIN . '/' . $m['id'],
                'body' => __('Die Karte zeigt jetzt das Gebiet {region}. Die Beispiel-Gebiete haben nur einzelne Flächen – bitte die richtigen zuordnen.', ['region' => (string) ($ctx['region'] ?? '')])];
            if ($m['level'] === 'laender' || $code === '' || !preg_match('~^\d{2}$~', $code)) { $lines[] = $task; continue; }
            Repo::saveMap($m['id'], ['title' => $m['title'], 'base' => $m['base'], 'level' => $m['level'], 'scope' => [$code], 'source' => $m['source'],
                'binding' => $m['binding'], 'options' => $m['options']]);
            $map = Repo::map($m['id']);
            if (!$map) continue;
            $units = array_keys(Bases::units($map['base'], $map['level'], $map['scope'])['units']);
            sort($units);
            $regions = Bound::regionsOf($map);
            $by = [];
            $i = 0;
            foreach ($regions as $r) {
                $by[$r['id']] = array_slice($units, $i, self::EXAMPLE_UNITS);
                $i += self::EXAMPLE_UNITS;
            }
            if ($map['source'] === 'table') {
                EntryAssign::save($map, $by);
            } else {
                Repo::saveAssignment($map['id'], $by);
            }
            Render::flushCache();
            $lines[] = __('Gebietskarte „{title}“: Ausschnitt {region}, {n} Gebiete mit Beispiel-Flächen.', ['title' => $map['title'], 'region' => (string) ($ctx['region'] ?? $code), 'n' => count($by)]);
            $lines[] = $task;
        }
        \Core\PageCache::clear();
        return $lines;
    }
}
