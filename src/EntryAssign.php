<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Data\Entries;
use Core\Data\EntryEdit;
use Core\Data\Tables;

/**
 * Zuordnung per Klick bei Karten aus einer Datentabelle: schreibt das Feld mit den Flächen in den Einträgen – nur über die
 * Mittel des Cores für Einträge:
 *  - Rechte: Recht „Daten bearbeiten“ der Tabelle (can('data.edit', $handle)) und Core\Data\EntryEdit::reason() – veröffentlichte
 *    Einträge nur mit „Veröffentlichen“, fremde Einträge geteilter Tabellen und Einträge aus externen Quellen nie.
 *  - Speichern: Core\Data\Entries::save() mit Teilaktualisierung (nur das Feld, Status bleibt) – Prüfung der Felder und
 *    Bedingungen, Bearbeitungssperre, Version mit Notiz (Core\Data\Revisions), Ereignis EntrySaved (Aktionsprotokoll), Seiten-Cache.
 *  - Feldtypen: Mehrfachauswahl (Optionen, deren Schlüssel oder Beschriftung zu genau einer Fläche passt), Text (durch Komma
 *    getrennt) und Textfeld (je Zeile). Werte außerhalb dieser Karte (anderer Ausschnitt, unbekannt) bleiben stehen.
 *  - Schreibweise: wie die Einträge der Tabelle es bisher halten – überwiegend Namen → Namen (bei mehrdeutigen Namen der
 *    Schlüssel), sonst amtliche Schlüssel.
 */
final class EntryAssign
{
    public const TYPES = ['multiselect', 'text', 'textarea'];

    /** Feld mit den Flächen, wenn es sich schreiben lässt (sonst null) */
    public static function field(array $table, array $map): ?array
    {
        $f = Tables::field($table, (string) ($map['binding']['units'] ?? ''));
        return $f && in_array($f['type'] ?? '', self::TYPES, true) ? $f : null;
    }

    /** Warum lässt sich die Zuordnung dieser Karte nicht per Klick ändern? null = Tabelle und Feld passen */
    public static function mapReason(?array $table, array $map): ?string
    {
        if (!$table) return __('Keine Datentabelle gewählt.');
        if (!can('data.edit', $table['handle'])) return __('Für die Tabelle „{table}“ fehlt Ihrer Rolle das Recht „Daten bearbeiten“.', ['table' => $table['name']]);
        if (!self::field($table, $map)) return __('Das Feld mit den Flächen lässt sich nur als Mehrfachauswahl, Text oder Textfeld per Klick pflegen.');
        return null;
    }

    /** Warum darf dieser Eintrag nicht geändert werden? null = darf (Regeln des Cores für die Bearbeitung von Einträgen) */
    public static function reason(array $table, array $entry): ?string
    {
        return EntryEdit::reason($table, $entry);
    }

    /**
     * Schreibweise der Tabelle: 'names' oder 'keys' – nach der Mehrheit der Werte aller Gebiete der Karte (leer: Namen).
     * @param list<array> $regions
     */
    public static function style(array $regions): string
    {
        $n = $k = 0;
        foreach ($regions as $r) foreach ((array) ($r['raw'] ?? []) as $v) preg_match('~^\d{1,12}$~', trim((string) $v)) ? $k++ : $n++;
        return $k > $n ? 'keys' : 'names';
    }

