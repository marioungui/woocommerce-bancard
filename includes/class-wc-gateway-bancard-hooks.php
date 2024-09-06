<?php

/**
 * Elimina las columnas predeterminadas de la tabla de métodos de pago
 * en la sección de cuenta del usuario.
 *
 * @param array $columns Un array con las columnas predeterminadas.
 *
 * @return array Un array vacío.
 */
function personalizar_metodos_de_pago( $columns ) {
    // Devuelve un array vacío para eliminar las columnas predeterminadas
    return [];
}
add_filter( 'woocommerce_account_payment_methods_columns', 'personalizar_metodos_de_pago', 10, 1 );

/**
 * Modifica el texto del botón de pago en el carrito y en la página de pago
 * si el método de pago seleccionado es Bancard.
 *
 * @param string $button_text Texto del botón de pago.
 * @return string Nuevo texto del botón de pago.
 */
function bancard_custom_order_button_text($button_text) {
    // Cambia el texto si Bancard está seleccionado
    if (WC()->session->get('chosen_payment_method') == 'bancard') {
        return __('Ir al pago', 'woocommerce');
    }
    return $button_text;
}
add_filter('woocommerce_order_button_text', 'bancard_custom_order_button_text');

/**
 * Agrega un enlace a la página de métodos de pago en el menú de la cuenta del usuario.
 *
 * @param array $menu_links Enlaces del menú de la cuenta del usuario.
 * @return array Enlaces del menú de la cuenta del usuario con el enlace a la página de métodos de pago agregado.
 */
function bancard_add_payment_methods_link($menu_links) {
    $menu_links = array_slice($menu_links, 0, 5, true) 
    + array('payment-methods' => 'Mis tarjetas') 
    + array_slice($menu_links, 5, NULL, true);
    
    return $menu_links;
}
add_filter('woocommerce_account_menu_items', 'bancard_add_payment_methods_link');

/**
 * Contenido de la página de métodos de pago.
 *
 * Muestra la lista de tarjetas guardadas del usuario y un enlace para agregar un nuevo método de pago.
 */
function bancard_payment_methods_content() {
    $tokens = new WC_Gateway_Bancard_Tokens();
    $tokens->list_payment_methods();
}
add_action('woocommerce_account_payment-methods_endpoint', 'bancard_payment_methods_content');

/**
 * Agrega el endpoint para agregar un método de pago
 *
 * Reescribe la URL para que el endpoint se llame "add-payment-method"
 * y esté disponible en la página de cuenta del usuario
 *
 */
function bancard_add_add_payment_method_endpoint() {
    add_rewrite_endpoint('add-payment-method', EP_PAGES);
}
add_action('init', 'bancard_add_add_payment_method_endpoint');

/**
 * Contenido de la página para agregar un método de pago
 *
 * Muestra el formulario para agregar un método de pago
 */
function bancard_add_payment_method_content() {
    $tokens = new WC_Gateway_Bancard_Tokens();
    $tokens->add_payment_method();
}
add_action('woocommerce_account_add-payment-method_endpoint', 'bancard_add_payment_method_content');

/**
 * Agrega una acción al menú de acciones de la orden para confirmar manualmente
 * una transacción con Bancard.
 *
 * @param array $actions Arreglo de acciones de la orden.
 * @return array Arreglo de acciones de la orden con la acción para confirmar
 *               manualmente una transacción con Bancard.
 */
function add_bancard_confirm_transaction_action($actions) {
    $actions['bancard_confirm_transaction'] = __('Confirmar transacción con Bancard', 'woocommerce');
    return $actions;
}
add_action('woocommerce_order_actions', 'add_bancard_confirm_transaction_action');

/**
 * Procesa la acción de confirmar una transacción de pago en la administración de pedidos
 *
 * Llama al método confirm_transaction de la clase WC_Gateway_Bancard y agrega un mensaje de error
 * en la administración de pedidos si falla la confirmación. Si la confirmación es exitosa, agrega
 * una nota al pedido.
 *
 * @param WC_Order $order El pedido a confirmar.
 */
function process_bancard_confirm_transaction($order) {
    // Obtener el ID del pedido
    $order_id = $order->get_id();

    // Llama al método para confirmar la transacción
    $gateway = new WC_Gateway_Bancard();
    $result = $gateway->confirm_transaction($order_id);

    if (is_wp_error($result)) {
        // Mostrar mensaje de error en el backend
        add_action('admin_notices', function() use ($result) {
            echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        });
    } else {
        // Mostrar mensaje de éxito en el backend
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>' . __('Transacción confirmada exitosamente.', 'woocommerce') . '</p></div>';
        });
    }
}
add_action('woocommerce_order_action_bancard_confirm_transaction', 'process_bancard_confirm_transaction');

// Check for updates from Github
add_action('admin_init', function () {
    $plugin_data = get_plugin_data(__FILE__);
    $plugin_version = $plugin_data['Version'];
    $repo_url = 'https://api.github.com/repos/marioungui/woocommerce-bancard/releases/latest';
    $transient_name = 'woocommerce_bancard_latest_release';
    $transient = get_transient($transient_name);
    if ($transient) {
        $latest_release = $transient;
    } else {
        $response = wp_remote_get($repo_url);
        if (is_wp_error($response)) {
            return;
        }
        $latest_release = json_decode(wp_remote_retrieve_body($response));
        // Cache the latest release for 24h
        set_transient($transient_name, $latest_release, DAY_IN_SECONDS);
    }
    if (version_compare($latest_release->tag_name, $plugin_version, '>')) {
        add_action('admin_notices', function () use ($latest_release) {
            $message = 'Una nueva actualización del plugin WooCommerce Bancard esta disponible. <a href="' . $latest_release->zipball_url . '">Descargalo aquí.</a>';
            echo '<div class="notice notice-info"><p>' . $message . '</p></div>';
        });
    }
});

