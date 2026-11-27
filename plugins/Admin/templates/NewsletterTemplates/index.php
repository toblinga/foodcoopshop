<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Verwaltung der gespeicherten Newsletter-Vorlagen und -Bausteine (anlegen geschieht im Editor).
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
use Cake\Core\Configure;

$this->element('addScript', ['script' => Configure::read('app.jsNamespace') . '.Admin.init();']);
echo $this->element('Newsletter/adminTabs', ['active' => 'templates']);
$typeLabels = [
    'header' => 'Kopf mit Logo', 'heading' => 'Überschrift', 'text' => 'Text', 'image' => 'Bild', 'imageText' => 'Bild + Text',
    'columns' => '2 Spalten', 'button' => 'Knopf', 'divider' => 'Trenner', 'spacer' => 'Abstand', 'callout' => 'Hinweis / Zitat',
    'blog' => 'Blog-Beitrag', 'recipe' => 'Rezept', 'products' => 'Produkte',
];
$rows = function (array $items, bool $isTemplate) use ($typeLabels) {
    if ($items === []) {
        echo '<p class="nl-hint">' . ($isTemplate
            ? 'Noch keine Vorlagen. Im Editor unter „Vorlagen“ → „Aktuelle Kampagne als Vorlage speichern“.'
            : 'Noch keine Bausteine. Im Editor am Baustein auf den Stern klicken.') . '</p>';
        return;
    }
    echo '<table class="list nl-responsive">';
    echo '<tr class="sort"><th class="stretch">Name</th><th>' . ($isTemplate ? 'Bausteine' : 'Art') . '</th><th>Angelegt</th><th></th></tr>';
    foreach ($items as $t) {
        echo '<tr class="data">';
        echo '<td>';
        echo $this->Form->create(null, ['url' => '/admin/newsletter-templates/rename/' . $t['id'], 'class' => 'nl-rename']);
        echo $this->Form->text('name', ['value' => $t['name'], 'aria-label' => 'Name']);
        echo $this->Form->button('<i class="fas fa-check"></i>', ['class' => 'btn btn-outline-light', 'escapeTitle' => false, 'title' => 'Umbenennen']);
        echo $this->Form->end();
        echo '</td>';
        if ($isTemplate) {
            $types = array_map(fn($b) => $typeLabels[$b['type'] ?? ''] ?? null, $t['blocks']['blocks'] ?? []);
            echo '<td><small>' . h(implode(', ', array_filter($types))) . '</small></td>';
        } else {
            echo '<td>' . h($typeLabels[$t['blocks'][0]['type'] ?? ''] ?? '') . '</td>';
        }
        echo '<td>' . h($t['created'] ? date('d.m.Y', strtotime($t['created'])) : '') . '</td>';
        echo '<td class="nl-actions">';
        if ($isTemplate) {
            echo $this->Html->link('<i class="fas fa-plus-circle ok"></i> Neue Kampagne daraus', '/admin/newsletter-campaigns/add?template=' . $t['id'], ['class' => 'btn btn-outline-light', 'escape' => false]);
        }
        echo $this->Form->postLink('<i class="fas fa-trash-alt not-ok"></i>', '/admin/newsletter-templates/delete/' . $t['id'], [
            'class' => 'btn btn-outline-light', 'escape' => false, 'title' => 'Löschen',
            'confirm' => '„' . $t['name'] . '“ löschen? Kampagnen, die ihn schon verwenden, bleiben unverändert.',
        ]);
        echo '</td></tr>';
    }
    echo '</table>';
};
?>
<style>.nl-rename { display: flex; gap: 6px; } .nl-rename input { flex: 1; min-width: 160px; }</style>
<div id="newsletterTemplates">
    <div class="filter-container"><h1><?php echo $title_for_layout; ?></h1></div>
    <h2>Vorlagen (ganze Newsletter)</h2>
    <?php $rows($templates, true); ?>
    <h2 style="margin-top:24px">Gespeicherte Bausteine</h2>
    <?php $rows($blocks, false); ?>
</div>
