<?php

namespace App\Repositories;

use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class SalesAnalyticsRepository
{
    /**
     * Obtiene el reporte de rentabilidad agrupado por Categoría o Marca.
     *
     * @param string $startDate Fecha inicio (Y-m-d)
     * @param string $endDate Fecha fin (Y-m-d)
     * @param string $groupBy 'category' o 'brand'
     * @return Collection
     */
    public function getProfitReport(string $startDate, string $endDate, string $groupBy = 'category'): Collection
    {
        $query = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id');

        if ($groupBy === 'brand') {
            $query->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                  ->selectRaw('COALESCE(brands.name, "Sin Marca") as group_name, products.brand_id as group_id, products.id as product_id, products.name as product_name');
            $groupFields = ['products.brand_id', 'brands.name'];
        } else {
            $query->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                  ->selectRaw('COALESCE(categories.name, "Sin Categoría") as group_name, products.category_id as group_id, products.id as product_id, products.name as product_name');
            $groupFields = ['products.category_id', 'categories.name'];
        }

        $productStats = $query->selectRaw('
                SUM(sale_items.quantity) as items_sold,
                SUM(sale_items.subtotal) as total_revenue,
                SUM(
                    CASE
                        WHEN sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0
                        THEN sale_items.subtotal - (sale_items.unit_cost_price * sale_items.quantity)
                        WHEN products.cost_price IS NOT NULL AND products.cost_price > 0
                        THEN sale_items.subtotal - (products.cost_price * sale_items.quantity)
                        ELSE 0
                    END
                ) as total_profit,
                SUM(
                    CASE
                        WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                          OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                        THEN sale_items.subtotal
                        ELSE 0
                    END
                ) as revenue_with_cost,
                COUNT(CASE
                    WHEN (sale_items.unit_cost_price IS NOT NULL AND sale_items.unit_cost_price > 0)
                      OR (products.cost_price IS NOT NULL AND products.cost_price > 0)
                    THEN 1
                END) as items_with_cost,
                COUNT(*) as total_items
            ')
            ->whereBetween('sales.created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->where('sales.status', 'completed')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('customers')
                  ->whereColumn('customers.id', 'sales.customer_id')
                  ->where('customers.is_internal_account', true);
            })
            ->groupBy(array_merge($groupFields, ['products.id', 'products.name']))
            ->get();

        return $productStats->groupBy('group_name')->map(function ($items, $groupName) {
            return [
                'category_name'   => $groupName, // Manteniendo key legacy para el frontend
                'items_sold'      => $items->sum('items_sold'),
                'total_revenue'   => $items->sum('total_revenue'),
                'total_profit'    => $items->sum('total_profit'),
                'revenue_with_cost' => $items->sum('revenue_with_cost'),
                'items_with_cost' => $items->sum('items_with_cost'),
                'total_items'     => $items->sum('total_items'),
                'products'        => $items->sortByDesc('total_revenue')->map(function ($prod) {
                    return [
                        'product_id'       => $prod->product_id,
                        'product_name'     => $prod->product_name,
                        'items_sold'       => (int)   $prod->items_sold,
                        'total_revenue'    => (float) $prod->total_revenue,
                        'total_profit'     => (float) $prod->total_profit,
                        'revenue_with_cost'=> (float) $prod->revenue_with_cost,
                        'items_with_cost'  => (int)   $prod->items_with_cost,
                        'total_items'      => (int)   $prod->total_items,
                    ];
                })->values(),
            ];
        })->sortByDesc('total_revenue')->values();
    }
}
