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

if ($process_id) {
    ?>
    <script src="<?= $endpoint; ?>/checkout/javascript/dist/bancard-checkout-4.0.0.js"></script>
    <div id="bancard-payment-form">
        <p>Cargando el formulario de pago...</p>
    </div>

    <script>
        Bancard.Checkout.createForm('bancard-payment-form', '<?= $process_id; ?>'); 
    </script>
    <?php
} else {
    echo '<p>Error: No se encontró el ID del proceso.</p>';
}

get_footer();
