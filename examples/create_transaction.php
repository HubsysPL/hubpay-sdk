<?php
/**
 * Przykład inicjalizacji transakcji HubPay i przekierowania klienta do kasy.
 *
 * Uruchomienie:
 *   1. composer require hubsys/hubpay-sdk
 *   2. Ustaw HUBPAY_API_KEY i HUBPAY_API_SECRET (poniżej albo w .env)
 *   3. php examples/create_transaction.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Hubsys\HubPay\Sdk\HubPayClient;
use Hubsys\HubPay\Sdk\Exception\ApiException;

// W produkcji ładuj z env / vault, NIE hardkoduj.
$apiKey    = getenv('HUBPAY_API_KEY')    ?: 'hp_pk_test_xxxxxxxxxxxxxxxxxxxxxxxx';
$apiSecret = getenv('HUBPAY_API_SECRET') ?: 'hp_sk_test_yyyyyyyyyyyyyyyyyyyy';

$client = new HubPayClient(
    apiKey: $apiKey,
    apiSecret: $apiSecret,
    sandbox: true,  // Sandbox dla testów
);

// Demo: utwórz transakcję za 49.99 HUB
try {
    $tx = $client->createTransaction(
        amount: 49.99,
        description: 'Testowa transakcja z przykładu SDK',
        returnUrl: 'https://example.com/success',
        callbackUrl: 'https://example.com/api/hubpay/ipn',
        metadata: [
            'demo' => '1',
            'source' => 'hubpay-sdk-examples',
        ],
    );

    echo "OK — transakcja utworzona:\n";
    echo "  transaction_id: " . ($tx['transaction_id'] ?? '(brak)') . "\n";
    echo "  payment_url:    " . ($tx['payment_url'] ?? '(brak)') . "\n";
} catch (ApiException $e) {
    echo "Błąd API: " . $e->getMessage() . "\n";
    echo "  HTTP:  " . ($e->httpStatus ?? '(n/a)') . "\n";
    echo "  Body:  " . ($e->responseBody ?? '(n/a)') . "\n";
    exit(1);
} catch (\InvalidArgumentException $e) {
    echo "Błąd argumentów: " . $e->getMessage() . "\n";
    exit(1);
}
