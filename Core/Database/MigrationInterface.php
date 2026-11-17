<?php

namespace Core\Database;

interface MigrationInterface
{
    public function up(Connection $connection): void;

    public function down(Connection $connection): void;
}
