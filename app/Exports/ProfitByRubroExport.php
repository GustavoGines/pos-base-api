<?php

namespace App\Exports;

use App\Repositories\SalesAnalyticsRepository;

class ProfitByRubroExport extends ProfitByCategoryExport
{
    public function __construct($startDate, $endDate, mixed $rubroFilter = null, ?SalesAnalyticsRepository $repository = null, string $fiscalFilter = 'all')
    {
        parent::__construct($startDate, $endDate, 'rubro', $repository, $rubroFilter, $fiscalFilter);
    }
}
