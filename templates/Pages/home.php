<?php
declare(strict_types=1);

use Cake\Core\Configure;
use App\Model\Entity\Block;
use App\Services\Newsletter\NewsletterSubscriberService;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 1.0.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */

$this->element('addScript', ['script' =>
    Configure::read('app.jsNamespace').".Helper.init();"
]);

echo $this->element('acceptUpdatedTermsOfUseForm');

$srcLargeImage = $this->Html->getHomeImageSrc('single');
if ($srcLargeImage != '') {
    $srcMobileImage = $this->Html->getHomeImageSrc('mobile');
    if ($srcMobileImage == '') {
        $srcMobileImage = $srcLargeImage;
    }
    $this->set('pageHeaderImageDesktop', $srcLargeImage);
    $this->set('pageHeaderImageMobile', $srcMobileImage);
}

if ($identity !== null) {
    if ($identity->isSuperadmin() || $identity->isAdmin()) {
        echo $this->Html->link(
            '<i class="fas fa-pencil-alt"></i>',
            $this->Slug->getPageEditHome(),
            [
                'class' => 'btn btn-outline-light edit-shortcut-button home-edit-shortcut-button',
                'title' => __('Edit'),
                'escape' => false,
            ]
        );
    }
}

$blockHeadingFont = (string) Configure::read('appDb.FCS_FONT_BLOCK_HEADING');
if ($blockHeadingFont === '') {
    $blockHeadingFont = 'open-sans';
}
$blockHeadingFontClass = 'font-' . $blockHeadingFont;

if (!empty($homeBlocks)) {
    echo '<section class="home-blocks">';
        foreach ($homeBlocks as $block) {
            $imagePosition = (int)($block->image_position ?? Block::IMAGE_POSITION_LEFT);
            $blockClass = $imagePosition === Block::IMAGE_POSITION_RIGHT ? 'home-block image-right' : 'home-block image-left';
            if (empty($block->image)) {
                $blockClass .= ' no-image';
            }
            echo '<article class="' . $blockClass . '">';
                echo '<div class="home-block-inner">';
                    if (!empty($block->image)) {
                        echo '<div class="home-block-image">';
                            echo $this->Html->image($this->Html->getBlockImageSrc($block));
                        echo '</div>';
                    }
                    echo '<div class="home-block-body">';
                        if (!empty($block->heading)) {
                            echo '<h2 class="' . h($blockHeadingFontClass) . '">' . h($block->heading) . '</h2>';
                        }
                        if (!empty($block->content)) {
                            echo '<div class="home-block-text">' . $block->content . '</div>';
                        }
                        $primaryLabel = trim((string)($block->primary_label ?? ''));
                        $primaryHref = trim((string)($block->primary_href ?? ''));
                        $secondaryLabel = trim((string)($block->secondary_label ?? ''));
                        $secondaryHref = trim((string)($block->secondary_href ?? ''));
                        if (($primaryLabel !== '' && $primaryHref !== '') || ($secondaryLabel !== '' && $secondaryHref !== '')) {
                            echo '<div class="header-promo-actions home-block-actions">';
                                if ($primaryLabel !== '' && $primaryHref !== '') {
                                    echo '<a class="btn btn-success primary" href="' . h($primaryHref) . '">' . h($primaryLabel) . '</a>';
                                }
                                if ($secondaryLabel !== '' && $secondaryHref !== '') {
                                    echo '<a class="btn btn-outline-light secondary" href="' . h($secondaryHref) . '">' . h($secondaryLabel) . '</a>';
                                }
                            echo '</div>';
                        }
                    echo '</div>';
                echo '</div>';
            echo '</article>';
        }
    echo '</section>';
}

if (NewsletterSubscriberService::isEnabled()) {
    echo $this->element('Newsletter/signupForm', ['variant' => 'teaser']);
}

if (!empty($blogPosts) && $blogPosts->count() > 0) {
    echo '<h1 class="news">'.__('News').'</h1>';
}
if (!empty($blogPosts) && $blogPosts->count() > 0) {
    echo '<div class="blog-carousel">';
        echo '<button type="button" class="products-nav blog-nav blog-nav-prev" aria-label="zurück" onclick="document.querySelector(\'.blog-wrapper\').scrollBy({left: -280, behavior: \'smooth\'})">&#8249;</button>';
        echo $this->element('blogPosts', [
            'blogPosts' => $blogPosts,
            'useCarousel' => false
        ]);
        echo '<button type="button" class="products-nav blog-nav blog-nav-next" aria-label="weiter" onclick="document.querySelector(\'.blog-wrapper\').scrollBy({left: 280, behavior: \'smooth\'})">&#8250;</button>';
    echo '</div>';
}

