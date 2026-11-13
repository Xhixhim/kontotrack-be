<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BusinessResource;
use App\Http\Resources\Api\V1\CounterpartyResource;
use App\Http\Resources\Api\V1\InventoryItemResource;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\Business;
use App\Models\BusinessApiKey;
use App\Models\User;
use App\Rules\CounterpartyIdentifier;
use App\Services\Counterparties\CounterpartyService;
use App\Services\Integrations\IntegrationSalesService;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The API other systems (POS, web shop) use with a business API key. The business always comes from the key.
 */
class IntegrationController extends Controller
{
    public function business(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            ...(new BusinessResource($this->currentBusiness($request)))->resolve($request),
            'api_key' => ['name' => $this->key($request)->name, 'abilities' => $this->key($request)->abilities],
        ]]);
    }

    public function items(Request $request, InventoryService $inventoryService): AnonymousResourceCollection
    {
        return InventoryItemResource::collection($inventoryService->overview($this->currentBusiness($request))['items']->where('is_active', true)->values());
    }

    public function customers(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['nui' => ['nullable', 'string', 'max:32'], 'search' => ['nullable', 'string', 'max:120']]);

        return CounterpartyResource::collection($this->currentBusiness($request)->counterparties()
            ->whereIn('type', ['customer', 'both'])
            ->where('status', 'active')
            ->when(isset($filters['nui']), fn ($query) => $query->where('nui', $filters['nui']))
            ->when(isset($filters['search']), fn ($query) => $query->where('name', 'like', '%'.addcslashes($filters['search'], '%_\\').'%'))
            ->orderBy('name')
            ->limit(200)
            ->get());
    }

    public function storeCustomer(Request $request, CounterpartyService $counterpartyService): JsonResponse
    {
        $attributes = $request->validate($this->customerRules($request->input('country_code'), 'required'));
        /** @var User $actor */
        $actor = $request->user();
        $customer = $counterpartyService->createForBusiness($this->currentBusiness($request), (int) $actor->getKey(), [...$attributes, 'type' => 'customer', 'country_code' => $attributes['country_code'] ?? 'XK']);

        return (new CounterpartyResource($customer))->response()->setStatusCode(201);
    }

    public function storeSale(Request $request, IntegrationSalesService $salesService): JsonResponse
    {
        $attributes = $request->validate([
            'external_id' => ['required', 'string', 'max:120'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'payment_method' => ['nullable', 'string', 'in:cash,bank_transfer,card,other'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'issue' => ['sometimes', 'boolean'],
            'customer' => ['required', 'array'],
            'customer.id' => ['nullable', 'integer'],
            ...collect($this->customerRules($request->input('customer.country_code'), 'nullable'))->mapWithKeys(fn (array $rules, string $field): array => ["customer.{$field}" => $rules])->all(),
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:100000'],
            'lines.*.unit' => ['nullable', 'string', 'max:24'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:100000'],
            'lines.*.discount_amount' => ['sometimes', 'numeric', 'gte:0', 'decimal:0,2'],
            'lines.*.vat_code' => ['required', 'string', 'max:40'],
            'lines.*.sku' => ['nullable', 'string', 'max:80'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $result = $salesService->record($this->currentBusiness($request), $actor, $this->key($request), $attributes);

        return (new InvoiceResource($result['invoice']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function showSale(Request $request, IntegrationSalesService $salesService, string $externalId): InvoiceResource
    {
        return new InvoiceResource($salesService->findByExternalId($this->currentBusiness($request), $externalId) ?? abort(404, 'No sale with this external id has been recorded.'));
    }

    /** @return array<string, list<mixed>> */
    private function customerRules(mixed $countryCode, string $namePresence): array
    {
        return [
            'name' => [$namePresence, 'string', 'max:255'],
            'nui' => ['nullable', 'string', 'max:32', new CounterpartyIdentifier(is_string($countryCode) && $countryCode !== '' ? $countryCode : 'XK')],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ];
    }

    private function currentBusiness(Request $request): Business
    {
        return $request->attributes->get('integration_business');
    }

    private function key(Request $request): BusinessApiKey
    {
        return $request->attributes->get('integration_key');
    }
}
