<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryItemRequest;
use App\Http\Requests\StoreStockMovementRequest;
use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Resources\Api\V1\InventoryItemResource;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Http\Resources\Api\V1\WarehouseResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventoryService) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $overview = $this->inventoryService->overview($business);

        return response()->json([
            'data' => [
                'items' => $overview['items']->map(fn ($item): array => (new InventoryItemResource($item))->resolve($request))->values(),
                'warehouses' => $overview['warehouses']->map(fn ($warehouse): array => (new WarehouseResource($warehouse))->resolve($request))->values(),
                'movements' => $overview['movements']->map(fn ($movement): array => (new StockMovementResource($movement))->resolve($request))->values(),
            ],
        ]);
    }

    public function storeItem(StoreInventoryItemRequest $request, Business $business): InventoryItemResource
    {
        /** @var User $user */
        $user = $request->user();

        return new InventoryItemResource($this->inventoryService->createItem($business, $user, $request->validated()));
    }

    public function storeWarehouse(StoreWarehouseRequest $request, Business $business): WarehouseResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WarehouseResource($this->inventoryService->createWarehouse($business, $user, $request->validated()));
    }

    public function transfer(Request $request, Business $business): JsonResponse
    {
        $attributes = $request->validate([
            'inventory_item_id' => ['required', 'integer'],
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:100000000'],
            'document_id' => ['required', 'integer'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['required', 'string', 'max:5000'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $movements = $this->inventoryService->transferStock($business, $user, $attributes);

        return response()->json(['data' => [
            'from' => new StockMovementResource($movements['from']),
            'to' => new StockMovementResource($movements['to']),
        ]], 201);
    }

    public function count(Request $request, Business $business): JsonResponse
    {
        $attributes = $request->validate([
            'inventory_item_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'counted_quantity' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:100000000'],
            'document_id' => ['required', 'integer'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['required', 'string', 'max:5000'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $result = $this->inventoryService->recordStockCount($business, $user, $attributes);

        return response()->json(['data' => [
            'recorded_quantity' => $result['recorded_quantity'],
            'counted_quantity' => $result['counted_quantity'],
            'difference' => $result['difference'],
            'movement' => $result['movement'] === null ? null : new StockMovementResource($result['movement']),
        ]]);
    }

    public function storeMovement(StoreStockMovementRequest $request, Business $business): StockMovementResource
    {
        /** @var User $user */
        $user = $request->user();

        return new StockMovementResource($this->inventoryService->adjustStock($business, $user, $request->validated()));
    }
}
