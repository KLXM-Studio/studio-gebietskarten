<?php
/**
 * Karte bearbeiten: Gebiete bilden (Klick auf die Karte, Liste mit Suche), Angaben je Gebiet, Einstellungen, Vorschau hell/dunkel.
 * @var array $map  @var list<array> $regions  @var array $geo  @var ?array $table  @var int $editRegion  @var array $tables
 * @var string $preview  @var array $kitLight  @var array $kitDark  @var array $flash
 */
use Core\Fields;
use Klxm\Gebietskarten\Bases;
use Klxm\Gebietskarten\Bound;
use Klxm\Gebietskarten\Colors;
use Klxm\Gebietskarten\Gebietskarten as G;
use Klxm\Gebietskarten\Preview;

$base = Bases::all()[$map['base']] ?? Bases::all()['de'];
$own = $map['source'] !== 'table';
$noun = Bases::unitNoun($map['base'], $map['level']);
$nouns = Bases::unitNoun($map['base'], $map['level'], true);
$adm = G::ADMIN . '/' . $map['id'];
$index = array_flip(array_column($regions, 'key'));
$color = fn(array $r): string => Colors::approx(Colors::effective($r['color'], $index[$r['key']]), $kitLight);
$byKey = array_column($regions, null, 'key');

$actions = '<a class="adm-btn adm-btn--ghost" href="' . e(url(G::ADMIN)) . '">' . icon('arrow-left') . ' ' . e(__('Alle Karten')) . '</a>';
if (can('media.upload')) {
    $actions .= '<form method="post" action="' . e(url($adm . '/grafik')) . '">' . csrf_field() . '<button class="adm-btn adm-btn--ghost">' . icon('pen-nib')
        . ' ' . e(G::grafikActive() ? __('Als Grafik weiterbearbeiten') : __('Als SVG in die Mediathek')) . '</button></form>';
}
$scopeText = $map['scope'] ? implode(', ', array_map(fn($s) => $base['parts'][$s] ?? $s, $map['scope'])) : $base['label'];
echo \Core\Theme::capture(__DIR__ . '/_head.php', ['h1' => $map['title'], 'flash' => $flash, 'actions' => $actions,
    'lead' => Bases::levelLabel($map['base'], $map['level']) . ' · ' . $scopeText . ' · ' . (count($regions) === 1 ? __('1 Gebiet') : __('{n} Gebiete', ['n' => count($regions)]))]);

$editorData = [
    'save' => url($adm . '/zuordnung'), 'own' => $own,
    'regions' => array_map(fn($r) => ['id' => $r['id'], 'key' => $r['key'], 'name' => $r['name'], 'color' => $color($r)], $regions),
    'owner' => (object) $geo['owner'], 'names' => (object) $geo['names'],
    't' => ['assigned' => __('{unit} → {region}'), 'released' => __('{unit} ist keinem Gebiet mehr zugeordnet'), 'saved' => __('Zuordnung gespeichert.'),
        'unsaved' => __('Nicht gespeicherte Änderungen'), 'error' => __('Speichern fehlgeschlagen.'), 'none' => __('kein Gebiet'), 'leave' => __('Änderungen verwerfen?')],
];
?>
<?php if ($geo['missing']): ?>
<p class="adm-flash adm-flash--warning" role="status"><?= e(__('Kartendaten fehlen für: {list}.', ['list' => implode(', ', array_map(fn($s) => Bases::LAENDER[$s] ?? $s, $geo['missing']))])) ?>
  <a href="<?= e(url(G::ADMIN . '/kartendaten')) ?>"><?= e(__('Kartendaten laden')) ?></a></p>
