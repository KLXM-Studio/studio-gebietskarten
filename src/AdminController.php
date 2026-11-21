<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Gebietskarten;

use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Verwaltung → Gebietskarten (Recht gebietskarten.edit; Anmeldung, Recht und CSRF prüft der Router):
 * Karten anlegen, Ebene und Ausschnitt wählen, Gebiete bilden (Klick auf die Karte oder Liste), Angaben je Gebiet,
 * Vorschau hell/dunkel, Kartendaten nachladen, Export als SVG in die Mediathek bzw. den Grafik-Editor.
 */
final class AdminController extends \Core\Http\Controllers\Admin\AdminController
{
    private function guard(): void
    {
        if (!Gebietskarten::on()) throw new HttpException(404);
    }

    private function mapOr404(string $id): array
    {
        $this->guard();
        $m = Repo::map((int) $id);
        if (!$m) throw new HttpException(404);
        return $m;
    }

    private function to(string $path, string $type, string $msg): Response
    {
        app()->session->flash($type, $msg);
        return Response::redirect(url($path), 303);
    }

    public function index(Request $r): Response
    {
        $this->guard();
        $maps = [];
        foreach (Repo::maps() as $m) $maps[] = $m + ['count' => count(Bound::regionsOf($m))];
        return Gebietskarten::page('index', ['maps' => $maps, 'packs' => DataPacks::installed()]);
    }

    public function create(Request $r): Response
    {
        $this->guard();
        $in = (array) ($r->post['m'] ?? []);
        $id = Repo::saveMap(null, $in + ['options' => Repo::OPTIONS]);
        // Eigene Karte: gleich ein erstes Gebiet, damit Klicks auf die Karte sofort etwas zuordnen
        if (($in['source'] ?? 'own') !== 'table') Repo::saveRegion($id, null, ['name' => __('Gebiet 1')]);
        return $this->to(Gebietskarten::ADMIN . '/' . $id, 'success', __('Karte angelegt. Wählen Sie ein Gebiet und klicken Sie auf die Flächen.'));
    }

    public function edit(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        $regions = Bound::regionsOf($m);
        $geo = Render::geometry($m, $regions, true);
        $table = $m['source'] === 'table' ? Bound::table($m) : null;
        $edit = (int) $r->str('gebiet');
        // Datentabelle: Zuordnung per Klick nur mit Recht auf die Tabelle und schreibbarem Feld; je Eintrag die Regeln des Cores
        $assignReason = $m['source'] === 'table' ? EntryAssign::mapReason($table, $m) : null;
        $locked = [];
        if ($table && $assignReason === null) {
            foreach ($regions as $reg) {
                $e = \Core\Data\Entries::find($table, (int) $reg['id']);
                $why = $e ? EntryAssign::reason($table, $e) : __('Eintrag nicht gefunden.');
                if ($why !== null) $locked[(int) $reg['id']] = $why;
            }
        }
        return Gebietskarten::page('edit', ['map' => $m, 'regions' => $regions, 'geo' => $geo, 'table' => $table, 'editRegion' => $edit,
            'tables' => self::tableOptions(), 'preview' => Render::html($m, ['preview' => true, 'assets' => false]),
            'kitLight' => Preview::kit(false), 'kitDark' => Preview::kit(true), 'darkOn' => Preview::darkOn(),
            'assignReason' => $assignReason, 'locked' => $locked]);
    }

    /** Datentabellen für die Bindung (ohne Eingangs-Tabellen) */
    private static function tableOptions(): array
    {
        $o = ['' => '–'];
        foreach (\Core\Data\Tables::content() as $t) $o[$t['handle']] = $t['name'] . (\Core\Data\Tables::isShared($t) ? ' · ' . __('geteilt') : '');
        return $o;
    }

    public function save(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        $in = (array) ($r->post['m'] ?? []);
        foreach (array_keys(Repo::OPTIONS) as $k) {
            if (is_bool(Repo::OPTIONS[$k])) $in['options'][$k] = !empty($in['options'][$k]);
        }
        $in['scope'] = (array) ($in['scope'] ?? []);
        $in['binding'] = (array) ($in['binding'] ?? []) + $m['binding'];
        Repo::saveMap($m['id'], $in);
        return $this->to(Gebietskarten::ADMIN . '/' . $m['id'], 'success', __('Karte gespeichert.'));
    }