if (!empty($newProducts)) {

    $this->element('addScript', ['script' =>
        Configure::read('app.jsNamespace').".ModalImage.addLightboxToWysiwygEditorImages('.pw .toggle-content.description img');".
        Configure::read('app.jsNamespace').".ModalImage.init('.pw a.open-with-modal');".
        Configure::read('app.jsNamespace').".Helper.initTooltip('.ew .price, .c3 .is-stock-product');".
        Configure::read('app.jsNamespace').".Helper.bindToggleLinks();".
        Configure::read('app.jsNamespace').".Helper.initAmountSwitcher();".
        Configure::read('app.jsNamespace').".Helper.initProductAttributesButtons();".
        Configure::read('app.jsNamespace').".Cart.initAddToCartButton();".
        Configure::read('app.jsNamespace').".Cart.initRemoveFromCartLinks();"
    ]);

    if ($identity !== null) {
        $this->element('addScript', ['script' =>
            Configure::read('app.jsNamespace').".Helper.setFutureOrderDetails('".addslashes(json_encode($identity->getFutureOrderDetails()))."');"
        ]);
    }

    if (Configure::read('app.showOrderedProductsTotalAmountInCatalog')) {
        $this->element('addScript', ['script' =>
            Configure::read('app.jsNamespace') . ".Helper.initTooltip('.ordered-products-total-amount');"
        ]);
    }

    $isFirstElement = empty($blogPosts) || $blogPosts->count() == 0;
    echo '<h1 style="float:left;' . (!$isFirstElement ? 'margin-top:10px;' : '') . '">';
        echo 'Produkte der Woche';
    echo '</h2>';

    echo '<div class="products-carousel">';
        echo '<button type="button" class="products-nav products-nav-prev" aria-label="zurück" onclick="document.querySelector(\'.products-wrapper\').scrollBy({left: -320, behavior: \'smooth\'})">&#8249;</button>';
    echo '<div class="products-wrapper">';
        foreach ($newProducts as $product) {
            $imageData = $this->Html->getProductImageSrcWithManufacturerImageFallback(!empty($product->image) ? (int) $product->image->id_image : 0, (int) $product->id_manufacturer);
            $detailUrl = $this->Slug->getProductDetail($product->id_product, $product->name);
            echo '<div class="pw home-product-card">';
                echo '<a class="home-product-image" href="' . h($detailUrl) . '">';
                    echo '<img src="' . h($imageData['productImageSrc']) . '" alt="' . h($product->name) . '" loading="lazy" />';
                echo '</a>';
                echo '<div class="home-product-body">';
                    echo '<a class="home-product-name" href="' . h($detailUrl) . '">' . h($product->name) . '</a>';
                    $priceValue = (float) ($product->selling_prices['gross_price'] ?? $product->gross_price);
            $pricePrefix = '';
            $priceSuffix = '';
            if (!empty($product->product_attributes)) {
                $variantPrices = [];
                $variantPerUnit = [];
                foreach ($product->product_attributes as $attribute) {
                    $variantPrice = (float) ($attribute->selling_prices['gross_price'] ?? $attribute->gross_price);
                    if ($variantPrice > 0) {
                        $variantPrices[] = $variantPrice;
                    } elseif (!empty($attribute->selling_prices['price_incl_per_unit'])) {
                        // Gewichtsware: nur Preis pro Kilo hinterlegt
                        $variantPerUnit[] = (float) $attribute->selling_prices['price_incl_per_unit'];
                    }
                }
                if (!empty($variantPrices)) {
                    $priceValue = min($variantPrices);
                } elseif (!empty($variantPerUnit)) {
                    $priceValue = min($variantPerUnit);
                    $pricePrefix = '';
                    $priceSuffix = ' /kg';
                }
                $pricePrefix = '';
            }
            echo '<div class="home-product-price">' . h($pricePrefix . $this->Number->formatAsCurrency($priceValue) . $priceSuffix) . '</div>';
                echo '</div>';
            echo '</div>';
        }
    echo '</div>';
        echo '<button type="button" class="products-nav products-nav-next" aria-label="weiter" onclick="document.querySelector(\'.products-wrapper\').scrollBy({left: 320, behavior: \'smooth\'})">&#8250;</button>';
    echo '</div>';
}

if (Configure::read('appDb.FCS_FOODCOOPS_MAP_ENABLED')) {
    echo $this->element('foodCoopShopInstancesMap', [
        'isFirstElement' => (empty($blogPosts) || $blogPosts->count() == 0) && empty($newProducts)
    ]);
}
?>
