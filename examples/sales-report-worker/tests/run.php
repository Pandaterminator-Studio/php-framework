<?php

use App\Jobs\BuildDailySalesReport;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Queue\DatabaseQueue;
use Core\Queue\Worker;

function checkReport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('IRIS_SALES_REPORT_DB=:memory:');
$application = require dirname(__DIR__) . '/bootstrap.php';
$services = $application->container();
$database = $services->get(Connection::class);
$migrator = new Migrator($database, dirname(__DIR__) . '/database/migrations');
$migrator->migrate();
checkReport($migrator->migrate() === [], 'Een tweede migratie moet de gegevens behouden.');
$queue = $services->get(DatabaseQueue::class);
$queue->install();
$worker = $services->get(Worker::class);

$day = ['day' => '2025-04-14'];
$expected = ['report_date' => '2025-04-14', 'sale_count' => 3, 'revenue_cents' => 4620];
$queue->push(BuildDailySalesReport::NAME, $day);
$queue->push(BuildDailySalesReport::NAME, $day);
checkReport($database->table('daily_sales_reports')->count() === 0, 'Een taak publiceren mag het rapport nog niet maken.');
checkReport($worker->runNext() === 'completed' && $worker->runNext() === 'completed', 'Beide taken moeten slagen.');
checkReport($database->table('daily_sales_reports')->get() === [$expected], 'Herhaalde verwerking moet hetzelfde dagtotaal geven.');

$queue->push(BuildDailySalesReport::NAME, ['day' => '2025-04-16']);
checkReport($worker->runNext() === 'completed', 'Een dag zonder verkopen moet verwerkt worden.');
$empty = $database->table('daily_sales_reports')->where('report_date', '2025-04-16')->first();
checkReport($empty['sale_count'] === 0 && $empty['revenue_cents'] === 0, 'Een lege dag moet een nulrapport geven.');
checkReport($worker->runNext() === 'empty', 'Alle taken moeten afgewerkt zijn.');
echo "Verkooprapport, herhaalde verwerking en lege dag gecontroleerd.\n";
