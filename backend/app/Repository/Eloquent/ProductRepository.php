<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\Constants;
use HiEvents\DomainObjects\CapacityAssignmentDomainObject;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\CapacityAssignment;
use HiEvents\Models\CheckInList;
use HiEvents\Models\Product;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use RuntimeException;

class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            [ProductDomainObjectAbstract::EVENT_ID, '=', $eventId]
        ];

        if (!empty($params->query)) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(ProductDomainObjectAbstract::TITLE, 'ilike', '%' . $params->query . '%');
            };
        }

        $this->model = $this->model->orderBy(
            $params->sort_by ?? ProductDomainObject::getDefaultSort(),
            $params->sort_direction ?? ProductDomainObject::getDefaultSortDirection(),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    /**
     * @param int $productId
     * @param int $productPriceId
     * @return int
     */
    public function getQuantityRemainingForProductPrice(int $productId, int $productPriceId): int
    {
        $query = <<<SQL
        SELECT
            COALESCE(product_prices.initial_quantity_available, 0) - (
                product_prices.quantity_sold + COALESCE((
                    SELECT sum(order_items.quantity)
                    FROM orders
                    INNER JOIN order_items ON orders.id = order_items.order_id
                    WHERE order_items.product_price_id = :productPriceId
                    AND orders.status in ('RESERVED')
                    AND current_timestamp < orders.reserved_until
                    AND orders.deleted_at IS NULL
                    AND order_items.deleted_at IS NULL
                ), 0)
            ) AS quantity_remaining,
            product_prices.initial_quantity_available IS NULL AS unlimited_products_available
        FROM product_prices
        WHERE product_prices.id = :productPriceId
        AND product_prices.product_id = :productId
        AND product_prices.deleted_at IS NULL
    SQL;

        $result = $this->db->selectOne($query, [
            'productPriceId' => $productPriceId,
            'productId' => $productId
        ]);

        if ($result === null) {
            throw new RuntimeException('Product price not found');
        }

        if ($result->unlimited_products_available) {
            return Constants::INFINITE;
        }

        return (int)$result->quantity_remaining;
    }

    public function getTaxesByProductId(int $productId): Collection
    {
        $query = <<<SQL
            SELECT tf.*
            FROM product_taxes_and_fees ttf
            INNER JOIN taxes_and_fees tf ON tf.id = ttf.tax_and_fee_id
            WHERE ttf.product_id = :productId
            AND tf.deleted_at IS NULL
        SQL;

        $taxAndFees = $this->db->select($query, [
            'productId' => $productId
        ]);

        return $this->handleResults($taxAndFees, TaxAndFeesDomainObject::class);
    }

    public function getProductsByTaxId(int $taxId): Collection
    {
        $query = <<<SQL
            SELECT t.*
            FROM product_taxes_and_fees ttf
            INNER JOIN products t ON t.id = ttf.product_id
            WHERE ttf.tax_and_fee_id = :taxAndFeeId
            AND t.deleted_at IS NULL
        SQL;

        $products = $this->model->select($query, [
            'taxAndFeeId' => $taxId
        ]);

        return $this->handleResults($products, ProductDomainObject::class);
    }

    public function getCapacityAssignmentsByProductId(int $productId): Collection
    {
        $capacityAssignments = CapacityAssignment::whereHas('products', static function ($query) use ($productId) {
            $query->where('product_id', $productId);
        })->get();

        return $this->handleResults($capacityAssignments, CapacityAssignmentDomainObject::class);
    }

    public function addTaxesAndFeesToProduct(int $productId, array $taxIds): void
    {
        Product::findOrFail($productId)?->tax_and_fees()->sync($taxIds);
    }

    public function addCapacityAssignmentToProducts(int $capacityAssignmentId, array $productIds): void
    {
        $productIds = array_unique($productIds);

        Product::whereNotIn('id', $productIds)
            ->whereHas('capacity_assignments', function ($query) use ($capacityAssignmentId) {
                $query->where('capacity_assignment_id', $capacityAssignmentId);
            })
            ->each(function (Product $product) use ($capacityAssignmentId) {
                $product->capacity_assignments()->detach($capacityAssignmentId);
            });

        Product::whereIn('id', $productIds)
            ->each(function (Product $product) use ($capacityAssignmentId) {
                $product->capacity_assignments()->syncWithoutDetaching([$capacityAssignmentId]);
            });
    }

    public function addCheckInListToProducts(int $checkInListId, array $productIds): void
    {
        $productIds = array_unique($productIds);

        Product::whereNotIn('id', $productIds)
            ->whereHas('check_in_lists', function ($query) use ($checkInListId) {
                $query->where('check_in_list_id', $checkInListId);
            })
            ->each(function (Product $product) use ($checkInListId) {
                $product->check_in_lists()->detach($checkInListId);
            });

        Product::whereIn('id', $productIds)
            ->each(function (Product $product) use ($checkInListId) {
                $product->check_in_lists()->syncWithoutDetaching([$checkInListId]);
            });
    }

    public function removeCheckInListFromProducts(int $checkInListId): void
    {
        $checkInList = CheckInList::find($checkInListId);

        $checkInList?->products()->detach();
    }

    public function removeCapacityAssignmentFromProducts(int $capacityAssignmentId): void
    {
        $capacityAssignment = CapacityAssignment::find($capacityAssignmentId);

        $capacityAssignment?->products()->detach();
    }

    public function sortProducts(int $eventId, array $orderedProductIds): void
    {
        $parameters = [
            'eventId' => $eventId,
            'productIds' => '{' . implode(',', $orderedProductIds) . '}',
            'orders' => '{' . implode(',', range(1, count($orderedProductIds))) . '}',
        ];

        $query = "WITH new_order AS (
                  SELECT unnest(:productIds::bigint[]) AS product_id,
                         unnest(:orders::int[]) AS order
              )
              UPDATE products
              SET \"order\" = new_order.order
              FROM new_order
              WHERE products.id = new_order.product_id AND products.event_id = :eventId";

        $this->db->update($query, $parameters);
    }

    public function getModel(): string
    {
        return Product::class;
    }

    public function getDomainObject(): string
    {
        return ProductDomainObject::class;
    }
}
