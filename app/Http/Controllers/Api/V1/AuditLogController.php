<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\Business;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __construct(private AuditLogService $auditLogService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'subject' => ['nullable', 'string', 'in:'.implode(',', array_keys(AuditLogService::SUBJECTS))],
            'subject_id' => ['nullable', 'integer', 'required_with:subject'],
        ]);

        return AuditLogResource::collection($this->auditLogService->listForBusiness(
            $business,
            $filters['subject'] ?? null,
            isset($filters['subject_id']) ? (int) $filters['subject_id'] : null,
        ));
    }
}
