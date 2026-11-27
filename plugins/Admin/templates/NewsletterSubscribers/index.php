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

$this->element('addScript', ['script' => Configure::read('app.jsNamespace') . '.Admin.init();']);
echo $this->element('Newsletter/adminTabs', ['active' => 'subscribers']);
$fmt = fn($d) => $d ? $d->format('d.m.Y H:i') : '';
$query = array_filter(['q' => $q, 'status' => $status, 'source' => $source]);
?>
<div id="newsletterSubscribers">
    <div class="filter-container">
        <?php echo $this->Form->create(null, ['type' => 'get', 'url' => '/admin/newsletter-subscribers']); ?>
            <h1><?php echo $title_for_layout; ?></h1>
            <?php
            echo $this->Form->control('q', ['label' => '', 'placeholder' => 'Name oder E-Mail', 'value' => $q, 'type' => 'search']);
            echo $this->Form->control('status', ['label' => '', 'empty' => 'Alle Status', 'options' => $statusLabels, 'value' => $status]);
            echo $this->Form->control('source', ['label' => '', 'empty' => 'Alle Quellen', 'options' => $sourceLabels, 'value' => $source]);
            echo $this->Form->button('Suchen', ['class' => 'btn btn-outline-light']);
            ?>
            <div class="right">
                <?php
                $parts = [];
                foreach ($statusLabels as $k => $label) {
                    $parts[] = '<span class="nl-badge ' . $k . '">' . h($label) . ': ' . (int)($counts[$k] ?? 0) . '</span>';
                }
                echo implode(' ', $parts);
                echo ' ' . $this->Html->link('<i class="fas fa-file-csv ok"></i> CSV-Export', '/admin/newsletter-subscribers/export' . ($query ? '?' . http_build_query($query) : ''), [
                    'class' => 'btn btn-outline-light', 'escape' => false, 'title' => 'Aktuelle Auswahl als CSV herunterladen',
                ]);
                ?>
            </div>
        <?php echo $this->Form->end(); ?>
    </div>

<?php
$this->Paginator->setPaginated($subscribers);
echo '<table class="list nl-responsive">';
echo '<tr class="sort">';
echo '<th class="stretch">' . $this->Paginator->sort('NewsletterSubscribers.email', 'E-Mail') . '</th>';
echo '<th>' . $this->Paginator->sort('NewsletterSubscribers.first_name', 'Name') . '</th>';
echo '<th>' . $this->Paginator->sort('NewsletterSubscribers.status', 'Status') . '</th>';
echo '<th>' . $this->Paginator->sort('NewsletterSubscribers.source', 'Quelle') . '</th>';
echo '<th>' . $this->Paginator->sort('NewsletterSubscribers.consent_at', 'Einwilligung') . '</th>';
echo '<th>Bestätigt / abgemeldet</th>';
echo '<th></th>';
echo '</tr>';
$i = 0;
foreach ($subscribers as $s) {
    $i++;
    echo '<tr class="data' . ($s->status === 'active' ? '' : ' deactivated') . '">';
    echo '<td>' . h($s->email) . ($s->id_customer ? ' <i class="fas fa-user ok" title="mit Kundenkonto verknüpft"></i>' : '') . '</td>';
    echo '<td>' . h(trim($s->first_name . ' ' . $s->last_name)) . '</td>';
    echo '<td><span class="nl-badge ' . h($s->status) . '">' . h($statusLabels[$s->status] ?? $s->status) . '</span></td>';
    echo '<td>' . h($sourceLabels[$s->source] ?? $s->source) . '</td>';
    echo '<td title="' . h((string)$s->consent_text) . '">' . $fmt($s->consent_at) . ($s->consent_ip ? '<br /><small>' . h($s->consent_ip) . '</small>' : '') . '</td>';
    echo '<td>' . ($s->unsubscribed_at ? 'abgemeldet ' . $fmt($s->unsubscribed_at) : $fmt($s->confirmed_at)) . '</td>';
    echo '<td class="nl-actions">';
    if (in_array($s->status, ['active', 'pending'], true)) {
        echo $this->Form->postLink('<i class="fas fa-user-slash not-ok"></i> Abmelden', '/admin/newsletter-subscribers/unsubscribe/' . $s->id, [
            'class' => 'btn btn-outline-light', 'escape' => false,
            'confirm' => h($s->email) . ' vom Newsletter abmelden?',
        ]);
    }
    echo '</td>';
    echo '</tr>';
}
echo '<tr><td colspan="7"><b>' . $i . '</b> von ' . $this->Paginator->counter('{{count}}') . ' Einträgen';
if ($this->Paginator->hasPage(2)) {
    echo ' <ul class="nl-pages">' . $this->Paginator->prev('‹ zurück') . $this->Paginator->numbers() . $this->Paginator->next('weiter ›') . '</ul>';
}
echo '</td></tr>';
echo '</table>';
?>
</div>
