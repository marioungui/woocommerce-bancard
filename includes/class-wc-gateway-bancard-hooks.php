<?php
add_filter('woocommerce_order_button_text', 'bancard_custom_order_button_text');

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

add_filter('woocommerce_account_menu_items', 'bancard_add_payment_methods_link');

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

add_action('woocommerce_account_payment-methods_endpoint', 'bancard_payment_methods_content');

/**
 * Contenido de la página de métodos de pago.
 *
 * Muestra la lista de tarjetas guardadas del usuario y un enlace para agregar un nuevo método de pago.
 */
function bancard_payment_methods_content() {
    $tokens = new WC_Gateway_Bancard_Tokens();
    $tokens->list_payment_methods();

    echo '<a href="' . wc_get_endpoint_url('add-payment-method') . '" class="button">Agregar Método de Pago</a>';
}

add_action('init', 'bancard_add_add_payment_method_endpoint');

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

add_action('woocommerce_account_add-payment-method_endpoint', 'bancard_add_payment_method_content');

/**
 * Contenido de la página para agregar un método de pago
 *
 * Muestra el formulario para agregar un método de pago
 */
function bancard_add_payment_method_content() {
    $tokens = new WC_Gateway_Bancard_Tokens();
    $tokens->add_payment_method();
}