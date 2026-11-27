<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
use Cake\Core\Configure;

$statusLabels = ['draft' => 'Entwurf', 'sending' => 'wird gesendet', 'sent' => 'gesendet'];
$statusIcons = ['draft' => 'fa-pencil-alt', 'sending' => 'fa-paper-plane', 'sent' => 'fa-check-circle'];
$this->element('addScript', ['script' => Configure::read('app.jsNamespace') . '.Admin.init();']);
echo $this->element('Newsletter/adminTabs', ['active' => 'campaigns']);
?>
<div id="newsletterCampaigns">
    <div class="filter-container">
        <h1><?php echo $title_for_layout; ?></h1>
        <div class="right">
            <span class="nl-hint"><?php echo $activeCount; ?> aktive Abonnent:innen</span>
            <?php echo $this->Html->link('<i class="fas fa-plus-circle ok"></i> Neue Kampagne', '/admin/newsletter-campaigns/add', [
                'class' => 'btn btn-outline-light', 'escape' => false, 'id' => 'add-newsletter-campaign',
            ]); ?>
        </div>
    </div>

<?php
echo '<table class="list newsletter-campaigns nl-responsive">';
echo '<tr class="sort">';
echo '<th></th>';
echo '<th class="stretch">Betreff</th>';
echo '<th>Status</th>';
echo '<th>Empfänger:innen</th>';
echo '<th>Verschickt</th>';
echo '<th>Fehler</th>';
echo '<th>Geändert / gesendet</th>';
echo '<th></th>';
echo '</tr>';

$i = 0;
foreach ($campaigns as $c) {
    $i++;
    echo '<tr class="data" id="newsletterCampaign-' . $c->id . '">';
    echo '<td>';
    echo $this->Html->link(
        '<i class="fas ' . ($c->status === 'draft' ? 'fa-pencil-alt' : 'fa-eye') . ' ok"></i>',
        '/admin/newsletter-campaigns/edit/' . $c->id,
        ['class' => 'btn btn-outline-light', 'title' => $c->status === 'draft' ? 'Bearbeiten' : 'Ansehen', 'escape' => false]
    );
    echo '</td>';
    echo '<td><b>' . h($c->subject) . '</b>' . ($c->preheader ? '<br /><small>' . h($c->preheader) . '</small>' : '') . '</td>';
    $statusText = h($statusLabels[$c->status] ?? $c->status);
    if ($c->status === 'draft' && $c->scheduled_at) {
        $statusText = 'geplant: ' . h($c->scheduled_at->format('d.m.Y H:i'));
    }
    echo '<td class="nl-status nl-status-' . h($c->status) . '"><i class="fas ' . ($statusIcons[$c->status] ?? 'fa-question') . '"></i> ' . $statusText . '</td>';
    echo '<td align="right">' . ($c->status === 'draft' ? '–' : (int)$c->count_total) . '</td>';
    echo '<td align="right">' . ($c->status === 'draft' ? '–' : (int)$c->count_sent) . '</td>';
    echo '<td align="right">' . ($c->count_failed ? '<span class="nl-err">' . (int)$c->count_failed . '</span>' : ($c->status === 'draft' ? '–' : '0')) . '</td>';
    $date = $c->sent_at ?? $c->started_at ?? $c->modified;
    echo '<td>' . ($date ? $date->format('d.m.Y H:i') : '') . '</td>';
    echo '<td class="nl-actions">';
    echo $this->Form->postLink('<i class="far fa-copy"></i>', '/admin/newsletter-campaigns/duplicate/' . $c->id, [
        'class' => 'btn btn-outline-light', 'title' => 'Kopieren', 'escape' => false,
    ]);
    if ($c->status === 'draft') {
        echo $this->Form->postLink('<i class="fas fa-trash-alt not-ok"></i>', '/admin/newsletter-campaigns/delete/' . $c->id, [
            'class' => 'btn btn-outline-light', 'title' => 'Löschen', 'escape' => false,
            'confirm' => 'Entwurf „' . $c->subject . '“ wirklich löschen?',
        ]);
    }
    echo '</td>';
    echo '</tr>';
}
echo '<tr><td colspan="8"><b>' . $i . '</b> Kampagnen</td></tr>';
echo '</table>';
?>
</div>
