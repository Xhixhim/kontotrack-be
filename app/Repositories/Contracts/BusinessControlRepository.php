<?php

namespace App\Repositories\Contracts;

use App\Models\Business;

interface BusinessControlRepository
{
    /**
     * Collect the raw facts the automatic controls evaluate, as of the given date.
     *
     * @return array<string, mixed>
     */
    public function controlData(Business $business, string $today): array;
}
