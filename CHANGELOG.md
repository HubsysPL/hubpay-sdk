# Changelog

All notable changes to **hubsys/hubpay-sdk** will be documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/);
project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] – 2026-09-07

### Dodane
- `Hubsys\HubPay\Sdk\HubPayClient` — lekki klient PHP (czysty cURL + JSON, brak zależności) do integracji z bramką HubPay (produkcja + Sandbox).
  - `createTransaction(amount, description, returnUrl, callbackUrl?, metadata?)` — inicjalizacja sesji płatności, zwraca `payment_url` do przekierowania klienta.
  - `verifyWebhook(payload, signature)` — weryfikacja podpisu HMAC-SHA256 (timing-safe `hash_equals`).
  - `getTransaction(transactionId)` — odczyt stanu i szczegółów transakcji.
  - Stała `DEFAULT_BASE_URL` (nadpisywalna przez konstruktor), `User-Agent: HubPay-PHP-SDK/1.0`.
- `Hubsys\HubPay\Sdk\Exception\ApiException` — wydzielony typ wyjątku z polami `httpStatus` i `responseBody` (wcześniej `Exception` ogólny).
- Wtyczka **HubPay Gateway for WooCommerce** (`woocommerce/hubpay-woocommerce.php`) — gotowa do skopiowania do `wp-content/plugins/`. WooCommerce 7.0+, WordPress 6.0+, PHP 8.1+.
- Przykłady (`examples/`):
  - `create_transaction.php` — minimalna inicjalizacja transakcji i przekierowanie.
  - `webhook.php` — obsługa IPN z weryfikacją podpisu.
- `composer.json` (`hubsys/hubpay-sdk`, PSR-4, MIT, PHP 8.1+) — kompatybilne z `composer require hubsys/hubpay-sdk`.
- GitHub Actions `.github/workflows/release.yml` — na tag `v*` buduje ZIP i publikuje GitHub Release.

### Bez zmian / nieobsługiwane
- Brak klienta dla endpointów HubCode (generate/pay/authorize/status/p2p) — planowane w v1.1.
- Brak wsparcia dla starego namespace `Modules\HubPay\Sdk\HubPayClient` — przy migracji z wbudowanego SDK modułu Laravel `hubsys/Hubsys.pl` zaktualizuj `use`.
