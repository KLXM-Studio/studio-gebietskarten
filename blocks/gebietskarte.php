<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Gebietskarten. Copyright (C) 2026 KLXM and contributors (see LICENSE)
/**
 * Block „Gebietskarte“ (Erweiterung gebietskarten): Karte wählen, Darstellung (Übersicht, ein Gebiet, Gebiet dieser Seite oder
 * Website) und Klickverhalten (Link, Info-Fenster, nichts). Ausgabe: Klxm\Gebietskarten\Render::html().
 * @var \Core\Block $b  @var array $d
 */
use Klxm\Gebietskarten\Render;
use Klxm\Gebietskarten\Repo;

$map = ($id = (int) ($d['map'] ?? 0)) > 0 ? Repo::map($id) : null;
[$hlMap, $hlKey] = array_pad(explode(':', (string) ($d['highlight'] ?? ''), 2), 2, '');
$title = trim((string) ($d['title'] ?? ''));
?>
<div class="<?= e(app()->theme->def['container_class'] ?? 'wrap') ?> gk-block">
  <?php if ($title !== '' || is_editing()): ?><h2 class="gk-block__title"<?= $b->edit('title') ?>><?= e($title) ?></h2><?php endif; ?>
  <?php if (!$map): ?>
    <?php if (is_editing()): ?><p class="gk-note"><?= e(__('Bitte eine Karte wählen – Karten legen Sie unter Gebietskarten an.')) ?></p><?php endif; ?>
  <?php else: ?>
    <?= Render::html($map, [
        'mode' => (string) ($d['mode'] ?? 'overview'),
        'highlight' => (int) $hlMap === (int) $map['id'] ? $hlKey : '',
        'action' => (string) ($d['action'] ?? 'link'),
        'title' => $title,
        'list' => match ((string) ($d['list'] ?? '')) { 'show' => true, 'hide' => false, default => null },
    ]) ?>
  <?php endif; ?>
</div>
