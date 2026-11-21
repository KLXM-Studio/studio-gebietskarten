<?php
/** Kartendaten: Länder und Kreise im Paket, Gemeinden je Land nachladen. @var array $packs  @var string $source  @var list<string> $needed  @var array $flash */
use Klxm\Gebietskarten\Bases;
use Klxm\Gebietskarten\Render;
use Klxm\Gebietskarten\Gebietskarten as G;

$actions = '<a class="adm-btn adm-btn--ghost" href="' . e(url(G::ADMIN)) . '">' . icon('arrow-left') . ' ' . e(__('Zur Übersicht')) . '</a>';
echo \Core\Theme::capture(__DIR__ . '/_head.php', ['h1' => __('Kartendaten'), 'flash' => $flash, 'actions' => $actions,
    'lead' => __('Bundesländer und Kreise sind im Paket. Gemeinden laden Sie je Land einmal nach – danach liegen sie auf diesem Server; Besucher haben keinen Kontakt zu Dritten.')]);
$att = Bases::attribution('de', 'kreise');
?>
<section class="adm-card">
  <h2><?= e(__('Gemeinden je Bundesland')) ?></h2>
  <form method="post" action="<?= e(url(G::ADMIN . '/kartendaten')) ?>">
    <?= csrf_field() ?>
    <table class="adm-table gk-adm-packs">
      <thead><tr><th scope="col"><span class="adm-sr"><?= e(__('Auswahl')) ?></span></th><th scope="col"><?= e(__('Land')) ?></th><th scope="col"><?= e(__('Stand')) ?></th><th scope="col"><?= e(__('Gemeinden')) ?></th><th scope="col"><?= e(__('Größe')) ?></th></tr></thead>
      <tbody>
      <?php foreach (Bases::LAENDER as $sn => $name): $sn = str_pad((string) $sn, 2, '0', STR_PAD_LEFT); $p = $packs[$sn] ?? null; ?>
        <tr>
          <td><input type="checkbox" name="parts[]" value="<?= e($sn) ?>" id="gk-p<?= e($sn) ?>"<?= !$p && in_array($sn, $needed, true) ? ' checked' : '' ?>></td>
          <td><label for="gk-p<?= e($sn) ?>"><?= e($name) ?></label><?php if (!$p && in_array($sn, $needed, true)): ?> <span class="adm-badge"><?= e(__('von einer Karte gebraucht')) ?></span><?php endif; ?></td>
          <td><?= $p ? e(__('geladen {date}', ['date' => fmt()->datetime($p['at'])])) : '<span class="adm-muted">' . e(__('nicht geladen')) . '</span>' ?></td>
          <td><?= $p ? (int) $p['count'] : '' ?></td>
          <td><?= $p ? e(fmt()->bytes($p['bytes'])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="adm-savebar">
      <button class="adm-btn adm-btn--primary" name="do" value="install"><?= icon('download-simple') ?> <?= e(__('Ausgewählte laden')) ?></button>
      <button class="adm-btn adm-btn--ghost" name="do" value="remove"><?= e(__('Ausgewählte entfernen')) ?></button>
    </div>
  </form>
  <p class="adm-muted"><?= e(__('Quelle: {source}', ['source' => $source])) ?> · <?= e(__('Konsole: php bin/console gebietskarten:daten --land=05')) ?></p>
</section>
<section class="adm-card">
  <h2><?= e(__('Quelle und Lizenz')) ?></h2>
  <p><?= e(__('Verwaltungsgebiete 1:250 000 (VG250) des Bundesamts für Kartographie und Geodäsie, vereinfacht für die Darstellung im Web. Lizenz: Datenlizenz Deutschland – Namensnennung – Version 2.0. Unter jeder Karte, in der Vorschau und in jedem SVG-Export steht automatisch derselbe Quellenvermerk:')) ?></p>
  <?= Render::attributionHtml('de', 'kreise', 'gk-adm-src') ?>
  <p class="adm-muted"><?= e(__('Als Text (Mediathek, Grafik-Editor): {text}', ['text' => Render::attributionText('de', 'kreise')])) ?></p>
  <p><a href="<?= e($att['url']) ?>" target="_blank" rel="noopener"><?= e(__('Lizenztext (govdata.de)')) ?></a></p>
</section>
