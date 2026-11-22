<?php
/**
 * Karte bearbeiten: Gebiete bilden (Klick auf die Karte, Liste mit Suche – eigene Gebiete oder Einträge einer Datentabelle),
 * Reihenfolge (Ziehen, Auf/Ab, Alt+Pfeil), Angaben je Gebiet, Beschriftungen, Darstellung mit Vorschau hell/dunkel (Hover,
 * Schatten, automatische Farben, Stil der Beschriftungen – seit 0.4.0), Einstellungen.
 * @var array $map  @var list<array> $regions  @var array $geo  @var ?array $table  @var int $editRegion  @var array $tables
 * @var string $preview  @var array $kitLight  @var array $kitDark  @var bool $darkOn  @var ?string $assignReason
 * @var array<int, string> $locked  @var array $flash
 * @var string $labelBox  @var array $labelInfo  @var bool $labelBound  @var array<string, string> $textLock
 */
use Core\Fields;
use Klxm\Gebietskarten\Bases;
use Klxm\Gebietskarten\Bound;
use Klxm\Gebietskarten\Colors;
use Klxm\Gebietskarten\Gebietskarten as G;
use Klxm\Gebietskarten\Labels;
use Klxm\Gebietskarten\Preview;

$base = Bases::all()[$map['base']] ?? Bases::all()['de'];
$own = $map['source'] !== 'table';
// per Klick zuordnen: eigene Karte immer, Datentabelle mit Recht auf die Tabelle und schreibbarem Feld
$editable = $own || ($table && $assignReason === null);
$noun = Bases::unitNoun($map['base'], $map['level']);
$nouns = Bases::unitNoun($map['base'], $map['level'], true);
$adm = G::ADMIN . '/' . $map['id'];
$effs = Colors::assign($regions, (array) ($geo['neighbors'] ?? []), (string) $map['options']['palette']);
$color = fn(array $r): string => Colors::approx($effs[(string) $r['key']], $kitLight);
$byKey = array_column($regions, null, 'key');
$free = array_values(array_filter($regions, fn($r) => !isset($locked[(int) $r['id']])));
$pick = $editRegion && isset($byKey[($own ? 'r' : 'e') . $editRegion]) && !isset($locked[$editRegion]) ? $editRegion : 0;

$actions = '<a class="adm-btn adm-btn--ghost" href="' . e(url(G::ADMIN)) . '">' . icon('arrow-left') . ' ' . e(__('Alle Karten')) . '</a>';
if (can('media.upload')) {
    $actions .= '<form method="post" action="' . e(url($adm . '/grafik')) . '">' . csrf_field() . '<button class="adm-btn adm-btn--ghost">' . icon('pen-nib')
        . ' ' . e(G::grafikActive() ? __('Als Grafik weiterbearbeiten') : __('Als SVG in die Mediathek')) . '</button></form>';
}
$scopeText = $map['scope'] ? implode(', ', array_map(fn($s) => $base['parts'][$s] ?? $s, $map['scope'])) : $base['label'];
echo \Core\Theme::capture(__DIR__ . '/_head.php', ['h1' => $map['title'], 'flash' => $flash, 'actions' => $actions,
    'lead' => Bases::levelLabel($map['base'], $map['level']) . ' · ' . $scopeText . ' · ' . (count($regions) === 1 ? __('1 Gebiet') : __('{n} Gebiete', ['n' => count($regions)]))]);

$editorData = [
    'save' => url($adm . '/zuordnung'), 'sort' => url($adm . '/reihenfolge'), 'preview' => url($adm . '/vorschau'),
    'own' => $own, 'editable' => (bool) $editable,
    'regions' => array_map(fn($r) => ['id' => $r['id'], 'key' => $r['key'], 'name' => $r['name'], 'color' => $color($r), 'locked' => $locked[(int) $r['id']] ?? null], $regions),
    'owner' => (object) $geo['owner'], 'names' => (object) $geo['names'],
    't' => ['assigned' => __('{unit} → {region}'), 'released' => __('{unit} ist keinem Gebiet mehr zugeordnet'), 'saved' => __('Zuordnung gespeichert.'),
        'unsaved' => __('Nicht gespeicherte Änderungen'), 'error' => __('Speichern fehlgeschlagen.'), 'none' => __('kein Gebiet'), 'leave' => __('Änderungen verwerfen?'),
        'locked' => __('{unit} gehört zu „{region}“ – {reason}'), 'moved' => __('„{name}“ an Position {pos} von {n}'), 'sortError' => __('Reihenfolge nicht gespeichert.'),
        'saving' => __('Wird gespeichert …')],
];
?>
<?php if ($geo['missing']): ?>
<p class="adm-flash adm-flash--warning" role="status"><?= e(__('Kartendaten fehlen für: {list}.', ['list' => implode(', ', array_map(fn($s) => Bases::LAENDER[$s] ?? $s, $geo['missing']))])) ?>
  <a href="<?= e(url(G::ADMIN . '/kartendaten')) ?>"><?= e(__('Kartendaten laden')) ?></a></p>
