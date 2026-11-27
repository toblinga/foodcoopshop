<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Reiter fuer die Newsletter-Verwaltung.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
$tabs = [
    'campaigns' => ['Kampagnen', '/admin/newsletter-campaigns', 'fa-paper-plane'],
    'subscribers' => ['Abonnent:innen', '/admin/newsletter-subscribers', 'fa-users'],
    'templates' => ['Vorlagen und Bausteine', '/admin/newsletter-templates', 'fa-th-large'],
];
?>
<style>
    .nl-admin-tabs { display: flex; gap: 4px; flex-wrap: wrap; margin: 0 0 10px; border-bottom: 2px solid #e6d9cf; }
    .nl-admin-tabs a { padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: inherit; margin-bottom: -2px; border-bottom: 2px solid transparent; }
    .nl-admin-tabs a.active { border-bottom-color: #800000; font-weight: bold; color: #800000; }
    .nl-hint { margin-right: 10px; color: #6b6b6b; }
    .nl-status-sending { color: #a86400; font-weight: bold; }
    .nl-status-sent { color: #2f7a3a; }
    .nl-err { color: #b3261e; font-weight: bold; }
    .nl-actions { white-space: nowrap; }
    .nl-pages { display: inline-flex; gap: 8px; list-style: none; margin: 0 0 0 12px; padding: 0; }
    .nl-actions form { display: inline; }
    .nl-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #eee; }
    .nl-badge.active { background: #e8f4ea; color: #2f7a3a; }
    .nl-badge.pending { background: #fff4e0; color: #a86400; }
    .nl-badge.unsubscribed { background: #eee; color: #6b6b6b; }
    .nl-badge.bounced { background: #fdecea; color: #b3261e; }
    @media (max-width: 767px) {
        table.list.nl-responsive tr.sort { display: none; }
        table.list.nl-responsive tr.data { display: block; border-bottom: 1px solid #ddd; padding: 6px 0; }
        table.list.nl-responsive tr.data td { display: inline-block; border: 0; padding: 2px 6px; }
    }
</style>
<nav class="nl-admin-tabs" aria-label="Newsletter">
<?php foreach ($tabs as $key => [$label, $url, $icon]) { ?>
    <a href="<?php echo $url; ?>" class="<?php echo $key === ($active ?? '') ? 'active' : ''; ?>"><i class="fas <?php echo $icon; ?>"></i> <?php echo h($label); ?></a>
<?php } ?>
</nav>
