<?php

namespace Hubsys\HubPay\Sdk;

use Hubsys\HubPay\Sdk\Exception\ApiException;
use InvalidArgumentException;

/**
 * Oficjalny klient PHP SDK do integracji z bramką płatności HubPay (Hubsys).
 * Obsługuje środowisko produkcyjne oraz Sandbox.
 *
 * Wymaga PHP 8.1+. Zależności: brak (czysty cURL + JSON).
 *
 * @example
 *   $client = new HubPayClient(
 *       apiKey: 'hp_pk_live_xxxxxx',
 *       apiSecret: 'hp_sk_live_yyyyyy',
 *       sandbox: false
 *   );
 *   $tx = $client->createTransaction(
 *       amount: 149.99,
 *       description: 'Zamówienie #1042',
 *       returnUrl: 'https://twojsklep.pl/sukces',
 *       callbackUrl: 'https://twojsklep.pl/api/hubpay/ipn',
 *       metadata: ['order_id' => 1042]
 *   );
 *   header('Location: ' . $tx['payment_url']);
 */
class HubPayClient
{
    protected string $apiKey;
    protected string $apiSecret;
    protected string $baseUrl;
    protected int $timeout;
    protected bool $sandbox;

    /** @var string Domyślny URL bramki HubPay (produkcja). Można nadpisać w konstruktorze (np. dla testów). */
    public const DEFAULT_BASE_URL = 'https://hubsys.pl';

    /** @var string Stały User-Agent ułatwiający identyfikację integracji po stronie bramki. */
    public const USER_AGENT = 'HubPay-PHP-SDK/1.0';

    /**
     * @param string      $apiKey        Publiczny klucz API (np. hp_pk_live_... lub hp_pk_test_... dla Sandbox).
     * @param string      $apiSecret     Prywatny klucz API (np. hp_sk_live_... lub hp_sk_test_... dla Sandbox).
     * @param bool        $sandbox       true dla środowiska testowego (klucze testowe + endpoint Sandbox).
     * @param string|null $customBaseUrl Niestandardowy URL bramki (opcjonalny, domyślnie DEFAULT_BASE_URL).
     * @param int         $timeout       Timeout cURL w sekundach (domyślnie 10).
     */
    public function __construct(
        string $apiKey,
        string $apiSecret,
        bool $sandbox = false,
        ?string $customBaseUrl = null,
        int $timeout = 10
    ) {
        if (empty($apiKey) || empty($apiSecret)) {
            throw new InvalidArgumentException('Klucze apiKey oraz apiSecret są wymagane do inicjalizacji HubPayClient.');
        }

        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->sandbox = $sandbox;
        $this->timeout = max(1, $timeout);

        $this->baseUrl = $customBaseUrl
            ? rtrim($customBaseUrl, '/')
            : self::DEFAULT_BASE_URL;
    }

    /**
     * Inicjalizuje nową sesję płatności i zwraca adres URL do przekierowania klienta.
     *
     * @param float        $amount       Kwota płatności w HUB (np. 49.99).
     * @param string       $description  Tytuł / opis zamówienia (widoczny w panelu merchanta i na stronie płatności).
     * @param string       $returnUrl    URL powrotu po udanej płatności.
     * @param string|null  $callbackUrl  URL webhooka IPN do powiadomień asynchronicznych (opcjonalny).
     * @param array<mixed> $metadata     Dodatkowe metadane (np. order_id, customer_email). Max 10 kluczy, każdy do 64 znaków.
     *
     * @return array{success: bool, transaction_id: string, payment_url: string, expires_at?: string}
     *
     * @throws ApiException          Błąd API lub brak autoryzacji.
     * @throws InvalidArgumentException Kwota <= 0.
     */
    public function createTransaction(
        float $amount,
        string $description,
        string $returnUrl,
        ?string $callbackUrl = null,
        array $metadata = []
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Kwota transakcji musi być większa od zera.');
        }

        $formattedAmount = number_format($amount, 2, '.', '');
        $signature = $this->sign([$this->apiKey, $formattedAmount, $returnUrl]);

        $payload = [
            'api_key' => $this->apiKey,
            'amount' => $amount,
            'description' => $description,
            'return_url' => $returnUrl,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
            'signature' => $signature,
            'sandbox' => $this->sandbox,
        ];

        return $this->request('POST', '/api/hubpay/v1/hubpay/init', $payload);
    }

    /**
     * Weryfikuje podpis HMAC-SHA256 otrzymanego webhooka od HubPay.
     * Używa hash_equals() żeby uniknąć ataków timingowych.
     *
     * @param array|string $payload   Tablica asocjacyjna lub surowy JSON z ciała webhooka.
     * @param string       $signature Podpis z nagłówka X-HubPay-Signature (lub odpowiedniego pola).
     */
    public function verifyWebhook(array|string $payload, string $signature): bool
    {
        $rawPayload = is_array($payload) ? json_encode($payload) : $payload;
        $expected = hash_hmac('sha256', $rawPayload, $this->apiSecret);

        return hash_equals($expected, trim($signature));
    }

    /**
     * Pobiera aktualny stan i szczegóły transakcji.
     *
     * @return array{success: bool, transaction: array<string,mixed>}
     */
    public function getTransaction(string $transactionId): array
    {
        return $this->request('GET', '/api/hubpay/v1/hubpay/transaction/' . urlencode($transactionId));
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Generuje podpis HMAC-SHA256 dla payloadu (zachowuje kolejność elementów).
     *
     * @param array<string> $parts
     */
    protected function sign(array $parts): string
    {
        return hash_hmac('sha256', implode('', $parts), $this->apiSecret);
    }

    /**
     * Niskopoziomowe zapytanie HTTP cURL do API HubPay.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     * @throws ApiException
     */
    protected function request(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                $jsonBody = json_encode($data, JSON_THROW_ON_ERROR);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($jsonBody);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new ApiException("Błąd połączenia z API HubPay ({$url}): {$curlError}");
        }

        try {
            $decoded = json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                "Nieprawidłowa odpowiedź JSON z API HubPay (HTTP {$httpCode}): {$response}",
                $httpCode,
                (string) $response,
                $e
            );
        }

        if ($httpCode >= 400) {
            $message = $decoded['error'] ?? $decoded['message'] ?? 'Nieznany błąd bramki HubPay';
            throw new ApiException(
                "Błąd HubPay API [HTTP {$httpCode}]: {$message}",
                $httpCode,
                (string) $response
            );
        }

        return $decoded;
    }
}