    /**
     * Neuer Wert des Feldes für einen Eintrag. $raw: bisherige Werte, $want: gewünschte Flächen dieser Karte (Schlüssel),
     * $units: alle Flächen der Karte. Rückgabe: [Wert für Entries::save, nicht schreibbare Flächen (Mehrfachauswahl ohne Option)].
     * @param list<string> $raw  @param list<string> $want  @param array<string, array> $units
     * @return array{0: mixed, 1: list<string>}
     */
    public static function value(array $field, mixed $stored, array $raw, array $want, array $units, string $style): array
    {
        $want = array_values(array_unique(array_map('strval', $want)));
        $keep = [];
        $covered = [];
        // bisherige Werte: bleiben, wenn sie außerhalb der Karte liegen oder nur gewünschte Flächen meinen
        foreach ($raw as $v) {
            [$ids] = Bound::resolve([$v], $units);
            if (!$ids) { $keep[] = $v; continue; }                  // andere Karte, anderer Ausschnitt, unbekannt
            if (array_diff($ids, $want)) continue;                    // meint (auch) eine Fläche, die nicht mehr dazugehört
            $keep[] = $v;
            foreach ($ids as $id) $covered[$id] = true;
        }
        $missing = [];
        $opts = $field['type'] === 'multiselect' ? \Core\Fields::options($field) : [];
        foreach ($want as $u) {
            if (isset($covered[$u]) || !isset($units[$u])) continue;
            if ($field['type'] === 'multiselect') {
                $hit = null;
                foreach ($opts as $k => $l) {
                    foreach ([(string) $k, (string) $l] as $cand) {
                        if (Bound::resolve([$cand], $units)[0] === [$u]) { $hit = (string) $k; break 2; }
                    }
                }
                if ($hit === null) { $missing[] = $u; continue; }
                $keep[] = $hit;
                continue;
            }
            $name = (string) $units[$u]['name'];
            $keep[] = $style === 'names' && Bound::resolve([$name], $units)[0] === [$u] ? $name : $u;
        }
        if ($field['type'] === 'multiselect') return [array_values(array_unique($keep)), $missing];
        $sep = $field['type'] === 'textarea' ? "\n" : (is_string($stored) && str_contains($stored, ';') ? '; ' : ', ');
        if (is_string($stored) && str_contains($stored, "\n")) $sep = "\n";
        return [implode($sep, array_values(array_unique($keep))), $missing];
    }

    /**
     * Zuordnung speichern: je Eintrag die vollständige Liste der Flächen dieser Karte. Nur geänderte Einträge werden gespeichert.
     * @param array<int, list<string>> $byEntry
     * @return array{saved: int, errors: array<int, string>}
     */
    public static function save(array $map, array $byEntry): array
    {
        $t = Bound::table($map);
        if (($why = self::mapReason($t, $map)) !== null || !$t) return ['saved' => 0, 'errors' => [0 => (string) $why]];
        $field = (array) self::field($t, $map);
        $regions = Bound::regionsOf($map);
        $geo = Render::geometry($map, $regions);
        $units = Bases::units($map['base'], $map['level'], $map['scope'])['units'];
        $style = self::style($regions);
        // jede Fläche genau einem Eintrag: frühere Einträge in der Reihenfolge der Karte gewinnen
        $seen = [];
        $clean = [];
        foreach ($regions as $r) {
            if (!array_key_exists($r['id'], $byEntry)) continue;
            $list = [];
            foreach (Repo::unitList((array) $byEntry[$r['id']]) as $u) {
                if (isset($seen[$u]) || !isset($units[$u])) continue;
                $list[] = $seen[$u] = $u;
            }
            $clean[$r['id']] = $list;
        }
        $saved = 0;
        $errors = [];
        foreach ($regions as $r) {
            if (!array_key_exists($r['id'], $clean)) continue;
            $now = $geo['unitsOf'][$r['key']] ?? [];
            $want = $clean[$r['id']];
            $a = $now; sort($a);
            $b = $want; sort($b);
            if ($a === $b) continue;
            $e = Entries::find($t, (int) $r['id']);
            if (!$e) { $errors[$r['id']] = __('„{name}“: Eintrag nicht gefunden.', ['name' => $r['name']]); continue; }
            if (($why = self::reason($t, $e)) !== null) { $errors[$r['id']] = $r['name'] . ': ' . $why; continue; }
            [$value, $missing] = self::value($field, $e[$field['name']] ?? null, (array) ($r['raw'] ?? []), $want, $units, $style);
            if ($missing) {
                $errors[$r['id']] = __('„{name}“: Für {list} gibt es im Feld „{field}“ keine passende Auswahl.', ['name' => $r['name'],
                    'list' => implode(', ', array_map(fn($u) => (string) ($units[$u]['name'] ?? $u), $missing)), 'field' => Tables::label($field)]);
                continue;
            }
            [$id, $errs] = Entries::save($t, (int) $e['id'], [$field['name'] => $value, 'status' => $e['status']],
                __('Gebietskarte „{title}“: Flächen per Klick zugeordnet', ['title' => $map['title']]));
            if ($errs || !$id) { $errors[$r['id']] = $r['name'] . ': ' . implode(' ', array_map('strval', $errs)); continue; }
            $saved++;
        }
        if ($saved) {
            Render::flushCache();
            \Core\PageCache::clear();
        }
        return ['saved' => $saved, 'errors' => $errors];
    }
}
