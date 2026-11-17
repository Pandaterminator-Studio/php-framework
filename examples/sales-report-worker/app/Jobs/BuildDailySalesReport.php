<?php

namespace App\Jobs;

use Core\Database\Connection;
use Core\Queue\JobHandler;
use Core\Validation\Validator;

final class BuildDailySalesReport implements JobHandler
{
    public const NAME = 'sales.daily-report';

    public function __construct(private Connection $database)
    {
    }

    public function handle(array $payload, string $jobId): void
    {
        $fields = Validator::validate($payload, ['day' => 'required|date']);
        $this->database->execute(
            'INSERT INTO daily_sales_reports (report_date, sale_count, revenue_cents)
             SELECT ?, COUNT(*), COALESCE(SUM(amount_cents), 0) FROM sales WHERE sold_on = ?
             ON CONFLICT(report_date) DO UPDATE SET
                 sale_count = excluded.sale_count,
                 revenue_cents = excluded.revenue_cents',
            [$fields['day'], $fields['day']],
        );
    }
}
