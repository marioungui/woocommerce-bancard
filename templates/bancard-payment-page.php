<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/* Template for Bancard Payment Page */

get_header();

$bancard = new WC_Gateway_Bancard();
$environment = $bancard->environment;
$endpoint = $environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';

$process_id = isset($_GET['process_id']) ? sanitize_text_field($_GET['process_id']) : '';
$zimple = isset($_GET['zimple']) ? sanitize_text_field($_GET['zimple']) : false;

if ($process_id) {
    wp_enqueue_script('bancard-checkout', $endpoint . '/checkout/javascript/dist/bancard-checkout-4.0.0.js', array(), null, false);
	wp_enqueue_style('bancard-style', plugins_url('/assets/css/style.css', __FILE__), array(), WC_BANCARD_VERSION);
    ?>
    <div id="bancard-payment-form" style="display: table; width: calc(100% - 20px); max-width: 900px; margin: 40px auto">
        <p>Cargando el formulario de pago...</p>
    </div>

    <script>
		jQuery(document).ready(function () {
			Bancard.<?= $zimple ? 'Zimple' : 'Checkout'?>.createForm('bancard-payment-form', '<?= esc_html($process_id); ?>');	   
		});         
    </script>
    <?php
} else {
    echo '<p>Error: No se encontró el ID del proceso.</p>';
}

get_footer();