    /**
     * Zuordnung speichern: JSON {"regions": {"12": ["05170", …]}} (Editor) bzw. Formular units[12] = "05170, 05154".
     * Eigene Karte: Gebiete dieser Erweiterung. Datentabelle: das Feld mit den Flächen in den Einträgen (EntryAssign – Rechte,
     * Versionen und Protokoll des Cores); nicht änderbare Einträge melden einen Grund.
     */
    public function assign(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        $by = [];
        foreach ((array) ($r->post['regions'] ?? $r->post['units'] ?? []) as $rid => $list) $by[(int) $rid] = Repo::unitList(is_array($list) ? $list : (string) $list);
        $json = $r->wantsJson() || str_contains((string) ($r->server['CONTENT_TYPE'] ?? ''), 'json');
        if ($m['source'] === 'table') {
            $res = EntryAssign::save($m, $by);
            $m = (array) Repo::map($m['id']);
            $geo = Render::geometry($m, Bound::regionsOf($m), true);
            $msg = $res['errors'] ? __('{n} Einträge gespeichert, nicht gespeichert: {list}', ['n' => $res['saved'], 'list' => implode(' · ', $res['errors'])])
                : ($res['saved'] === 1 ? __('1 Eintrag gespeichert.') : __('{n} Einträge gespeichert.', ['n' => $res['saved']]));
            if ($json) return Response::json(['ok' => !$res['errors'], 'saved' => $res['saved'], 'errors' => array_values($res['errors']), 'message' => $msg,
                'owner' => (object) $geo['owner'], 'preview' => Render::html($m, ['preview' => true, 'assets' => false])]);
            return $this->to(Gebietskarten::ADMIN . '/' . $m['id'], $res['errors'] ? 'error' : 'success', $msg);
        }
        Repo::saveAssignment($m['id'], $by);
        if ($json) {
            $m = (array) Repo::map($m['id']);
            return Response::json(['ok' => true, 'message' => __('Zuordnung gespeichert.'), 'preview' => Render::html($m, ['preview' => true, 'assets' => false])]);
        }
        return $this->to(Gebietskarten::ADMIN . '/' . $m['id'], 'success', __('Zuordnung gespeichert.'));
    }

    public function saveRegion(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        if ($m['source'] === 'table') throw new HttpException(422);
        $in = (array) ($r->post['g'] ?? []);
        $rid = (int) ($in['id'] ?? 0);
        if ($rid && !($reg = Repo::region($rid)) || ($rid && $reg['map_id'] !== $m['id'])) throw new HttpException(404);
        if (($in['color'] ?? '') === 'own') $in['color'] = (string) ($in['own_color'] ?? '');
        else $in['color_dark'] = '';
        // Link über die Core-Linkauswahl (Fields, Typ link): page:ID, entry:…, /pfad oder https://…
        [$vals, $errs] = \Core\Fields::sanitize([['name' => 'link', 'type' => 'link'], ['name' => 'text', 'type' => 'richtext'], ['name' => 'media_id', 'type' => 'media']], $in);
        if (isset($errs['link'])) $vals['link'] = '';
        $in = $vals + $in;
        if (!isset($in['units']) && $rid) $in['units'] = Repo::region($rid)['units'] ?? [];
        $new = Repo::saveRegion($m['id'], $rid ?: null, $in);
        return $this->to(Gebietskarten::ADMIN . '/' . $m['id'] . '?gebiet=' . $new . '#gk-gebiete', 'success', $rid ? __('Gebiet gespeichert.') : __('Gebiet angelegt.'));
    }

    public function deleteRegion(Request $r, string $id, string $rid): Response
    {
        $m = $this->mapOr404($id);
        Repo::deleteRegion($m['id'], (int) $rid);
        return $this->to(Gebietskarten::ADMIN . '/' . $m['id'] . '#gk-gebiete', 'success', __('Gebiet gelöscht.'));
    }

    /** Reihenfolge der Gebiete (Ziehen, Auf/Ab-Knöpfe, Alt+Pfeil): ids[] in neuer Folge. JSON: neue Farben (Palette folgt der Position) und Vorschau */
    public function reorder(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        if ($m['source'] === 'table') return Response::fail(__('Die Reihenfolge der Gebiete aus einer Datentabelle folgt der Tabelle.'), 422);
        Repo::reorder($m['id'], array_map('intval', (array) ($r->post['ids'] ?? [])));
        if (!$r->wantsJson() && !str_contains((string) ($r->server['CONTENT_TYPE'] ?? ''), 'json')) {
            return $this->to(Gebietskarten::ADMIN . '/' . $m['id'] . '#gk-gebiete', 'success', __('Reihenfolge gespeichert.'));
        }
        $regions = Repo::regions($m['id']);
        $kit = Preview::kit(false);
        $colors = [];
        $effs = Colors::assign($regions, (array) (Render::geometry($m, $regions)['neighbors'] ?? []));
        foreach ($regions as $reg) $colors[(string) $reg['id']] = Colors::approx($effs[$reg['key']], $kit);
        return Response::json(['ok' => true, 'order' => array_map(fn($x) => $x['id'], $regions), 'colors' => (object) $colors,
            'preview' => Render::html($m, ['preview' => true, 'assets' => false])]);
    }

