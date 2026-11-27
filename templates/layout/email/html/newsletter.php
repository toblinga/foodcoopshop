<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Mobilfreundliches Newsletter-Layout (600 px, einspaltig, Tabellen für Outlook).
 * Platzhalter {{unsubscribe_url}} wird je Empfänger:in ersetzt.
 *
 * @var \Cake\View\View $this
 * @var string $preheader
 * @var string $appName
 * @var string $appAddress
 * @var string $baseUrl
 * @var string $logoUrl
 * @var string $themeColor
 * @var string $unsubscribeUrlPlaceholder
 * @var \App\Model\Entity\NewsletterCampaign $campaign
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">
    <meta name="x-apple-disable-message-reformatting">
    <title><?php echo h($campaign->subject); ?></title>
    <style>
        body { margin:0; padding:0; background:#f2f0eb; }
        a { color: <?php echo h($themeColor); ?>; }
        img { border:0; outline:none; text-decoration:none; max-width:100%; height:auto; }
        .nl-content p { margin:0 0 14px 0; }
        .nl-content h2, .nl-content h3 { margin:18px 0 10px 0; }
        @media only screen and (max-width: 620px) {
            .nl-container { width:100% !important; }
            .nl-pad { padding-left:18px !important; padding-right:18px !important; }
            .nl-h1 { font-size:24px !important; }
            .nl-button a { display:block !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f2f0eb;">
    <?php if ($preheader !== '') { ?>
        <div data-preheader style="display:none;max-height:0;overflow:hidden;mso-hide:all;"><?php echo h($preheader); ?></div>
    <?php } ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f0eb;">
        <tr>
            <td align="center" style="padding:20px 8px;">
                <table role="presentation" class="nl-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:6px;">
                    <tr>
                        <td align="center" class="nl-pad" style="padding:24px 32px 12px 32px;border-bottom:3px solid <?php echo h($themeColor); ?>;">
                            <a href="<?php echo h($baseUrl); ?>"><img src="<?php echo h($logoUrl); ?>" width="160" alt="<?php echo h($appName); ?>" style="width:160px;"></a>
                        </td>
                    </tr>
                    <tr>
                        <td class="nl-pad nl-content" style="padding:24px 32px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.55;color:#222222;">
                            <?php echo $this->fetch('content'); ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="nl-pad" style="padding:18px 32px 26px 32px;background:#faf9f6;border-top:1px solid #e5e2da;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#666666;border-radius:0 0 6px 6px;">
                            <?php echo h($appName); ?><br>
                            <?php echo strip_tags($appAddress, '<br>'); ?><br><br>
                            Du bekommst diese E-Mail, weil du dich für unseren Newsletter angemeldet hast.<br>
                            <a href="<?php echo $unsubscribeUrlPlaceholder; ?>" style="color:#666666;text-decoration:underline;">Vom Newsletter abmelden</a>
                            &nbsp;·&nbsp;
                            <a href="<?php echo h($baseUrl); ?>" style="color:#666666;text-decoration:underline;"><?php echo h((string)preg_replace('#^https?://#', '', $baseUrl)); ?></a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
