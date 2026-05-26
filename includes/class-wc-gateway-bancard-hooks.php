<?php

if (!defined('ABSPATH')) {
    exit;
}

function wc_bancard_get_gateway_instance($gateway_id) {
    switch ($gateway_id) {
        case 'bancard_tokens':
            return new WC_Gateway_Bancard_Tokens();
        case 'bancard_zimple':
            return new WC_Gateway_Bancard_Zimple();
        case 'bancard':
        default:
            return new WC_Gateway_Bancard();
    }
}

function wc_bancard_get_order_gateway_instance(WC_Order $order) {
    return wc_bancard_get_gateway_instance($order->get_payment_method());
}

function bancard_custom_order_button_text($button_text) {
    $method = WC()->session ? WC()->session->get('chosen_payment_method') : '';
    if (in_array($method, array('bancard', 'bancard_zimple', 'bancard_tokens'), true)) {
        return __('Ir al pago', 'woocommerce-bancard');
    }

    return $button_text;
}
add_filter('woocommerce_order_button_text', 'bancard_custom_order_button_text');

function bancard_add_payment_methods_link($menu_links) {
    if (isset($menu_links['payment-methods'])) {
        $menu_links['payment-methods'] = __('Mis tarjetas', 'woocommerce-bancard');
        return $menu_links;
    }

    return array_slice($menu_links, 0, 5, true)
        + array('payment-methods' => __('Mis tarjetas', 'woocommerce-bancard'))
        + array_slice($menu_links, 5, null, true);
}
add_filter('woocommerce_account_menu_items', 'bancard_add_payment_methods_link');

function bancard_payment_methods_content() {
    $tokens = new WC_Gateway_Bancard_Tokens();
    $tokens->list_payment_methods();
}
add_action('woocommerce_account_payment-methods_endpoint', 'bancard_payment_methods_content');


function bancard_order_actions($actions) {
    global $theorder;

    if (!$theorder instanceof WC_Order || !in_array($theorder->get_payment_method(), array('bancard', 'bancard_tokens', 'bancard_zimple'), true)) {
        return $actions;
    }

    $actions['bancard_confirm_transaction'] = __('Consultar confirmación Bancard', 'woocommerce-bancard');

    if ($theorder->get_meta('_bancard_is_preauthorization', true) === 'yes') {
        $actions['bancard_confirm_preauthorization'] = __('Confirmar preautorización Bancard', 'woocommerce-bancard');
    }

    if ($theorder->get_meta('_bancard_billing_response', true)) {
        $actions['bancard_cancel_invoice'] = __('Cancelar factura electrónica Bancard', 'woocommerce-bancard');
    }

    return $actions;
}
add_filter('woocommerce_order_actions', 'bancard_order_actions');

function process_bancard_confirm_transaction($order) {
    $gateway = wc_bancard_get_order_gateway_instance($order);
    $result = method_exists($gateway, 'confirm_transaction') ? $gateway->confirm_transaction($order->get_id()) : new WP_Error('bancard_invalid_gateway', __('El gateway no soporta consulta de confirmación.', 'woocommerce-bancard'));

    add_action(
        'admin_notices',
        static function () use ($result) {
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                return;
            }

            echo '<div class="notice notice-success"><p>' . esc_html__('Transacción consultada correctamente en Bancard.', 'woocommerce-bancard') . '</p></div>';
        }
    );
}
add_action('woocommerce_order_action_bancard_confirm_transaction', 'process_bancard_confirm_transaction');

function process_bancard_confirm_preauthorization($order) {
    $gateway = wc_bancard_get_order_gateway_instance($order);
    $result = method_exists($gateway, 'confirm_preauthorization') ? $gateway->confirm_preauthorization($order->get_id()) : new WP_Error('bancard_invalid_gateway', __('El gateway no soporta confirmación de preautorización.', 'woocommerce-bancard'));

    add_action(
        'admin_notices',
        static function () use ($result) {
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                return;
            }

            echo '<div class="notice notice-success"><p>' . esc_html__('Preautorización confirmada correctamente en Bancard.', 'woocommerce-bancard') . '</p></div>';
        }
    );
}
add_action('woocommerce_order_action_bancard_confirm_preauthorization', 'process_bancard_confirm_preauthorization');

function process_bancard_cancel_invoice($order) {
    $gateway = wc_bancard_get_order_gateway_instance($order);
    $result = method_exists($gateway, 'cancel_generated_invoice') ? $gateway->cancel_generated_invoice($order->get_id()) : new WP_Error('bancard_invalid_gateway', __('El gateway no soporta cancelación de factura.', 'woocommerce-bancard'));
    $message = is_wp_error($result) ? $result->get_error_message() : $gateway->get_api_client()->parse_error_message($result, __('Operación procesada.', 'woocommerce-bancard'));

    add_action(
        'admin_notices',
        static function () use ($result, $message) {
            $class = is_wp_error($result) ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
        }
    );
}
add_action('woocommerce_order_action_bancard_cancel_invoice', 'process_bancard_cancel_invoice');

