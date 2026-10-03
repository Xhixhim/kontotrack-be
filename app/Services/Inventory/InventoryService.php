<?php

namespace App\Services\Inventory;

use App\Models\Business;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\Contracts\InventoryRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private InventoryRepository $inventoryRepository) {}

    /** @return array{items: Collection<int, InventoryItem>, warehouses: Collection<int, Warehouse>, movements: Collection<int, StockMovement>} */
    public function overview(Business $business): array
    {
        return [
            'items' => $this->inventoryRepository->listItems($business),
            'warehouses' => $this->inventoryRepository->listWarehouses($business),
            'movements' => $this->inventoryRepository->listMovements($business),
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function createItem(Business $business, User $user, array $attributes): InventoryItem
    {
        if (isset($attributes['supplier_counterparty_id'])) {
            $supplier = $business->counterparties()
                ->whereKey($attributes['supplier_counterparty_id'])
                ->whereIn('type', ['supplier', 'both'])
                ->first();

            if ($supplier === null) {
                throw ValidationException::withMessages(['supplier_counterparty_id' => ['Choose a supplier that belongs to this business.']]);
            }
        }

        return DB::transaction(function () use ($business, $user, $attributes): InventoryItem {
            $attributes['is_active'] = $attributes['is_active'] ?? true;
            $item = $this->inventoryRepository->createItem($business, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'inventory_item.created',
                'subject_type' => InventoryItem::class,
                'subject_id' => $item->getKey(),
                'new_values' => $item->only(['sku', 'name', 'unit', 'purchase_price', 'sale_price', 'minimum_quantity']),
            ]);

            return $item;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createWarehouse(Business $business, User $user, array $attributes): Warehouse
    {
        return DB::transaction(function () use ($business, $user, $attributes): Warehouse {
            Business::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            $attributes['is_default'] = ! $business->warehouses()->exists() || (bool) ($attributes['is_default'] ?? false);
            $attributes['is_active'] = $attributes['is_active'] ?? true;
            $warehouse = $this->inventoryRepository->createWarehouse($business, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'warehouse.created',
                'subject_type' => Warehouse::class,
                'subject_id' => $warehouse->getKey(),
                'new_values' => $warehouse->only(['name', 'address', 'is_default']),
            ]);

            return $warehouse;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function adjustStock(Business $business, User $user, array $attributes): StockMovement
    {
        return DB::transaction(function () use ($business, $user, $attributes): StockMovement {
            $item = $this->inventoryRepository->findItemForUpdate($business, (int) $attributes['inventory_item_id']);
            $warehouse = $this->inventoryRepository->findWarehouseForUpdate($business, (int) $attributes['warehouse_id']);
            $document = $business->documents()->whereKey($attributes['document_id'])->first();

            if (! $item->is_active) {
                throw ValidationException::withMessages(['inventory_item_id' => ['Choose an active inventory item.']]);
            }

            if (! $warehouse->is_active) {
                throw ValidationException::withMessages(['warehouse_id' => ['Choose an active warehouse.']]);
            }

            if ($document === null) {
                throw ValidationException::withMessages(['document_id' => ['Attach a source document belonging to this business.']]);
            }

            $period = $business->accountingPeriods()
                ->whereDate('starts_on', '<=', $attributes['occurred_on'])
                ->whereDate('ends_on', '>=', $attributes['occurred_on'])
                ->lockForUpdate()
                ->first();

            if ($period === null || $period->status !== 'open') {
                throw ValidationException::withMessages(['occurred_on' => ['Choose a date within an open accounting period.']]);
            }

            if ($attributes['movement_type'] === 'opening' && $attributes['direction'] !== 'in') {
                throw ValidationException::withMessages(['direction' => ['An opening stock balance must increase inventory.']]);
            }

            $quantityUnits = $this->quantityToUnits((string) $attributes['quantity']);
            $quantityDelta = $attributes['direction'] === 'in' ? $quantityUnits : -$quantityUnits;
            $currentUnits = $this->quantityToUnits($this->inventoryRepository->stockOnHand(
                $business,
                (int) $item->getKey(),
                (int) $warehouse->getKey(),
            ));

            if ($currentUnits + $quantityDelta < 0) {
                throw ValidationException::withMessages(['quantity' => ['This movement would make stock negative in the selected warehouse.']]);
            }

            $movement = $this->inventoryRepository->createMovement($business, [
                'inventory_item_id' => $item->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'document_id' => $document->getKey(),
                'created_by' => $user->getKey(),
                'movement_type' => $attributes['movement_type'],
                'quantity_delta' => $this->unitsToQuantity($quantityDelta),
                'unit_cost' => $attributes['unit_cost'] ?? null,
                'occurred_on' => $attributes['occurred_on'],
                'notes' => $attributes['notes'],
            ]);

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'stock_movement.recorded',
                'subject_type' => StockMovement::class,
                'subject_id' => $movement->getKey(),
                'new_values' => $movement->only(['inventory_item_id', 'warehouse_id', 'movement_type', 'quantity_delta', 'document_id', 'occurred_on', 'notes']),
            ]);

            return $movement->load(['inventoryItem', 'warehouse', 'document', 'creator']);
        });
    }

    public function recordInvoiceMovements(Business $business, Invoice $invoice, User $user): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->inventory_item_id === null && $line->warehouse_id === null) {
                continue;
            }

            if ($line->inventory_item_id === null || $line->warehouse_id === null) {
                throw ValidationException::withMessages(['lines' => ['Each stock-tracked invoice line must have both an item and a warehouse.']]);
            }

            if ($this->inventoryRepository->invoiceLineMovementExists($business, (int) $line->getKey())) {
                throw ValidationException::withMessages(['invoice' => ['A stock movement has already been recorded for an invoice line.']]);
            }

            $item = $this->inventoryRepository->findItemForUpdate($business, (int) $line->inventory_item_id);
            $warehouse = $this->inventoryRepository->findWarehouseForUpdate($business, (int) $line->warehouse_id);

            if (! $item->is_active || ! $warehouse->is_active) {
                throw ValidationException::withMessages(['lines' => ['Invoice stock items and warehouses must still be active when the invoice is issued.']]);
            }

            $quantityUnits = $this->quantityToUnits((string) $line->quantity);
            $quantityDelta = ($invoice->kind === 'sale') !== $invoice->isCreditNote() ? -$quantityUnits : $quantityUnits;
            $currentUnits = $this->quantityToUnits($this->inventoryRepository->stockOnHand(
                $business,
                (int) $item->getKey(),
                (int) $warehouse->getKey(),
            ));

            if ($currentUnits + $quantityDelta < 0) {
                throw ValidationException::withMessages([
                    'lines.'.((int) $line->position - 1).'.quantity' => ['There is not enough stock in the selected warehouse to issue this sales invoice.'],
                ]);
            }

            $movement = $this->inventoryRepository->createMovement($business, [
                'inventory_item_id' => $item->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'document_id' => $invoice->document_id,
                'invoice_id' => $invoice->getKey(),
                'invoice_line_id' => $line->getKey(),
                'created_by' => $user->getKey(),
                'movement_type' => $invoice->isCreditNote() ? $invoice->kind.'_credit' : $invoice->kind,
                'quantity_delta' => $this->unitsToQuantity($quantityDelta),
                'occurred_on' => $invoice->issued_on->toDateString(),
                'notes' => 'Recorded from invoice '.$invoice->invoice_number,
            ]);

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'stock_movement.invoice_recorded',
                'subject_type' => StockMovement::class,
                'subject_id' => $movement->getKey(),
                'new_values' => $movement->only(['inventory_item_id', 'warehouse_id', 'movement_type', 'quantity_delta', 'invoice_id', 'invoice_line_id', 'occurred_on']),
            ]);
        }
    }

    /**
     * Move stock between two warehouses as a paired out/in movement on the same date and source document.
     *
     * @param  array{inventory_item_id: int, from_warehouse_id: int, to_warehouse_id: int, quantity: string, document_id: int, occurred_on: string, notes: string}  $attributes
     * @return array{from: StockMovement, to: StockMovement}
     */
    public function transferStock(Business $business, User $user, array $attributes): array
    {
        if ((int) $attributes['from_warehouse_id'] === (int) $attributes['to_warehouse_id']) {
            throw ValidationException::withMessages(['to_warehouse_id' => ['Choose a different destination warehouse.']]);
        }

        return DB::transaction(function () use ($business, $user, $attributes): array {
            $shared = [
                'inventory_item_id' => $attributes['inventory_item_id'],
                'quantity' => $attributes['quantity'],
                'document_id' => $attributes['document_id'],
                'occurred_on' => $attributes['occurred_on'],
                'notes' => $attributes['notes'],
            ];

            return [
                'from' => $this->adjustStock($business, $user, $shared + ['warehouse_id' => $attributes['from_warehouse_id'], 'direction' => 'out', 'movement_type' => 'transfer_out']),
                'to' => $this->adjustStock($business, $user, $shared + ['warehouse_id' => $attributes['to_warehouse_id'], 'direction' => 'in', 'movement_type' => 'transfer_in']),
            ];
        });
    }

    /**
     * Record a stock count: the difference between the counted and the recorded quantity becomes a count movement.
     *
     * @param  array{inventory_item_id: int, warehouse_id: int, counted_quantity: string, document_id: int, occurred_on: string, notes: string}  $attributes
     * @return array{recorded_quantity: string, counted_quantity: string, difference: string, movement: StockMovement|null}
     */
    public function recordStockCount(Business $business, User $user, array $attributes): array
    {
        return DB::transaction(function () use ($business, $user, $attributes): array {
            $item = $this->inventoryRepository->findItemForUpdate($business, (int) $attributes['inventory_item_id']);
            $warehouse = $this->inventoryRepository->findWarehouseForUpdate($business, (int) $attributes['warehouse_id']);
            $recordedUnits = $this->quantityToUnits($this->inventoryRepository->stockOnHand($business, (int) $item->getKey(), (int) $warehouse->getKey()));
            $countedUnits = $this->quantityToUnits((string) $attributes['counted_quantity']);
            $differenceUnits = $countedUnits - $recordedUnits;
            $movement = $differenceUnits === 0 ? null : $this->adjustStock($business, $user, [
                'inventory_item_id' => $item->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'direction' => $differenceUnits > 0 ? 'in' : 'out',
                'movement_type' => 'count',
                'quantity' => $this->unitsToQuantity(abs($differenceUnits)),
                'document_id' => $attributes['document_id'],
                'occurred_on' => $attributes['occurred_on'],
                'notes' => $attributes['notes'],
            ]);

            return [
                'recorded_quantity' => $this->unitsToQuantity($recordedUnits),
                'counted_quantity' => $this->unitsToQuantity($countedUnits),
                'difference' => $this->unitsToQuantity($differenceUnits),
                'movement' => $movement,
            ];
        });
    }

    /**
     * Record opposite stock movements for every movement an invoice created when it was issued.
     */
    public function reverseInvoiceMovements(Business $business, Invoice $invoice, User $user, string $occurredOn): void
    {
        $movements = $business->stockMovements()
            ->where('invoice_id', $invoice->getKey())
            ->whereNotNull('invoice_line_id')
            ->orderBy('id')
            ->get();

        foreach ($movements as $original) {
            $item = $this->inventoryRepository->findItemForUpdate($business, (int) $original->inventory_item_id);
            $warehouse = $this->inventoryRepository->findWarehouseForUpdate($business, (int) $original->warehouse_id);
            $quantityDelta = -$this->quantityToUnits((string) $original->quantity_delta);
            $currentUnits = $this->quantityToUnits($this->inventoryRepository->stockOnHand(
                $business,
                (int) $item->getKey(),
                (int) $warehouse->getKey(),
            ));

            if ($currentUnits + $quantityDelta < 0) {
                throw ValidationException::withMessages(['invoice' => ["Cancelling this invoice would make stock of {$item->name} negative in {$warehouse->name}."]]);
            }

            $movement = $this->inventoryRepository->createMovement($business, [
                'inventory_item_id' => $item->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'document_id' => $invoice->document_id,
                'invoice_id' => $invoice->getKey(),
                'invoice_line_id' => null,
                'created_by' => $user->getKey(),
                'movement_type' => 'invoice_cancellation',
                'quantity_delta' => $this->unitsToQuantity($quantityDelta),
                'occurred_on' => $occurredOn,
                'notes' => 'Reversed by cancelling invoice '.$invoice->invoice_number,
            ]);

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'stock_movement.invoice_cancellation_recorded',
                'subject_type' => StockMovement::class,
                'subject_id' => $movement->getKey(),
                'new_values' => $movement->only(['inventory_item_id', 'warehouse_id', 'movement_type', 'quantity_delta', 'invoice_id', 'occurred_on']),
            ]);
        }
    }

    private function quantityToUnits(string $quantity): int
    {
        $negative = str_starts_with($quantity, '-');
        $quantity = ltrim($quantity, '+-');
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $units = ((int) $whole * 10_000) + (int) substr(str_pad($fraction, 4, '0'), 0, 4);

        return $negative ? -$units : $units;
    }

    private function unitsToQuantity(int $units): string
    {
        $negative = $units < 0;
        $absoluteUnits = abs($units);
        $quantity = intdiv($absoluteUnits, 10_000).'.'.str_pad((string) ($absoluteUnits % 10_000), 4, '0', STR_PAD_LEFT);

        return $negative ? '-'.$quantity : $quantity;
    }
}
