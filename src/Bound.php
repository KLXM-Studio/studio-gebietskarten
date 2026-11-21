<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Data\Entries;
use Core\Data\Tables;

/**
 * Gebiete aus einer Datentabelle (Karte mit Quelle „table“): jeder veröffentlichte Eintrag ist ein Gebiet. Die Einheiten stehen
 * in einem Feld des Eintrags – Mehrfachauswahl, Text oder Textfeld, Werte als Schlüssel (AGS, z. B. 05170) oder Namen
 * („Wesel, Kleve“), getrennt durch Komma, Semikolon oder Zeilenumbruch. Name, Farbe, Link, Infotext, Bild und Kontakt kommen
 * aus frei wählbaren Feldern; ohne Link-Feld verlinkt die Detailseite des Eintrags.
 *
 * Netzwerk: Eine geteilte Tabelle (Core\Data\Shared) funktioniert genauso – jede Website legt ihre eigene Karte darauf an
 * und hebt mit dem Feld „Website“ (Schlüssel oder Adresse der Website) oder im Block ihr Gebiet hervor.
 */
final class Bound
{
    public const KEYS = ['table', 'units', 'name', 'color', 'link', 'text', 'image', 'contact', 'site'];

    public static function cleanBinding(array $in): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            $out[$k] = preg_match('~^[a-z][a-z0-9_]{0,63}$~', $v) ? $v : '';
        }
        return $out;
    }

    /** Datentabelle der Karte (null = keine oder nicht mehr vorhanden) */
    public static function table(array $map): ?array
    {
        $h = (string) ($map['binding']['table'] ?? '');
        if ($h === '') return null;
        try {
            $t = Tables::find($h);
        } catch (\Throwable) {
            return null;
        }
        return $t && !Tables::isInbox($t) ? $t : null;
    }

    /** Felder einer Tabelle für die Zuordnung (Name => Beschriftung) */
    public static function fieldOptions(array $table, array $types = []): array
    {
        $o = ['' => '–'];
        foreach (Tables::dataFields($table['fields']) as $f) {
            if ($types && !in_array($f['type'] ?? '', $types, true)) continue;
            $o[$f['name']] = Tables::label($f) . ' (' . $f['name'] . ')';
        }
        return $o;
    }

    /**
     * Gebiete einer Karte – eigene (Repo) oder aus der Tabelle. Gleiche Form wie Repo::regions, zusätzlich 'raw' (Werte aus dem
     * Feld, noch nicht aufgelöst) und 'entry' (Eintrag) bei Tabellen.
     * @return list<array>
     */
    public static function regionsOf(array $map): array
    {
        if (($map['source'] ?? 'own') !== 'table') return Repo::regions((int) $map['id']);
        $t = self::table($map);
        if (!$t) return [];
        $b = $map['binding'];
        $out = [];
        foreach (Entries::query($t, ['status' => 'published', 'limit' => 500]) as $i => $e) {
            $raw = $e[$b['units']] ?? '';
            $list = is_array($raw) ? array_map('strval', $raw) : preg_split('~[,;\n\r]+~', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
            $link = '';
            if ($b['link'] !== '' && is_scalar($e[$b['link']] ?? null)) $link = (string) $e[$b['link']];
            if ($link === '') $link = (string) (Entries::href($t, $e) ?? '');
            $out[] = [
                'id' => (int) $e['id'], 'key' => 'e' . (int) $e['id'], 'map_id' => (int) $map['id'],
                'name' => $b['name'] !== '' ? trim(strip_tags((string) ($e[$b['name']] ?? ''))) ?: Entries::title($t, $e) : Entries::title($t, $e),
                'color' => $b['color'] !== '' ? Colors::clean((string) ($e[$b['color']] ?? '')) : '',
                'link' => $link,
                'text' => $b['text'] !== '' ? Entries::html($t, $e, $b['text']) : '',
                'media_id' => $b['image'] !== '' && (int) ($e[$b['image']] ?? 0) > 0 ? (int) $e[$b['image']] : null,
                'contact' => $b['contact'] !== '' ? Entries::text($t, $e, $b['contact']) : '',
                'units' => [], 'raw' => array_values(array_filter(array_map('trim', (array) $list), fn($v) => $v !== '')),
                'site' => $b['site'] !== '' ? strtolower(trim((string) ($e[$b['site']] ?? ''))) : '',
                'sort' => $i, 'entry' => ['table' => $t['handle'], 'id' => (int) $e['id']],
            ];
        }
        return $out;
    }

    /**
     * Werte aus dem Feld auf Einheiten abbilden: Schlüssel (AGS, auch ohne führende Null) oder Name (ohne Groß-/Kleinschreibung,
     * „Kreis Wesel“ = „Wesel“). @param array<string, array> $units @return array{0: list<string>, 1: list<string>} [ids, nicht gefunden]
     */
    public static function resolve(array $raw, array $units): array
    {
        $byName = [];
        foreach ($units as $id => $u) {
            foreach (self::nameKeys($u['name'], $u['bez']) as $n) $byName[$n][] = (string) $id;
        }
        $ids = [];
        $miss = [];
        foreach ($raw as $v) {
            $v = trim((string) $v);
            if ($v === '') continue;
            if (preg_match('~^\d{1,12}$~', $v)) {
                $pad = [$v, str_pad($v, 2, '0', STR_PAD_LEFT), str_pad($v, 5, '0', STR_PAD_LEFT), str_pad($v, 8, '0', STR_PAD_LEFT)];
                $hit = null;
                foreach ($pad as $p) if (isset($units[$p])) { $hit = $p; break; }
                if ($hit !== null) { $ids[$hit] = true; continue; }
            }
            $n = self::norm($v);
            if (isset($byName[$n])) { foreach ($byName[$n] as $id) $ids[$id] = true; continue; }
            $miss[] = $v;
        }
        return [array_map('strval', array_keys($ids)), $miss];
    }

    /** Vergleichsformen eines Namens: „Wesel“, „Kreis Wesel“, „Wesel, Kreis“, „Landkreis Wesel“ … @return list<string> */
    private static function nameKeys(string $name, string $bez): array
    {
        $n = self::norm($name);
        $out = [$n];
        // VG250 schreibt Zusätze nach Komma („Offenbach am Main“, „Neustadt a.d.Waldnaab“) – auch ohne Zusatz suchbar
        if (str_contains($name, ',')) $out[] = self::norm(explode(',', $name)[0]);
        if ($bez !== '') $out[] = self::norm($bez . ' ' . $name);
        foreach (['kreis', 'landkreis', 'stadt', 'städteregion', 'regionalverband'] as $p) $out[] = self::norm($p . ' ' . $name);
        return array_values(array_unique($out));
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('~\s+~u', ' ', $s) ?? $s));
        return strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '.' => '', '-' => ' ']);
    }
}
