# Catalogus-API

Deze Iris-app serveert een JSON-catalogus met vijf fictieve producten uit een koffiebar. De API is publiek leesbaar. Je kunt één product opvragen of de catalogus filteren op categorie en per pagina uitlezen. Prijzen staan in eurocenten.

## Lokaal starten

Gebruik PHP 8.3 of hoger met PDO SQLite en Composer 2. Voer vanuit de hoofdmap van de Iris-repository de volgende opdrachten uit.

```sh
cd examples/catalog-api
composer install
php vendor/bin/iris migrate
php vendor/bin/iris serve --port=8102
```

De migratie maakt `storage/catalog.sqlite` aan en vult de tabel. Iris houdt bij welke migraties zijn uitgevoerd. Je kunt `migrate` dus herhalen met behoud van de vijf producten.

## Proberen

```sh
curl -H 'Accept: application/json' 'http://127.0.0.1:8102/products?category=koffie&page=2&per_page=1'
curl -H 'Accept: application/json' http://127.0.0.1:8102/products/2
```

Het eerste verzoek geeft de tweede koffie uit de catalogus terug.

```json
{
  "data": [{"id": 2, "name": "Filterkoffie", "category": "koffie", "price_cents": 750}],
  "meta": {"current_page": 2, "per_page": 1, "total": 2, "last_page": 2}
}
```

`GET /products` begint op pagina 1 met drie producten per pagina. Met `per_page` kies je een paginagrootte van 1 tot 100. Het veld `total` telt alleen de producten die aan de categoriefilter voldoen. Een onbekend product-ID geeft HTTP 404. Een ongeldige paginagrootte geeft HTTP 422.

## Testen

Voer in de projectmap `composer test` uit. De test past de migratie toe op een SQLite-databank in het geheugen en controleert de routes met de HTTP-testclient van Iris.
