# HubPay PHP SDK

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net/)
[![GitHub release](https://img.shields.io/github/v/release/HubsysPL/hubpay-sdk)](https://github.com/HubsysPL/hubpay-sdk/releases)

Oficjalny **PHP SDK** + **WooCommerce gateway** do integracji z bramką płatności **HubPay** (ekosystem Hubsys).

- 🪶 **Lekki** — brak zewnętrznych zależności (czysty cURL + JSON), tylko `ext-curl`, `ext-json`, `ext-openssl`.
- 🔐 **Bezpieczny** — weryfikacja podpisu HMAC-SHA256 z użyciem `hash_equals` (odporna na timing attacks).
- 🧪 **Sandbox + produkcja** — ten sam klient, przełączenie jednym parametrem.
- 🛒 **WooCommerce out-of-the-box** — wystarczy skopiować plik wtyczki do `wp-content/plugins/`.

---

## 📦 Instalacja

### Przez Composer (zalecane)

```bash
composer require hubsys/hubpay-sdk
```

W pliku PHP:

```php
require __DIR__ . '/vendor/autoload.php';

use Hubsys\HubPay\Sdk\HubPayClient;

$client = new HubPayClient(
    apiKey: 'hp_pk_live_xxxxxx',
    apiSecret: 'hp_sk_live_yyyyyy',
    sandbox: false,           // true dla środowiska testowego
);
```

### Ręcznie (bez Composera)

Pobierz archiwum z [Releases](https://github.com/HubsysPL/hubpay-sdk/releases) (`hubpay-sdk-vX.Y.Z.zip`), rozpakuj i wskaż `require_once`:

```php
require_once __DIR__ . '/hubpay-sdk/src/HubPayClient.php';
require_once __DIR__ . '/hubpay-sdk/src/Exception/ApiException.php';
```

---

## 🚀 Szybki start — utworzenie transakcji

```php
use Hubsys\HubPay\Sdk\HubPayClient;
use Hubsys\HubPay\Sdk\Exception\ApiException;

$client = new HubPayClient(
    apiKey:    'hp_pk_live_xxxxxx',
    apiSecret: 'hp_sk_live_yyyyyy',
);

try {
    $tx = $client->createTransaction(
        amount:      149.99,
        description: 'Zamówienie #1042 w Sklepie Treska',
        returnUrl:   'https://twojsklep.pl/zamowienie/sukces',
        callbackUrl: 'https://twojsklep.pl/api/hubpay/ipn',
        metadata:    [
            'order_id'       => 1042,
            'customer_email' => 'klient@treska.xyz',
        ],
    );

    header('Location: ' . $tx['payment_url']);
    exit;
} catch (ApiException $e) {
    // Błąd bramki / połączenia / autoryzacji
    error_log('HubPay: ' . $e->getMessage() . ' [HTTP ' . $e->httpStatus . ']');
    http_response_code(500);
    echo 'Płatność chwilowo niedostępna.';
}
```

Więcej: [`examples/create_transaction.php`](examples/create_transaction.php).

---

## 🪝 Webhook (IPN) — odbiór i weryfikacja

```php
use Hubsys\HubPay\Sdk\HubPayClient;

$client = new HubPayClient('hp_pk_live_xxxxxx', 'hp_sk_live_yyyyyy');

$rawPayload = file_get_contents('php://input');
$signature  = $_SERVER['HTTP_X_HUBPAY_SIGNATURE'] ?? '';

if (! $client->verifyWebhook($rawPayload, $signature)) {
    http_response_code(400);
    exit('Nieprawidłowy podpis webhooka.');
}

$data = json_decode($rawPayload, true);
if ($data['status'] === 'completed') {
    // Zrealizuj zamówienie ($data['metadata']['order_id'])
    http_response_code(200);
    echo 'OK';
}
```

Pełny przykład z walidacją struktury: [`examples/webhook.php`](examples/webhook.php).

---

## 🛒 WooCommerce

1. Pobierz archiwum SDK z [Releases](https://github.com/HubsysPL/hubpay-sdk/releases).
2. Rozpakuj i skopiuj katalog `woocommerce/` do `wp-content/plugins/` w instalacji WordPress.
3. W panelu WordPress aktywuj wtyczkę **HubPay Gateway for WooCommerce**.
4. **WooCommerce → Ustawienia → Płatności → HubPay** → zaznacz „Włącz", wpisz `api_key` i `api_secret` (z panelu merchanta HubPay).
5. (Opcjonalnie) Włącz „Tryb Testowy" dla transakcji Sandbox.

Szczegóły: nagłówek pliku `woocommerce/hubpay-woocommerce.php`.

---

## 📚 API

### `new HubPayClient(string $apiKey, string $apiSecret, bool $sandbox = false, ?string $customBaseUrl = null, int $timeout = 10)`

| Parametr        | Typ            | Domyślnie               | Opis                                                       |
|-----------------|----------------|-------------------------|------------------------------------------------------------|
| `$apiKey`       | `string`       | — (wymagane)            | Klucz publiczny API (`hp_pk_live_…` lub `hp_pk_test_…`).    |
| `$apiSecret`    | `string`       | — (wymagane)            | Klucz prywatny API (`hp_sk_live_…` lub `hp_sk_test_…`).    |
| `$sandbox`      | `bool`         | `false`                 | `true` → użyj kluczy testowych + endpointu Sandbox.         |
| `$customBaseUrl`| `?string`      | `HubPayClient::DEFAULT_BASE_URL` | Niestandardowy URL bramki (testy, mirror).       |
| `$timeout`      | `int`          | `10`                    | Timeout cURL w sekundach.                                  |

### `createTransaction(float $amount, string $description, string $returnUrl, ?string $callbackUrl = null, array $metadata = []): array`

Inicjalizuje transakcję. Zwraca tablicę z `payment_url` (do przekierowania klienta) i `transaction_id`.

### `verifyWebhook(array|string $payload, string $signature): bool`

Sprawdza podpis HMAC-SHA256. Zwraca `true` tylko gdy podpis jest w 100% poprawny (timing-safe).

### `getTransaction(string $transactionId): array`

Pobiera aktualny stan i szczegóły transakcji (np. do pollingu po stronie serwera w aplikacjach asynchronicznych).

### `isSandbox(): bool` · `getBaseUrl(): string`

Pomocnicze gettery.

---

## 🛟 Obsługa błędów

SDK rzuca `Hubsys\HubPay\Sdk\Exception\ApiException` z polami:
- `getMessage(): string` — opis błędu po polsku.
- `httpStatus: ?int` — kod HTTP odpowiedzi (lub `null` dla błędów połączenia cURL).
- `responseBody: ?string` — surowa odpowiedź serwera (do debugowania).

Łap **jednym** `catch`:

```php
try {
    $client->createTransaction(...);
} catch (\Hubsys\HubPay\Sdk\Exception\ApiException $e) {
    // bramka / sieć / autoryzacja
} catch (\InvalidArgumentException $e) {
    // złe argumenty po stronie klienta
}
```

---

## 🧪 Sandbox

Aby testować bez prawdziwych pieniędzy:
1. Zaloguj się do panelu merchanta HubPay (`https://hubsys.pl/developers/hubpay`).
2. Wygeneruj parę kluczy **testowych** (`hp_pk_test_…` / `hp_sk_test_…`).
3. Ustaw `sandbox: true` w konstruktorze klienta (lub zaznacz w ustawieniach wtyczki WooCommerce).

---

## 🤝 Wsparcie

- 🐛 **Błędy:** [GitHub Issues](https://github.com/HubsysPL/hubpay-sdk/issues)
- 📖 **Dokumentacja API bramki:** [developers.hubsys.pl/docs/hubpay](https://developers.hubsys.pl/docs/hubpay)
- 💬 **Discord:** [discord.gg/efWu5HWM2u](https://discord.gg/efWu5HWM2u)

---

## 📜 Licencja

[MIT](LICENSE) — Hubsys Engineering, 2026.
