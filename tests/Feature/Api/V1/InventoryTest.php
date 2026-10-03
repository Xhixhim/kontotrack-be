<?php

namespace Tests\Feature\Api\V1;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_opening_balance_and_adjustment_need_a_source_document_and_cannot_go_negative(): void
    {
        [$user, $business, $item, $warehouse] = $this->stockSetup();
        $document = Document::factory()->for($business)->create();

        $this->movement($user, $business, ['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'direction' => 'in', 'movement_type' => 'opening', 'quantity' => '10', 'document_id' => $document->id])
            ->assertCreated();
        $this->movement($user, $business, ['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'direction' => 'out', 'movement_type' => 'adjustment', 'quantity' => '11', 'document_id' => $document->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity' => 'This movement would make stock negative in the selected warehouse.']);
        $this->movement($user, $business, ['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'direction' => 'out', 'movement_type' => 'adjustment', 'quantity' => '1', 'document_id' => Document::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['document_id' => 'Attach a source document belonging to this business.']);

        $this->assertSame(10.0, $this->onHand($item, $warehouse));
    }

    public function test_transfer_moves_stock_between_warehouses(): void
    {
        [$user, $business, $item, $source] = $this->stockSetup('8');
        $destination = Warehouse::factory()->for($business)->create(['is_default' => false]);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/inventory/transfers", [
            'inventory_item_id' => $item->id,
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $destination->id,
            'quantity' => '3',
            'document_id' => Document::factory()->for($business)->create()->id,
            'occurred_on' => '2026-07-10',
            'notes' => 'Restock shop',
        ])->assertCreated()
            ->assertJsonPath('data.from.movement_type', 'transfer_out')
            ->assertJsonPath('data.to.movement_type', 'transfer_in');

        $this->assertSame(5.0, $this->onHand($item, $source));
        $this->assertSame(3.0, $this->onHand($item, $destination));
    }

    public function test_transfer_cannot_take_more_than_is_in_the_source_warehouse_and_changes_nothing(): void
    {
        [$user, $business, $item, $source] = $this->stockSetup('2');
        $destination = Warehouse::factory()->for($business)->create(['is_default' => false]);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/inventory/transfers", [
            'inventory_item_id' => $item->id,
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $destination->id,
            'quantity' => '5',
            'document_id' => Document::factory()->for($business)->create()->id,
            'occurred_on' => '2026-07-10',
            'notes' => 'Too much',
        ])->assertUnprocessable();

        $this->assertSame(0.0, $this->onHand($item, $destination));
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_stock_count_records_the_difference_as_a_count_movement(): void
    {
        [$user, $business, $item, $warehouse] = $this->stockSetup('10');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/inventory/counts", [
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'counted_quantity' => '7.5',
            'document_id' => Document::factory()->for($business)->create()->id,
            'occurred_on' => '2026-07-15',
            'notes' => 'Mid-year count',
        ])->assertOk()
            ->assertJsonPath('data.recorded_quantity', '10.0000')
            ->assertJsonPath('data.difference', '-2.5000')
            ->assertJsonPath('data.movement.movement_type', 'count');

        $this->assertSame(7.5, $this->onHand($item, $warehouse));
    }

    public function test_matching_count_records_no_movement(): void
    {
        [$user, $business, $item, $warehouse] = $this->stockSetup('4');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/inventory/counts", [
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'counted_quantity' => '4',
            'document_id' => Document::factory()->for($business)->create()->id,
            'occurred_on' => '2026-07-15',
            'notes' => 'Count',
        ])->assertOk()->assertJsonPath('data.movement', null);

        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_auditor_cannot_move_stock(): void
    {
        [, $business, $item, $warehouse] = $this->stockSetup();
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/inventory/counts", ['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id])->assertForbidden();
    }

    /** @return array{User, Business, InventoryItem, Warehouse} */
    private function stockSetup(?string $opening = null): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);
        AccountingPeriod::factory()->for($business)->create();
        $item = InventoryItem::factory()->for($business)->create();
        $warehouse = Warehouse::factory()->for($business)->create();

        if ($opening !== null) {
            StockMovement::factory()->for($business)->create(['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'movement_type' => 'opening', 'quantity_delta' => $opening]);
        }

        return [$user, $business, $item, $warehouse];
    }

    /** @param array<string, mixed> $attributes */
    private function movement(User $user, Business $business, array $attributes): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/inventory/movements", $attributes + ['occurred_on' => '2026-07-01', 'notes' => 'Test']);
    }

    private function onHand(InventoryItem $item, Warehouse $warehouse): float
    {
        return (float) StockMovement::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->sum('quantity_delta');
    }
}
