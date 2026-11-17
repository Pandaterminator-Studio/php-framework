<?php

return [
    'driver' => 'sqlite',
    'database' => getenv('IRIS_SALES_REPORT_DB') ?: dirname(__DIR__) . '/storage/sales.sqlite',
];
