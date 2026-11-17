<?php

use App\Jobs\BuildDailySalesReport;

return [
    'handlers' => [BuildDailySalesReport::NAME => BuildDailySalesReport::class],
];