<?php endif; ?>
<?php // „In deiner Nähe“ (Core): Orte unterhalb der Kreisebene (z. B. Gevelsberg) findet die Karte nur mit Gemeindedaten der Länder im Ausschnitt
$nearMissing = [];
if (($map['source'] ?? 'own') === 'table' && $map['level'] !== 'gemeinden') Bases::files($map['base'], 'gemeinden', (array) $map['scope'], $nearMissing);
if ($nearMissing && (can('settings.edit') || can(G::PERM))): ?>
<form class="adm-flash adm-flash--info" method="post" action="<?= e(url(G::ADMIN . '/kartendaten')) ?>">
  <?= csrf_field() ?><input type="hidden" name="do" value="install"><?php foreach ($nearMissing as $sn): ?><input type="hidden" name="parts[]" value="<?= e((string) $sn) ?>"><?php endforeach; ?>
  <p><?= e(__('Für Orte unterhalb der Kreisebene (z. B. „In deiner Nähe“ mit einem Gemeindenamen) Gemeindedaten laden: {list}.', ['list' => implode(', ', array_map(fn($s) => Bases::LAENDER[$s] ?? $s, $nearMissing))])) ?></p>
  <button class="adm-btn adm-btn--small" type="submit"><?= icon('download-simple') ?> <?= e(__('Gemeindedaten laden')) ?></button>
