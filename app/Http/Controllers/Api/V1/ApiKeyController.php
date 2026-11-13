<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessApiKey;
use App\Models\User;
use App\Services\Integrations\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $apiKeyService) {}

    public function index(Business $business): JsonResponse
    {
        return response()->json(['data' => $this->apiKeyService->listForBusiness($business)->map($this->present(...))->all()]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'distinct', Rule::in(BusinessApiKey::ABILITIES)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $created = $this->apiKeyService->create($business, $user, $attributes);

        // The only time the token is shown.
        return response()->json(['data' => [...$this->present($created['key']), 'token' => $created['token']]], 201);
    }

    public function destroy(Request $request, Business $business, int $apiKey): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->apiKeyService->revoke($business, $user, $apiKey);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(BusinessApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'abilities' => $key->abilities,
            'created_by' => $key->creator?->name,
            'created_at' => $key->created_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
        ];
    }
}