    public function delete(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        Repo::deleteMap($m['id']);
        return $this->to(Gebietskarten::ADMIN, 'success', __('Karte „{title}“ gelöscht.', ['title' => $m['title']]));
    }

    /** Vorschau wie auf der Website (Fragment), z. B. nach Änderungen im Editor */
    public function preview(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        return Response::json(['ok' => true, 'html' => Render::html($m, ['preview' => true, 'assets' => false])]);
    }

    /** Karte als bereinigtes SVG in die Mediathek – mit Grafik-Editor gleich dort weiterbearbeiten */
    public function toGraphic(Request $r, string $id): Response
    {
        $m = $this->mapOr404($id);
        if (!can('media.upload')) throw new HttpException(403);
        $svg = Render::svg($m, Preview::kit(false));
        $tmp = tempnam(sys_get_temp_dir(), 'gk');
        if ($tmp === false || file_put_contents($tmp, $svg) === false) return $this->to(Gebietskarten::ADMIN . '/' . $m['id'], 'error', __('Datei nicht speicherbar.'));
        [$media, $err] = \Core\Media::import($tmp, \Core\Media::fileSlug($m['title']) . '.svg', __('Karte: {title}', ['title' => $m['title']]),
            ['title' => $m['title'], 'credit' => Render::sourceText($m), 'tags' => 'gebietskarte']);
        @unlink($tmp);
        if (!$media) return $this->to(Gebietskarten::ADMIN . '/' . $m['id'], 'error', (string) $err);
        if (Gebietskarten::grafikActive()) return Response::redirect(url('/admin/grafik/editor?media=' . (int) $media['id']), 303);
        return $this->to('/admin/media?id=' . (int) $media['id'], 'success', __('Karte als SVG in der Mediathek abgelegt.'));
    }

    public function data(Request $r): Response
    {
        $this->guard();
        return Gebietskarten::page('data', ['packs' => DataPacks::installed(), 'source' => DataPacks::source(),
            'needed' => self::neededParts()]);
    }

    /** Länder, die Karten mit Ebene Gemeinden brauchen (leerer Ausschnitt = alle). @return list<string> */
    private static function neededParts(): array
    {
        $need = [];
        foreach (Repo::maps() as $m) {
            if ($m['level'] !== 'gemeinden') continue;
            foreach ($m['scope'] ?: array_keys(Bases::LAENDER) as $sn) $need[(string) $sn] = true;
        }
        return array_map('strval', array_keys($need));
    }

    public function dataInstall(Request $r): Response
    {
        $this->guard();
        // Kartendaten gelten für die ganze Installation – nur, wer die Funktionen verwalten darf (oder die Konsole)
        if (!can('settings.edit') && !can(Gebietskarten::PERM)) throw new HttpException(403);
        $parts = Bases::cleanScope('de', (array) ($r->post['parts'] ?? []));
        if (($r->post['do'] ?? '') === 'remove') {
            $n = DataPacks::remove($parts);
            return $this->to(Gebietskarten::ADMIN . '/kartendaten', 'success', __('{n} Datei(en) entfernt.', ['n' => $n]));
        }
        if (!$parts) return $this->to(Gebietskarten::ADMIN . '/kartendaten', 'error', __('Bitte mindestens ein Land wählen.'));
        try {
            $res = DataPacks::install($parts);
        } catch (\Throwable $e) {
            return $this->to(Gebietskarten::ADMIN . '/kartendaten', 'error', $e->getMessage());
        }
        if ($res['failed']) {
            $list = [];
            foreach ($res['failed'] as $sn => $msg) $list[] = (Bases::LAENDER[$sn] ?? $sn) . ': ' . $msg;
            return $this->to(Gebietskarten::ADMIN . '/kartendaten', 'error', __('Nicht geladen: {list}', ['list' => implode('; ', $list)]));
        }
        return $this->to(Gebietskarten::ADMIN . '/kartendaten', 'success', __('{n} Land/Länder geladen ({size}).', ['n' => count($res['ok']), 'size' => fmt()->bytes($res['bytes'])]));
    }
}
