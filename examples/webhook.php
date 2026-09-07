<?php
/**
 * Minimalny odbiór webhooka HubPay (IPN) z weryfikacją podpisu.
 *
 * W produkcji ten plik byłby osadzony w routingu aplikacji
 * (np. /api/hubpay/ipn) i akceptowałby POST z JSON body.
 *
 * Uruchomienie testowe:
 *   php -S 127.0.0.1:8000 -t examples
 *   curl -X POST http://127.0.0.1:8000/webhook.php \
 *        -H "Content-Type: application/json" \
 *        -H "X-HubPay-Signature: <signature>" \
 *        --data @examples/sample-webhook.json
 */

require __DIR__ . '/../vendor/autoload.php';

use Hubsys\HubPay\Sdk\HubPayClient;

$apiKey    = getenv('HUBPAY_API_KEY')    ?: 'hp_pk_test_xxxxxxxxxxxxxxxxxxxxxxxx';
$apiSecret = getenv('HUBPAY_API_SECRET') ?: 'hp_sk_test_yyyyyyyyyyyyyyyyyyyy';

$client = new HubPayClient(apiKey: $apiKey, apiSecret: $apiSecret, sandbox: true);

// 1. Odczytaj surowe body i nagłówek z podpisem
$rawPayload = file_get_contents('php://input') ?: '';
$signature  = $_SERVER['HTTP_X_HUBPAY_SIGNATURE'] ?? '';

if ($signature === '') {
    http_response_code(400);
    exit('Brak nagłówka X-HubPay-Signature.');
}

// 2. Zweryfikuj podpis
if (! $client->verifyWebhook($rawPayload, $signature)) {
    http_response_code(400);
    exit('Nieprawidłowy podpis webhooka.');
}

// 3. Zdekoduj i obsłuż zdarzenie
$data = json_decode($rawPayload, true);
if (! is_array($data)) {
    http_response_code(400);
    exit('Nieprawidłowy JSON.');
}

$status     = $data['status'] ?? null;
$txId       = $data['transaction_id'] ?? null;
$orderId    = $data['metadata']['order_id'] ?? null;

switch ($status) {
    case 'completed':
        // TODO: oznacz zamówienie $orderId jako opłacone (id transakcji: $txId)
        error_log("HubPay: tx=$txId order=$orderId COMPLETED");
        break;
    case 'failed':
    case 'rejected':
        // TODO: oznacz zamówienie $orderId jako nieudane
        error_log("HubPay: tx=$txId order=$orderId REJECTED");
        break;
    default:
        error_log("HubPay: tx=$txId status=$status (ignoruję)");
}

http_response_code(200);
echo 'OK';
