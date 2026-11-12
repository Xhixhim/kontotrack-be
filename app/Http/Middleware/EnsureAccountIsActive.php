<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Refuse requests from accounts a system administrator has deactivated, even with a session or token that is still valid.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->deactivated_at !== null) {
            return response()->json(['message' => 'This account has been deactivated.'], 401);
        }

        return $next($request);
    }
}
