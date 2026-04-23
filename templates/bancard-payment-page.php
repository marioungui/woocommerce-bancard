<?php

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$process_id = isset($_GET['process_id']) ? sanitize_text_field(wp_unslash($_GET['process_id'])) : '';
$mode = isset($_GET['mode']) ? sanitize_text_field(wp_unslash($_GET['mode'])) : 'checkout';
$gateway_id = isset($_GET['gateway']) ? sanitize_text_field(wp_unslash($_GET['gateway'])) : 'bancard';
$gateway = function_exists('wc_bancard_get_gateway_instance') ? wc_bancard_get_gateway_instance($gateway_id) : new WC_Gateway_Bancard();
$endpoint = $gateway->get_api_base_url();

$modes = array(
    'checkout' => 'Checkout',
    'zimple'   => 'Zimple',
    'charge3ds'=> 'Charge3DS',
);

$js_method = isset($modes[$mode]) ? $modes[$mode] : 'Checkout';

if ($process_id) :
    wp_enqueue_script('bancard-checkout', $endpoint . '/checkout/javascript/dist/bancard-checkout-4.0.0.js', array(), null, false);
    wp_enqueue_style('bancard-style', plugins_url('assets/css/style.css', WC_BANCARD_PLUGIN_FILE), array(), WC_BANCARD_VERSION);
    ?>
    <div id="bancard-payment-form" style="display:table;width:calc(100% - 20px);max-width:900px;margin:40px auto">
        <p><?php echo esc_html__('Cargando el formulario de pago de Bancard...', 'woocommerce-bancard'); ?></p>
    </div>
    <script>
        window.addEventListener('load', function () {
            if (window.Bancard && Bancard.<?php echo esc_js($js_method); ?>) {
                Bancard.<?php echo esc_js($js_method); ?>.createForm('bancard-payment-form', '<?php echo esc_js($process_id); ?>');
            }
        });
    </script>
    <?php
else :
    echo '<p>' . esc_html__('No se encontró el identificador de proceso de Bancard.', 'woocommerce-bancard') . '</p>';
endif;

get_footer();
