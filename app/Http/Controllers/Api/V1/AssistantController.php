<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Services\Ai\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(private AssistantService $assistantService) {}

    public function ask(Request $request, Business $business): JsonResponse
    {
        $attributes = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            // Earlier turns of this conversation, oldest first, so follow-up questions make sense.
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:12000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->assistantService->ask(
            $business,
            $user,
            $attributes['question'],
            array_map(fn (array $turn): array => ['role' => $turn['role'], 'content' => $turn['content']], $attributes['history'] ?? []),
            $user->can('viewPayroll', $business),
        )]);
    }
}
