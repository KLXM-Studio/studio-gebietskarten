<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Cli;
use Core\Http\Fetch;
use Core\Http\FetchException;

/**
 * Datenpakete zum Nachladen: Gemeinden je Bundesland (zusammen gut 6 MB, daher nicht im Paket). Einmal laden – Konsole
 * `php bin/console gebietskarten:daten` oder Knopf unter Gebietskarten → Kartendaten –, danach liegen sie lokal unter
 * storage/gebietskarten/de/gemeinden/ (für alle Websites der Installation). Besucher haben nie Kontakt zu Dritten.
 *
 * Quelle: Branch „data“ des Paket-Repositorys (manifest.json mit SHA-256 je Datei – jede Datei wird vor dem Speichern
 * geprüft). Eigene Quelle: Konfiguration 'gebietskarten' => ['data_url' => 'https://…/de/gemeinden/'] oder ein lokaler
 * Ordner (Entwicklung, Server ohne Zugang nach außen); einmalig auch `--from=…`.
 */
final class DataPacks
{
    public const SOURCE = 'https://raw.githubusercontent.com/KLXM-Studio/studio-gebietskarten/data/de/gemeinden/';
    private const MAX = 5_000_000;

    public static function dir(string $base = 'de', string $level = 'gemeinden'): string
    {
        return Gebietskarten::storage() . '/' . $base . '/' . $level;
    }

    public static function file(string $base, string $level, int|string $part): string
    {
        // Schlüssel wie „10“ werden in PHP-Arrays zu Zahlen – hier wieder zweistellig
        $part = is_int($part) ? str_pad((string) $part, 2, '0', STR_PAD_LEFT) : $part;
        return self::dir($base, $level) . '/' . preg_replace('~[^0-9a-z_-]~i', '', $part) . '.topo.json';
    }

    /** Eingestellte Quelle (Konfiguration) oder die des Pakets */
    public static function source(): string
    {
        $cfg = (array) (app()->config->get('gebietskarten') ?? []);
        $u = trim((string) ($cfg['data_url'] ?? ''));
        return $u !== '' ? $u : self::SOURCE;
    }

    /** Geladene Länder mit Größe und Stand. @return array<string, array{name: string, bytes: int, count: int, at: string}> */
    public static function installed(): array
    {
        $out = [];
        foreach (Bases::LAENDER as $sn => $name) {
            $f = self::file('de', 'gemeinden', $sn);
            if (!is_file($f)) continue;
            $count = 0;
            try {
                $count = count(Topo::load($f)['units']);
            } catch (\Throwable) {
            }
            $out[(string) $sn] = ['name' => $name, 'bytes' => (int) filesize($f), 'count' => $count, 'at' => date('Y-m-d H:i', (int) filemtime($f))];
        }
        return $out;
    }

    /** Datei der Quelle lesen: http(s) über Core\Http\Fetch (SSRF-Schutz, Größenlimit), sonst lokaler Ordner */
    private static function read(string $source, string $name): string
    {
        if (preg_match('~^https?://~i', $source)) {
            try {
                $r = Fetch::get(rtrim($source, '/') . '/' . rawurlencode($name), ['timeout' => 30, 'max' => self::MAX, 'kind' => 'json']);
            } catch (FetchException $e) {
                throw new \RuntimeException(__('Abruf fehlgeschlagen: {error}', ['error' => $e->getMessage()]));
            }
            if ($r['status'] !== 200) throw new \RuntimeException(__('Abruf fehlgeschlagen: HTTP {status}', ['status' => $r['status']]));
            return $r['body'];
        }
        $dir = realpath($source);
        $f = $dir ? realpath($dir . '/' . $name) : false;
        if (!$dir || !$f || !str_starts_with($f, $dir . DIRECTORY_SEPARATOR) || !is_file($f) || filesize($f) > self::MAX) {
            throw new \RuntimeException(__('Datei nicht gefunden: {file}', ['file' => $name]));
        }
        return (string) file_get_contents($f);
    }

