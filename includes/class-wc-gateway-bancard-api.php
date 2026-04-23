<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Bancard_API {
    const STAGING_URL = 'https://vpos.infonet.com.py:8888';
    const PRODUCTION_URL = 'https://vpos.infonet.com.py';

    protected $public_key;
    protected $private_key;
    protected $environment;

    public function __construct($public_key, $private_key, $environment = 'staging') {
        $this->public_key = (string) $public_key;
        $this->private_key = (string) $private_key;
        $this->environment = $environment === 'production' ? 'production' : 'staging';
    }

    public function get_base_url() {
        return $this->environment === 'production' ? self::PRODUCTION_URL : self::STAGING_URL;
    }

    public function is_configured() {
        return $this->public_key !== '' && $this->private_key !== '';
    }

    public function format_amount($amount) {
        return number_format((float) $amount, 2, '.', '');
    }

    public function generate_hash() {
        $parts = func_get_args();
        $normalized = array_map(
            static function ($part) {
                return is_null($part) ? '' : (string) $part;
            },
            $parts
        );

        return md5(implode('', $normalized));
    }

    public function build_payload(array $operation) {
        return array(
            'public_key' => $this->public_key,
            'operation'  => $operation,
        );
    }

    public function request($path, array $payload, $method = 'POST') {
        if (!$this->is_configured()) {
            return new WP_Error('bancard_missing_credentials', __('Configurá la llave pública y privada de Bancard antes de operar.', 'woocommerce-bancard'));
        }

        $response = wp_remote_request(
            $this->get_base_url() . $path,
            array(
                'method'  => strtoupper($method),
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body'    => wp_json_encode($payload),
                'timeout' => 45,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return new WP_Error('bancard_invalid_json', __('Bancard devolvió una respuesta inválida.', 'woocommerce-bancard'));
        }

        if ($status_code >= 400 && empty($body['status'])) {
            return new WP_Error('bancard_http_error', __('Bancard devolvió un error HTTP inesperado.', 'woocommerce-bancard'));
        }

        return $body;
    }

    public function parse_error_message($response, $fallback = '') {
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        if (isset($response['messages']) && is_array($response['messages']) && !empty($response['messages'])) {
            $message = $response['messages'][0];
            if (!empty($message['dsc'])) {
                return (string) $message['dsc'];
            }

            if (!empty($message['key'])) {
                return (string) $message['key'];
            }
        }

        if (!empty($response['message'])) {
            return (string) $response['message'];
        }

        if (!empty($response['response_description'])) {
            return (string) $response['response_description'];
        }

        return $fallback ?: __('Ocurrió un error al comunicarse con Bancard.', 'woocommerce-bancard');
    }

    public function request_single_buy(array $operation) {
        return $this->request('/vpos/api/0.3/single_buy', $this->build_payload($operation));
    }

    public function request_charge(array $operation) {
        return $this->request('/vpos/api/0.3/charge', $this->build_payload($operation));
    }

    public function request_cards_new(array $operation) {
        return $this->request('/vpos/api/0.3/cards/new', $this->build_payload($operation));
    }

    public function request_user_cards($user_id, array $operation) {
        return $this->request('/vpos/api/0.3/users/' . rawurlencode((string) $user_id) . '/cards', $this->build_payload($operation));
    }

    public function delete_card($user_id, array $operation) {
        return $this->request('/vpos/api/0.3/users/' . rawurlencode((string) $user_id) . '/cards', $this->build_payload($operation), 'DELETE');
    }

    public function request_get_confirmation(array $operation) {
        return $this->request('/vpos/api/0.3/single_buy/confirmations', $this->build_payload($operation));
    }

    public function request_rollback(array $operation) {
        return $this->request('/vpos/api/0.3/single_buy/rollback', $this->build_payload($operation));
    }

    public function request_preauthorization_confirm(array $operation) {
        return $this->request('/vpos/api/0.3/preauthorizations/confirm', $this->build_payload($operation));
    }

    public function request_billing_client_info(array $operation) {
        return $this->request('/vpos/api/0.3/billing/client_info', $this->build_payload($operation));
    }

    public function request_billing_cancel(array $operation) {
        return $this->request('/vpos/api/0.3/billing/cancel', $this->build_payload($operation));
    }
}
