<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

/**
 * Kartengrundlagen: woher die Flächen kommen, welche Ebenen es gibt, wie sie heißen und welcher Quellenvermerk gilt.
 * Phase 1: Deutschland (BKG VG250) mit Ländern und Kreisen im Paket (data/de/) und Gemeinden je Land als Datenpaket zum
 * Nachladen (DataPacks, storage/gebietskarten/de/gemeinden/). Weitere Grundlagen (Welt, andere Länder) kommen hier dazu –
 * jede mit eigener Quelle, Lizenz und eigenem Vermerk.
 */
final class Bases
{
    public const LAENDER = ['01' => 'Schleswig-Holstein', '02' => 'Hamburg', '03' => 'Niedersachsen', '04' => 'Bremen',
        '05' => 'Nordrhein-Westfalen', '06' => 'Hessen', '07' => 'Rheinland-Pfalz', '08' => 'Baden-Württemberg', '09' => 'Bayern',
        '10' => 'Saarland', '11' => 'Berlin', '12' => 'Brandenburg', '13' => 'Mecklenburg-Vorpommern', '14' => 'Sachsen',
        '15' => 'Sachsen-Anhalt', '16' => 'Thüringen'];

    /** Teile: Schlüssel wie „10“ werden in PHP zu Zahlen. @return array<string, array{label: string, levels: array<string, string>, parts: array<int|string, string>, partLabel: string}> */
    public static function all(): array
    {
        return [
            'de' => ['label' => __('Deutschland'), 'partLabel' => __('Bundesländer im Ausschnitt'), 'parts' => self::LAENDER,
                'levels' => ['laender' => __('Bundesländer'), 'kreise' => __('Kreise und kreisfreie Städte'), 'gemeinden' => __('Gemeinden')]],
        ];
    }

    public static function valid(string $base, string $level): bool
    {
        return isset(self::all()[$base]['levels'][$level]);
    }

    public static function levelLabel(string $base, string $level): string
    {
        return self::all()[$base]['levels'][$level] ?? $level;
    }

    /** Bezeichnung einer Einheit im Singular (für Listen und zugängliche Namen) */
    public static function unitNoun(string $base, string $level, bool $plural = false): string
    {
        return match ($level) {
            'laender' => $plural ? lt('Bundesländer') : lt('Bundesland'),
            'kreise' => $plural ? lt('Kreise') : lt('Kreis'),
            'gemeinden' => $plural ? lt('Gemeinden') : lt('Gemeinde'),
            default => $plural ? lt('Flächen') : lt('Fläche'),
        };
    }

    /** Teile (Länder) einer Grundlage, auf die der Ausschnitt begrenzt ist – unbekannte fallen weg. @return list<string> */
    public static function cleanScope(string $base, array|string $scope): array
    {
        $list = is_array($scope) ? $scope : preg_split('~[\s,;]+~', $scope, -1, PREG_SPLIT_NO_EMPTY);
        $parts = self::all()[$base]['parts'] ?? [];
        $out = array_values(array_unique(array_filter(array_map('strval', (array) $list), fn($p) => isset($parts[$p]))));
        sort($out);
        return $out;
    }

    /**
     * Dateien für Ebene und Ausschnitt. Gemeinden: nur die nachgeladenen Länder (fehlende in $missing).
     * @param list<string> $scope
     * @param list<string> $missing
     * @return list<string>
     */
    public static function files(string $base, string $level, array $scope, array &$missing = []): array
    {
        $missing = [];
        if ($base !== 'de') return [];
        if ($level !== 'gemeinden') {
            $f = Gebietskarten::dir() . '/data/de/' . $level . '.topo.json';
            return is_file($f) ? [$f] : [];
        }
        $out = [];
        foreach ($scope ?: array_map('strval', array_keys(self::LAENDER)) as $sn) {
            $sn = str_pad((string) $sn, 2, '0', STR_PAD_LEFT);
            $f = DataPacks::file('de', 'gemeinden', $sn);
            if (is_file($f)) $out[] = $f;
            else $missing[] = $sn;
        }
        return $out;
    }

    /**
     * Einheiten einer Karte (Ebene + Ausschnitt).
     * @param list<string> $scope
     * @return array{units: array<string, array>, arcs: array<int, list<array{0: float, 1: float}>>, meta: array, missing: list<string>}
     */
    public static function units(string $base, string $level, array $scope): array
    {
        $missing = [];
        $files = self::files($base, $level, $scope, $missing);
        $set = $scope ? array_flip($scope) : null;
        $d = Topo::collect($files, $set === null ? null : fn(array $u): bool => isset($set[$u['l']]));
        return $d + ['missing' => $missing];
    }

    /** Stand der Daten (Jahr) einer Ebene – für den Quellenvermerk */
    public static function meta(string $base, string $level): array
    {
        $files = self::files($base, $level, []);
        if (!$files) return [];
        try {
            return Topo::load($files[0])['meta'];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Quellenvermerk unter jeder Karte (Pflicht laut Datenlizenz Deutschland – Namensnennung 2.0 und den Nutzungsbedingungen des BKG
     * für VG250, Stand 19.08.2025): „© BKG (Jahr des letzten Datenbezugs) dl-de/by-2-0, Datenquellen: …“ – im Web „BKG“ und
     * „dl-de/by-2-0“ verlinkt – plus Veränderungshinweis (vereinfacht).
     * @return array{provider: string, providerUrl: string, year: string, license: string, url: string, sources: string, changed: string, text: string}
     */
    public static function attribution(string $base, string $level): array
    {
        $m = self::meta($base, $level);
        $year = preg_match('~^\d{4}$~', (string) ($m['jahr'] ?? '')) ? (string) $m['jahr'] : (preg_match('~\((\d{4})\)~', (string) ($m['vermerk'] ?? ''), $y) ? $y[1] : date('Y'));
        $a = [
            'provider' => (string) ($m['anbieter'] ?? 'BKG'), 'providerUrl' => (string) ($m['anbieter_url'] ?? 'https://www.bkg.bund.de'), 'year' => $year,
            'license' => (string) ($m['lizenz_kurz'] ?? 'dl-de/by-2-0'), 'url' => (string) ($m['lizenz_url'] ?? 'https://www.govdata.de/dl-de/by-2-0'),
            'sources' => (string) ($m['datenquellen_url'] ?? 'https://sgx.geodatenzentrum.de/web_public/gdz/datenquellen/datenquellen_vg_nuts.pdf'),
            'changed' => (string) ($m['veraendert'] ?? 'vereinfacht'),
        ];
        $a['text'] = '© ' . $a['provider'] . ' (' . $year . ') ' . $a['license'];
        return $a;
    }
}
