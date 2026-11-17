<?php

use Core\Testing\TestClient;
use Core\Testing\TestResponse;

function hiddenFields(string $html): array
{
    preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]+)">/', $html, $inputs, PREG_SET_ORDER);
    $fields = array_column($inputs, 2, 1);
    foreach (['_csrf_token', '_integrity_token', 'workshop_id', 'price_cents'] as $name) {
        if (!isset($fields[$name])) {
            throw new RuntimeException("Formulierveld ontbreekt: $name");
        }
    }
    return $fields;
}

function submit(TestClient $client, array $fields): TestResponse
{
    return $client->request('POST', '/reserveren', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
}

function expectText(string $html, string $text): void
{
    if (!str_contains($html, $text)) {
        throw new RuntimeException("Verwachte tekst ontbreekt: $text");
    }
}

$sessionDirectory = sys_get_temp_dir() . '/iris-reservation-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
ini_set('session.save_handler', 'files');
session_save_path($sessionDirectory);

try {
    $application = require dirname(__DIR__) . '/bootstrap.php';
    $client = new TestClient($application);
    $form = $client->get('/')->assertStatus(200)->response()->body();
    expectText($form, '€ 27,50 per persoon');
    $fields = hiddenFields($form) + ['name' => 'Sam & Noor', 'seats' => '2'];
    submit($client, array_diff_key($fields, ['_csrf_token' => true]))->assertStatus(403);
    submit($client, array_replace($fields, ['price_cents' => '1']))->assertStatus(403);
    submit($client, array_replace($fields, ['workshop_id' => '99']))->assertStatus(403);

    $confirmation = submit($client, $fields)->assertStatus(200)->response()->body();
    expectText($confirmation, 'Bedankt, Sam &amp; Noor.');
    expectText($confirmation, '€ 55,00');
    submit($client, $fields)->assertStatus(403);

    $fields = hiddenFields($client->get('/')->assertStatus(200)->response()->body());
    $retry = submit($client, $fields + ['name' => 'Mika', 'seats' => '7'])->assertStatus(422)->response()->body();
    expectText($retry, 'Kies tussen 1 en 6 plaatsen.');
    expectText($retry, 'value="Mika"');
    submit($client, hiddenFields($retry) + ['name' => 'Mika', 'seats' => '6'])->assertStatus(200);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach (glob($sessionDirectory . '/sess_*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    rmdir($sessionDirectory);
}

echo "Reserveringsformulier, prijscontrole en eenmalige bevestiging getest.\n";
