<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;

interface InventoryRepository
{
    /** @return Collection<int, InventoryItem> */
    public function listItems(Business $business): Collection;

    /** @return Collection<int, Warehouse> */
    public function listWarehouses(Business $business): Collection;

    /** @return Collection<int, StockMovement> */
    public function listMovements(Business $business): Collection;

    /** @param array<string, mixed> $attributes */
    public function createItem(Business $business, array $attributes): InventoryItem;

    /** @param array<string, mixed> $attributes */
    public function createWarehouse(Business $business, array $attributes): Warehouse;

    public function findItemForUpdate(Business $business, int $inventoryItemId): InventoryItem;

    public function findWarehouseForUpdate(Business $business, int $warehouseId): Warehouse;

    public function stockOnHand(Business $business, int $inventoryItemId, int $warehouseId): string;

    /** @param array<string, mixed> $attributes */
    public function createMovement(Business $business, array $attributes): StockMovement;

    public function invoiceLineMovementExists(Business $business, int $invoiceLineId): bool;
}
