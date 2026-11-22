<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Data\Entries;
use Core\Data\Tables;
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
 *                      (übrige grau), dazu je Karte eine Aufgabe „Gebiete zuordnen“. Karten aus einer Datentabelle (source=table): die
 *                      Beispiel-Kreise ersetzen das gebundene Feld der Einträge (alte Werte fallen weg), Demo-Einträge heißen danach
 *                      „{Einzahl der Tabelle} {Kreis}“ (seit 0.7.1)
 *   blueprint.areas    (seit 0.7.1) Ortsnamen der Quell-Region (Kreise, kreisfreie Städte, Namen der Gebiete) für die Prüfung der Texte,
 *                      gebundene Flächen-Felder (nicht prüfen) und neutrale Namen der Gebiete („Regionalgruppe Nord/Mitte/Süd“)
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
        // Beschriftungen je Gebiet (Text, Lage) gehören zu den Flächen der Quelle – bei anonymen Demoinhalten weg, die Standardbeschriftung greift
        if (($ctx['demo'] ?? 'anonym') !== 'full') foreach ($maps as $i => $m) $maps[$i]['labels'] = '{}';
        $regions = [];
        $labels = self::ownLabels();
        foreach (app()->db->fetchAll('SELECT * FROM ' . Repo::REGIONS . ' ORDER BY map_id, sort, id') as $r) {
            $r['contact'] = '';      // Ansprechpersonen gehören der Quelle
            $r['media_id'] = null;   // Bilder liegen in der Mediathek der Quelle
            if (isset($labels[(int) $r['id']])) $r['name'] = $labels[(int) $r['id']];   // Namen der Quell-Region (Landschaften, Städte) → neutral
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

    /** Richtungen für neutrale Namen, nach der Lage (Nord → Süd) */
    private const BANDS = ['Nord', 'Mitte', 'Süd'];

    /**
     * Neutrale Namen für Gebiete nach ihrer Lage: 1 → Mitte, 2 → Nord/Süd, 3 → Nord/Mitte/Süd, mehr → je Band nummeriert („Nord 1“).
     * $lat: id → Breitengrad der Mitte (null = unbekannt, kommt ans Ende). @param array<int, ?float> $lat @return array<int, string>
     */
    public static function bandLabels(array $lat): array
    {
        $ids = array_keys($lat);
        usort($ids, fn($a, $b) => ($lat[$b] ?? -999.0) <=> ($lat[$a] ?? -999.0) ?: $a <=> $b);
        $n = count($ids);
        if ($n === 0) return [];
        if ($n === 1) return [$ids[0] => 'Mitte'];
        if ($n === 2) return [$ids[0] => 'Nord', $ids[1] => 'Süd'];
        $out = [];
        $per = (int) ceil($n / 3);
        foreach ($ids as $i => $id) {
            $band = self::BANDS[min(2, intdiv($i, $per))];
            $out[$id] = $band;
        }
        $count = array_count_values($out);
        $k = [];
        foreach ($out as $id => $band) if ($count[$band] > 1) $out[$id] = $band . ' ' . ($k[$band] = ($k[$band] ?? 0) + 1);
        return $out;
    }

    /** Mitte (Breite) der Flächen eines Gebiets – Mittel der Beschriftungspunkte @param array<string, array> $units */
    private static function lat(array $ids, array $units): ?float
    {
        $ys = [];
        foreach ($ids as $u) if (isset($units[$u]) && (float) $units[$u]['y'] !== 0.0) $ys[] = (float) $units[$u]['y'];
        return $ys ? array_sum($ys) / count($ys) : null;
    }

    /** Neutrale Namen der eigenen Gebiete (source=own) je Karte: „Gebiet Nord“ … @return array<int, string> Gebiet-ID → Name */
    public static function ownLabels(): array
    {
        $out = [];
        foreach (Repo::maps() as $m) {
            if (($m['source'] ?? 'own') === 'table') continue;
            $units = self::unitsOf($m);
            $lat = [];
            foreach (Repo::regions((int) $m['id']) as $r) $lat[(int) $r['id']] = self::lat((array) $r['units'], $units);
            foreach (self::bandLabels($lat) as $id => $band) $out[$id] = __('Gebiet') . ' ' . $band;
        }
        return $out;
    }

    /** @return array<string, array> Flächen einer Karte (leer, wenn die Daten fehlen) */
    private static function unitsOf(array $m, ?string $level = null): array
    {
        try {
            return Bases::units((string) $m['base'], $level ?? (string) $m['level'], (array) $m['scope'])['units'];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Ortsnamen aus einem Namen: ganz und in Teilen – „Rhein-Sieg/Bonn“ → Rhein-Sieg, Bonn, Rhein, Sieg; „Offenbach am Main, Stadt“ →
     * Offenbach am Main. Teile unter vier Zeichen und Zusätze (Kreis, Stadt, Land …) zählen nicht. @return list<string>
     */
    public static function nameParts(string $name): array
    {
        $out = [];
        foreach (preg_split('~\s*(?:/|,|;|\bund\b|&)\s*~u', trim($name)) ?: [] as $p) {
            $p = trim((string) preg_replace('~^(?:Regionalgruppe|Gruppe|Gebiet|Kreis|Landkreis|Stadt|Region)\s+~u', '', trim($p)));
            if (mb_strlen($p) >= 4) $out[] = $p;
            if (str_contains($p, '-')) foreach (explode('-', $p) as $q) if (mb_strlen($q) >= 4 && !preg_match('~^(?:Kreis|Stadt|Land|Landkreis|Region)$~iu', $q)) $out[] = $q;
        }
        return array_values(array_unique(array_filter($out, fn($p) => !preg_match('~^(?:Kreis|Stadt|Nord|Mitte|Süd|West|Ost)$~iu', $p))));
    }

    /**
     * Eindeutige Schreibweise einer Fläche für ein Text-Feld: Name, wenn er nur diese Fläche meint und kein anderer Name mit ihm beginnt
     * („Offenbach“ ↔ „Offenbach am Main“), sonst mit Art („Landkreis Offenbach“), sonst der amtliche Schlüssel – immer so, dass
     * Bound::resolve genau diese Fläche findet. @param array<string, array> $units
     */
    public static function unitValue(string $u, array $units): string
    {
        if (!isset($units[$u])) return $u;
        $name = (string) $units[$u]['name'];
        $n = Bound::norm($name);
        $prefix = false;
        foreach ($units as $id => $x) {
            if ((string) $id === $u) continue;
            $o = Bound::norm((string) $x['name']);
            if ($o === $n || str_starts_with($o, $n . ' ') || str_starts_with($o, $n . ',')) { $prefix = true; break; }
        }
        $cands = $prefix ? [trim((string) $units[$u]['bez'] . ' ' . $name)] : [$name, trim((string) $units[$u]['bez'] . ' ' . $name)];
        foreach ($cands as $c) if ($c !== '' && Bound::resolve([$c], $units)[0] === [$u]) return $c;
        return $u;
    }

    /** Einzahl einer Tabelle („Regionalgruppe“) – Feld singular, sonst der Name */
    private static function singular(array $t): string
    {
        return trim((string) ($t['singular'] ?? '')) ?: (string) $t['name'];
    }

    /** Titelfeld der Gebiete einer Karte aus einer Tabelle: Bindung „name“, sonst Titelfeld der Tabelle */
    private static function titleField(array $t, array $map): string
    {
        $n = (string) ($map['binding']['name'] ?? '');
        return $n !== '' ? $n : (string) ($t['settings']['title_field'] ?? '');
    }

    /**
     * Filter blueprint.areas (Export und Probelauf): Ortsnamen der Quell-Region, gebundene Flächen-Felder, neutrale Namen.
     * Neutrale Namen ersetzt der Core nur an echten Verweisen (Core\Blueprint\References) – Namen im Fließtext meldet die Prüfung (places).
     * @return array{places: list<string>, fields: list<string>, entries: array<string, array{field: string, singular: string, labels: array<int, string>}>}
     */
    public static function areas(mixed $v, array $ctx): ?array
    {
        $v = is_array($v) ? $v : [];
        if (!G::on()) return null;
        $maps = Repo::maps();
        if (!$maps) return null;
        $places = (array) ($v['places'] ?? []);
        $fields = (array) ($v['fields'] ?? []);
        $entries = (array) ($v['entries'] ?? []);
        foreach ($maps as $m) {
            // Kreise und kreisfreie Städte des Ausschnitts (auch bei Karten auf Gemeinde-Ebene – Gemeindenamen wären zu viele Fehlalarme)
            if ($m['level'] !== 'laender') {
                foreach (self::unitsOf($m, 'kreise') as $u) array_push($places, ...self::nameParts((string) $u['name']));
            }
            if (($m['source'] ?? 'own') !== 'table') {
                foreach (Repo::regions((int) $m['id']) as $r) array_push($places, ...self::nameParts((string) $r['name']));
                continue;
            }
            $t = Bound::table($m);
            if (!$t) continue;
            $units = self::unitsOf($m);
            $fields[] = $t['handle'] . '.' . (string) ($m['binding']['units'] ?? '');
            $tf = self::titleField($t, $m);
            $lat = [];
            $names = [];
            foreach (Bound::regionsOf($m) as $r) {
                [$ids] = Bound::resolve((array) ($r['raw'] ?? []), $units);
                $lat[(int) $r['id']] = self::lat($ids, $units);
                $names[(int) $r['id']] = (string) $r['name'];
            }
            $labels = [];
            foreach (self::bandLabels($lat) as $id => $band) {
                $labels[$id] = self::singular($t) . ' ' . $band;
                array_push($places, ...self::nameParts($names[$id]));
            }
            if ($tf !== '') $entries[$t['handle']] = ['field' => $tf, 'labels' => $labels, 'singular' => self::singular($t)];
        }
        return ['places' => array_values(array_unique($places)), 'fields' => array_values(array_unique($fields)), 'entries' => $entries] + $v;
    }

    /**
     * Karte aus einer Datentabelle nach dem Regionswechsel: das gebundene Feld der Einträge bekommt genau die Beispiel-Flächen (alte
     * Werte der Quell-Region fallen weg), Demo-Einträge heißen „{Einzahl} {erster Kreis}“ (Titel, Adresse, Nennungen in Texten des
     * Eintrags und in Seiten). Schreibt über Core\Data\Entries::save (Prüfung der Felder, Version mit Notiz) – ohne Rechteprüfung,
     * weil der Schritt Gebiet der Schnelleinrichtung bzw. site:create ihn auslöst (Recht system.manage bzw. Kommandozeile).
     * @param array<int, list<string>> $by Eintrag-ID → Flächen @return array{saved: int, renamed: int, errors: list<string>}
     */
    public static function tableRegion(array $map, array $by): array
    {
        $t = Bound::table($map);
        $field = $t ? EntryAssign::field($t, $map) : null;
        if (!$t || !$field) return ['saved' => 0, 'renamed' => 0, 'errors' => [__('Gebietskarte „{title}“: Tabelle oder Feld der Flächen fehlt.', ['title' => $map['title']])]];
        $units = self::unitsOf($map);
        $tf = self::titleField($t, $map);
        $saved = $renamed = 0;
        $errors = [];
        $rename = [];
        foreach ($by as $id => $list) {
            $e = Entries::find($t, (int) $id);
            if (!$e) continue;
            $names = [];
            foreach ($list as $u) {
                if (!isset($units[$u])) continue;
                $names[] = self::unitValue((string) $u, $units);
            }
            if ($field['type'] === 'multiselect') {
                [$value, $missing] = EntryAssign::value($field, $e[$field['name']] ?? null, [], $list, $units, 'names');
                if ($missing) { $errors[] = __('„{name}“: keine passende Auswahl für die Beispiel-Flächen.', ['name' => (string) ($e[$tf] ?? $id)]); continue; }
            } else {
                $value = implode($field['type'] === 'textarea' ? "\n" : ', ', $names);
            }
            $in = [$field['name'] => $value, 'status' => $e['status']];
            $demo = class_exists(\Core\Blueprint\Demo::class) && \Core\Blueprint\Demo::isDemo((string) $t['handle'], (int) $id);
            $first = $list && isset($units[$list[0]]) ? trim(explode(',', (string) $units[$list[0]]['name'])[0]) : '';
            if ($demo && $tf !== '' && $first !== '' && is_string($e[$tf] ?? null) && trim((string) $e[$tf]) !== '') {
                $old = trim((string) $e[$tf]);
                $new = self::singular($t) . ' ' . $first;
                if ($old !== $new) {
                    $in[$tf] = $new;
                    $in['slug'] = \Core\Pages::slugify($new);
                    $rename[] = ['handle' => (string) $t['handle'], 'id' => (int) $id, 'old' => $old, 'new' => $new, 'slug_old' => (string) ($e['slug'] ?? ''),
                        'slug_new' => $in['slug'], 'prefix' => self::singular($t)];
                }
            }
            [$sid, $errs] = Entries::save($t, (int) $id, $in, __('Gebietskarte „{title}“: Beispiel-Flächen der neuen Region', ['title' => $map['title']]));
            if ($errs || !$sid) { $errors[] = (string) ($e[$tf] ?? $id) . ': ' . implode(' ', array_map('strval', $errs)); continue; }
            $saved++;
            if (isset($in[$tf]) && $tf !== '') $renamed++;
        }
        if ($rename) self::relink($t, $rename, [$tf, $field['name']]);
        return ['saved' => $saved, 'renamed' => $renamed, 'errors' => $errors];
    }

    /**
     * Neue Namen der Demo-Gebiete an echten Verweisen nachziehen (Core\Blueprint\References): Links auf den Eintrag (Linktext, Adresse),
     * Linkfelder mit Beschriftung, Werte genau gleich dem Namen – in Seiten und in den Einträgen der Tabelle. Fließtext bleibt.
     * @param list<array> $refs @param list<string> $skip Felder, die nicht angefasst werden (Titel, Flächen)
     */
    private static function relink(array $t, array $refs, array $skip): void
    {
        if (!class_exists(\Core\Blueprint\References::class)) return;
        if (method_exists(\Core\Blueprint\References::class, 'renameTasks')) \Core\Blueprint\References::renameTasks($refs);   // Aufgaben „Text anpassen: …“
        $db = app()->db;
        foreach ($db->fetchAll('SELECT id, title, nav_title, content_draft, content_published FROM pages') as $p) {
            $upd = [];
            foreach (['title', 'nav_title'] as $c) if (is_string($p[$c]) && ($n = \Core\Blueprint\References::text($p[$c], $refs)) !== $p[$c]) $upd[$c] = $n;
            foreach (['content_draft', 'content_published'] as $c) {
                $d = json_decode((string) $p[$c], true);
                if (!is_array($d)) continue;
                $new = \Core\Blueprint\References::apply($d, $refs);
                if ($new !== $d) $upd[$c] = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($upd) $db->update('pages', $upd, 'id = ?', [(int) $p['id']]);
        }
        foreach (Entries::query($t, ['limit' => 500]) as $e) {
            $in = [];
            foreach (Tables::dataFields($t['fields']) as $f) {
                $c = (string) $f['name'];
                if (in_array($c, $skip, true) || !is_string($e[$c] ?? null) || $e[$c] === '') continue;
                $d = str_starts_with(ltrim($e[$c]), '[') || str_starts_with(ltrim($e[$c]), '{') ? json_decode($e[$c], true) : null;
                $n = is_array($d) ? (string) json_encode(\Core\Blueprint\References::apply($d, $refs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : \Core\Blueprint\References::text($e[$c], $refs);
                if (is_array($d) ? \Core\Blueprint\References::apply($d, $refs) !== $d : $n !== $e[$c]) $in[$c] = $n;
            }
            if ($in) Entries::save($t, (int) $e['id'], $in + ['status' => $e['status']], __('Gebietskarte: Verweise auf umbenannte Gebiete'));
        }
    }

    /**
     * Beispiel-Flächen über das Land verteilt: $n Bänder von Nord nach Süd (nach der Breite der Beschriftungspunkte), je Band die Fläche
     * nahe der Bandmitte und der Mitte des Landes, dazu Nachbarn im selben Band – nicht angrenzend an Flächen anderer Gebiete, soweit möglich.
     * @param array<string, array> $units @return list<list<string>> je Gebiet (Nord zuerst)
     */
    public static function spread(array $units, int $n, int $per = self::EXAMPLE_UNITS): array
    {
        $ids = array_values(array_filter(array_map('strval', array_keys($units)), fn($u) => (float) ($units[$u]['y'] ?? 0) !== 0.0));
        if ($n <= 0 || !$ids) return [];
        usort($ids, fn($a, $b) => (float) $units[$b]['y'] <=> (float) $units[$a]['y'] ?: strcmp($a, $b));
        $adj = Render::neighbors(array_combine($ids, $ids), $units);
        $cx = array_sum(array_map(fn($u) => (float) $units[$u]['x'], $ids)) / count($ids);
        $taken = [];   // Fläche → Gebiet (Index)
        $out = [];
        $len = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $slice = array_slice($ids, (int) floor($i * $len / $n), max(1, (int) floor(($i + 1) * $len / $n) - (int) floor($i * $len / $n)));
            $my = array_sum(array_map(fn($u) => (float) $units[$u]['y'], $slice)) / count($slice);
            $touchesOther = fn(string $u) => (bool) array_filter($adj[$u] ?? [], fn($v) => isset($taken[$v]) && $taken[$v] !== $i);
            $score = fn(string $u) => abs((float) $units[$u]['y'] - $my) + 0.5 * abs((float) $units[$u]['x'] - $cx) + ($touchesOther($u) ? 10 : 0);
            $cand = array_values(array_filter($slice, fn($u) => !isset($taken[$u])));
            if (!$cand) { $out[] = []; continue; }
            usort($cand, fn($a, $b) => $score($a) <=> $score($b));
            $pick = [$cand[0]];
            $taken[$cand[0]] = $i;
            while (count($pick) < $per) {
                $next = array_values(array_filter($cand, fn($u) => !isset($taken[$u]) && array_intersect($adj[$u] ?? [], $pick)));
                if (!$next) $next = array_values(array_filter($cand, fn($u) => !isset($taken[$u])));
                if (!$next) break;
                usort($next, fn($a, $b) => ($touchesOther($a) <=> $touchesOther($b)) ?: ($score($a) <=> $score($b)));
                $pick[] = $next[0];
                $taken[$next[0]] = $i;
            }
            $out[] = $pick;
        }
        return $out;
    }

    /** Reihenfolge der Gebiete Nord → Süd: nach dem neutralen Namen (Nord, Mitte, Süd, Nummer), sonst wie gespeichert @param list<array> $regions */
    private static function bandOrder(array $regions): array
    {
        $rank = function (array $r): array {
            if (preg_match('~\b(Nord|Mitte|Süd)(?: (\d+))?$~u', (string) $r['name'], $m)) return [array_search($m[1], self::BANDS, true), (int) ($m[2] ?? 0)];
            return [9, 0];
        };
        $idx = array_keys($regions);
        usort($idx, fn($a, $b) => $rank($regions[$a]) <=> $rank($regions[$b]) ?: $a <=> $b);
        return array_map(fn($i) => $regions[$i], $idx);
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
            $unitsAll = Bases::units($map['base'], $map['level'], $map['scope'])['units'];
            // Beispiel-Flächen über das Land verteilt, passend zu den Namen Nord/Mitte/Süd
            $regions = self::bandOrder(Bound::regionsOf($map));
            $spread = self::spread($unitsAll, count($regions));
            $by = [];
            foreach ($regions as $i => $r) $by[$r['id']] = $spread[$i] ?? [];
            // Beschriftungen der Quelle (Text, Lage) passen nicht zu den neuen Flächen – Standardbeschriftung
            app()->db->update(Repo::MAPS, ['labels' => '{}'], 'id = ?', [(int) $map['id']]);
            if ($map['source'] === 'table') {
                // Einträge: Feld mit den Flächen ersetzen (die Kreise der Quell-Region fallen weg), Demo-Einträge umbenennen
                $res = self::tableRegion($map, $by);
                foreach ($res['errors'] as $err) $lines[] = '! ' . $err;
                $n = $res['saved'];
            } else {
                Repo::saveAssignment($map['id'], $by);
                // eigene Gebiete: neutrale Namen aus der Vorlage („Gebiet Nord“) → erster Beispiel-Kreis
                foreach ($regions as $r) {
                    $first = $by[$r['id']][0] ?? null;
                    if ($first !== null && isset($unitsAll[$first]) && preg_match('~^' . preg_quote(__('Gebiet'), '~') . ' (Nord|Mitte|Süd)( \d+)?$~u', (string) $r['name'])) {
                        app()->db->update(Repo::REGIONS, ['name' => trim(explode(',', (string) $unitsAll[$first]['name'])[0])], 'id = ?', [(int) $r['id']]);
                    }
                }
                $n = count($by);
            }
            Render::flushCache();
            $lines[] = __('Gebietskarte „{title}“: Ausschnitt {region}, {n} Gebiete mit Beispiel-Flächen.', ['title' => $map['title'], 'region' => (string) ($ctx['region'] ?? $code), 'n' => $n]);
            $lines[] = $task;
        }
        \Core\PageCache::clear();
        return $lines;
    }
}
