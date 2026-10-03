<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'name'], 'warehouses_business_name_unique');
            $table->unique(['id', 'business_id'], 'warehouses_id_business_unique');
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_counterparty_id')->nullable();
            $table->string('sku', 80);
            $table->string('barcode', 80)->nullable();
            $table->string('name', 180);
            $table->string('description')->nullable();
            $table->string('unit', 24)->default('piece');
            $table->decimal('purchase_price', 16, 4)->default(0);
            $table->decimal('sale_price', 16, 4)->default(0);
            $table->decimal('minimum_quantity', 16, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'sku'], 'inventory_items_business_sku_unique');
            $table->unique(['business_id', 'barcode'], 'inventory_items_business_barcode_unique');
            $table->unique(['id', 'business_id'], 'inventory_items_id_business_unique');
            $table->foreign(['supplier_counterparty_id', 'business_id'], 'inventory_items_supplier_business_foreign')
                ->references(['id', 'business_id'])
                ->on('counterparties')
                ->restrictOnDelete();
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->foreignId('inventory_item_id')->nullable();
            $table->foreignId('warehouse_id')->nullable();
            $table->unique(['id', 'business_id'], 'invoice_lines_id_business_unique');
            $table->foreign(['inventory_item_id', 'business_id'], 'invoice_lines_inventory_item_business_foreign')
                ->references(['id', 'business_id'])
                ->on('inventory_items')
                ->restrictOnDelete();
            $table->foreign(['warehouse_id', 'business_id'], 'invoice_lines_warehouse_business_foreign')
                ->references(['id', 'business_id'])
                ->on('warehouses')
                ->restrictOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('document_id')->nullable();
            $table->foreignId('invoice_id')->nullable();
            $table->foreignId('invoice_line_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('movement_type', 24);
            $table->decimal('quantity_delta', 16, 4);
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->date('occurred_on');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'invoice_line_id'], 'stock_movements_business_invoice_line_unique');
            $table->foreign(['inventory_item_id', 'business_id'], 'stock_movements_item_business_foreign')
                ->references(['id', 'business_id'])
                ->on('inventory_items')
                ->restrictOnDelete();
            $table->foreign(['warehouse_id', 'business_id'], 'stock_movements_warehouse_business_foreign')
                ->references(['id', 'business_id'])
                ->on('warehouses')
                ->restrictOnDelete();
            $table->foreign(['document_id', 'business_id'], 'stock_movements_document_business_foreign')
                ->references(['id', 'business_id'])
                ->on('documents')
                ->restrictOnDelete();
            $table->foreign(['invoice_id', 'business_id'], 'stock_movements_invoice_business_foreign')
                ->references(['id', 'business_id'])
                ->on('invoices')
                ->restrictOnDelete();
            $table->foreign(['invoice_line_id', 'business_id'], 'stock_movements_invoice_line_business_foreign')
                ->references(['id', 'business_id'])
                ->on('invoice_lines')
                ->restrictOnDelete();
            $table->index(['business_id', 'inventory_item_id', 'warehouse_id', 'occurred_on'], 'stock_movements_balance_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropForeign('invoice_lines_inventory_item_business_foreign');
            $table->dropForeign('invoice_lines_warehouse_business_foreign');
            $table->dropUnique('invoice_lines_id_business_unique');
            $table->dropColumn(['inventory_item_id', 'warehouse_id']);
        });
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('warehouses');
    }
};
