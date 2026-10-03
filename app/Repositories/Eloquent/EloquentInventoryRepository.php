<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Repositories\Contracts\InventoryRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentInventoryRepository implements InventoryRepository
{
    /** @return Collection<int, InventoryItem> */
    public function listItems(Business $business): Collection
    {
        return $business->inventoryItems()
            ->with(['preferredSupplier:id,name,business_id'])
            ->withSum('stockMovements as on_hand_quantity', 'quantity_delta')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Warehouse> */
    public function listWarehouses(Business $business): Collection
    {
        return $business->warehouses()->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** @return Collection<int, StockMovement> */
    public function listMovements(Business $business): Collection
    {
        return $business->stockMovements()
            ->with(['inventoryItem:id,business_id,sku,name,unit', 'warehouse:id,business_id,name', 'document:id,business_id,original_name', 'invoice:id,business_id,invoice_number,kind', 'creator:id,name'])
            ->latest('occurred_on')
            ->latest('id')
            ->limit(100)
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function createItem(Business $business, array $attributes): InventoryItem
    {
        return $business->inventoryItems()->create($attributes)->load('preferredSupplier:id,name,business_id');
    }

    /** @param array<string, mixed> $attributes */
    public function createWarehouse(Business $business, array $attributes): Warehouse
    {
        if ($attributes['is_default']) {
            $business->warehouses()->update(['is_default' => false]);
        }

        return $business->warehouses()->create($attributes);
    }

    public function findItemForUpdate(Business $business, int $inventoryItemId): InventoryItem
    {
        return $business->inventoryItems()->whereKey($inventoryItemId)->lockForUpdate()->firstOrFail();
    }

    public function findWarehouseForUpdate(Business $business, int $warehouseId): Warehouse
    {
        return $business->warehouses()->whereKey($warehouseId)->lockForUpdate()->firstOrFail();
    }

    public function stockOnHand(Business $business, int $inventoryItemId, int $warehouseId): string
    {
        $quantity = DB::table('stock_movements')
            ->where('business_id', $business->getKey())
            ->where('inventory_item_id', $inventoryItemId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('COALESCE(SUM(quantity_delta), 0) AS quantity_on_hand')
            ->value('quantity_on_hand');

        return (string) ($quantity ?? '0.0000');
    }

    /** @param array<string, mixed> $attributes */
    public function createMovement(Business $business, array $attributes): StockMovement
    {
        return $business->stockMovements()->create($attributes);
    }

    public function invoiceLineMovementExists(Business $business, int $invoiceLineId): bool
    {
        return $business->stockMovements()->where('invoice_line_id', $invoiceLineId)->exists();
    }
}
