<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
/**
 * Erweiterung „gebietskarten“: Flächen einer freien Kartengrundlage (Deutschland: Bundesländer, Kreise, Gemeinden aus
 * BKG VG250) zu Gebieten zusammenfassen – Regionalgruppen, Kreise eines Verbands, Vertriebsgebiete – mit Infos je Gebiet,
 * als zugängliches SVG ohne Kartenbibliothek (Block „Gebietskarte“). Gebiete pflegt man hier oder bindet eine Datentabelle an
 * (auch geteilte Tabellen im Netzwerk). Abgegrenzt von den Straßenkarten des Cores (Core\Maps, MapLibre): keine Kacheln,
 * keine Adressen, keine Zoomstufen – nur Flächen.
 *
 * Einschalten je Website: Administration → Funktionen & Erweiterungen oder 'extensions' => ['gebietskarten'].
 * Recht: „Gebietskarten bearbeiten“ (gebietskarten.edit). Kartendaten: Länder und Kreise im Paket (data/de/), Gemeinden je
 * Land zum Nachladen (php bin/console gebietskarten:daten). Doku: README.md, Handbuch und Entwicklerhandbuch → Gebietskarten.
 */
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Klxm\\Gebietskarten\\')) {
        $file = __DIR__ . '/src/' . substr($class, strlen('Klxm\\Gebietskarten\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

use Klxm\Gebietskarten\AdminController;
use Klxm\Gebietskarten\Gebietskarten as G;
use Klxm\Gebietskarten\Repo;

return [
    'name' => 'gebietskarten',
    'label' => 'Gebietskarten – Regionen, Kreise und Vertriebsgebiete auf freien Karten',
    'version' => '0.4.0',
    'requires' => '>=1.0.0',
    'description' => 'Flächen einer Kartengrundlage (Deutschland: Länder, Kreise, Gemeinden – BKG VG250) zu Gebieten zusammenfassen, je Gebiet Infos pflegen und als zugängliche Karte ausgeben. Gebiete auch aus einer Datentabelle.',
    'author' => 'KLXM Crossmedia GmbH and contributors',
    'license' => 'MIT',
    'provides' => ['Menüpunkt „Gebietskarten“ (Karten, Gebiete, Kartendaten)', 'Block „Gebietskarte“', 'Recht „Gebietskarten bearbeiten“',
        'Befehle gebietskarten:daten und gebietskarten:selftest'],
    'commands' => ['gebietskarten:daten', 'gebietskarten:selftest'],
    'usage' => function (): ?string {
        try {
            $c = Repo::counts();
        } catch (\Throwable) {
            return null;
        }
        return $c['maps'] ? __('{n} Karten', ['n' => $c['maps']]) . ' · ' . __('{n} Gebiete', ['n' => $c['regions']]) : null;
    },
    'boot' => function (Core\Extension $x): void {
        $x->feature(G::FEATURE, 'Gebietskarten: Regionen auf freien Karten', [G::PERM]);
        $x->permissions('Gebietskarten', [G::PERM => 'Gebietskarten anlegen und Gebiete zuordnen']);
        foreach (Repo::tables() as $name => $define) $x->table($name, $define);
        // Nachladbare Kartendaten (Gemeinden) und berechnete Geometrie nicht mitsichern – beides lässt sich neu erzeugen
        $x->backupSkip('gebietskarten', 'cache/gebietskarten');

        $on = G::on();
        if ($on) {
            $x->blocks([G::BLOCK => [
                'label' => __('Gebietskarte'), 'icon' => 'map-trifold', 'group' => 'Medien',
                'help' => __('Karte mit Gebieten (Regionen, Kreise, Vertriebsgebiete). Karten und Gebiete pflegen Sie unter Gebietskarten.'),
                'fields' => [
                    ['name' => 'map', 'label' => __('Karte'), 'type' => 'select', 'required' => true, 'options' => Repo::mapOptions(),
                        'help' => __('Neue Karten legen Sie unter Gebietskarten an.')],
                    ['name' => 'title', 'label' => __('Überschrift (optional)'), 'type' => 'text', 'max' => 160],
                    ['name' => 'mode', 'label' => __('Darstellung'), 'type' => 'select', 'required' => true, 'default' => 'overview', 'width' => 'half', 'options' => [
                        'overview' => __('Übersicht aller Gebiete'), 'highlight' => __('Ein Gebiet hervorheben'),
                        'current' => __('Gebiet dieser Seite hervorheben'), 'site' => __('Gebiet dieser Website hervorheben (Datentabelle)')]],
                    ['name' => 'action', 'label' => __('Klick auf ein Gebiet'), 'type' => 'select', 'required' => true, 'default' => 'link', 'width' => 'half', 'options' => [
                        'link' => __('Link des Gebiets öffnen'), 'info' => __('Info-Fenster zeigen'), 'none' => __('Nichts (nur ansehen)')]],
                    ['name' => 'highlight', 'label' => __('Hervorgehobenes Gebiet'), 'type' => 'select', 'options' => ['' => '–'] + Repo::regionOptions(),
                        'help' => __('Nur bei „Ein Gebiet hervorheben“. „Gebiet dieser Seite“ nimmt das Gebiet, dessen Link auf diese Seite oder eine übergeordnete zeigt.')],
                    ['name' => 'list', 'label' => __('Liste der Gebiete'), 'type' => 'select', 'default' => '', 'width' => 'half', 'options' => [
                        '' => __('wie bei der Karte eingestellt'), 'show' => __('unter der Karte zeigen'), 'hide' => __('nur für Screenreader')]],
                ]]], G::dir() . '/blocks');
        }

        $x->adminPage(['href' => G::ADMIN, 'label' => 'Gebietskarten', 'kind' => 'content', 'icon' => 'map-trifold', 'perm' => G::PERM,
            'feature' => G::FEATURE, 'description' => 'Gebiete auf Karten: Regionalgruppen, Kreise, Vertriebsgebiete']);
        // Editor: dazu das Skript der Website (Hover „animiert“ in der Vorschau)
        $x->adminAssets(fn(string $view): array => $view === G::NAME . '/edit' ? ['js/gebietskarten.js', 'js/gebietskarten-admin.js'] : []);

        $x->docs('manual', ['gebietskarten' => ['id' => 'gebietskarten', 'title' => 'Gebietskarten', 'after' => 'medien', 'file' => G::dir() . '/docs/manual.php']]);
        $x->docs('technical', ['gebietskarten' => ['title' => 'Gebietskarten (Erweiterung)', 'part' => 2, 'after' => 'medien', 'file' => G::dir() . '/docs/technical.php']]);

        $x->health(function (): array {
            if (!G::on()) return [];
            $out = [];
            foreach (Repo::maps() as $m) {
                if ($m['level'] !== 'gemeinden') continue;
                $miss = [];
                Klxm\Gebietskarten\Bases::files($m['base'], $m['level'], $m['scope'], $miss);
                if ($miss) $out[__('Gebietskarte „{title}“: Kartendaten der Gemeinden fehlen ({list}) – php bin/console gebietskarten:daten', ['title' => $m['title'], 'list' => implode(', ', $miss)])] = null;
            }
            return $out;
        });

        $x->routes(function (Core\Http\Router $r): void {
            $c = AdminController::class;
            $p = G::PERM;
            $r->get(G::ADMIN, [$c, 'index'], $p);
            $r->post(G::ADMIN . '/neu', [$c, 'create'], $p);
            $r->get(G::ADMIN . '/kartendaten', [$c, 'data'], $p);
            $r->post(G::ADMIN . '/kartendaten', [$c, 'dataInstall'], $p);
            $r->get(G::ADMIN . '/{id}', [$c, 'edit'], $p);
            $r->post(G::ADMIN . '/{id}', [$c, 'save'], $p);
            $r->post(G::ADMIN . '/{id}/zuordnung', [$c, 'assign'], $p);
            $r->post(G::ADMIN . '/{id}/gebiet', [$c, 'saveRegion'], $p);
            $r->post(G::ADMIN . '/{id}/gebiet/{rid}/loeschen', [$c, 'deleteRegion'], $p);
            $r->post(G::ADMIN . '/{id}/reihenfolge', [$c, 'reorder'], $p);
            $r->post(G::ADMIN . '/{id}/beschriftung', [$c, 'labels'], $p);
            $r->post(G::ADMIN . '/{id}/beschriftung/automatisch', [$c, 'labelsAuto'], $p);
            $r->post(G::ADMIN . '/{id}/darstellung', [$c, 'look'], $p);
            $r->post(G::ADMIN . '/{id}/loeschen', [$c, 'delete'], $p);
            $r->get(G::ADMIN . '/{id}/vorschau', [$c, 'preview'], $p);
            $r->post(G::ADMIN . '/{id}/grafik', [$c, 'toGraphic'], $p);
        });

        $x->command('gebietskarten:daten', 'Gebietskarten: Kartendaten der Gemeinden je Land nachladen (--land=05,06, --from=URL|Ordner, --list, --remove)',
            fn(array $args): int => Klxm\Gebietskarten\DataPacks::console($args));
        $x->command('gebietskarten:selftest', 'Gebietskarten: Verschmelzen, Zuordnung (auch in Einträge), Reihenfolge, Farben im Dunkeln, Beschriftungen, Darstellung, Ausgabe (CSP, Zugänglichkeit), Quellenvermerk',
            fn(array $args): int => Klxm\Gebietskarten\SelfTest::run());
    },
];
