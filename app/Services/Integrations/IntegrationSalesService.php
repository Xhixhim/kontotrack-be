<?php

namespace App\Services\Integrations;

use App\Models\Business;
use App\Models\BusinessApiKey;
use App\Models\Counterparty;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Counterparties\CounterpartyService;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records sales sent by another system. A sale arrives as a draft invoice, or as an issued one when asked;
 * sending the same external id again returns the invoice already recorded instead of a second one.
 */
class IntegrationSalesService
{
    public function __construct(private InvoiceService $invoiceService, private CounterpartyService $counterpartyService) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{invoice: Invoice, created: bool}
     */
    public function record(Business $business, User $actor, BusinessApiKey $key, array $attributes): array
    {
        $existing = $this->findByExternalId($business, $attributes['external_id']);

        if ($existing !== null) {
            return ['invoice' => $existing, 'created' => false];
        }

        return DB::transaction(function () use ($business, $actor, $key, $attributes): array {
            $customer = $this->resolveCustomer($business, $actor, $key, $attributes['customer']);
            $lines = [];

            foreach (array_values($attributes['lines']) as $position => $line) {
                $itemId = null;
                $warehouseId = null;

                if (isset($line['sku'])) {
                    $itemId = $business->inventoryItems()->where('sku', $line['sku'])->where('is_active', true)->value('id');
                    $warehouseId = $business->warehouses()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->value('id');

                    if ($itemId === null) {
                        throw ValidationException::withMessages(["lines.{$position}.sku" => ["There is no active item with the code {$line['sku']}."]]);
                    }

                    if ($warehouseId === null) {
                        throw ValidationException::withMessages(["lines.{$position}.sku" => ['The business has no active warehouse to take stock from.']]);
                    }
                }

                $lines[] = [
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'] ?? null,
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount_amount'] ?? '0.00',
                    'vat_code' => $line['vat_code'],
                    'inventory_item_id' => $itemId,
                    'warehouse_id' => $warehouseId,
                ];
            }

            $invoice = $this->invoiceService->createDraft($business, (int) $actor->getKey(), [
                'kind' => 'sale',
                'counterparty_id' => $customer->getKey(),
                'issued_on' => $attributes['issued_on'],
                'due_on' => $attributes['due_on'] ?? null,
                'payment_method' => $attributes['payment_method'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'lines' => $lines,
            ]);
            $invoice->forceFill(['external_id' => $attributes['external_id']])->save();
            $business->auditLogs()->create([
                'user_id' => $actor->getKey(),
                'action' => 'integration.sale_received',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->getKey(),
                'new_values' => ['api_key' => $key->name, 'external_id' => $attributes['external_id'], 'invoice_number' => $invoice->invoice_number],
            ]);

            if ($attributes['issue'] ?? false) {
                $invoice = $this->invoiceService->issueWithGeneratedJournal($business, $actor, (int) $invoice->getKey());
            }

            return ['invoice' => $this->invoiceService->findForBusiness($business, (int) $invoice->getKey()), 'created' => true];
        });
    }

    public function findByExternalId(Business $business, string $externalId): ?Invoice
    {
        $invoiceId = $business->invoices()->where('external_id', $externalId)->value('id');

        return $invoiceId === null ? null : $this->invoiceService->findForBusiness($business, (int) $invoiceId);
    }

    /** @param array<string, mixed> $customer */
    private function resolveCustomer(Business $business, User $actor, BusinessApiKey $key, array $customer): Counterparty
    {
        $query = $business->counterparties()->whereIn('type', ['customer', 'both'])->where('status', 'active');

        if (isset($customer['id'])) {
            return $query->whereKey($customer['id'])->first()
                ?? throw ValidationException::withMessages(['customer.id' => ['There is no active customer with this id.']]);
        }

        if (isset($customer['nui']) && ($known = (clone $query)->where('nui', $customer['nui'])->first()) !== null) {
            return $known;
        }

        if (! isset($customer['name'])) {
            throw ValidationException::withMessages(['customer.name' => ['Send the customer id, a NUI that is already recorded, or a name to create the customer.']]);
        }

        if (! $key->allows('customers.write')) {
            throw ValidationException::withMessages(['customer' => ['This customer is not recorded yet and this API key may not create customers.']]);
        }

        return $this->counterpartyService->createForBusiness($business, (int) $actor->getKey(), [
            'type' => 'customer',
            'name' => $customer['name'],
            'nui' => $customer['nui'] ?? null,
            'email' => $customer['email'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'address' => $customer['address'] ?? null,
            'municipality' => $customer['municipality'] ?? null,
            'country_code' => $customer['country_code'] ?? 'XK',
        ]);
    }
}
