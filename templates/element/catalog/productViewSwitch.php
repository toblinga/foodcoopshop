<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://www.foodcoopshop.com
 */

$isTileView = (bool) ($isTileView ?? false);
?>
<span class="product-view-switch">
    <a href="javascript:void(0);" data-view="liste" class="view-switch-list<?php echo !$isTileView ? ' active' : ''; ?>" title="Listenansicht" aria-label="Listenansicht">
        <i class="fas fa-list"></i>
    </a><a href="javascript:void(0);" data-view="kacheln" class="view-switch-tiles<?php echo $isTileView ? ' active' : ''; ?>" title="Kachelansicht" aria-label="Kachelansicht">
        <i class="fas fa-th-large"></i>
    </a>
</span>
<script>
(function () {
    var switchEl = document.currentScript.previousElementSibling;
    if (!switchEl || !switchEl.classList.contains('product-view-switch')) {
        return;
    }
    switchEl.querySelectorAll('a[data-view]').forEach(function (link) {
        link.addEventListener('click', function () {
            var view = link.getAttribute('data-view') === 'kacheln' ? 'kacheln' : 'liste';
            document.cookie = 'fcsProductView=' + view + '; path=/; max-age=31536000; SameSite=Lax';
            document.querySelectorAll('.products-wrapper').forEach(function (wrapper) {
                wrapper.classList.toggle('kacheln', view === 'kacheln');
            });
            switchEl.querySelectorAll('a[data-view]').forEach(function (other) {
                other.classList.toggle('active', other === link);
            });
        });
    });
})();
</script>