function wc_bancard_register_order_metabox() {
    $screens = array('shop_order');
    if (function_exists('wc_get_page_screen_id')) {
        $screens[] = wc_get_page_screen_id('shop-order');
    }

    add_meta_box(
        'wc-bancard-order-details',
        __('Bancard', 'woocommerce-bancard'),
        'wc_bancard_render_order_metabox',
        array_unique($screens),
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'wc_bancard_register_order_metabox');

function wc_bancard_render_order_metabox($post) {
    $order = $post instanceof WC_Order ? $post : wc_get_order(isset($post->ID) ? $post->ID : 0);
    if (!$order || !in_array($order->get_payment_method(), array('bancard', 'bancard_tokens', 'bancard_zimple'), true)) {
        echo '<p>' . esc_html__('Este pedido no fue pagado con Bancard.', 'woocommerce-bancard') . '</p>';
        return;
    }

    $fields = array(
        __('Gateway', 'woocommerce-bancard')       => $order->get_payment_method_title(),
        __('Process ID', 'woocommerce-bancard')    => $order->get_meta('_bancard_process_id', true),
        __('Modo', 'woocommerce-bancard')          => $order->get_meta('_bancard_process_mode', true),
        __('Autorizacion', 'woocommerce-bancard') => $order->get_meta('_bancard_authorization_number', true),
        __('Ticket', 'woocommerce-bancard')        => $order->get_meta('_bancard_ticket_number', true),
        __('Respuesta', 'woocommerce-bancard')     => $order->get_meta('_bancard_response_code', true),
        __('Tipo tarjeta', 'woocommerce-bancard')  => wc_bancard_format_card_type($order->get_meta('_bancard_payment_card_type', true)),
        __('Cuotas', 'woocommerce-bancard')        => $order->get_meta('_bancard_installments', true),
        __('Riesgo', 'woocommerce-bancard')        => $order->get_meta('_bancard_risk_index', true),
        __('Pais tarjeta', 'woocommerce-bancard') => $order->get_meta('_bancard_card_country', true),
        __('IP cliente', 'woocommerce-bancard')    => $order->get_meta('_bancard_customer_ip', true),
        __('3DS requerido', 'woocommerce-bancard') => $order->get_meta('_bancard_requires_customer_3ds', true) === 'yes' ? __('Si', 'woocommerce-bancard') : '',
    );

    echo '<table class="widefat striped"><tbody>';
    foreach ($fields as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        echo '<tr><th style="width:42%; padding:6px;">' . esc_html($label) . '</th><td style="padding:6px;">' . esc_html($value) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<p style="margin-top:10px;">';
    echo '<button type="button" class="button" onclick="(function(){var select=document.querySelector(\'select[name=wc_order_action]\'); var button=document.querySelector(\'button[name=save]\'); if(select){select.value=\'bancard_confirm_transaction\';} if(button){button.click();}})();">' . esc_html__('Consultar confirmacion', 'woocommerce-bancard') . '</button>';
    echo '</p>';
}

function wc_bancard_format_card_type($type) {
    $type = strtolower((string) $type);
    if ($type === 'credit') {
        return __('Credito', 'woocommerce-bancard');
    }

    if ($type === 'debit') {
        return __('Debito', 'woocommerce-bancard');
    }

    return $type;
}

function wc_bancard_admin_health_notices() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_id = $screen ? $screen->id : '';
    if ($screen_id && strpos($screen_id, 'woocommerce') === false && $screen_id !== 'plugins') {
        return;
    }

    $settings = get_option('woocommerce_bancard_settings', array());
    if (empty($settings['public_key']) || empty($settings['private_key'])) {
        echo '<div class="notice notice-warning"><p>' . esc_html__('Bancard: faltan configurar la llave publica o privada.', 'woocommerce-bancard') . '</p></div>';
    }

    if (!get_page_by_path('bancard-payment')) {
        echo '<div class="notice notice-warning"><p>' . esc_html__('Bancard: no existe la pagina bancard-payment para el iframe de pago.', 'woocommerce-bancard') . '</p></div>';
    }

    if (!empty($settings['environment']) && $settings['environment'] === 'staging') {
        echo '<div class="notice notice-info"><p>' . esc_html__('Bancard: el gateway principal esta usando ambiente de pruebas.', 'woocommerce-bancard') . '</p></div>';
    }

    if (!class_exists('WC_Subscriptions') && !class_exists('WC_Subscriptions_Order')) {
        echo '<div class="notice notice-info"><p>' . esc_html__('Bancard: WooCommerce Subscriptions no esta activo; los cobros recurrentes no se ejecutaran automaticamente.', 'woocommerce-bancard') . '</p></div>';
    }

    echo '<div class="notice notice-info"><p>' . wp_kses_post(sprintf(__('Bancard: confirme en el portal de comercios que la URL de confirmacion sea <code>%s</code>.', 'woocommerce-bancard'), esc_html(WC()->api_request_url('WC_Gateway_Bancard')))) . '</p></div>';
}
add_action('admin_notices', 'wc_bancard_admin_health_notices');

add_action(
    'admin_init',
    static function () {
        $repo_url = 'https://api.github.com/repos/marioungui/woocommerce-bancard/releases/latest';
        $transient_name = 'woocommerce_bancard_latest_release';
        $latest_release = get_transient($transient_name);

        if (!$latest_release) {
            $response = wp_remote_get(
                $repo_url,
                array(
                    'headers' => array(
                        'Accept'     => 'application/vnd.github+json',
                        'User-Agent' => 'woocommerce-bancard-plugin',
                    ),
                    'timeout' => 15,
                )
            );

            if (is_wp_error($response)) {
                return;
            }

            $latest_release = json_decode(wp_remote_retrieve_body($response));
            if ($latest_release) {
                set_transient($transient_name, $latest_release, DAY_IN_SECONDS);
            }
        }

        if (!empty($latest_release->tag_name) && version_compare($latest_release->tag_name, WC_BANCARD_VERSION, '>')) {
            add_action(
                'admin_notices',
                static function () use ($latest_release) {
                    $message = sprintf(
                        __('Hay una nueva versión de WooCommerce Bancard disponible. <a href="%s" target="_blank" rel="noopener noreferrer">Descargar</a>.', 'woocommerce-bancard'),
                        esc_url($latest_release->zipball_url)
                    );
                    echo '<div class="notice notice-info"><p>' . wp_kses_post($message) . '</p></div>';
                }
            );
        }
    }
);
