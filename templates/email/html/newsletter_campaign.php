<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Inhalt einer Kampagne aus vorbereiteten Bausteinen (NewsletterRenderService::prepareBlocks()).
 *
 * @var \Cake\View\View $this
 * @var list<array<string, mixed>> $blocks
 * @var string $themeColor
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
$themeColor = (string)$themeColor;
foreach ($blocks as $block) {
    switch ($block['type']) {
        case 'heading':
            if ($block['level'] === 1) {
                echo '<h1 class="nl-h1" style="margin:0 0 16px 0;font-size:28px;line-height:1.25;color:' . h($themeColor) . ';">' . h($block['text']) . '</h1>';
            } else {
                echo '<h2 style="margin:22px 0 10px 0;font-size:20px;line-height:1.3;color:#222222;">' . h($block['text']) . '</h2>';
            }
            break;
        case 'text':
            echo '<div style="margin:0 0 14px 0;">' . $block['html'] . '</div>';
            break;
        case 'image':
            $img = '<img src="' . h($block['src']) . '" alt="' . h($block['alt']) . '" width="536" style="display:block;width:100%;max-width:536px;height:auto;border-radius:4px;">';
            if ($block['href'] !== '') {
                $img = '<a href="' . h($block['href']) . '">' . $img . '</a>';
            }
            echo '<div style="margin:6px 0 18px 0;">' . $img . '</div>';
            break;
        case 'button':
            echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="nl-button" style="margin:8px 0 20px 0;"><tr><td style="border-radius:24px;background:' . h($themeColor) . ';text-align:center;">';
            echo '<a href="' . h($block['href']) . '" style="display:inline-block;padding:12px 24px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:24px;">' . h($block['label']) . '</a>';
            echo '</td></tr></table>';
            break;
        case 'divider':
            echo '<hr style="border:0;border-top:1px solid #e5e2da;margin:22px 0;">';
            break;
        case 'spacer':
            echo '<div style="height:' . (int)$block['height'] . 'px;line-height:' . (int)$block['height'] . 'px;font-size:1px;">&nbsp;</div>';
            break;
    }
}
