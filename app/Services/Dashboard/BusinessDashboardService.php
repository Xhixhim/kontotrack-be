<?php

namespace App\Services\Dashboard;

use App\Models\Business;
use App\Repositories\Contracts\BusinessDashboardRepository;

class BusinessDashboardService
{
    public function __construct(private BusinessDashboardRepository $dashboardRepository) {}

    /** @return array<string, string|int> */
    public function summaryForBusiness(Business $business, string $from, string $to): array
    {
        return $this->dashboardRepository->summaryForBusiness($business, $from, $to);
    }
}
