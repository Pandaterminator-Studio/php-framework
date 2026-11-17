# Reserveringsformulier

Een klein Kookatelier-voorbeeld met Iris en Twig. Kies één tot zes plaatsen voor een workshop van € 27,50 per persoon en bekijk het berekende totaal. De server bewaart alleen de formulierbeveiliging in een PHP-sessie. De naam en de reservering bestaan alleen tijdens de aanvraag.

## Lokaal starten

Voer vanuit de hoofdmap van de Iris-repository de volgende opdrachten uit met PHP 8.3 of hoger binnen PHP 8.x en Composer 2.

```sh
cd examples/reservation-form
composer install
php vendor/bin/iris serve --host=127.0.0.1 --port=8081
```

Open [het formulier](http://127.0.0.1:8081/) en selecteer bijvoorbeeld twee plaatsen. De bevestiging toont € 55,00. De applicatie gebruikt de lokale Iris-bibliotheek en PHP-sessies. Je hebt geen database nodig.

## Hoe het werkt

- `GET /` genereert een CSRF-token en een eenmalig integriteitstoken voor de benoemde POST-route. De server koppelt de workshop-ID en de prijs in eurocent aan dat integriteitstoken.
- `POST /reserveren` accepteert naam en aantal plaatsen als bewerkbare velden. Iris controleert de beschermde gegevens. De controller valideert de invoer en berekent het totaal met de vertrouwde prijs.
- Bij een gewone invoerfout toont het formulier een nieuwe inzendcode en behoudt het de ingevoerde naam. Gewijzigde beschermde waarden en een herhaalde bevestiging krijgen HTTP 403.

De route en het bijbehorende veldbeleid staan in [routes/web.php](routes/web.php). [ReservationController.php](app/ReservationController.php) bevat de prijsberekening en de weergave.

## Testen

```sh
composer test
```

De test doorloopt het formulier met Iris' HTTP-testclient en een tijdelijke sessiemap. Hij controleert een geldige reservering, ontbrekende CSRF-beveiliging, prijs- en ID-wijzigingen, een tweede inzending en herstel na een ongeldig aantal plaatsen.