<?php endif; ?>
<?php if ($geo['unmatched']): ?>
<div class="adm-flash adm-flash--warning" role="status"><p><?= e(__('Einige Angaben passen zu keiner Fläche dieser Karte:')) ?></p><ul>
  <?php foreach ($geo['unmatched'] as $k => $list): ?><li><?= e(($byKey[$k]['name'] ?? $k) . ': ' . implode(', ', array_slice($list, 0, 12)) . (count($list) > 12 ? ' …' : '')) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<div class="gk-ed" id="gk-gebiete">
  <section class="adm-card gk-ed__map" aria-labelledby="gk-ed-h">
    <h2 id="gk-ed-h"><?= e($own ? __('Gebiete bilden') : __('Gebiete aus der Datentabelle')) ?></h2>
    <?php if ($own && $regions): ?>
    <div class="gk-ed__bar">
      <div class="f gk-ed__active"><label for="gk-active"><?= e(__('Zuordnen zu')) ?></label>
        <select id="gk-active" data-gk-active><?php foreach ($regions as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $editRegion === $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <p class="adm-muted gk-ed__hint"><?= e(__('Klick auf eine Fläche ordnet sie dem gewählten Gebiet zu, ein zweiter Klick gibt sie wieder frei. Mit der Tastatur: Liste „{nouns}“ rechts.', ['nouns' => $nouns])) ?></p>
      <button type="button" class="adm-btn adm-btn--primary" data-gk-save disabled><?= e(__('Zuordnung speichern')) ?></button>
      <span class="gk-ed__status" data-gk-status role="status" aria-live="polite"></span>
    </div>
    <?php elseif ($own): ?>
    <p class="adm-muted"><?= e(__('Legen Sie zuerst ein Gebiet an (rechts).')) ?></p>
    <?php endif; ?>
    <div class="gk-ed__canvas">
      <svg class="gk-edsvg" viewBox="0 0 <?= (int) $geo['w'] ?> <?= (int) $geo['h'] ?>" aria-hidden="true" focusable="false" data-gk-svg>
        <?php foreach ($geo['units'] as $u => $d): $k = $geo['owner'][$u] ?? null; ?>
        <path d="<?= $d ?>" fill-rule="evenodd" data-u="<?= e($u) ?>" class="gk-u<?= $k ? '' : ' is-free' ?>"<?= $k && isset($byKey[$k]) ? ' style="fill:' . e($color($byKey[$k])) . '"' : '' ?>><title><?= e(($geo['names'][$u] ?? $u) . ' – ' . ($k && isset($byKey[$k]) ? $byKey[$k]['name'] : __('kein Gebiet'))) ?></title></path>
        <?php endforeach; ?>
      </svg>
    </div>
    <ul class="gk-ed__legend" aria-label="<?= e(__('Legende')) ?>">
      <?php foreach ($regions as $r): ?><li><span class="gk-ed__sw" style="background:<?= e($color($r)) ?>"></span><?= e($r['name']) ?> <span class="adm-muted" data-gk-count="<?= (int) $r['id'] ?>">(<?= count($geo['unitsOf'][$r['key']] ?? []) ?>)</span></li><?php endforeach; ?>
      <li><span class="gk-ed__sw is-free"></span><?= e(__('kein Gebiet')) ?></li>
    </ul>
  </section>

  <aside class="gk-ed__side">
    <?php if ($own): ?>
    <section class="adm-card">
      <h2><?= e(__('Gebiete')) ?></h2>
      <ol class="gk-ed__regions">
        <?php foreach ($regions as $r): ?>
        <li>
          <span class="gk-ed__sw" style="background:<?= e($color($r)) ?>"></span>
          <a href="<?= e(url($adm . '?gebiet=' . $r['id'] . '#gk-gebiet')) ?>"<?= $editRegion === $r['id'] ? ' aria-current="true"' : '' ?>><?= e($r['name']) ?></a>
          <span class="adm-muted">· <?= e(__('{n} {nouns}', ['n' => count($geo['unitsOf'][$r['key']] ?? []), 'nouns' => $nouns])) ?></span>
        </li>
        <?php endforeach; ?>
      </ol>
      <form method="post" action="<?= e(url($adm . '/gebiet')) ?>" class="gk-ed__new">
        <?= csrf_field() ?>
        <div class="f"><label for="gk-newname"><?= e(__('Neues Gebiet')) ?></label><input id="gk-newname" name="g[name]" maxlength="160" required placeholder="<?= e(__('Name, z. B. Region Nord')) ?>"></div>
        <button class="adm-btn adm-btn--small"><?= icon('plus') ?> <?= e(__('Anlegen')) ?></button>
      </form>
    </section>
    <?php if ($regions): ?>
    <section class="adm-card gk-ed__units">
      <h2 id="gk-units-h"><?= e($nouns) ?></h2>
      <div class="f"><label for="gk-q"><?= e(__('{noun} suchen', ['noun' => $noun])) ?></label><input id="gk-q" type="search" data-gk-q autocomplete="off" placeholder="<?= e(__('Name oder Schlüssel')) ?>"></div>
      <p class="adm-muted f-help"><?= e(__('Häkchen = gehört zum gewählten Gebiet „Zuordnen zu“.')) ?></p>
      <ul class="gk-ed__ulist" aria-labelledby="gk-units-h" data-gk-list>
        <?php foreach ($geo['names'] as $u => $name): $k = $geo['owner'][$u] ?? null; ?>
        <li data-name="<?= e(mb_strtolower($name . ' ' . $u)) ?>"><label><input type="checkbox" data-u="<?= e((string) $u) ?>"> <span><?= e($name) ?></span> <span class="adm-muted gk-ed__own" data-own><?= e($k && isset($byKey[$k]) ? $byKey[$k]['name'] : '') ?></span></label></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
    <?php else: ?>
    <section class="adm-card">
      <h2><?= e(__('Gebiete')) ?></h2>
      <?php if (!$table): ?>
      <p class="adm-muted"><?= e(__('Wählen Sie unten unter „Einstellungen der Karte“ eine Datentabelle und das Feld mit den Flächen.')) ?></p>
      <?php else: ?>
      <p class="adm-muted"><?= e(__('Ein veröffentlichter Eintrag der Tabelle „{table}“ = ein Gebiet. Die Flächen stehen im Feld „{field}“ (Schlüssel oder Namen, durch Komma getrennt).', ['table' => $table['name'], 'field' => $map['binding']['units'] ?: '–'])) ?></p>
      <ol class="gk-ed__regions">
        <?php foreach ($regions as $r): ?><li><span class="gk-ed__sw" style="background:<?= e($color($r)) ?>"></span><a href="<?= e(url('/admin/data/' . $table['handle'] . '/' . $r['id'])) ?>"><?= e($r['name']) ?></a> <span class="adm-muted">· <?= e(__('{n} {nouns}', ['n' => count($geo['unitsOf'][$r['key']] ?? []), 'nouns' => $nouns])) ?></span></li><?php endforeach; ?>
      </ol>
      <?php endif; ?>
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
            'help' => __('Design-Farben folgen dem Kit hell und dunkel; eigene Farben gelten in beiden Fällen gleich.')],
        ['name' => 'own_color', 'label' => __('Eigene Farbe'), 'type' => 'color', 'width' => 'half', 'help' => __('Nur bei „Eigene Farbe …“.')],
        ['name' => 'link', 'label' => __('Link (z. B. Seite des Gebiets)'), 'type' => 'link', 'width' => 'half'],
        ['name' => 'text', 'label' => __('Infotext'), 'type' => 'richtext'],
        ['name' => 'media_id', 'label' => __('Bild'), 'type' => 'media', 'width' => 'half'],
        ['name' => 'contact', 'label' => __('Kontakt'), 'type' => 'textarea', 'max' => 2000, 'width' => 'half', 'help' => __('Mehrzeilig, z. B. Ansprechperson, E-Mail, Telefon.')],
        ['name' => 'units', 'label' => __('Flächen (Schlüssel)'), 'type' => 'textarea', 'help' => __('Amtliche Schlüssel, durch Komma getrennt – wird beim Klicken auf die Karte automatisch gepflegt.')],
    ];
    $vals = ['name' => $reg['name'], 'color' => $isOwn ? 'own' : $reg['color'], 'own_color' => $isOwn ? strtoupper($reg['color']) : '', 'link' => $reg['link'],
        'text' => $reg['text'], 'media_id' => $reg['media_id'], 'contact' => $reg['contact'], 'units' => implode(', ', $reg['units'])];
