<?php

use App\Jobs\BuildDailySalesReport;
use Core\Database\Connection;
use Core\Queue\DatabaseQueue;
use Core\Validation\Validator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$application = require __DIR__ . '/bootstrap.php';
if ($argc !== 3 || !in_array($argv[1], ['publish', 'show'], true)
    || !Validator::check(['day' => $argv[2]], ['day' => 'required|date'])->valid()) {
    fwrite(STDERR, "Gebruik php report.php publish|show JJJJ-MM-DD.\n");
    exit(2);
}

$day = $argv[2];
$services = $application->container();
if ($argv[1] === 'publish') {
    $id = $services->get(DatabaseQueue::class)->push(BuildDailySalesReport::NAME, ['day' => $day]);
    echo "Rapport voor $day aangevraagd (taak $id).\n";
    exit(0);
}

$report = $services->get(Connection::class)->first(
    'SELECT report_date, sale_count, revenue_cents FROM daily_sales_reports WHERE report_date = ?',
    [$day],
);
if ($report === null) {
    fwrite(STDERR, "Er is nog geen rapport voor $day.\n");
    exit(1);
}
echo json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL;
