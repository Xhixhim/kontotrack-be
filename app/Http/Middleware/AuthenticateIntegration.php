<?php

namespace App\Http\Middleware;

use App\Models\BusinessApiKey;
use App\Services\Integrations\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegration
{
    public function __construct(private ApiKeyService $apiKeyService) {}

    /**
     * Authenticate another system by its API key. The request then acts for the key's business,
     * with the permissions of the member who created the key.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $key = $this->apiKeyService->resolve($request->bearerToken());
        $creator = $key?->creator;
        $business = $key?->business;

        if ($key === null || $creator === null || $business === null || $business->status !== 'active') {
            return response()->json(['message' => 'This API key is not valid.'], 401);
        }

        // A key is only as good as the person behind it: once they are deactivated or lose bookkeeping access, it stops working.
        if ($creator->deactivated_at !== null || ! Gate::forUser($creator)->allows('manageBookkeeping', $business)) {
            return response()->json(['message' => 'The member who created this API key no longer has access to the business.'], 401);
        }

        if (! $key->allows($ability)) {
            return response()->json(['message' => "This API key may not do this (missing ability: {$ability})."], 403);
        }

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            BusinessApiKey::query()->whereKey($key->getKey())->update(['last_used_at' => now()]);
        }

        Auth::setUser($creator);
        $request->setUserResolver(fn () => $creator);
        $request->attributes->set('integration_key', $key);
        $request->attributes->set('integration_business', $business);

        return $next($request);
    }
}
