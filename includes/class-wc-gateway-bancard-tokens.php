<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Bancard_Tokens extends WC_Gateway_Bancard_Base {
    public function __construct() {
        $this->boot_gateway(
            array(
                'id'                 => 'bancard_tokens',
                'icon'               => plugins_url('assets/images/token.svg', __DIR__),
                'has_fields'         => true,
                'method_title'       => __('Bancard Tokens', 'woocommerce-bancard'),
                'method_description' => __('Pagos con tarjetas catastradas en Bancard.', 'woocommerce-bancard'),
                'supports'           => array('products', 'refunds', 'subscriptions', 'tokenization'),
            )
        );

        if (class_exists('WC_Subscriptions_Order')) {
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'process_subscription_payment'), 10, 2);
        }
    }

    public function init_form_fields() {
        $this->form_fields = $this->get_common_form_fields(
            array(
                'title' => array(
                    'title'       => __('Título', 'woocommerce-bancard'),
                    'type'        => 'text',
                    'description' => __('Texto visible para el cliente durante el checkout.', 'woocommerce-bancard'),
                    'default'     => __('Tarjetas guardadas (Bancard)', 'woocommerce-bancard'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Descripción', 'woocommerce-bancard'),
                    'type'        => 'textarea',
                    'description' => __('Descripción visible en el checkout.', 'woocommerce-bancard'),
                    'default'     => __('Pagá con una tarjeta que ya registraste en Bancard.', 'woocommerce-bancard'),
                ),
            )
        );
    }

    public function payment_fields() {
        $cards_response = $this->get_user_cards(get_current_user_id());
        if (is_wp_error($cards_response)) {
            wc_print_notice($cards_response->get_error_message(), 'error');
            return;
        }

        $cards = isset($cards_response['cards']) && is_array($cards_response['cards']) ? $cards_response['cards'] : array();

        echo '<div id="info-catastro-field"><p>' . esc_html__('Pagá de forma rápida y segura usando una tarjeta registrada en tu cuenta.', 'woocommerce-bancard') . '</p></div>';

        if (empty($cards)) {
            echo '<p>' . wp_kses_post(sprintf(__('No tenés tarjetas registradas todavía. Podés agregarlas desde <a href="%s">Mi Cuenta</a>.', 'woocommerce-bancard'), esc_url(wc_get_account_endpoint_url('payment-methods')))) . '</p>';
            return;
        }

        echo '<div class="bancard-token-cards">';
        foreach ($cards as $card) {
            $alias_token = isset($card['alias_token']) ? $card['alias_token'] : '';
            $brand = $this->normalize_card_brand(isset($card['card_brand']) ? $card['card_brand'] : '');
            $icon = plugin_dir_url(__FILE__) . '../assets/credit-cards/' . $brand . '.png';
            $masked = isset($card['card_masked_number']) ? $card['card_masked_number'] : '';
            $expiration = isset($card['expiration_date']) ? $card['expiration_date'] : '';
            $card_type = isset($card['card_type']) ? $card['card_type'] : '';

            echo '<label class="bancard-card-box form-row" for="bancard-card-' . esc_attr($alias_token) . '">';
            echo '<input type="radio" id="bancard-card-' . esc_attr($alias_token) . '" name="bancard_card_token" value="' . esc_attr($alias_token) . '">';
            echo '<img class="bancard-cardbrand" src="' . esc_url($icon) . '" alt="' . esc_attr($brand) . '">';
            echo '<span class="bancard-nmaskednumber">' . esc_html(chunk_split($masked, 4, ' ')) . '</span>';
            echo '<span class="bancard-cardvenc"> ' . esc_html(sprintf(__('Venc. %s', 'woocommerce-bancard'), $expiration)) . '</span>';
            echo '<span class="bancard-cardtype"> ' . esc_html($card_type) . '</span>';
            echo '</label>';
        }
        echo '</div>';

        woocommerce_form_field(
            'bancard_installments',
            array(
                'type'     => 'number',
                'label'    => __('Cuotas', 'woocommerce-bancard'),
                'required' => true,
                'default'  => $this->default_installments,
                'custom_attributes' => array(
                    'min'  => 1,
                    'step' => 1,
                ),
            ),
            $this->default_installments
        );
    }

    public function validate_fields() {
        if (empty($_POST['bancard_card_token'])) {
            wc_add_notice(__('Seleccioná una tarjeta para continuar.', 'woocommerce-bancard'), 'error');
            return false;
        }

        if (empty($_POST['bancard_installments']) || absint(wp_unslash($_POST['bancard_installments'])) < 1) {
            wc_add_notice(__('Ingresá una cantidad de cuotas válida.', 'woocommerce-bancard'), 'error');
            return false;
        }

        return true;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('No se pudo recuperar la orden para iniciar el pago.', 'woocommerce-bancard'), 'error');
            return array('result' => 'failure');
        }

        $alias_token = isset($_POST['bancard_card_token']) ? sanitize_text_field(wp_unslash($_POST['bancard_card_token'])) : '';
        if ($alias_token === '') {
            wc_add_notice(__('No se recibió el alias token de Bancard.', 'woocommerce-bancard'), 'error');
            return array('result' => 'failure');
        }

        $installments = max(1, absint(isset($_POST['bancard_installments']) ? wp_unslash($_POST['bancard_installments']) : $this->default_installments));
        $amount = $this->get_amount($order->get_total());

        $operation = array(
            'token'                    => $this->get_api_client()->generate_hash($this->private_key, $order->get_id(), 'charge', $amount, $order->get_currency(), $alias_token),
            'shop_process_id'          => $order->get_id(),
            'number_of_payments'       => $installments,
            'amount'                   => $amount,
            'currency'                 => $order->get_currency(),
            'description'              => $this->get_order_description($order),
            'alias_token'              => $alias_token,
            'return_url'               => $this->get_order_return_url($order),
            'extra_response_attributes'=> array('confirmation.process_id'),
        );

        $additional_data = $this->get_additional_data($order);
        if ($additional_data !== '') {
            $operation['additional_data'] = $additional_data;
        }

        if ($this->is_preauthorization_enabled()) {
            $operation['preauthorization'] = 'S';
            $order->update_meta_data('_bancard_is_preauthorization', 'yes');
        }

        $billing = $this->build_billing_data($order);
        if ($billing) {
            $operation['billing'] = $billing;
        }

        $order->update_meta_data('_bancard_installments', $installments);
        $order->update_meta_data('_bancard_alias_token', $alias_token);
        $order->save();

        $response = $this->get_api_client()->request_charge($operation);
        if (is_wp_error($response)) {
            wc_add_notice($response->get_error_message(), 'error');
            return array('result' => 'failure');
        }

        if (!empty($response['status']) && $response['status'] === 'error') {
            wc_add_notice($this->get_api_client()->parse_error_message($response), 'error');
            return array('result' => 'failure');
        }

        $operation_response = isset($response['operation']) && is_array($response['operation']) ? $response['operation'] : array();
        if (!empty($operation_response['process_id']) && $this->is_3ds_enabled()) {
            $this->store_process_meta($order, $operation_response['process_id'], 'charge3ds');
            $order->update_status('pending', __('Esperando autenticación Secure3D de Bancard.', 'woocommerce-bancard'));

            return array(
                'result'   => 'success',
                'redirect' => $this->get_payment_page_url($operation_response['process_id'], 'charge3ds', $order),
            );
        }

        if ($this->is_successful_confirmation($operation_response)) {
            if ($order->get_meta('_bancard_is_preauthorization', true) === 'yes') {
                $this->mark_order_as_preauthorized($order, $operation_response, __('Preautorización con token aprobada por Bancard.', 'woocommerce-bancard'));
            } else {
                $this->mark_order_as_paid($order, $operation_response, __('Pago con token aprobado por Bancard.', 'woocommerce-bancard'));
            }

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        wc_add_notice($this->get_confirmation_error($operation_response), 'error');
        return array('result' => 'failure');
    }

    public function add_payment_method() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            wc_print_notice(__('Debés iniciar sesión para registrar una tarjeta.', 'woocommerce-bancard'), 'error');
            return;
        }

        if (!empty($_GET['status'])) {
            $status = sanitize_text_field(wp_unslash($_GET['status']));
            $description = !empty($_GET['description']) ? sanitize_text_field(wp_unslash($_GET['description'])) : '';

            if ($status === 'add_new_card_success') {
                wc_print_notice(__('Tarjeta registrada correctamente en Bancard.', 'woocommerce-bancard'), 'success');
                return;
            }

            if ($status === 'add_new_card_fail') {
                wc_print_notice($description ?: __('Bancard rechazó el catastro de la tarjeta.', 'woocommerce-bancard'), 'error');
                return;
            }
        }

        $cards_response = $this->get_user_cards($user_id);
        if (!is_wp_error($cards_response) && !empty($cards_response['cards']) && count($cards_response['cards']) >= 5) {
            wc_print_notice(__('Bancard permite un máximo de 5 tarjetas por usuario.', 'woocommerce-bancard'), 'error');
            return;
        }

        $card_id = $this->generate_card_id($user_id);
        $operation = array(
            'token'           => $this->get_api_client()->generate_hash($this->private_key, $card_id, $user_id, 'request_new_card'),
            'card_id'         => $card_id,
            'user_id'         => $user_id,
            'user_cell_phone' => get_user_meta($user_id, 'billing_phone', true),
            'user_mail'       => get_user_meta($user_id, 'billing_email', true),
            'return_url'      => wc_get_endpoint_url('add-payment-method'),
        );

        $response = $this->get_api_client()->request_cards_new($operation);
        if (is_wp_error($response) || empty($response['status']) || $response['status'] !== 'success' || empty($response['process_id'])) {
            wc_print_notice($this->get_api_client()->parse_error_message($response, __('No se pudo iniciar el catastro de la tarjeta.', 'woocommerce-bancard')), 'error');
            return;
        }

        $this->save_card_id($user_id, $card_id);
        $script = esc_url($this->get_api_client()->get_base_url() . '/checkout/javascript/dist/bancard-checkout-4.0.0.js');

        echo '<script src="' . $script . '"></script>';
        echo '<div id="bancard-token-form"><p>' . esc_html__('Cargando formulario de registro de tarjeta...', 'woocommerce-bancard') . '</p></div>';
        echo '<style>form#add_payment_method{display:none!important;}</style>';
        echo "<script>window.addEventListener('load',function(){Bancard.Cards.createForm('bancard-token-form','" . esc_js($response['process_id']) . "');});</script>";
    }

    public function get_user_cards($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) {
            return new WP_Error('bancard_missing_user', __('Debés iniciar sesión para ver tus tarjetas.', 'woocommerce-bancard'));
        }

        $operation = array(
            'token' => $this->get_api_client()->generate_hash($this->private_key, $user_id, 'request_user_cards'),
        );

        return $this->get_api_client()->request_user_cards($user_id, $operation);
    }

    public function list_payment_methods() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            wc_print_notice(__('Debés iniciar sesión para gestionar tus tarjetas.', 'woocommerce-bancard'), 'error');
            return;
        }

        if (isset($_GET['bancard_action'], $_GET['card_token'], $_GET['_wpnonce']) && sanitize_text_field(wp_unslash($_GET['bancard_action'])) === 'delete_card') {
            if ($this->delete_bancard_card($user_id, sanitize_text_field(wp_unslash($_GET['card_token'])), sanitize_text_field(wp_unslash($_GET['_wpnonce'])))) {
                wp_safe_redirect(wc_get_endpoint_url('payment-methods'));
                exit;
            }
        }

        $response = $this->get_user_cards($user_id);
        if (is_wp_error($response)) {
            wc_print_notice($response->get_error_message(), 'error');
            return;
        }

        $cards = isset($response['cards']) && is_array($response['cards']) ? $response['cards'] : array();
        if (empty($cards)) {
            echo '<p>' . esc_html__('No tenés métodos de pago guardados.', 'woocommerce-bancard') . '</p>';
            return;
        }

        echo '<table id="Bancard-cards-table" class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Número de tarjeta', 'woocommerce-bancard') . '</th>';
        echo '<th>' . esc_html__('Vencimiento', 'woocommerce-bancard') . '</th>';
        echo '<th>' . esc_html__('Marca', 'woocommerce-bancard') . '</th>';
        echo '<th>' . esc_html__('Tipo', 'woocommerce-bancard') . '</th>';
        echo '<th>' . esc_html__('Acciones', 'woocommerce-bancard') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($cards as $card) {
            $this->sync_woocommerce_token($user_id, $card);

            $delete_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'bancard_action' => 'delete_card',
                        'card_token'     => $card['alias_token'],
                    ),
                    wc_get_endpoint_url('payment-methods')
                ),
                'bancard_delete_card'
            );

            echo '<tr>';
            echo '<td>' . esc_html($card['card_masked_number']) . '</td>';
            echo '<td>' . esc_html($card['expiration_date']) . '</td>';
            echo '<td>' . esc_html($card['card_brand']) . '</td>';
            echo '<td>' . esc_html(isset($card['card_type']) ? $card['card_type'] : '') . '</td>';
            echo '<td><a href="' . esc_url($delete_url) . '" class="button" onclick="return confirm(\'' . esc_js(__('¿Eliminar esta tarjeta?', 'woocommerce-bancard')) . '\');">' . esc_html__('Eliminar', 'woocommerce-bancard') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function delete_bancard_card($user_id, $card_token, $nonce = '') {
        if (!wp_verify_nonce($nonce, 'bancard_delete_card')) {
            wc_add_notice(__('No se pudo validar la solicitud para eliminar la tarjeta.', 'woocommerce-bancard'), 'error');
            return false;
        }

        $response = $this->get_api_client()->delete_card(
            $user_id,
            array(
                'token'       => $this->get_api_client()->generate_hash($this->private_key, 'delete_card', $user_id, $card_token),
                'alias_token' => $card_token,
            )
        );

        if (is_wp_error($response) || empty($response['status']) || $response['status'] !== 'success') {
            wc_add_notice($this->get_api_client()->parse_error_message($response, __('No se pudo eliminar la tarjeta en Bancard.', 'woocommerce-bancard')), 'error');
            return false;
        }

        foreach (WC_Payment_Tokens::get_customer_tokens($user_id, $this->id) as $token) {
            if ($token->get_token() === $card_token) {
                WC_Payment_Tokens::delete($token->get_id());
            }
        }

        wc_add_notice(__('Tarjeta eliminada correctamente.', 'woocommerce-bancard'), 'success');
        return true;
    }

    public function process_subscription_payment($amount, $order) {
        $alias_token = $order->get_meta('_bancard_alias_token', true);
        if ($alias_token === '') {
            $order->update_status('failed', __('No se encontró alias token Bancard para el cobro recurrente.', 'woocommerce-bancard'));
            return false;
        }

        $operation = array(
            'token'                    => $this->get_api_client()->generate_hash($this->private_key, $order->get_id(), 'charge', $this->get_amount($amount), $order->get_currency(), $alias_token),
            'shop_process_id'          => $order->get_id(),
            'number_of_payments'       => 1,
            'amount'                   => $this->get_amount($amount),
            'currency'                 => $order->get_currency(),
            'description'              => $this->get_order_description($order),
            'alias_token'              => $alias_token,
            'return_url'               => $this->get_order_return_url($order),
            'extra_response_attributes'=> array('confirmation.process_id'),
        );

        $response = $this->get_api_client()->request_charge($operation);
        if (is_wp_error($response)) {
            $order->update_status('failed', $response->get_error_message());
            return false;
        }

        $operation_response = isset($response['operation']) && is_array($response['operation']) ? $response['operation'] : array();
        if ($this->is_successful_confirmation($operation_response)) {
            $this->mark_order_as_paid($order, $operation_response, __('Cobro recurrente procesado correctamente por Bancard.', 'woocommerce-bancard'));
            return true;
        }

        $order->update_status('failed', $this->get_confirmation_error($operation_response));
        return false;
    }

    protected function normalize_card_brand($brand) {
        $brand = strtolower($brand);
        if (strpos($brand, 'master') !== false) {
            return 'mastercard';
        }
        if (strpos($brand, 'visa') !== false) {
            return 'visa';
        }
        if (strpos($brand, 'amex') !== false) {
            return 'amex';
        }
        if (strpos($brand, 'diners') !== false) {
            return 'diners';
        }
        return 'bancard';
    }

    protected function generate_card_id($user_id) {
        $cards = get_user_meta($user_id, '_bancard_card_ids', true);
        if (!is_array($cards)) {
            $cards = array();
        }

        return 'card_' . $user_id . '_' . (count($cards) + 1);
    }

    protected function save_card_id($user_id, $card_id) {
        $cards = get_user_meta($user_id, '_bancard_card_ids', true);
        if (!is_array($cards)) {
            $cards = array();
        }

        if (!in_array($card_id, $cards, true)) {
            $cards[] = $card_id;
            update_user_meta($user_id, '_bancard_card_ids', $cards);
        }
    }

    protected function sync_woocommerce_token($user_id, array $card) {
        if (empty($card['alias_token']) || empty($card['card_masked_number']) || empty($card['expiration_date'])) {
            return;
        }

        list($month, $year) = array_pad(explode('/', $card['expiration_date']), 2, '');
        $year = strlen($year) === 2 ? '20' . $year : $year;

        foreach (WC_Payment_Tokens::get_customer_tokens($user_id, $this->id) as $saved_token) {
            if ($saved_token->get_token() === $card['alias_token']) {
                return;
            }
        }

        $token = new WC_Payment_Token_CC();
        $token->set_token($card['alias_token']);
        $token->set_gateway_id($this->id);
        $token->set_last4(substr(preg_replace('/\D+/', '', $card['card_masked_number']), -4));
        $token->set_expiry_month($month);
        $token->set_expiry_year($year);
        $token->set_card_type($this->normalize_card_brand($card['card_brand']));
        $token->set_user_id($user_id);
        $token->save();
    }
}
