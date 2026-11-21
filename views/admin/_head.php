<?php
/** Kopf der Seiten „Gebietskarten“. @var string $h1  @var string $lead  @var string $actions (HTML, optional) */
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= e(__('Erweiterung')) ?> · <?= e(__('Gebietskarten')) ?></p><h1><?= e($h1) ?></h1><?php if (!empty($lead)): ?><p class="adm-muted"><?= e($lead) ?></p><?php endif; ?></div>
  <?php if (!empty($actions)): ?><div class="gk-adm-actions"><?= $actions ?></div><?php endif; ?>
</header>
