<?php

class WC_Gateway_Bancard_Zimple extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'bancard_zimple';
        $this->icon = ''; // Aquí puedes añadir la URL del icono para Zimple
        $this->has_fields = false;
        $this->method_title = 'Bancard Zimple';
        $this->method_description = 'Pasarela de pagos Zimple para WooCommerce.';

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->public_key = $this->get_option('public_key');
        $this->private_key = $this->get_option('private_key');
        $this->environment = $this->get_option('environment');

        // Actions
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Shortcode para la vista de pago
        add_shortcode('pago_bancard_zimple', array($this, 'bancard_zimple_payment_shortcode'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Habilitar la pasarela de pagos Zimple',
                'default' => 'yes'
            ),
            'title' => array(
                'title' => 'Título',
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.'),
                'default' => 'Billetera Zimple',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Descripción',
                'type' => 'textarea',
                'description' => 'Este campo controla la descripción que ve el usuario en la página de compras',
                'default' => 'Pagos con Billetera Zimple',
            ),
            'public_key' => array(
                'title' => 'Llave pública',
                'type' => 'text'
            ),
            'private_key' => array(
                'title' => 'Llave privada',
                'type' => 'password'
            ),
            'environment' => array(
                'title' => 'Servidor de pagos:',
                'type' => 'select',
                'options' => array(
                    'production' => 'Producción',
                    'staging' => 'Pruebas'
                ),
                'default' => 'staging'
            )
        );
    }

    function display_bancard_zimple_transaction_details($order_id) {
        // Obtener los datos de la orden
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $process_id = get_post_meta($order_id, '_bancard_process_id', true);

        if (!$process_id) {
            $result = $this->create_payment($order);
            if ($result['status'] === 'success') {
                $process_id = $result['process_id'];
                update_post_meta($order_id, '_bancard_process_id', $process_id);
            } else {
                wc_add_notice('Error en la respuesta de Bancard: ' . $result['messages'][0]['dsc'], 'error');
                wp_delete_post($order_id, true);
                return array('result' => 'failure');
            }
        }

        $payment_page = get_page_by_path('bancard-payment');
        if ($payment_page) {
            $payment_page_url = add_query_arg(array(
                'process_id' => $process_id,
                'zimple' => 'true'
            ), get_permalink($payment_page->ID));
            return array(
                'result'   => 'success',
                'redirect' => $payment_page_url,
            );    
        } else {
            wc_add_notice('No se encontró la página de pago de Bancard.', 'error');
            return array('result' => 'failure');
        }
    }

    private function create_payment($order) {
        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
        $url = $endpoint . '/vpos/api/0.3/single_buy';
    
        $shop_process_id = $order->get_id();
        $amount = number_format($order->get_total(), 2, '.', '');
        $currency = 'PYG';
    
        // Obtener el teléfono del cliente
        $billing_phone = $order->get_billing_phone();
    
        if (!$billing_phone) {
            // Fallback si el número de teléfono no está disponible en la orden
            $user_id = $order->get_user_id();
            if ($user_id) {
                $billing_phone = get_user_meta($user_id, 'billing_phone', true);
            }
        }
    
        if (!$billing_phone) {
            // Si no hay un número de teléfono, lanzar un error en el frontend
            wc_add_notice(__('Por favor, ingrese un número de teléfono válido para completar el pago.', 'woocommerce'), 'error');
            return array('result' => 'failure');
        }
    
        $token = md5($this->private_key . $shop_process_id . $amount . $currency);
    
        $body = json_encode(array(
            'public_key' => $this->public_key,
            'operation' => array(
                'token' => $token,
                'shop_process_id' => $shop_process_id,
                'amount' => $amount,
                'currency' => $currency,
                'additional_data' => $billing_phone, // Aquí va el número de teléfono del usuario
                'description' => 'Order ' . $shop_process_id,
                'return_url' => $this->get_return_url($order),
                'cancel_url' => wc_get_cart_url(),
                'zimple' => 'S' // Activar Zimple en el iframe
            )
        ));
    
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'body' => $body,
            'headers' => array('Content-Type' => 'application/json')
        ));
    
        if (is_wp_error($response)) {
            return array('status' => 'fail', 'message' => $response->get_error_message());
        }
    
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
    
        if ($response_body['status'] == 'success') {
            return array('status' => 'success', 'process_id' => $response_body['process_id']);
        } else {
            return array('status' => 'fail', 'message' => $response_body['message']);
        }
    }

    public function check_response() {
        $response = json_decode(file_get_contents('php://input'), true);
    
        if (isset($response['operation']) && isset($response['operation']['shop_process_id'])) {
            $order_id = $response['operation']['shop_process_id'];
            $order = wc_get_order($order_id);
    
            if ($response['operation']['response'] == 'S') {
                // Guardar los datos de autorización y ticket en los metadatos de la orden
                update_post_meta($order_id, '_bancard_authorization_number', $response['operation']['authorization_number']);
                update_post_meta($order_id, '_bancard_ticket_number', $response['operation']['ticket_number']);
    
                $order->payment_complete();
                $order->add_order_note(
                    sprintf('Payment confirmed via Bancard. Authorization Number: %s, Ticket Number: %s', 
                    $response['operation']['authorization_number'], 
                    $response['operation']['ticket_number'])
                );
                wc_reduce_stock_levels($order_id);
                exit(json_encode(['status' => 'success']));
            } else {
                $order->update_status('failed', 'Payment failed: ' . $response['message']);
            }
        }
    
        http_response_code(400);
        exit(json_encode(['status' => 'payment_fail']));
    }
    
    // El resto de métodos, como check_response, bancard_zimple_payment_template, etc., serían similares a los ya implementados para el gateway de Bancard regular.

}
?>
