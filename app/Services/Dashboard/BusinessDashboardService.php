<?php

namespace App\Services\Dashboard;

use App\Models\Business;
use App\Repositories\Contracts\BusinessDashboardRepository;

class BusinessDashboardService
{
    public function __construct(private BusinessDashboardRepository $dashboardRepository) {}

    /**
     * Payroll figures are withheld (null) for members who may not see payroll.
     *
     * @return array<string, string|int|null>
     */
    public function summaryForBusiness(Business $business, string $from, string $to, bool $includePayroll = true): array
    {
        $summary = $this->dashboardRepository->summaryForBusiness($business, $from, $to);

        if (! $includePayroll) {
            $summary['payroll_gross'] = null;
            $summary['payroll_employees'] = null;
            $summary['payroll_status'] = null;
        }

        return $summary;
    }
}
