# Dagelijks verkooprapport

Dit CLI-project gebruikt de Iris-wachtrij om een dagrapport te berekenen. De migratie zet vier fictieve verkopen in SQLite. Kies een verkoopdag, plaats de opdracht in de wachtrij en laat de worker het aantal verkopen en de omzet berekenen.

## Uitproberen

Gebruik PHP 8.3 of hoger binnen de 8.x-reeks, Composer 2 en de extensie PDO SQLite. Voer de volgende opdrachten uit vanaf de hoofdmap van de Iris-repository.

```sh
cd examples/sales-report-worker
composer install
php vendor/bin/iris migrate
php vendor/bin/iris queue:install
php report.php publish 2025-04-14
php vendor/bin/iris queue:work
php report.php show 2025-04-14
```

De drie verkopen van 14 april leveren samen € 46,20 op. Het rapport drukt bedragen uit in eurocenten.

```json
{"report_date":"2025-04-14","sale_count":3,"revenue_cents":4620}
```

`publish` geeft het taak-ID terug. `queue:work` verwerkt standaard één taak en sluit af. Als je voor dezelfde dag opnieuw een taak publiceert, berekent de worker het rapport opnieuw uit de oorspronkelijke verkopen. De datum is de primaire sleutel in de rapporttabel. Daardoor blijft er één rapport per dag, ook wanneer Iris een taak na een onderbreking opnieuw uitvoert.

Op 15 april bevat de databank één verkoop van € 17,00. Kies een andere dag voor een rapport met nul verkopen. De voorbeelddata en rapporten staan in `storage/sales.sqlite`. Met de omgevingsvariabele `IRIS_SALES_REPORT_DB` kun je een ander SQLite-bestand kiezen.

## Testen

```sh
composer test
```

De test voert de migratie, de queue en de worker uit op een afzonderlijke SQLite-databank in het geheugen. Ze controleert het dagtotaal, een herhaalde opdracht en een dag zonder verkopen.
