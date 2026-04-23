<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Bancard_Zimple extends WC_Gateway_Bancard_Base {
    public function __construct() {
        $this->boot_gateway(
            array(
                'id'                 => 'bancard_zimple',
                'icon'               => plugins_url('assets/images/zimple.png', __DIR__),
                'has_fields'         => false,
                'method_title'       => __('Billetera Zimple', 'woocommerce-bancard'),
                'method_description' => __('Pagos embebidos con Zimple a través de Bancard.', 'woocommerce-bancard'),
                'supports'           => array('products'),
            )
        );

        add_shortcode('pago_bancard_zimple', array($this, 'bancard_zimple_payment_shortcode'));
    }

    public function init_form_fields() {
        $this->form_fields = $this->get_common_form_fields(
            array(
                'title' => array(
                    'title'       => __('Título', 'woocommerce-bancard'),
                    'type'        => 'text',
                    'description' => __('Texto visible para el cliente durante el checkout.', 'woocommerce-bancard'),
                    'default'     => __('Billetera Zimple', 'woocommerce-bancard'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Descripción', 'woocommerce-bancard'),
                    'type'        => 'textarea',
                    'description' => __('Descripción visible en el checkout.', 'woocommerce-bancard'),
                    'default'     => __('Pagá con tu billetera Zimple.', 'woocommerce-bancard'),
                ),
                'enable_3ds' => array(
                    'title'       => __('Secure3D', 'woocommerce-bancard'),
                    'type'        => 'checkbox',
                    'label'       => __('Sin efecto para Zimple', 'woocommerce-bancard'),
                    'default'     => 'no',
                    'description' => __('El flujo 3DS documentado aplica al charge con token.', 'woocommerce-bancard'),
                ),
                'default_installments' => array(
                    'title'       => __('Cuotas por defecto', 'woocommerce-bancard'),
                    'type'        => 'number',
                    'description' => __('No aplica para Zimple.', 'woocommerce-bancard'),
                    'default'     => 1,
                    'custom_attributes' => array(
                        'min'  => 1,
                        'step' => 1,
                    ),
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

        $billing_phone = preg_replace('/\s+/', '', $order->get_billing_phone());
        if ($billing_phone === '') {
            wc_add_notice(__('Zimple requiere un teléfono celular válido en la facturación del pedido.', 'woocommerce-bancard'), 'error');
            return array('result' => 'failure');
        }

        $amount = $this->get_amount($order->get_total());
        $operation = array(
            'token'           => $this->get_api_client()->generate_hash($this->private_key, $order->get_id(), $amount, $order->get_currency()),
            'shop_process_id' => $order->get_id(),
            'amount'          => $amount,
            'currency'        => $order->get_currency(),
            'additional_data' => mb_substr($billing_phone, 0, 255),
            'description'     => $this->get_order_description($order),
            'return_url'      => $this->get_order_return_url($order),
            'cancel_url'      => $this->get_order_cancel_url(),
            'zimple'          => 'S',
        );

        $billing = $this->build_billing_data($order);
        if ($billing) {
            $operation['billing'] = $billing;
        }

        $response = $this->get_api_client()->request_single_buy($operation);
        if (is_wp_error($response) || empty($response['status']) || $response['status'] !== 'success' || empty($response['process_id'])) {
            $this->maybe_add_notice_from_response($response, __('No se pudo crear el pedido de pago Zimple.', 'woocommerce-bancard'));
            return array('result' => 'failure');
        }

        $this->store_process_meta($order, $response['process_id'], 'zimple');
        $order->update_status('pending', __('Esperando confirmación de pago desde Zimple/Bancard.', 'woocommerce-bancard'));

        return array(
            'result'   => 'success',
            'redirect' => $this->get_payment_page_url($response['process_id'], 'zimple', $order),
        );
    }

    public function bancard_zimple_payment_shortcode($atts) {
        ob_start();
        include plugin_dir_path(__FILE__) . '../templates/bancard-payment-page.php';
        return ob_get_clean();
    }
}