</form>
<?php endif; ?>
<?php if ($geo['unmatched']): ?>
<div class="adm-flash adm-flash--warning" role="status"><p><?= e(__('Einige Angaben passen zu keiner Fläche dieser Karte:')) ?></p><ul>
  <?php foreach ($geo['unmatched'] as $k => $list): ?><li><?= e(($byKey[$k]['name'] ?? $k) . ': ' . implode(', ', array_slice($list, 0, 12)) . (count($list) > 12 ? ' …' : '')) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<div class="gk-ed" id="gk-gebiete">
  <section class="adm-card gk-ed__map" aria-labelledby="gk-ed-h">
    <h2 id="gk-ed-h"><?= e($own ? __('Gebiete bilden') : __('Gebiete aus der Datentabelle')) ?></h2>
    <?php if ($editable && $free): ?>
    <div class="gk-ed__bar">
      <div class="f gk-ed__active"><label for="gk-active"><?= e(__('Zuordnen zu')) ?></label>
        <select id="gk-active" data-gk-active><?php foreach ($free as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $pick === $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <p class="adm-muted gk-ed__hint"><?= e(__('Klick auf eine Fläche ordnet sie dem gewählten Gebiet zu, ein zweiter Klick gibt sie wieder frei. Mit der Tastatur: Liste „{nouns}“ rechts.', ['nouns' => $nouns])) ?></p>
      <button type="button" class="adm-btn adm-btn--primary" data-gk-save disabled><?= e(__('Zuordnung speichern')) ?></button>
      <span class="gk-ed__status" data-gk-status role="status" aria-live="polite"></span>
    </div>
    <?php if (!$own): ?>
    <p class="adm-muted gk-ed__note"><?= icon('info') ?> <?= e(__('Speichern ändert das Feld „{field}“ der betroffenen Einträge – mit Version und Eintrag im Protokoll, wie beim Bearbeiten in der Datentabelle. Der Status der Einträge bleibt.', ['field' => $table ? \Core\Data\Tables::label((array) \Core\Data\Tables::field($table, (string) $map['binding']['units'])) : ''])) ?></p>
    <?php endif; ?>
    <?php elseif ($own): ?>
    <p class="adm-muted"><?= e(__('Legen Sie zuerst ein Gebiet an (rechts).')) ?></p>
    <?php elseif ($table && $assignReason !== null): ?>
    <p class="adm-muted gk-ed__note"><?= icon('lock') ?> <?= e(__('Zuordnung per Klick nicht möglich: {reason} Die Flächen pflegen Sie dann in den Einträgen.', ['reason' => $assignReason])) ?></p>
    <?php elseif ($table && $regions): ?>
    <p class="adm-muted gk-ed__note"><?= icon('lock') ?> <?= e(__('Keinen der Einträge darf Ihre Rolle ändern.')) ?></p>
    <?php endif; ?>
    <div class="gk-ed__canvas">
      <svg class="gk-edsvg<?= $editable && $free ? '' : ' is-readonly' ?>" viewBox="0 0 <?= (int) $geo['w'] ?> <?= (int) $geo['h'] ?>" aria-hidden="true" focusable="false" data-gk-svg>
        <?php foreach ($geo['units'] as $u => $d): $k = $geo['owner'][$u] ?? null; ?>
        <path d="<?= $d ?>" fill-rule="evenodd" data-u="<?= e($u) ?>" class="gk-u<?= $k ? '' : ' is-free' ?>"<?= $k && isset($byKey[$k]) ? ' style="fill:' . e($color($byKey[$k])) . '"' : '' ?>><title><?= e(($geo['names'][$u] ?? $u) . ' – ' . ($k && isset($byKey[$k]) ? $byKey[$k]['name'] : __('kein Gebiet'))) ?></title></path>
        <?php endforeach; ?>
      </svg>
    </div>
    <ul class="gk-ed__legend" aria-label="<?= e(__('Legende')) ?>" data-gk-legend>
      <?php foreach ($regions as $r): ?><li data-id="<?= (int) $r['id'] ?>"><span class="gk-ed__sw" data-gk-sw="<?= (int) $r['id'] ?>" style="background:<?= e($color($r)) ?>"></span><?= e($r['name']) ?> <span class="adm-muted" data-gk-count="<?= (int) $r['id'] ?>">(<?= count($geo['unitsOf'][$r['key']] ?? []) ?>)</span></li><?php endforeach; ?>
      <li><span class="gk-ed__sw is-free"></span><?= e(__('kein Gebiet')) ?></li>
    </ul>
  </section>

  <aside class="gk-ed__side">
    <?php if ($own): ?>
    <section class="adm-card">
      <h2 id="gk-regions-h"><?= e(__('Gebiete')) ?></h2>
      <?php if (count($regions) > 1): ?><p class="adm-muted f-help" id="gk-sort-help"><?= e(__('Reihenfolge: ziehen, mit den Pfeil-Knöpfen oder Alt+Pfeil auf/ab. Sie gilt für Liste und Legende auf der Website und für die automatischen Farben.')) ?></p><?php endif; ?>
      <ol class="gk-ed__regions" data-gk-sort aria-labelledby="gk-regions-h"<?= count($regions) > 1 ? ' aria-describedby="gk-sort-help"' : '' ?>>
        <?php foreach ($regions as $i => $r): ?>
        <li class="gk-ed__reg" data-id="<?= (int) $r['id'] ?>"<?= count($regions) > 1 ? ' draggable="true"' : '' ?>>
          <?php if (count($regions) > 1): ?><span class="gk-ed__grip" aria-hidden="true" title="<?= e(__('Ziehen zum Sortieren')) ?>"><?= icon('dots-six-vertical') ?></span><?php endif; ?>
          <span class="gk-ed__sw" data-gk-sw="<?= (int) $r['id'] ?>" style="background:<?= e($color($r)) ?>"></span>
          <span class="gk-ed__regname"><a href="<?= e(url($adm . '?gebiet=' . $r['id'] . '#gk-gebiet')) ?>" data-gk-reglink<?= $editRegion === $r['id'] ? ' aria-current="true"' : '' ?>><?= e($r['name']) ?></a>
          <span class="adm-muted">· <?= e(__('{n} {nouns}', ['n' => count($geo['unitsOf'][$r['key']] ?? []), 'nouns' => $nouns])) ?></span></span>
          <?php if (count($regions) > 1): ?>
          <span class="gk-ed__move">
            <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-gk-move="-1" aria-label="<?= e(__('„{name}“ nach oben', ['name' => $r['name']])) ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('arrow-up') ?></button>
            <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-gk-move="1" aria-label="<?= e(__('„{name}“ nach unten', ['name' => $r['name']])) ?>"<?= $i === count($regions) - 1 ? ' disabled' : '' ?>><?= icon('arrow-down') ?></button>
          </span>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
      <span class="adm-sr" data-gk-sort-status role="status" aria-live="polite"></span>
      <form method="post" action="<?= e(url($adm . '/gebiet')) ?>" class="gk-ed__new">
        <?= csrf_field() ?>
        <div class="f"><label for="gk-newname"><?= e(__('Neues Gebiet')) ?></label><input id="gk-newname" name="g[name]" maxlength="160" required placeholder="<?= e(__('Name, z. B. Region Nord')) ?>"></div>
        <button class="adm-btn adm-btn--small"><?= icon('plus') ?> <?= e(__('Anlegen')) ?></button>
      </form>
    </section>
    <?php else: ?>
    <section class="adm-card">
      <h2><?= e(__('Gebiete')) ?></h2>
      <?php if (!$table): ?>
      <p class="adm-muted"><?= e(__('Wählen Sie unten unter „Einstellungen der Karte“ eine Datentabelle und das Feld mit den Flächen.')) ?></p>
      <?php else: ?>
      <p class="adm-muted"><?= e(__('Ein veröffentlichter Eintrag der Tabelle „{table}“ = ein Gebiet. Die Flächen stehen im Feld „{field}“ (Schlüssel oder Namen, durch Komma getrennt).', ['table' => $table['name'], 'field' => $map['binding']['units'] ?: '–'])) ?></p>
      <ol class="gk-ed__regions gk-ed__regions--table">
        <?php foreach ($regions as $r): ?><li><span class="gk-ed__sw" style="background:<?= e($color($r)) ?>"></span><a href="<?= e(url('/admin/data/' . $table['handle'] . '/' . $r['id'])) ?>"><?= e($r['name']) ?></a> <span class="adm-muted">· <?= e(__('{n} {nouns}', ['n' => count($geo['unitsOf'][$r['key']] ?? []), 'nouns' => $nouns])) ?></span>
          <?php if (isset($locked[(int) $r['id']])): ?><span class="gk-ed__lock"><?= icon('lock') ?> <?= e($locked[(int) $r['id']]) ?></span><?php endif; ?></li><?php endforeach; ?>
      </ol>
      <p class="adm-muted f-help"><?= e(__('Reihenfolge und Angaben je Gebiet pflegen Sie in der Datentabelle.')) ?></p>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($editable && $free): ?>
    <section class="adm-card gk-ed__units">
      <h2 id="gk-units-h"><?= e($nouns) ?></h2>
      <div class="f"><label for="gk-q"><?= e(__('{noun} suchen', ['noun' => $noun])) ?></label><input id="gk-q" type="search" data-gk-q autocomplete="off" placeholder="<?= e(__('Name oder Schlüssel')) ?>"></div>
      <p class="adm-muted f-help"><?= e(__('Häkchen = gehört zum gewählten Gebiet „Zuordnen zu“.')) ?></p>
      <ul class="gk-ed__ulist" aria-labelledby="gk-units-h" data-gk-list>
        <?php foreach ($geo['names'] as $u => $name): $k = $geo['owner'][$u] ?? null; $lk = $k && isset($byKey[$k]) && isset($locked[(int) $byKey[$k]['id']]); ?>
        <li data-name="<?= e(mb_strtolower($name . ' ' . $u)) ?>"><label><input type="checkbox" data-u="<?= e((string) $u) ?>"<?= $lk ? ' disabled' : '' ?>> <span><?= e($name) ?></span> <span class="adm-muted gk-ed__own" data-own><?= $lk ? icon('lock') . ' ' : '' ?><?= e($k && isset($byKey[$k]) ? $byKey[$k]['name'] : '') ?></span></label></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </aside>
</div>
<script type="application/json" id="gk-ed-data"><?= json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php if ($own && $editRegion && ($reg = $byKey['r' . $editRegion] ?? null)):
    $isOwn = $reg['color'] !== '' && $reg['color'][0] === '#';
    $fields = [
        ['name' => 'name', 'label' => __('Name'), 'type' => 'text', 'required' => true, 'max' => 160, 'width' => 'half'],
        ['name' => 'color', 'label' => __('Farbe'), 'type' => 'select', 'options' => Colors::options(), 'width' => 'half',
            'help' => __('Design-Farben und Palette folgen dem Kit hell und dunkel; eigene Farben haben einen eigenen Wert im Dunkeln.')],
        ['name' => 'own_color', 'label' => __('Eigene Farbe'), 'type' => 'color', 'width' => 'half', 'help' => __('Nur bei „Eigene Farbe …“.')],
        ['name' => 'color_dark', 'label' => __('Eigene Farbe im Dunkeln (optional)'), 'type' => 'color', 'width' => 'half',
            'help' => __('Leer = automatisch: zum dunklen Hintergrund des Kits passend und so dunkel, dass die Namen in der Karte mindestens 4,5:1 erreichen.')],
        ['name' => 'link', 'label' => __('Link (z. B. Seite des Gebiets)'), 'type' => 'link', 'width' => 'half'],
        ['name' => 'media_id', 'label' => __('Bild'), 'type' => 'media', 'width' => 'half'],
        ['name' => 'text', 'label' => __('Infotext'), 'type' => 'richtext'],
        ['name' => 'contact', 'label' => __('Kontakt'), 'type' => 'textarea', 'max' => 2000, 'width' => 'half', 'help' => __('Mehrzeilig, z. B. Ansprechperson, E-Mail, Telefon.')],
        ['name' => 'units', 'label' => __('Flächen (Schlüssel)'), 'type' => 'textarea', 'width' => 'half', 'help' => __('Amtliche Schlüssel, durch Komma getrennt – wird beim Klicken auf die Karte automatisch gepflegt.')],
    ];
    $vals = ['name' => $reg['name'], 'color' => $isOwn ? 'own' : $reg['color'], 'own_color' => $isOwn ? strtoupper($reg['color']) : '',
        'color_dark' => $isOwn && $reg['color_dark'] !== '' ? strtoupper($reg['color_dark']) : '', 'link' => $reg['link'],
        'text' => $reg['text'], 'media_id' => $reg['media_id'], 'contact' => $reg['contact'], 'units' => implode(', ', $reg['units'])];
    // Kontrast der Beschriftung (Namen in der Karte) auf der Fläche – hell und im Dunkeln
    $effL = Colors::approx($effs[(string) $reg['key']], $kitLight);
    $effD = Colors::approxDark($effs[(string) $reg['key']], (string) $reg['color_dark'], $kitDark);
    $cL = Colors::labelContrast($effL, $kitLight);
    $cD = Colors::labelContrast($effD, $kitDark);
    $ratio = fn(float $c): string => fmt()->number($c, 1) . ':1';
?>
<section class="adm-card" id="gk-gebiet">
  <h2><?= e(__('Gebiet „{name}“', ['name' => $reg['name']])) ?></h2>
  <form method="post" action="<?= e(url($adm . '/gebiet')) ?>" novalidate>
    <?= csrf_field() ?><input type="hidden" name="g[id]" value="<?= (int) $reg['id'] ?>">
    <div class="adm-fields"><?= Fields::renderForm($fields, $vals, [], 'g') ?></div>
    <div class="gk-ed__contrast" role="note">
      <span class="gk-ed__cpair"><span class="gk-ed__sw" style="background:<?= e($effL) ?>"></span><?= e(__('Beschriftung hell: {ratio}', ['ratio' => $ratio($cL)])) ?><?php if ($cL < Colors::MIN_LABEL): ?> <span class="adm-badge adm-badge--warn"><?= icon('warning') ?> <?= e(__('unter 4,5:1')) ?></span><?php endif; ?></span>
      <span class="gk-ed__cpair"><span class="gk-ed__sw" style="background:<?= e($effD) ?>"></span><?= e(__('im Dunkeln: {ratio}', ['ratio' => $ratio($cD)])) ?><?= $isOwn && $reg['color_dark'] === '' ? ' ' . e(__('(automatisch {color})', ['color' => strtoupper($effD)])) : '' ?><?php if ($cD < Colors::MIN_LABEL): ?> <span class="adm-badge adm-badge--warn"><?= icon('warning') ?> <?= e(__('unter 4,5:1')) ?></span><?php endif; ?></span>
    </div>
    <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Gebiet speichern')) ?></button></div>
  </form>
  <form method="post" action="<?= e(url($adm . '/gebiet/' . (int) $reg['id'] . '/loeschen')) ?>" data-confirm="<?= e(__('Gebiet „{name}“ löschen? Seine Flächen sind danach keinem Gebiet zugeordnet.', ['name' => $reg['name']])) ?>">
    <?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Gebiet löschen')) ?></button>
  </form>
</section>
<?php endif; ?>

<?php
// Beschriftungen: Text, Lage (Ziehen, Tastatur), Hinweislinie, Größe, Stil, Großbuchstaben je Gebiet – Lage und Gestaltung in der Karte,
// Text bei Datentabellen mit Feld „label“ in den Einträgen
$pole = $map['options']['label_mode'] === 'pole';
$labelData = [
    'save' => url($adm . '/beschriftung'), 'bound' => (bool) $labelBound, 'pole' => $pole,
    'labels' => (object) $map['labels'], 'info' => (object) $labelInfo,
    'regions' => array_values(array_map(fn($r) => ['key' => $r['key'], 'name' => $r['name'],
        'text' => $labelBound ? (string) ($r['label_field'] ?? '') : (string) ($map['labels'][$r['key']]['text'] ?? ''),
        'textLock' => $textLock[$r['key']] ?? null, 'has' => isset($geo['unitsOf'][$r['key']]) && $geo['unitsOf'][$r['key']] !== []], $regions)),
    'defaults' => ['show' => (bool) $map['options']['labels'], 'size' => $map['options']['label_size'], 'style' => $map['options']['label_style'], 'caps' => (bool) $map['options']['label_caps']],
    't' => ['auto' => __('automatisch'), 'manual' => __('von Hand gesetzt'), 'posAuto' => __('Lage: automatisch'), 'posManual' => __('Lage: von Hand gesetzt'),
        'contrastL' => __('Kontrast hell: {ratio}'), 'contrastD' => __('im Dunkeln: {ratio}'), 'low' => __('unter 4,5:1'),
        'tight' => __('Passt nicht ganz in das Gebiet – Kurzname, Zeilenumbruch, kleinere Schrift oder Hinweislinie.'),
        'phone' => __('Auf schmalen Bildschirmen ausgeblendet (zu wenig Platz).'), 'phoneLeader' => __('Mit Hinweislinie: auf schmalen Bildschirmen ausgeblendet.'),
        'off' => __('Diese Beschriftung ist ausgeblendet.'), 'size' => __('Größe: {size}'), 'tone' => __('Schrift: {tone}'),
        'toneD' => __('dunkel'), 'toneL' => __('hell'), 'toneKit' => __('Schrift des Kits'), 'toneTag' => __('Etikett'), 'tonePillL' => __('Pill hell'), 'tonePillD' => __('Pill dunkel'),
        'moved' => __('„{name}“ verschoben'), 'saved' => __('Beschriftungen gespeichert.'), 'error' => __('Speichern fehlgeschlagen.'),
        'unsaved' => __('Nicht gespeicherte Änderungen'), 'saving' => __('Wird gespeichert …'), 'leave' => __('Änderungen verwerfen?'),
        'textLocked' => __('Text aus dem Eintrag – hier nicht änderbar: {reason}'), 'noArea' => __('Gebiet ohne Flächen – keine Beschriftung.')],
];
?>
<section class="adm-card gk-lab-card" id="gk-beschriftung" aria-labelledby="gk-lab-h">
  <div class="gk-prev-head">
    <h2 id="gk-lab-h"><?= e(__('Beschriftungen')) ?></h2>
    <div class="gk-prev-switch" role="group" aria-label="<?= e(__('Farbschema der Vorschau')) ?>">
      <button type="button" class="adm-btn adm-btn--small adm-btn--primary" data-gk-scheme="light" aria-pressed="true"><?= e(__('Hell')) ?></button>
      <button type="button" class="adm-btn adm-btn--small" data-gk-scheme="dark" aria-pressed="false"><?= e(__('Dunkel')) ?></button>
    </div>
  </div>
  <p class="adm-muted"><?= e(__('Ziehen Sie eine Beschriftung an ihren Platz oder wählen Sie sie mit Tab und verschieben Sie sie mit den Pfeiltasten (Umschalt+Pfeil: große Schritte). Klick auf ein Gebiet oder eine Beschriftung wählt es rechts aus – dort Text, Größe, Stil und Hinweislinie.')) ?></p>
  <?php if (!$pole): ?>
  <div class="adm-flash adm-flash--info gk-lab-legacy" role="note"><p><?= e(__('Diese Karte nutzt die bisherige Lage der Namen (vor Version 0.3.0), damit sie auf der Website unverändert bleibt. „Automatisch neu setzen“ verwendet die neue Berechnung (Mitte der größten freien Fläche des Gebiets), passt die Schriftgröße auf dem Telefon an und blendet dort Namen ohne Platz aus.')) ?></p></div>
  <?php endif; ?>
  <div class="gk-lab-ed">
    <div class="gk-prevbox gk-labbox" data-gk-preview data-gk-labbox data-light="<?= e(Preview::vars($kitLight)) ?>" data-dark="<?= e(Preview::vars($kitDark)) ?>" style="<?= e(Preview::vars($kitLight)) ?>"><?= $labelBox ?></div>
    <div class="gk-lab-panel" data-gk-labpanel>
      <div class="f"><label for="gk-lab-sel"><?= e(__('Gebiet')) ?></label>
        <select id="gk-lab-sel" data-gk-lab-sel><?php foreach ($regions as $r): ?><option value="<?= e($r['key']) ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <div class="f"><label for="gk-lab-show"><?= e(__('Beschriftung')) ?></label>
        <select id="gk-lab-show" data-gk-lab-f="show"><option value="" data-gk-inherit><?= e(__('wie Karte')) ?></option><option value="on"><?= e(__('zeigen')) ?></option><option value="off"><?= e(__('ausblenden')) ?></option></select></div>
      <div class="f"><label for="gk-lab-text"><?= e(__('Text')) ?></label>
        <textarea id="gk-lab-text" rows="2" maxlength="<?= Labels::MAX_TEXT ?>" data-gk-lab-f="text" aria-describedby="gk-lab-text-h"></textarea>
        <p class="f-help" id="gk-lab-text-h"><?= e($labelBound ? __('Steht im Feld „{field}“ des Eintrags. Leer = Name. Neue Zeile mit Enter (höchstens 3).', ['field' => (string) $map['binding']['label']])
            : __('Leer = Name des Gebiets. Für lange Namen einen Kurznamen oder einen Zeilenumbruch (Enter, höchstens 3 Zeilen).')) ?></p></div>
      <div class="gk-lab-row">
        <div class="f"><label for="gk-lab-size"><?= e(__('Schriftgröße')) ?></label>
          <select id="gk-lab-size" data-gk-lab-f="size"><?php foreach (Labels::sizeOptions(true) as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="f"><label for="gk-lab-caps"><?= e(__('Großbuchstaben')) ?></label>
          <select id="gk-lab-caps" data-gk-lab-f="caps"><option value=""><?= e(__('wie Karte')) ?></option><option value="on"><?= e(__('ja')) ?></option><option value="off"><?= e(__('nein')) ?></option></select></div>
      </div>
      <div class="f"><label for="gk-lab-style"><?= e(__('Stil')) ?></label>
        <select id="gk-lab-style" data-gk-lab-f="style"><?php foreach (Labels::styleOptions(true) as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="f"><label class="gk-lab-check"><input type="checkbox" data-gk-lab-f="leader"> <?= e(__('Hinweislinie (Text außerhalb, Linie zum Ankerpunkt im Gebiet)')) ?></label>
        <p class="f-help"><?= e(__('Für kleine Gebiete: Text und Ankerpunkt lassen sich getrennt verschieben.')) ?></p></div>
      <div class="gk-lab-pos"><span data-gk-lab-pos></span>
        <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-gk-lab-reset><?= icon('arrow-counter-clockwise') ?> <?= e(__('Lage zurücksetzen')) ?></button></div>
      <div class="gk-lab-info" data-gk-lab-info role="status" aria-live="polite"></div>
    </div>
  </div>
  <div class="gk-lab-save">
    <button type="button" class="adm-btn adm-btn--primary" data-gk-lab-save disabled><?= e(__('Beschriftungen speichern')) ?></button>
    <span class="gk-ed__status" data-gk-lab-status role="status" aria-live="polite"></span>
  </div>
  <form method="post" action="<?= e(url($adm . '/beschriftung/automatisch')) ?>" class="gk-lab-autoform" data-confirm="<?= e(__('Alle Beschriftungen automatisch neu setzen? Von Hand gesetzte Lagen und Ankerpunkte gehen verloren, eigene Texte und Stile bleiben.')) ?>">
    <?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--ghost"><?= icon('crosshair') ?> <?= e(__('Automatisch neu setzen')) ?></button>
  </form>
</section>
<script type="application/json" id="gk-lab-data"><?= json_encode($labelData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php
// Darstellung: Hover/Fokus, Schatten, automatische Farben, Beschriftungen (Größe, Stil, Großbuchstaben, Schatten der Pills) – jede
// Änderung zeichnet die Vorschau sofort neu (Entwurf), auch Hover; gespeichert mit „Darstellung speichern“
$o = $map['options'];
$lookData = ['save' => url($adm . '/darstellung'),
    't' => ['saved' => __('Darstellung gespeichert.'), 'error' => __('Speichern fehlgeschlagen.'), 'unsaved' => __('Nicht gespeicherte Änderungen'),
        'saving' => __('Wird gespeichert …'), 'leave' => __('Änderungen verwerfen?')]];
?>
<section class="adm-card gk-prev-card" id="gk-darstellung" aria-labelledby="gk-look-h">
  <div class="gk-prev-head">
    <h2 id="gk-look-h"><?= e(__('Darstellung')) ?></h2>
    <div class="gk-prev-switch" role="group" aria-label="<?= e(__('Farbschema der Vorschau')) ?>">
      <button type="button" class="adm-btn adm-btn--small adm-btn--primary" data-gk-scheme="light" aria-pressed="true"><?= e(__('Hell')) ?></button>
      <button type="button" class="adm-btn adm-btn--small" data-gk-scheme="dark" aria-pressed="false"><?= e(__('Dunkel')) ?></button>
    </div>
  </div>
  <p class="adm-muted"><?= e(($kitLight['source'] ?? '') === 'kit'
      ? __('So erscheint die Karte im Block „Gebietskarte“ – mit den Farben des Kits und den Design-Werten dieser Website. Fahren Sie mit der Maus über die Karte, um den Hover-Effekt zu sehen.')
      : __('So erscheint die Karte im Block „Gebietskarte“ – mit den Grundfarben des Kits dieser Website. Fahren Sie mit der Maus über die Karte, um den Hover-Effekt zu sehen.')) ?>
    <?php if (!$darkOn): ?><span data-gk-darknote hidden><?= e(__('Der Dunkelmodus ist für diese Website aus (Design) – Besucher sehen die Karte immer hell.')) ?></span><?php endif; ?></p>
  <div class="gk-look">
    <div class="gk-prevbox" data-gk-preview data-light="<?= e(Preview::vars($kitLight)) ?>" data-dark="<?= e(Preview::vars($kitDark)) ?>" style="<?= e(Preview::vars($kitLight)) ?>"><?= $preview ?></div>
    <div class="gk-look__panel" data-gk-look>
      <div class="f"><label for="gk-hover"><?= e(__('Hover- und Fokus-Effekt')) ?></label>
        <select id="gk-hover" data-gk-look-f="hover" aria-describedby="gk-hover-h"><?php foreach (['none' => __('keiner'), 'subtle' => __('dezent – übrige Gebiete abdunkeln'), 'lift' => __('animiert – Gebiet hebt sich hervor')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $o['hover'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <p class="f-help" id="gk-hover-h"><?= e(__('Gilt für Maus und Tastatur. Auf Touch-Geräten bleibt es beim Antippen; bei „Bewegung reduzieren“ zeigt „animiert“ nur einen ruhigen Schatten.')) ?></p></div>
      <label class="gk-lab-check"><input type="checkbox" data-gk-look-f="shadow"<?= $o['shadow'] ? ' checked' : '' ?>> <?= e(__('Schatten unter der Karte')) ?></label>
      <div class="f"><label for="gk-palette"><?= e(__('Automatische Farben')) ?></label>
        <select id="gk-palette" data-gk-look-f="palette" aria-describedby="gk-palette-h"><?php foreach (Colors::presetOptions() as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $o['palette'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <p class="f-help" id="gk-palette-h"><?= e(__('Für Gebiete mit Farbe „Automatisch“ (z. B. Datentabellen ohne Farbfeld). Nachbargebiete bekommen immer verschiedene Farben bzw. Töne.')) ?></p></div>
      <fieldset class="gk-look__labels"><legend><?= e(__('Beschriftungen')) ?></legend>
        <div class="f"><label for="gk-ls"><?= e(__('Schriftgröße')) ?></label>
          <select id="gk-ls" data-gk-look-f="label_size"><?php foreach (Labels::sizeOptions() as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $o['label_size'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="f"><label for="gk-lst"><?= e(__('Stil')) ?></label>
          <select id="gk-lst" data-gk-look-f="label_style" aria-describedby="gk-lst-h"><?php foreach (Labels::styleOptions() as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $o['label_style'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
          <p class="f-help" id="gk-lst-h"><?= e(__('Je Gebiet abweichend im Abschnitt „Beschriftungen“.')) ?></p></div>
        <label class="gk-lab-check"><input type="checkbox" data-gk-look-f="label_caps"<?= $o['label_caps'] ? ' checked' : '' ?>> <?= e(__('Großbuchstaben')) ?></label>
        <label class="gk-lab-check"><input type="checkbox" data-gk-look-f="pill_shadow"<?= $o['pill_shadow'] ? ' checked' : '' ?>> <?= e(__('Schatten unter Pill-Beschriftungen')) ?></label>
      </fieldset>
      <div class="gk-lab-save">
        <button type="button" class="adm-btn adm-btn--primary" data-gk-look-save disabled><?= e(__('Darstellung speichern')) ?></button>
        <span class="gk-ed__status" data-gk-look-status role="status" aria-live="polite"></span>
      </div>
    </div>
  </div>
</section>
<script type="application/json" id="gk-look-data"><?= json_encode($lookData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<section class="adm-card">
  <h2><?= e(__('Einstellungen der Karte')) ?></h2>
  <form method="post" action="<?= e(url($adm)) ?>" class="gk-adm-form">
    <?= csrf_field() ?>
    <div class="f"><label for="gk-t"><?= e(__('Titel')) ?></label><input id="gk-t" name="m[title]" value="<?= e($map['title']) ?>" maxlength="160" required></div>
    <input type="hidden" name="m[base]" value="<?= e($map['base']) ?>">
    <div class="f"><label for="gk-l"><?= e(__('Ebene')) ?></label>
      <select id="gk-l" name="m[level]"><?php foreach ($base['levels'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $map['level'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <p class="f-help"><?= e(__('Achtung: Nach einem Wechsel der Ebene passen die zugeordneten Flächen nicht mehr.')) ?></p></div>
    <fieldset class="f gk-adm-scope"><legend><?= e($base['partLabel']) ?></legend>
      <p class="f-help"><?= e(__('Keine Auswahl = ganz Deutschland.')) ?></p>
      <div class="gk-adm-checks"><?php foreach ($base['parts'] as $sn => $name): ?><label><input type="checkbox" name="m[scope][]" value="<?= e($sn) ?>"<?= in_array($sn, $map['scope'], true) ? ' checked' : '' ?>> <?= e($name) ?></label><?php endforeach; ?></div>
    </fieldset>
    <fieldset class="f"><legend><?= e(__('Inhalt der Karte')) ?></legend>
      <label><input type="checkbox" name="m[options][rest]" value="1"<?= $map['options']['rest'] ? ' checked' : '' ?>> <?= e(__('Flächen ohne Gebiet zeigen (grau)')) ?></label><br>
      <label><input type="checkbox" name="m[options][lines]" value="1"<?= $map['options']['lines'] ? ' checked' : '' ?>> <?= e(__('Grenzen der {nouns} innerhalb der Gebiete zeigen', ['nouns' => $nouns])) ?></label><br>
      <label><input type="checkbox" name="m[options][labels]" value="1"<?= $map['options']['labels'] ? ' checked' : '' ?>> <?= e(__('Namen der Gebiete in der Karte (Gestaltung unter „Darstellung“, je Gebiet abweichend unter „Beschriftungen“)')) ?></label><br>
      <label><input type="checkbox" name="m[options][list]" value="1"<?= $map['options']['list'] ? ' checked' : '' ?>> <?= e(__('Liste der Gebiete unter der Karte (sonst nur für Screenreader)')) ?></label>
    </fieldset>
    <div class="f"><label for="gk-fit"><?= e(__('Ausschnitt')) ?></label>
      <select id="gk-fit" name="m[options][fit]"><option value="scope"<?= $map['options']['fit'] === 'scope' ? ' selected' : '' ?>><?= e(__('alle Flächen der gewählten Länder')) ?></option><option value="regions"<?= $map['options']['fit'] === 'regions' ? ' selected' : '' ?>><?= e(__('nur bis zum Rand der Gebiete')) ?></option></select></div>
    <fieldset class="f"><legend><?= e(__('Quelle der Gebiete')) ?></legend>
      <label><input type="radio" name="m[source]" value="own"<?= $own ? ' checked' : '' ?>> <?= e(__('hier pflegen')) ?></label><br>
      <label><input type="radio" name="m[source]" value="table"<?= !$own ? ' checked' : '' ?>> <?= e(__('aus einer Datentabelle')) ?></label>
    </fieldset>
    <?php if (!$own): $b = $map['binding']; $fo = $table ? Bound::fieldOptions($table) : ['' => '–']; ?>
    <fieldset class="f gk-adm-bind"><legend><?= e(__('Datentabelle')) ?></legend>
      <div class="f"><label for="gk-bt"><?= e(__('Tabelle')) ?></label><select id="gk-bt" name="m[binding][table]"><?php foreach ($tables as $h => $n): ?><option value="<?= e((string) $h) ?>"<?= (string) $h === $b['table'] ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
        <p class="f-help"><?= e(__('Nach dem Wechsel der Tabelle speichern – dann erscheinen ihre Felder.')) ?></p></div>
      <?php if ($table): foreach (['units' => __('Feld mit den Flächen (Pflicht)'), 'name' => __('Name (leer = Titel des Eintrags)'), 'color' => __('Farbe (Feld vom Typ Farbe)'),
          'color_dark' => __('Farbe im Dunkeln (optional, sonst automatisch)'),
          'link' => __('Link (leer = Detailseite des Eintrags)'), 'text' => __('Infotext'), 'image' => __('Bild'), 'contact' => __('Kontakt'),
          'site' => __('Website (Schlüssel oder Adresse – für „Gebiet dieser Website“)'),
          'label' => __('Beschriftung in der Karte (Kurzname, optional – Text oder Textfeld)')] as $k => $label): ?>
      <div class="f"><label for="gk-b<?= e($k) ?>"><?= e($label) ?></label><select id="gk-b<?= e($k) ?>" name="m[binding][<?= e($k) ?>]"><?php foreach ($fo as $fn => $fl): ?><option value="<?= e((string) $fn) ?>"<?= (string) $fn === ($b[$k] ?? '') ? ' selected' : '' ?>><?= e($fl) ?></option><?php endforeach; ?></select></div>
      <?php endforeach; endif; ?>
    </fieldset>
    <?php endif; ?>
    <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Einstellungen speichern')) ?></button></div>
  </form>
  <form method="post" action="<?= e(url($adm . '/loeschen')) ?>" data-confirm="<?= e(__('Karte „{title}“ mit allen Gebieten löschen? Blöcke mit dieser Karte bleiben leer.', ['title' => $map['title']])) ?>">
    <?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--danger"><?= e(__('Karte löschen')) ?></button>
  </form>
</section>
