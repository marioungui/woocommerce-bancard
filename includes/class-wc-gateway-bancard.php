<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Bancard extends WC_Gateway_Bancard_Base {
    public function __construct() {
        $this->boot_gateway(
            array(
                'id'                 => 'bancard',
                'icon'               => plugins_url('assets/images/bancard.png', __DIR__),
                'has_fields'         => false,
                'method_title'       => 'Bancard',
                'method_description' => __('Pagos embebidos con Bancard.', 'woocommerce-bancard'),
                'supports'           => array('products', 'refunds'),
            )
        );

        add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_response'));
        add_action('admin_init', array($this, 'check_bancard_payment_page'));
        add_filter('template_include', array($this, 'bancard_payment_template'));
        add_shortcode('pago_bancard', array($this, 'bancard_payment_shortcode'));
        add_action('woocommerce_thankyou', array($this, 'display_bancard_transaction_details'), 20);
        add_action('woocommerce_order_details_before_order_table_items', array($this, 'display_bancard_transaction_details'), 20);
    }

    public function init_form_fields() {
        $this->form_fields = $this->get_common_form_fields(
            array(
                'title' => array(
                    'title'       => __('Título', 'woocommerce-bancard'),
                    'type'        => 'text',
                    'description' => __('Texto visible para el cliente durante el checkout.', 'woocommerce-bancard'),
                    'default'     => 'Tarjeta de crédito/débito (Bancard)',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Descripción', 'woocommerce-bancard'),
                    'type'        => 'textarea',
                    'description' => __('Descripción visible en el checkout.', 'woocommerce-bancard'),
                    'default'     => __('Pagá con tu tarjeta vía Bancard.', 'woocommerce-bancard'),
                ),
            )
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('No se pudo recuperar la orden para iniciar el pago.', 'woocommerce-bancard'), 'error');
            return array('result' => 'failure');
        }

        $amount = $this->get_amount($order->get_total());
        $operation = array(
            'token'                    => $this->get_api_client()->generate_hash($this->private_key, $order->get_id(), $amount, $order->get_currency()),
            'shop_process_id'          => $order->get_id(),
            'amount'                   => $amount,
            'currency'                 => $order->get_currency(),
            'description'              => $this->get_order_description($order),
            'return_url'               => $this->get_order_return_url($order),
            'cancel_url'               => $this->get_order_cancel_url(),
            'extra_response_attributes'=> array('payment_card_type'),
        );

        $additional_data = $this->get_additional_data($order);
        if ($additional_data !== '') {
            $operation['additional_data'] = $additional_data;
        }

        if ($this->is_preauthorization_enabled()) {
            $operation['preauthorization'] = 'S';
            $order->update_meta_data('_bancard_is_preauthorization', 'yes');
            $order->save();
        }

        $billing = $this->build_billing_data($order);
        if ($billing) {
            $operation['billing'] = $billing;
        }

        $response = $this->get_api_client()->request_single_buy($operation);
        if (is_wp_error($response) || empty($response['status']) || $response['status'] !== 'success' || empty($response['process_id'])) {
            $this->maybe_add_notice_from_response($response, __('No se pudo crear el pedido de pago en Bancard.', 'woocommerce-bancard'));
            return array('result' => 'failure');
        }

        $this->store_process_meta($order, $response['process_id'], 'checkout');
        $order->update_status('pending', __('Esperando confirmación de pago desde Bancard.', 'woocommerce-bancard'));

        $redirect = $this->get_payment_page_url($response['process_id'], 'checkout', $order);
        if ($redirect === '') {
            wc_add_notice(__('No se encontró la página de pago embebida de Bancard.', 'woocommerce-bancard'), 'error');
            return array('result' => 'failure');
        }

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
    }

    public function check_response() {
        $payload = json_decode(file_get_contents('php://input'), true);
        $operation = isset($payload['operation']) && is_array($payload['operation']) ? $payload['operation'] : array();

        if (empty($operation['shop_process_id'])) {
            status_header(200);
            wp_send_json(array('status' => 'ignored'));
        }

        $order = wc_get_order((int) $operation['shop_process_id']);
        if (!$order) {
            status_header(200);
            wp_send_json(array('status' => 'order_not_found'));
        }

        $this->persist_confirmation_meta($order, $operation);

        if ($this->is_successful_confirmation($operation)) {
            if ($order->get_meta('_bancard_is_preauthorization', true) === 'yes') {
                $this->mark_order_as_preauthorized($order, $operation, __('Preautorización aprobada por Bancard. Pendiente de confirmación final.', 'woocommerce-bancard'));
            } else {
                $this->mark_order_as_paid($order, $operation, sprintf(__('Pago confirmado por Bancard. Autorización %1$s, ticket %2$s.', 'woocommerce-bancard'), $operation['authorization_number'], $operation['ticket_number']));
            }
        } else {
            $order->update_status('failed', $this->get_confirmation_error($operation));
        }

        status_header(200);
        wp_send_json(array('status' => 'success'));
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('bancard_refund_order_missing', __('No se pudo recuperar la orden a revertir.', 'woocommerce-bancard'));
        }

        $order_total = (float) $order->get_total();
        $requested_amount = $amount !== null ? (float) $amount : $order_total;

        if (abs($requested_amount - $order_total) > 0.0001) {
            return new WP_Error('bancard_refund_error', __('Bancard solo permite reversa total desde la API de este plugin.', 'woocommerce-bancard'));
        }

        $order_date = $order->get_date_created();
        if ($order_date && $order_date->format('Y-m-d') !== wp_date('Y-m-d')) {
            return new WP_Error('bancard_refund_error', __('La reversa por API solo está disponible el mismo día. Luego debe gestionarse desde el portal de comercios.', 'woocommerce-bancard'));
        }

        $operation = array(
            'token'           => $this->get_api_client()->generate_hash($this->private_key, $order->get_id(), 'rollback', '0.00'),
            'shop_process_id' => $order->get_id(),
        );

        $response = $this->get_api_client()->request_rollback($operation);
        if (is_wp_error($response) || empty($response['status']) || $response['status'] !== 'success') {
            return new WP_Error('bancard_refund_error', $this->get_api_client()->parse_error_message($response, __('La reversa fue rechazada por Bancard.', 'woocommerce-bancard')));
        }

        $order->add_order_note(__('Reversa solicitada correctamente a Bancard.', 'woocommerce-bancard'));
        return true;
    }

    public function confirm_transaction($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('bancard_confirmation_missing_order', __('No se encontró la orden a confirmar.', 'woocommerce-bancard'));
        }

        $operation = array(
            'token'           => $this->get_api_client()->generate_hash($this->private_key, $order->get_id(), 'get_confirmation'),
            'shop_process_id' => $order->get_id(),
        );

        $response = $this->get_api_client()->request_get_confirmation($operation);
        if (is_wp_error($response) || empty($response['status'])) {
            return new WP_Error('bancard_confirmation_error', $this->get_api_client()->parse_error_message($response, __('No fue posible consultar la confirmación en Bancard.', 'woocommerce-bancard')));
        }

        if ($response['status'] !== 'success' || empty($response['confirmation']) || !is_array($response['confirmation'])) {
            return new WP_Error('bancard_confirmation_error', $this->get_api_client()->parse_error_message($response, __('Bancard no devolvió una confirmación válida.', 'woocommerce-bancard')));
        }

        $confirmation = $response['confirmation'];
        if ($this->is_successful_confirmation($confirmation)) {
            if ($order->get_meta('_bancard_is_preauthorization', true) === 'yes') {
                $this->mark_order_as_preauthorized($order, $confirmation, __('Preautorización consultada y aprobada por Bancard.', 'woocommerce-bancard'));
            } else {
                $this->mark_order_as_paid($order, $confirmation, __('Transacción confirmada manualmente con Bancard.', 'woocommerce-bancard'));
            }

            return true;
        }

        $message = $this->get_confirmation_error($confirmation);
        $order->update_status('failed', $message);
        return new WP_Error('bancard_confirmation_error', $message);
    }

    public function confirm_preauthorization($order_id, $amount = null) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('bancard_preauth_missing_order', __('No se encontró la orden de preautorización.', 'woocommerce-bancard'));
        }

        $operation = array(
            'token'           => $this->get_api_client()->generate_hash($this->private_key, $order->get_id(), 'pre-authorization-confirm'),
            'shop_process_id' => $order->get_id(),
        );

        if ($amount !== null) {
            $operation['amount'] = $this->get_amount($amount);
        }

        $billing = $this->build_billing_data($order, true);
        if ($billing) {
            $operation['billing'] = $billing;
        }

        $response = $this->get_api_client()->request_preauthorization_confirm($operation);
        if (is_wp_error($response) || empty($response['status']) || $response['status'] !== 'success' || empty($response['confirmation'])) {
            return new WP_Error('bancard_preauth_error', $this->get_api_client()->parse_error_message($response, __('La confirmación de preautorización falló.', 'woocommerce-bancard')));
        }

        $confirmation = $response['confirmation'];
        if (!$this->is_successful_confirmation($confirmation)) {
            $message = $this->get_confirmation_error($confirmation);
            $order->add_order_note(__('Bancard rechazó la confirmación de la preautorización: ', 'woocommerce-bancard') . $message);
            return new WP_Error('bancard_preauth_error', $message);
        }

        $order->update_meta_data('_bancard_is_preauthorization', 'confirmed');
        $order->save();
        $this->mark_order_as_paid($order, $confirmation, __('Preautorización confirmada correctamente en Bancard.', 'woocommerce-bancard'));

        return true;
    }

    public function get_client_info_by_ruc($client_ruc) {
        $operation = array(
            'token'      => $this->get_api_client()->generate_hash($this->private_key, 'billing_client_info'),
            'client_ruc' => $client_ruc,
        );

        return $this->get_api_client()->request_billing_client_info($operation);
    }

    public function cancel_generated_invoice($order_id) {
        $operation = array(
            'token'           => $this->get_api_client()->generate_hash($this->private_key, $order_id, 'billing_cancel'),
            'shop_process_id' => $order_id,
        );

        return $this->get_api_client()->request_billing_cancel($operation);
    }

    public function check_bancard_payment_page() {
        if (!get_page_by_path('bancard-payment')) {
            add_action('admin_notices', array($this, 'bancard_payment_page_missing_notice'));
        }
    }

    public function bancard_payment_page_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php echo esc_html__('La página "bancard-payment" no existe. El plugin la necesita para renderizar los iframes de Bancard.', 'woocommerce-bancard'); ?></p>
        </div>
        <?php
    }

    public function bancard_payment_template($template) {
        if (is_page('bancard-payment')) {
            $plugin_template = plugin_dir_path(__FILE__) . '../templates/bancard-payment-page.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }

        return $template;
    }

    public function bancard_payment_shortcode($atts) {
        ob_start();
        include plugin_dir_path(__FILE__) . '../templates/bancard-payment-page.php';
        return ob_get_clean();
    }

    public function display_bancard_transaction_details($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $authorization_number = $order->get_meta('_bancard_authorization_number', true);
        $ticket_number = $order->get_meta('_bancard_ticket_number', true);
        $response_code = $order->get_meta('_bancard_response_code', true);
        $card_type = $order->get_meta('_bancard_payment_card_type', true);

        if (!$authorization_number && !$ticket_number) {
            return;
        }

        echo '<section class="woocommerce-bancard-transaction">';
        echo '<h2>' . esc_html__('Detalles de la transacción Bancard', 'woocommerce-bancard') . '</h2>';

        if ($authorization_number) {
            echo '<p><strong>' . esc_html__('Autorización VPOS:', 'woocommerce-bancard') . '</strong> ' . esc_html($authorization_number) . '</p>';
        }

        if ($ticket_number) {
            echo '<p><strong>' . esc_html__('Ticket VPOS:', 'woocommerce-bancard') . '</strong> ' . esc_html($ticket_number) . '</p>';
        }

        if ($response_code) {
            echo '<p><strong>' . esc_html__('Código de respuesta:', 'woocommerce-bancard') . '</strong> ' . esc_html($response_code) . '</p>';
        }

        if ($card_type) {
            echo '<p><strong>' . esc_html__('Tipo de tarjeta:', 'woocommerce-bancard') . '</strong> ' . esc_html($card_type) . '</p>';
        }

        echo '</section>';
    }
}
