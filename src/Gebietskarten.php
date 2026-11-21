<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Http\Response;
use Core\Theme;

/** Erweiterung „gebietskarten“: Namen, Rechte, Pfade und die Seiten der Verwaltung im Layout des Cores. */
final class Gebietskarten
{
    public const NAME = 'gebietskarten';
    public const FEATURE = 'gebietskarten';
    public const PERM = 'gebietskarten.edit';
    public const ADMIN = '/admin/gebietskarten';
    public const BLOCK = 'gebietskarte';

    /** Ordner des Pakets */
    public static function dir(): string
    {
        return dirname(__DIR__);
    }

    /** Nachladbare Daten der Installation (für alle Websites gemeinsam): storage/gebietskarten */
    public static function storage(): string
    {
        return ROOT . '/storage/gebietskarten';
    }

    /** Zwischenspeicher der berechneten Geometrie (abgeleitet, nicht in der Sicherung) */
    public static function cacheDir(): string
    {
        return ROOT . '/storage/cache/gebietskarten';
    }

    /** Öffentliche Adresse einer gebauten Datei (public/assets/ext/gebietskarten/…) */
    public static function asset(string $path): string
    {
        $x = \Core\Extensions::active()[self::NAME] ?? null;
        return $x ? $x->asset($path) : '';
    }

    public static function on(): bool
    {
        return \Core\Extensions::isActive(self::NAME) && \Core\Features::on(self::FEATURE);
    }

    /** Ist der Grafik-Editor (Paket klxm/studio-grafik) auf dieser Website aktiv? Keine harte Abhängigkeit. */
    public static function grafikActive(): bool
    {
        return \Core\Extensions::isActive('grafik') && can('media.upload');
    }

    /** Seite der Verwaltung: Ansicht aus views/admin im Layout des Cores */
    public static function page(string $view, array $vars = [], int $status = 200): Response
    {
        if (!array_key_exists('user', $vars)) $vars['user'] = app()->auth->user();
        $vars['flash'] = app()->session->takeFlash();
        $head = '<link rel="stylesheet" href="' . e(self::asset('css/gebietskarten.css')) . '">'
            . '<link rel="stylesheet" href="' . e(self::asset('css/gebietskarten-admin.css')) . '">';
        $content = $head . Theme::capture(self::dir() . '/views/admin/' . $view . '.php', $vars);
        if (!isset($vars['title']) && preg_match('~<h1[^>]*>(.*?)</h1>~s', $content, $m)) {
            $vars['title'] = trim((string) preg_replace('~\s+~', ' ', html_entity_decode(strip_tags($m[1]))));
        }
        $html = Theme::capture(ROOT . '/app/Admin/views/layout.php', $vars + ['content' => $content, 'view' => self::NAME . '/' . $view]);
        return \Core\Http\Controllers\Admin\AdminController::secure(new Response($html, $status));
    }
}
