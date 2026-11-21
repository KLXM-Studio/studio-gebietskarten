<?php
/** Übersicht der Karten + neue Karte. @var list<array> $maps  @var array $packs  @var array $flash */
use Klxm\Gebietskarten\Bases;
use Klxm\Gebietskarten\Gebietskarten as G;

$actions = '<a class="adm-btn adm-btn--ghost" href="' . e(url(G::ADMIN . '/kartendaten')) . '">' . icon('download-simple') . ' ' . e(__('Kartendaten')) . '</a>';
echo \Core\Theme::capture(__DIR__ . '/_head.php', ['h1' => __('Gebietskarten'), 'flash' => $flash, 'actions' => $actions,
    'lead' => __('Flächen einer Karte zu Gebieten zusammenfassen – Regionalgruppen, Kreise eines Verbands, Vertriebsgebiete.')]);
$base = Bases::all()['de'];
?>
<?php if ($maps): ?>
<section class="adm-card">
  <h2><?= e(__('Karten')) ?></h2>
  <table class="adm-table gk-adm-maps">
    <thead><tr><th scope="col"><?= e(__('Titel')) ?></th><th scope="col"><?= e(__('Ebene')) ?></th><th scope="col"><?= e(__('Ausschnitt')) ?></th><th scope="col"><?= e(__('Gebiete')) ?></th><th scope="col"><?= e(__('Geändert')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($maps as $m): ?>
      <tr>
        <td><a href="<?= e(url(G::ADMIN . '/' . $m['id'])) ?>"><?= e($m['title']) ?></a><?php if ($m['source'] === 'table'): ?> <span class="adm-badge"><?= e(__('Datentabelle')) ?></span><?php endif; ?></td>
        <td><?= e(Bases::levelLabel($m['base'], $m['level'])) ?></td>
        <td><?= e($m['scope'] ? implode(', ', array_map(fn($s) => $base['parts'][$s] ?? $s, $m['scope'])) : $base['label']) ?></td>
        <td><?= (int) $m['count'] ?></td>
        <td><?= e(fmt()->datetime($m['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php else: ?>
<p class="adm-muted"><?= e(__('Noch keine Karten. Legen Sie die erste unten an.')) ?></p>
<?php endif; ?>

<section class="adm-card" id="gk-neu">
  <h2><?= e(__('Neue Karte')) ?></h2>
  <form method="post" action="<?= e(url(G::ADMIN . '/neu')) ?>" class="gk-adm-form">
    <?= csrf_field() ?>
    <input type="hidden" name="m[base]" value="de">
    <div class="f"><label for="gk-nt"><?= e(__('Titel')) ?></label><input id="gk-nt" name="m[title]" maxlength="160" required placeholder="<?= e(__('z. B. Regionalgruppen')) ?>"></div>
    <div class="f"><label for="gk-nl"><?= e(__('Ebene (Flächen, aus denen Gebiete entstehen)')) ?></label>
      <select id="gk-nl" name="m[level]"><?php foreach ($base['levels'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === 'kreise' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <p class="f-help"><?= e(__('Gemeinden brauchen nachgeladene Kartendaten je Land (Kartendaten).')) ?></p></div>
    <fieldset class="f gk-adm-scope"><legend><?= e($base['partLabel']) ?></legend>
      <p class="f-help"><?= e(__('Keine Auswahl = ganz Deutschland.')) ?></p>
      <div class="gk-adm-checks"><?php foreach ($base['parts'] as $sn => $name): ?><label><input type="checkbox" name="m[scope][]" value="<?= e($sn) ?>"> <?= e($name) ?></label><?php endforeach; ?></div>
    </fieldset>
    <fieldset class="f"><legend><?= e(__('Gebiete')) ?></legend>
      <label><input type="radio" name="m[source]" value="own" checked> <?= e(__('hier pflegen (Name, Farbe, Link, Infos je Gebiet)')) ?></label><br>
      <label><input type="radio" name="m[source]" value="table"> <?= e(__('aus einer Datentabelle (ein Eintrag = ein Gebiet, auch geteilte Tabellen im Netzwerk)')) ?></label>
    </fieldset>
    <button class="adm-btn adm-btn--primary"><?= e(__('Karte anlegen')) ?></button>
  </form>
</section>
