<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://www.foodcoopshop.com
 */

namespace Api\Controller;

use App\Model\Entity\Product;
use App\Services\ProductQuantityService;
use App\Model\Table\CustomerPermissionsTable as P;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * /api/v1/products – products incl. attributes (variants).
 *
 * Changes go through the same ProductsTable methods as the admin (same validation, same side effects)
 * and are written to the action log. `?dry_run=1` returns the changes without saving.
 * `if_unmodified_since` (ISO date) in the body prevents overwriting a product changed in the meantime.
 */
class ProductsController extends ApiAppController
{
    private const PRODUCT_FIELDS = ['name', 'description_short', 'description', 'unity', 'gross_price', 'deposit',
        'active', 'quantity', 'always_available', 'tax_rate', 'price_per_unit', 'if_unmodified_since'];
    // the tax rate belongs to the product, a variant always has the rate of its product
    private const ATTRIBUTE_FIELDS = ['gross_price', 'deposit', 'quantity', 'always_available', 'price_per_unit'];

    public function index(): Response
    {
        if ($r = $this->requirePermission(P::API_PRODUCTS_READ)) {
            return $r;
        }
        $query = $this->baseQuery();
        $q = $this->getRequest()->getQueryParams();
        if (isset($q['manufacturer_id'])) {
            $query->where(['Products.id_manufacturer' => (int) $q['manufacturer_id']]);
        }
        if (isset($q['active'])) {
            $query->where(['Products.active' => (int) $q['active']]);
        } else {
            $query->where(['Products.active IN' => [APP_OFF, APP_ON]]); // without deleted
        }
        if (!empty($q['modified_since'])) {
            $query->where(['Products.modified >=' => new DateTime($q['modified_since'])]);
        }
        $products = array_map(fn($p) => $this->toArray($p), $query->all()->toList());
        return $this->json(['count' => count($products), 'products' => $products]);
    }

    public function view(int $id): Response
    {
        if ($r = $this->requirePermission(P::API_PRODUCTS_READ)) {
            return $r;
        }
        $product = $this->baseQuery()->where(['Products.id_product' => $id])->first();
        return $product ? $this->json($this->toArray($product)) : $this->error(404, 'product not found');
    }

    public function edit(int $id): Response
    {
        if ($r = $this->requirePermission(P::API_PRODUCTS_WRITE)) {
            return $r;
        }
        $product = $this->baseQuery()->where(['Products.id_product' => $id])->first();
        if ($product === null) {
            return $this->error(404, 'product not found');
        }
        $data = $this->body();
        if ($unknown = array_diff(array_keys($data), self::PRODUCT_FIELDS)) {
            return $this->error(422, 'unknown fields', ['fields' => array_values($unknown)]);
        }
        if (!empty($data['if_unmodified_since']) && $product->modified > new DateTime($data['if_unmodified_since'])) {
            return $this->error(409, 'product was modified in the meantime', ['modified' => $product->modified->toIso8601String()]);
        }
        unset($data['if_unmodified_since']);
        return $this->apply($product, (string) $id, $data, $this->toArray($product));
    }