?>
<section class="adm-card" id="gk-gebiet">
  <h2><?= e(__('Gebiet „{name}“', ['name' => $reg['name']])) ?></h2>
  <form method="post" action="<?= e(url($adm . '/gebiet')) ?>" novalidate>
    <?= csrf_field() ?><input type="hidden" name="g[id]" value="<?= (int) $reg['id'] ?>">
    <div class="adm-fields"><?= Fields::renderForm($fields, $vals, [], 'g') ?></div>
    <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Gebiet speichern')) ?></button></div>
  </form>
  <form method="post" action="<?= e(url($adm . '/gebiet/' . (int) $reg['id'] . '/loeschen')) ?>" data-confirm="<?= e(__('Gebiet „{name}“ löschen? Seine Flächen sind danach keinem Gebiet zugeordnet.', ['name' => $reg['name']])) ?>">
    <?= csrf_field() ?><button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Gebiet löschen')) ?></button>
  </form>
</section>
<?php endif; ?>

<section class="adm-card gk-prev-card">
  <div class="gk-prev-head">
    <h2><?= e(__('Vorschau')) ?></h2>
    <div class="gk-prev-switch" role="group" aria-label="<?= e(__('Farbschema der Vorschau')) ?>">
      <button type="button" class="adm-btn adm-btn--small adm-btn--primary" data-gk-scheme="light" aria-pressed="true"><?= e(__('Hell')) ?></button>
      <button type="button" class="adm-btn adm-btn--small" data-gk-scheme="dark" aria-pressed="false"><?= e(__('Dunkel')) ?></button>
    </div>
  </div>
  <p class="adm-muted"><?= e(__('So erscheint die Karte im Block „Gebietskarte“ – mit den Grundfarben des Kits dieser Website.')) ?></p>
  <div class="gk-prevbox" data-gk-preview data-light="<?= e(Preview::vars($kitLight)) ?>" data-dark="<?= e(Preview::vars($kitDark)) ?>" style="<?= e(Preview::vars($kitLight)) ?>"><?= $preview ?></div>
</section>

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
    <fieldset class="f"><legend><?= e(__('Darstellung')) ?></legend>
      <label><input type="checkbox" name="m[options][rest]" value="1"<?= $map['options']['rest'] ? ' checked' : '' ?>> <?= e(__('Flächen ohne Gebiet zeigen (grau)')) ?></label><br>
      <label><input type="checkbox" name="m[options][lines]" value="1"<?= $map['options']['lines'] ? ' checked' : '' ?>> <?= e(__('Grenzen der {nouns} innerhalb der Gebiete zeigen', ['nouns' => $nouns])) ?></label><br>
      <label><input type="checkbox" name="m[options][labels]" value="1"<?= $map['options']['labels'] ? ' checked' : '' ?>> <?= e(__('Namen der Gebiete in der Karte')) ?></label><br>
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
          'link' => __('Link (leer = Detailseite des Eintrags)'), 'text' => __('Infotext'), 'image' => __('Bild'), 'contact' => __('Kontakt'),
          'site' => __('Website (Schlüssel oder Adresse – für „Gebiet dieser Website“)')] as $k => $label): ?>
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
