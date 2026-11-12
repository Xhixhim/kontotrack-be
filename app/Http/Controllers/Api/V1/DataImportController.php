<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Services\Imports\DataImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DataImportController extends Controller
{
    public function __construct(private DataImportService $importService) {}

    public function store(Request $request, Business $business, string $type): JsonResponse
    {
        $attributes = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx,xls'],
            'default_type' => ['nullable', 'string', 'in:customer,supplier,both'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->importService->import($business, $user, $type, $attributes['file'], ['default_type' => $attributes['default_type'] ?? null])]);
    }

    public function template(Business $business, string $type): Response
    {
        $template = $this->importService->template($type);

        return response($template['contents'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$template['filename'].'"',
        ]);
    }
}
