<?php
/*
Plugin Name: WooCommerce Bancard Gateway
Plugin URI: https://emedos.com.py/woocommerce-bancard/
Description: Pasarela de pagos Bancard para WooCommerce.
Version: 0.2.2
Author: M2 Design
Author URI: https://emedos.com.py/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: woocommerce-bancard
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Hooks
add_filter('woocommerce_payment_gateways', 'add_bancard_gateway');
add_action('plugins_loaded', 'init_bancard_gateway', 11);
add_action('woocommerce_account_payment-methods_endpoint', 'bancard_payment_methods');
add_action('wp_enqueue_scripts', 'bancard_enqueue_scripts');
add_action('admin_enqueue_scripts', 'bancard_admin_scripts');

// Functions
function add_bancard_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Bancard';
    $gateways[] = 'WC_Gateway_Bancard_Zimple';
    $gateways[] = 'WC_Gateway_Bancard_Tokens';
    return $gateways;
}

/**
 * Init the gateway.
 *
 * Checks if WooCommerce is installed, then loads the necessary classes for the gateway.
 *
 * @return void
 */
function init_bancard_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once 'includes/class-wc-gateway-bancard.php';
    include_once 'includes/class-wc-gateway-bancard-api.php';
    include_once 'includes/class-wc-gateway-bancard-hooks.php';
    include_once 'includes/class-wc-gateway-bancard-tokens.php';
    include_once 'includes/class-wc-gateway-bancard-subscriptions.php';
    include_once 'includes/class-wc-gateway-bancard-zimple.php';
}

/**
 * Load the payment methods template.
 *
 * This function is hooked into `woocommerce_account_payment-methods_endpoint` and
 * is responsible for loading the payment methods template.
 *
 * @return void
 */
function bancard_payment_methods() {
    include_once 'templates/bancard-payment-methods.php';
}

/**
 * Enqueue scripts and styles for the frontend.
 *
 * This function is hooked into `wp_enqueue_scripts` and is responsible for
 * loading the necessary styles and scripts for the frontend.
 */
function bancard_enqueue_scripts() {
    wp_enqueue_style('bancard-style', plugins_url('/assets/css/style.css', __FILE__));
    wp_enqueue_script('bancard-script', plugins_url('/assets/js/script.js', __FILE__), array('jquery'), null, true);
}

/**
 * Enqueue scripts and styles for the admin area.
 *
 * This function is hooked into `admin_enqueue_scripts` and is responsible for
 * loading the necessary styles and scripts for the admin area.
 */
function bancard_admin_scripts() {
    wp_enqueue_script('bancard-admin-script', plugins_url('/assets/js/admin-script.js', __FILE__), array('jquery'), null, true);
}

register_activation_hook(__FILE__, 'create_bancard_payment_page');

/**
 * Verifica si la página de pago con Bancard existe y la crea si no es así.
 *
 * Esta función es un hook para `register_activation_hook` y se encarga de
 * crear la página de pago con Bancard en el momento de activar el plugin.
 *
 * @return void
 */
function create_bancard_payment_page() {
    // Verifica si la página ya existe
    $page = get_page_by_path('bancard-payment');
    if (!$page) {
        // Crear la página si no existe
        $page_data = array(
            'post_title'    => 'Pago con Bancard',
            'post_name'     => 'bancard-payment',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => 1,
            'post_content'  => '[woocommerce_checkout]', // Puedes usar un shortcode o dejar el contenido vacío
        );
        wp_insert_post($page_data);
    }
}

?>