    public function editAttribute(int $id, int $attributeId): Response
    {
        if ($r = $this->requirePermission(P::API_PRODUCTS_WRITE)) {
            return $r;
        }
        $product = $this->baseQuery()->where(['Products.id_product' => $id])->first();
        $current = $product ? $this->toArray($product) : null;
        $attribute = $current ? current(array_filter($current['attributes'], fn($a) => $a['attribute_id'] === $attributeId)) : false;
        if (!$attribute) {
            return $this->error(404, 'product attribute not found');
        }
        $data = $this->body();
        if ($unknown = array_diff(array_keys($data), self::ATTRIBUTE_FIELDS)) {
            return $this->error(422, 'unknown fields', ['fields' => array_values($unknown)]);
        }
        return $this->apply($product, $id . '-' . $attributeId, $data, $attribute);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $current
     */
    private function apply(Product $product, string $compositeId, array $data, array $current): Response
    {
        $changes = [];
        foreach ($data as $field => $value) {
            if ($field === 'price_per_unit') {
                // given and compared as price per kg / per l, whatever unit the variant is stored in
                $new = $this->requestedBasePrice($value);
                if ($new instanceof Response) {
                    return $new;
                }
                $old = $this->basePrice($current['price_per_unit'] ?? null);
                if ($old === null || abs($old - $new) > 0.00001) {
                    $changes[$field] = ['old' => $old, 'new' => $new];
                }
                continue;
            }
            if ($field === 'tax_rate' && $this->taxIdForRate((float) $value) === null) {
                return $this->error(422, 'unknown tax rate', ['tax_rate' => $value]);
            }
            if ($field === 'quantity') {
                // Goods that are counted by weight/volume (price per unit + "use weight as amount") have a decimal
                // stock in the unit of the product (kg, g, l, ml); everything else is counted in pieces.
                if (!is_numeric($value)) {
                    return $this->error(422, 'quantity needs to be a number');
                }
                if (($current['quantity_unit'] ?? null) === null && (float) $value != (int) round((float) $value)) {
                    return $this->error(422, 'quantity needs to be a whole number: this product is counted in pieces', ['quantity' => $value]);
                }
            }
            $old = $current[$field] ?? null;
            if ($old != $value) {
                $changes[$field] = ['old' => $old, 'new' => $value];
            }
        }
        if ($this->getRequest()->getQuery('dry_run')) {
            return $this->json(['dry_run' => true, 'id' => $compositeId, 'changes' => $changes]);
        }
        if (!$changes) {
            return $this->json(['id' => $compositeId, 'changes' => []]);
        }
        $productsTable = $this->getTableLocator()->get('Products');
        try {
            $countedInUnits = ($current['quantity_unit'] ?? null) !== null;
            $productsTable->getConnection()->transactional(function () use ($productsTable, $compositeId, $changes, $product, $countedInUnits) {
                $new = fn($f) => $changes[$f]['new'];
                if (array_intersect_key($changes, array_flip(['name', 'description_short', 'description', 'unity']))) {
                    $productsTable->changeName([[$compositeId => [
                        'name' => $changes['name']['new'] ?? $product->name,
                        'description_short' => $changes['description_short']['new'] ?? (string) $product->description_short,
                        'description' => $changes['description']['new'] ?? (string) $product->description,
                        'unity' => $changes['unity']['new'] ?? (string) $product->unity,
                    ]]]);
                }
                // order matters: the tax rate first (FCS keeps the gross price when the rate changes), then the
                // price per unit (recalculates the gross price of the variant), then an explicit gross price
                if (isset($changes['tax_rate'])) {
                    $this->changeTaxRate($product, (float) $new('tax_rate'));
                }
                if (isset($changes['price_per_unit'])) {
                    $this->changePricePerUnit($productsTable, $product, $compositeId, (float) $new('price_per_unit'));
                }
                if (isset($changes['gross_price'])) {
                    $productsTable->changePrice([[$compositeId => ['gross_price' => (float) $new('gross_price')]]]);
                }
                if (isset($changes['deposit'])) {
                    $productsTable->changeDeposit([[$compositeId => (float) $new('deposit')]]);
                }
                if (isset($changes['active'])) {
                    $productsTable->changeStatus([[$compositeId => (int) $new('active')]]);
                }
                if (isset($changes['quantity']) || isset($changes['always_available'])) {
                    $quantity = (float) ($changes['quantity']['new'] ?? 0);
                    $stock = ['quantity' => $countedInUnits ? $quantity : (int) round($quantity)];
                    if (isset($changes['always_available'])) {
                        $stock['always_available'] = (int) $new('always_available');
                    }
                    if (!isset($changes['quantity'])) {
                        unset($stock['quantity']);
                    }
                    $productsTable->changeQuantity([[$compositeId => $stock]]);
                }
            });
        } catch (\Exception $e) {
            return $this->error(422, $e->getMessage());
        }
        $this->getTableLocator()->get('ActionLogs')->customSave(
            'product_changed_via_api',
            $this->customer->id_customer,
            $product->id_product,
            'products',
            'API (' . ($this->customer->get('api_token_name') ?? '-') . ') ' . $compositeId . ': '
                . join(', ', array_map(fn($f, $c) => $f . ' ' . json_encode($c['old']) . ' → ' . json_encode($c['new']), array_keys($changes), $changes)),
        );
        $fresh = $this->baseQuery()->where(['Products.id_product' => $product->id_product])->first();
        return $this->json(['id' => $compositeId, 'changes' => $changes, 'product' => $this->toArray($fresh)]);
    }

    /**
     * @param mixed $value {"price_incl_per_unit": <price per kg or per l>}
     */
    private function requestedBasePrice(mixed $value): float|Response
    {
        $price = is_array($value) ? ($value['price_incl_per_unit'] ?? null) : null;
        if (!is_numeric($price) || (float) $price <= 0) {
            return $this->error(422, 'price_per_unit needs price_incl_per_unit (price per kg or per l, greater than 0)');
        }
        return round((float) $price, 4);
    }

    /**
     * Stored price per unit as price per kg / per l (the unit of a variant can be g, kg, ml or l).
     *
     * @param array<string, mixed>|null $unit
     */
    private function basePrice(?array $unit): ?float
    {
        if (!$unit || empty($unit['enabled']) || empty($unit['price_incl_per_unit'])) {
            return null;
        }
        $perAmount = (float) $unit['price_incl_per_unit'] / max(1, (int) ($unit['amount'] ?? 1));
        return match ($unit['name'] ?? '') {
            'kg', 'l' => round($perAmount, 4),
            'g', 'ml' => round($perAmount * 1000, 4),
            default => null,
        };
    }

    private function taxIdForRate(float $rate): ?int
    {
        $tax = $this->getTableLocator()->get('Taxes')->find()
            ->where(['Taxes.deleted' => APP_OFF, 'Taxes.rate' => $rate])->first();
        return $tax ? (int) $tax->id_tax : null;
    }

    /**
     * Same as the admin (EditTaxTrait): the net prices are recalculated so that the gross prices stay the same.
     */
    private function changeTaxRate(Product $product, float $newRate): void
    {
        $productsTable = $this->getTableLocator()->get('Products');
        $oldRate = (float) ($product->tax->rate ?? 0);
        $taxId = $this->taxIdForRate($newRate);
        $productsTable->save($productsTable->patchEntity($product, ['id_tax' => $taxId]));
        if (!empty($product->product_attributes)) {
            $attributesTable = $this->getTableLocator()->get('ProductAttributes');
            foreach ($product->product_attributes as $attribute) {
                $attributesTable->updateAll(
                    ['price' => $productsTable->getNetPriceForNewTaxRate($attribute->price, $oldRate, $newRate)],
                    ['id_product_attribute' => $attribute->id_product_attribute],
                );
            }
        } else {
            $productsTable->save($productsTable->patchEntity($product, [
                'price' => $productsTable->getNetPriceForNewTaxRate($product->price, $oldRate, $newRate),
            ]));
        }
    }

    /**
     * Sets the price per kg / per l of a variant (or a product without variants).
     *
     * FoodCoopShop saves the price per unit with the unit of the variant. With g or ml the price can only be
     * saved in coarse steps (0.0001 EUR per g = 0.10 EUR per kg), so a price like 90.23 EUR/kg is not representable.
     * Therefore g is converted to kg and ml to l (the approximate weight per piece is converted too); the price of
     * the variant is then price per unit * approximate quantity, rounded to cents.
     */
    private function changePricePerUnit(\App\Model\Table\ProductsTable $productsTable, Product $product, string $compositeId, float $basePrice): void
    {
        $ids = $productsTable->getProductIdAndAttributeId($compositeId);
        $unit = $this->getTableLocator()->get('Units')->find()->where(
            $ids['attributeId'] > 0
                ? ['Units.id_product_attribute' => $ids['attributeId']]
                : ['Units.id_product' => $ids['productId'], 'Units.id_product_attribute' => 0]
        )->first();
        if (!$unit || !$unit->price_per_unit_enabled || !in_array($unit->name, ['kg', 'g', 'l', 'ml'], true)) {
            throw new \Exception('price per unit is not enabled for ' . $compositeId);
        }
        $toBase = in_array($unit->name, ['g', 'ml'], true);
        $quantity = (float) $unit->quantity_in_units / ($toBase ? 1000 : 1);
        $productsTable->changePrice([[$compositeId => [
            'gross_price' => round($basePrice * $quantity, 2),
            'unit_product_price_per_unit_enabled' => 1,
            'unit_product_price_incl_per_unit' => $basePrice,
            'unit_product_name' => $toBase ? ($unit->name === 'g' ? 'kg' : 'l') : $unit->name,
            'unit_product_amount' => 1,
            'unit_product_quantity_in_units' => $quantity,
            'use_weight_as_amount' => (int) $unit->use_weight_as_amount,
        ]]]);
    }

    private function baseQuery(): \Cake\ORM\Query\SelectQuery
    {
        return $this->getTableLocator()->get('Products')->find()
            ->contain([
                'Manufacturers', 'Taxes', 'StockAvailables', 'DepositProducts', 'UnitProducts',
                'ProductAttributes' => [
                    'StockAvailables', 'DepositProductAttributes', 'UnitProductAttributes',
                    'ProductAttributeCombinations.Attributes',
                ],
            ])
            ->orderBy(['Products.name' => 'ASC']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Product $p): array
    {
        $productsTable = $this->getTableLocator()->get('Products');
        $taxRate = (float) ($p->tax->rate ?? 0);
        $gross = fn($net) => $net === null ? null : round($productsTable->getGrossPrice($net, $taxRate), 2);
        $unit = fn($u) => $u ? [
            'enabled' => (bool) $u->price_per_unit_enabled,
            'price_incl_per_unit' => $u->price_incl_per_unit !== null ? (float) $u->price_incl_per_unit : null,
            'name' => $u->name,
            'amount' => (int) $u->amount,
            'quantity_in_units' => $u->quantity_in_units !== null ? (float) $u->quantity_in_units : null,
        ] : null;
        // stock: decimal in the unit of the product when counted by weight/volume, otherwise whole pieces
        $quantityService = new ProductQuantityService();
        $stock = function ($stockAvailable, $unitEntity) use ($quantityService, $p): array {
            $quantity = (float) ($stockAvailable->quantity ?? 0);
            $inUnits = !empty($p->manufacturer) && $quantityService->isAmountBasedOnQuantityInUnits($p, $unitEntity);
            return [$inUnits ? $quantity : (int) round($quantity), $inUnits ? (string) $unitEntity->name : null];
        };
        $attributes = [];
        foreach ($p->product_attributes ?? [] as $a) {
            [$attributeQuantity, $attributeQuantityUnit] = $stock($a->stock_available ?? null, $a->unit_product_attribute ?? null);
            $attributes[] = [
                'id' => $p->id_product . '-' . $a->id_product_attribute,
                'attribute_id' => $a->id_product_attribute,
                'name' => $a->product_attribute_combination->attribute->name ?? '',
                'net_price' => (float) $a->price,
                'gross_price' => $gross((float) $a->price),
                'deposit' => (float) ($a->deposit_product_attribute->deposit ?? 0),
                'quantity' => $attributeQuantity,
                'quantity_unit' => $attributeQuantityUnit,
                'always_available' => (bool) ($a->stock_available->always_available ?? false),
                'price_per_unit' => $unit($a->unit_product_attribute ?? null),
            ];
        }
        [$productQuantity, $productQuantityUnit] = $stock($p->stock_available ?? null, $p->unit_product ?? null);
        return [
            'id' => $p->id_product,
            'manufacturer' => ['id' => $p->id_manufacturer, 'name' => $p->manufacturer->name ?? ''],
            'name' => $p->name,
            'description_short' => (string) $p->description_short,
            'description' => (string) $p->description,
            'unity' => (string) $p->unity,
            'tax_rate' => $taxRate,
            'net_price' => (float) $p->price,
            'gross_price' => $gross((float) $p->price),
            'deposit' => (float) ($p->deposit_product->deposit ?? 0),
            'active' => (int) $p->active,
            'quantity' => $productQuantity,
            'quantity_unit' => $productQuantityUnit,
            'always_available' => (bool) ($p->stock_available->always_available ?? false),
            'price_per_unit' => $unit($p->unit_product ?? null),
            'modified' => $p->modified?->toIso8601String(),
            'attributes' => $attributes,
        ];
    }
}