    /** @return array{files: array<string, array{file: string, sha256: string, bytes: int, count: int, name: string}>, stand: string, vermerk: string} */
    public static function manifest(?string $source = null): array
    {
        $m = json_decode(self::read($source ?? self::source(), 'manifest.json'), true);
        if (!is_array($m) || !is_array($m['files'] ?? null)) throw new \RuntimeException(__('Verzeichnis der Kartendaten ist ungültig.'));
        return $m + ['stand' => '', 'vermerk' => ''];
    }

    /**
     * Länder laden (leer = alle). Jede Datei: SHA-256 laut Verzeichnis, gültige Topologie, nur Gemeinden dieses Landes.
     * @param list<string> $parts
     * @return array{ok: list<string>, failed: array<string, string>, bytes: int}
     */
    public static function install(array $parts = [], ?string $source = null): array
    {
        $source ??= self::source();
        $m = self::manifest($source);
        $parts = $parts ?: array_map(fn($k) => str_pad((string) $k, 2, '0', STR_PAD_LEFT), array_keys(Bases::LAENDER));
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException(__('Ordner nicht anlegbar: {dir}', ['dir' => $dir]));
        $ok = [];
        $failed = [];
        $bytes = 0;
        foreach ($parts as $sn) {
            $sn = str_pad((string) $sn, 2, '0', STR_PAD_LEFT);
            $info = $m['files'][$sn] ?? null;
            if (!isset(Bases::LAENDER[$sn]) || !is_array($info)) { $failed[$sn] = __('nicht im Verzeichnis'); continue; }
            try {
                $body = self::read($source, (string) $info['file']);
                if (!hash_equals((string) ($info['sha256'] ?? ''), hash('sha256', $body))) throw new \RuntimeException(__('Prüfsumme stimmt nicht'));
                $t = json_decode($body, true);
                if (!is_array($t) || ($t['type'] ?? '') !== 'Topology') throw new \RuntimeException(__('keine gültige Topologie'));
                $units = Topo::decode($t)['units'];
                foreach ($units as $u) {
                    if ($u['l'] !== $sn || !preg_match('~^\d{8}$~', $u['id'])) throw new \RuntimeException(__('unerwarteter Inhalt'));
                }
                $tmp = self::file('de', 'gemeinden', $sn) . '.tmp';
                if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, self::file('de', 'gemeinden', $sn))) {
                    @unlink($tmp);
                    throw new \RuntimeException(__('nicht speicherbar'));
                }
                $ok[] = $sn;
                $bytes += strlen($body);
            } catch (\Throwable $e) {
                $failed[$sn] = $e->getMessage();
            }
        }
        Topo::flush();
        Render::flushCache();
        return ['ok' => $ok, 'failed' => $failed, 'bytes' => $bytes];
    }

    /** @param list<string> $parts leer = alle */
    public static function remove(array $parts = []): int
    {
        $n = 0;
        foreach ($parts ?: array_keys(Bases::LAENDER) as $sn) {
            $f = self::file('de', 'gemeinden', (string) $sn);
            if (is_file($f) && @unlink($f)) $n++;
        }
        Topo::flush();
        Render::flushCache();
        return $n;
    }

    /** php bin/console gebietskarten:daten [--land=05,06] [--from=URL|Ordner] [--remove] [--list] */
    public static function console(array $args): int
    {
        $parts = Bases::cleanScope('de', (string) (Cli::opt($args, 'land') ?? ''));
        if (Cli::flag($args, 'list')) {
            $have = self::installed();
            foreach (Bases::LAENDER as $sn => $name) {
                printf("  %s %-24s %s\n", $sn, $name, isset($have[$sn]) ? sprintf('%d Gemeinden, %.0f KB, %s', $have[$sn]['count'], $have[$sn]['bytes'] / 1024, $have[$sn]['at']) : '–');
            }
            return 0;
        }
        if (Cli::flag($args, 'remove')) {
            echo self::remove($parts) . " Datei(en) entfernt.\n";
            return 0;
        }
        $from = Cli::opt($args, 'from');
        try {
            $r = self::install($parts, $from !== null && $from !== '' ? $from : null);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
        printf("Gemeinden: %d Land/Länder geladen (%.1f MB) – %s\n", count($r['ok']), $r['bytes'] / 1048576, self::dir());
        foreach ($r['failed'] as $sn => $msg) fwrite(STDERR, "  $sn: $msg\n");
        return $r['failed'] ? 1 : 0;
    }
}
