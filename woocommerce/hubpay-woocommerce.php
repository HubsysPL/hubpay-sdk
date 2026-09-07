<?php
/**
 * Plugin Name: HubPay Gateway for WooCommerce
 * Plugin URI: https://github.com/HubsysPL/hubpay-sdk
 * Description: Oficjalna bramka szybkich płatności internetowych HubPay oraz kodu ZIP dla platformy WooCommerce.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 * Author: Hubsys Engineering
 * Author URI: https://hubsys.pl
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: woocommerce-hubpay
 * Domain Path: /languages
 *
 * Instalacja:
 *   1. Rozpakuj archiwum ZIP SDK.
 *   2. Skopiuj katalog `hubpay-woocommerce/` (zawierający ten plik) do `wp-content/plugins/`.
 *   3. Aktywuj wtyczkę w panelu WordPress (Wtyczki → Zainstalowane).
 *   4. Przejdź do WooCommerce → Ustawienia → Płatności → HubPay, włącz i wpisz api_key / api_secret.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_hubpay_gateway_class');

function init_hubpay_gateway_class()
{
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_HubPay extends WC_Payment_Gateway
    {
        public bool $sandbox;
        public string $api_key;
        public string $api_secret;

        public function __construct()
        {
            $this->id = 'hubpay';
            $this->icon = apply_filters('woocommerce_hubpay_icon', 'https://hubsys.pl/assets/hubpay_logo.svg');
            $this->has_fields = false;
            $this->method_title = 'HubPay (Płatności Online & Kod ZIP)';
            $this->method_description = 'Płatności internetowe w walucie HUB za pomocą bramki HubPay oraz szybkich kodów mobilnych ZIP.';

            $this->supports = ['products', 'refunds'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->sandbox = 'yes' === $this->get_option('sandbox');
            $this->api_key = $this->get_option('api_key');
            $this->api_secret = $this->get_option('api_secret');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_wc_gateway_hubpay', [$this, 'check_ipn_response']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Włącz / Wyłącz',
                    'type' => 'checkbox',
                    'label' => 'Włącz płatności HubPay',
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => 'Tytuł',
                    'type' => 'text',
                    'description' => 'Nazwa metody płatności widoczna dla klienta w koszyku.',
                    'default' => 'Płatność online HubPay / Kod ZIP',
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => 'Opis',
                    'type' => 'textarea',
                    'description' => 'Opis metody płatności widoczny w koszyku.',
                    'default' => 'Zapłać bezpiecznie i natychmiastowo za pomocą portfela HubPay lub 6-cyfrowego kodu ZIP w telefonie.',
                ],
                'sandbox' => [
                    'title' => 'Tryb Testowy (Sandbox)',
                    'type' => 'checkbox',
                    'label' => 'Włącz tryb testowy HubPay (klucze testowe)',
                    'default' => 'no',
                ],
                'api_key' => [
                    'title' => 'Klucz Publiczny API (API Key)',
                    'type' => 'text',
                    'description' => 'Twój klucz publiczny API z panelu merchanta HubPay (np. hp_pk_...).',
                    'default' => '',
                ],
                'api_secret' => [
                    'title' => 'Klucz Prywatny API (API Secret)',
                    'type' => 'password',
                    'description' => 'Twój klucz prywatny API z panelu merchanta HubPay (np. hp_sk_...).',
                    'default' => '',
                ],
            ];
        }

        /**
         * Procesuje zamówienie i przekierowuje do bramki płatności.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            $baseUrl = 'https://hubsys.pl';
            $amount = (float) $order->get_total();
            $returnUrl = $this->get_return_url($order);
            $callbackUrl = add_query_arg('wc-api', 'WC_Gateway_HubPay', home_url('/'));

            $formattedAmount = number_format($amount, 2, '.', '');
            $signature = hash_hmac('sha256', $this->api_key . $formattedAmount . $returnUrl, $this->api_secret);

            $payload = [
                'api_key' => $this->api_key,
                'amount' => $amount,
                'description' => sprintf('Zamówienie #%s w %s', $order->get_order_number(), get_bloginfo('name')),
                'return_url' => $returnUrl,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'order_id' => $order_id,
                    'customer_email' => $order->get_billing_email(),
                ],
                'signature' => $signature,
            ];

            $response = wp_remote_post($baseUrl . '/api/hubpay/v1/hubpay/init', [
                'headers' => ['Content-Type' => application/json, 'Accept' => application/json],
                'body' => json_encode($payload),
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                wc_add_notice('Błąd połączenia z bramką HubPay: ' . $response->get_error_message(), 'error');
                return ['result' => 'fail'];
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (! isset($body['success']) || ! $body['success'] || empty($body['payment_url'])) {
                $errorMsg = $body['error'] ?? 'Nie udało się zainicjalizować płatności.';
                wc_add_notice('Błąd HubPay: ' . $errorMsg, 'error');
                return ['result' => 'fail'];
            }

            $order->update_meta_data('_hubpay_transaction_id', $body['transaction_id']);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $body['payment_url'],
            ];
        }

        /**
         * Obsługuje webhook zwrotny (IPN) po zaksięgowaniu płatności.
         */
        public function check_ipn_response()
        {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (! $data || empty($data['transaction_id'])) {
                status_header(400);
                exit('Invalid webhook data');
            }

            $orderId = $data['metadata']['order_id'] ?? null;
            if (! $orderId) {
                status_header(404);
                exit('Order ID not found in metadata');
            }

            $order = wc_get_order($orderId);
            if (! $order) {
                status_header(404);
                exit('Order not found');
            }

            if ($data['status'] === 'completed') {
                $order->payment_complete($data['transaction_id']);
                $order->add_order_note(sprintf('Płatność HubPay zakończona sukcesem (ID Transakcji: %s)', $data['transaction_id']));
            } elseif ($data['status'] === 'failed' || $data['status'] === 'rejected') {
                $order->update_status('failed', 'Płatność HubPay została odrzucona.');
            }

            status_header(200);
            exit('OK');
        }
    }
}

add_filter('woocommerce_payment_gateways', function ($methods) {
    $methods[] = 'WC_Gateway_HubPay';
    return $methods;
});
