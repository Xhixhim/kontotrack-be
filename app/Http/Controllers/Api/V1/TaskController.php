<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TaskResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function __construct(private TaskService $taskService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'in:open,done'],
        ]);

        return TaskResource::collection($this->taskService->list($business, $filters['from'] ?? null, $filters['to'] ?? null, $filters['status'] ?? null));
    }

    public function store(Request $request, Business $business): TaskResource
    {
        $attributes = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new TaskResource($this->taskService->createTask($business, $user, $attributes));
    }

    public function update(Request $request, Business $business, int $task): TaskResource
    {
        $attributes = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'due_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new TaskResource($this->taskService->updateTask($business, $user, $task, $attributes));
    }

    public function complete(Request $request, Business $business, int $task): TaskResource
    {
        $attributes = $request->validate([
            'completed_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:99999999999999.99'],
            'document_id' => ['nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new TaskResource($this->taskService->completeTask($business, $user, $task, $attributes));
    }

    public function generate(Request $request, Business $business): JsonResponse
    {
        $attributes = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->taskService->generateTaxCalendar($business, $user, (int) $attributes['year'])]);
    }
}
