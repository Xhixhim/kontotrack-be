<?php

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use App\Models\Business;
use App\Repositories\Contracts\AuditLogRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentAuditLogRepository implements AuditLogRepository
{
    /** @return LengthAwarePaginator<int, AuditLog> */
    public function listForBusiness(Business $business, ?string $subjectType, ?int $subjectId): LengthAwarePaginator
    {
        return $business->auditLogs()
            ->when($subjectType !== null, fn ($query) => $query->where('subject_type', $subjectType))
            ->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->paginate(50);
    }
}
