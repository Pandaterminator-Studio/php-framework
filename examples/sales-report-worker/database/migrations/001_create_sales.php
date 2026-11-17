<?php

use Core\Database\Connection;
use Core\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(Connection $connection): void
    {
        $connection->execute('CREATE TABLE sales (
            id INTEGER PRIMARY KEY,
            sold_on TEXT NOT NULL,
            amount_cents INTEGER NOT NULL CHECK (amount_cents >= 0)
        )');
        $connection->execute('CREATE INDEX sales_by_day ON sales (sold_on)');
        $connection->execute('CREATE TABLE daily_sales_reports (
            report_date TEXT PRIMARY KEY NOT NULL,
            sale_count INTEGER NOT NULL,
            revenue_cents INTEGER NOT NULL
        )');
        foreach ([['2025-04-14', 1495], ['2025-04-14', 2275], ['2025-04-14', 850], ['2025-04-15', 1700]] as $sale) {
            $connection->execute('INSERT INTO sales (sold_on, amount_cents) VALUES (?, ?)', $sale);
        }
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE daily_sales_reports');
        $connection->execute('DROP TABLE sales');
    }
};
