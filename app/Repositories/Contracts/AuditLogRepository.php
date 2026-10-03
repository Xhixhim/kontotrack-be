<?php

namespace App\Repositories\Contracts;

use App\Models\AuditLog;
use App\Models\Business;
use Illuminate\Pagination\LengthAwarePaginator;

interface AuditLogRepository
{
    /** @return LengthAwarePaginator<int, AuditLog> */
    public function listForBusiness(Business $business, ?string $subjectType, ?int $subjectId): LengthAwarePaginator;
}
