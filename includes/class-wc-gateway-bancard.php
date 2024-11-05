<?php
class WC_Gateway_Bancard extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'bancard';
        $this->icon = ''; // URL to the icon that will be displayed on the checkout page.
        $this->has_fields = false;
        $this->method_title = 'Bancard';
        $this->method_description = 'Pasarela de pagos Bancard para WooCommerce.';

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->public_key = $this->get_option('public_key');
        $this->private_key = $this->get_option('private_key');
        $this->environment = $this->get_option('environment');
        $this->supports = array (
            'refunds',
            'subscriptions',
            'subscriptions_recurring',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation'
        );

        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // Save Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Payment listener/API hook
        add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_response'));

        // Support for WooCommerce Subscriptions
        if (class_exists('WC_Subscriptions_Order')) {
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'process_subscription_payment'), 10, 2);
        }
        // Hook para verificar la existencia de la página al inicializar el plugin
        add_action('admin_init', array($this, 'check_bancard_payment_page'));

        // Hook para agregar el template de la pasarela de pagos
        add_filter('template_include', array($this, 'bancard_payment_template'));
        
        // Shortcode para la vista de pago
        add_shortcode('pago_bancard', array($this, 'bancard_payment_shortcode'));

        // Añadir datos de la transacción a la orden
        add_action('woocommerce_thankyou', [$this, 'display_bancard_transaction_details'], 20);
        add_action('woocommerce_order_details_before_order_table_items', [$this, 'display_bancard_transaction_details'], 20);
    }

    /**
     * Verifica la existencia de la página de pago de Bancard al inicializar el plugin.
     *
     * Si la página no existe, agrega una notificación en la sección de administración
     * de WordPress.
     *
     * @since 1.0.0
     */
    function check_bancard_payment_page() {
        $payment_page = get_page_by_path('bancard-payment');
        if (!$payment_page) {
            // Si la página no existe, agrega una notificación
            add_action('admin_notices', 'bancard_payment_page_missing_notice');
        }
    }

    /**
     * Muestra los detalles de la transacción realizada con la pasarela de pagos de Bancard.
     *
     * @param int $order_id ID de la orden a la que se le mostrarán los detalles de transacción.
     */
    function display_bancard_transaction_details($order_id) {
        $order = wc_get_order($order_id);
    
        $authorization_number = get_post_meta($order_id, '_bancard_authorization_number', true);
        $ticket_number = get_post_meta($order_id, '_bancard_ticket_number', true);
    
        if ($authorization_number && $ticket_number) {
            echo '<h2>' . __('Detalles de la Transacción', 'bancard') . '</h2>';
            echo '<p><strong>' . __('N° de Autorización VPOS:', 'bancard') . '</strong> ' . esc_html($authorization_number) . '</p>';
            echo '<p><strong>' . __('N° de Ticket VPOS:', 'bancard') . '</strong> ' . esc_html($ticket_number) . '</p>';
        }
    }

    /**
     * Muestra una notificación de error en la sección de administración de WordPress
     * si la página "bancard-payment" no existe.
     *
     * Esta función se encarga de mostrar una notificación de error en la sección de
     * administración de WordPress si la página "bancard-payment" no existe. La notificación
     * indica que la página es necesaria para que el plugin Bancard funcione correctamente.
     *
     * @since 1.0.0
     */
    function bancard_payment_page_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php esc_html(__('La página requerida "bancard-payment" no está creada. Por favor, crea una página con el slug "bancard-payment" para que el plugin Bancard funcione correctamente.', 'bancard')); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Inicializa los campos de configuración de la pasarela de pagos Bancard.
     *
     * Estos campos se utilizan para configurar la pasarela de pagos Bancard en la
     * sección de administración de WordPress. Los campos se definen como un
     * arreglo de arrays, cada uno de los cuales contiene los siguientes elementos:
     *
     * - `title`: El título del campo.
     * - `type`: El tipo de campo (por ejemplo, `text`, `checkbox`, `select`).
     * - `label`: La etiqueta del campo.
     * - `description`: La descripción del campo.
     * - `default`: El valor predeterminado del campo.
     * - `desc_tip`: Un booleano que indica si se debe mostrar la descripción del
     *              campo como un tooltip.
     *
     * @since 1.0.0
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Habilitar la pasarela de pagos Bancard',
                'default' => 'yes'
            ),
            'title' => array(
                'title' => 'Título',
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.'),
                'default' => 'Credit Card (Bancard)',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Descripción',
                'type' => 'textarea',
                'description' => 'Este campo controla la descripción que ve el usuario en la página de compras',
                'default' => 'Pagos con tarjetas vía Bancard',
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

    /**
     * Procesa el pago del pedido a través de la pasarela de pagos Bancard.
     *
     * @param int $order_id El ID del pedido a procesar.
     *
     * @return array Un array con la respuesta a la petición de pago.
     *               Si la petición fue exitosa, devuelve un array con los elementos:
     *               - 'result' => 'success'
     *               - 'redirect' => La URL de la página de pago intermedia
     *
     *               Si la petición falló, devuelve un array con los elementos:
     *               - 'result' => 'failure'
     *               - 'redirect' => La URL de la página de compras
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $process_id = get_post_meta($order_id, '_bancard_process_id', true);
    
        // Si ya existe un process_id, usamos el mismo
        if (!$process_id) {
            // Generar una solicitud a la API de Bancard para obtener el process_id
            $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
            $url = $endpoint . '/vpos/api/0.3/single_buy';
            $amount = number_format($order->get_total(), 2, '.', '');
            $currency = 'PYG';
            $token = md5($this->private_key . $order_id . $amount . $currency);
    
            $data = array(
                'public_key' => $this->public_key,
                'operation' => array(
                    'token' => $token,
                    'shop_process_id' => $order_id,
                    'amount' => number_format($order->get_total(), 2, '.', ''),
                    'currency' => $currency,
                    'description' => __('Pedido ID: ', 'bancard') . $order_id,
                    'return_url' => $this->get_return_url($order),
                    'cancel_url' => wc_get_cart_url(),
                )
            );
    
            $response = wp_remote_post($url, array(
                'method' => 'POST',
                'body' => json_encode($data),
                'headers' => array('Content-Type' => 'application/json'),
            ));
    
            if (is_wp_error($response)) {
                wc_add_notice('Error al comunicarse con Bancard: ' . $response->get_error_message(), 'error');
                return;
            }
    
            $body = json_decode(wp_remote_retrieve_body($response), true);
    
            if ($body['status'] == 'success') {
                $process_id = $body['process_id'];
                update_post_meta($order_id, '_bancard_process_id', $process_id);
            } else {
                wc_add_notice('Error en la respuesta de Bancard: ' . $body['messages'][0]['dsc'], 'error');
                wp_delete_post($order_id, true);
                return;
            }
        }
    
        // Redirigir a la página intermedia con el process_id
        $payment_page = get_page_by_path('bancard-payment');
        if ($payment_page) {
            $payment_page_url = add_query_arg('process_id', $process_id, get_permalink($payment_page->ID));
            return array(
                'result'   => 'success',
                'redirect' => $payment_page_url,
            );
        } else {
            wc_add_notice('No se encontró la página de pago de Bancard.', 'error');
            return;
        }
    }

    /**
     * Crea una solicitud de pago a través de la API de Bancard.
     *
     * @param WC_Order $order La orden a procesar.
     * @return array con los resultados de la solicitud. El array contiene dos claves:
     *  - status: 'success' o 'fail' según el resultado de la solicitud.
     *  - message: El mensaje de error en caso de que status sea 'fail'.
     *  - process_id: El ID del proceso generado por Bancard en caso de que status sea 'success'.
     */
    private function create_payment($order) {
        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
        $url = $endpoint . '/vpos/api/0.3/single_buy';

        $shop_process_id = $order->get_id();
        $amount = number_format($order->get_total(), 2, '.', '');
        $currency = 'PYG';

        $token = md5($this->private_key . $shop_process_id . $amount . $currency);

        $body = json_encode(array(
            'public_key' => $this->public_key,
            'operation' => array(
                'token' => $token,
                'shop_process_id' => $shop_process_id,
                'amount' => $amount,
                'currency' => $currency,
                'description' => 'Order ' . $shop_process_id,
                'return_url' => $this->get_return_url($order),
                'cancel_url' => wc_get_cart_url()
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
    /**
     * Filter to locate the template for the payment page.
     *
     * Looks for a template file in the plugin directory and returns the path
     * to the template file if it exists. Otherwise, returns the original
     * template.
     *
     * @param string $template The original template path.
     * @return string The path to the template file.
     */
    public function bancard_payment_template($template) {
        if (is_page('bancard-payment')) {
            $plugin_template = plugin_dir_path(__FILE__) . '../templates/bancard-payment-page.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }
        return $template;
    }
    /**
     * Process a subscription payment using the Bancard API.
     *
     * @param float $amount The amount to charge the customer.
     * @param WC_Order $order The order being processed.
     * @return boolean True if the payment was successful, false otherwise.
     */
    public function process_subscription_payment($amount, $order) {
        $token = get_post_meta($order->get_id(), '_bancard_token', true);
        $shop_process_id = $order->get_id();
    
        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
        $url = $endpoint . '/vpos/api/0.3/token_charge';
    
        $data = array(
            'public_key' => $this->public_key,
            'operation' => array(
                'token' => $token,
                'shop_process_id' => $shop_process_id,
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => 'PYG',
                'description' => 'Subscription payment for order ' . $shop_process_id,
            )
        );
    
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
        ));
    
        if (is_wp_error($response)) {
            $order->update_status('failed', 'Subscription payment failed: ' . $response->get_error_message());
            return false;
        }
    
        $body = json_decode(wp_remote_retrieve_body($response), true);
    
        if ($body['status'] == 'success') {
            $order->payment_complete();
            $order->add_order_note('Subscription payment successful via Bancard.');
            return true;
        } else {
            $order->update_status('failed', 'Subscription payment failed: ' . $body['message']);
            return false;
        }
    }
    
    /**
     * Maneja la respuesta de la API de Bancard para una transacción.
     *
     * Si la respuesta es exitosa, guarda los datos de autorización y ticket
     * en los metadatos de la orden y completa el pago. Si la respuesta es
     * fallida, actualiza el estado de la orden a 'failed' y envía un
     * mensaje de error.
     *
     * @since 1.0.0
     */
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
    
    /**
     * Procesa un reembolso de una orden.
     *
     * Realiza una solicitud a la API de Bancard para reembolsar una orden.
     * Si la respuesta es exitosa, agrega un nota a la orden con el mensaje
     * "Refund processed via Bancard." y devuelve true. Si la respuesta es
     * fallida, devuelve un objeto WP_Error con el mensaje de error.
     *
     * @since 1.0.0
     *
     * @param int $order_id ID de la orden a reembolsar.
     * @param int $amount Monto del reembolso.
     * @param string $reason Motivo del reembolso.
     *
     * @return bool|WP_Error True si el reembolso fue exitoso, WP_Error en caso de error.
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $shop_process_id = $order->get_id();

        // Validate amount, it should be exactly the same amount of the order and if not return an error
        if ($amount != $order->get_total()) {
            return new WP_Error('bancard_refund_error', 'El monto del reembolso debe ser exactamente el mismo que el monto de la orden.');
        }

        // Check if the order date is today and if not, return an error
        $order_date = $order->get_date_created();
        $today = new DateTime('now');
        $today->setTime(0, 0, 0);
        if ($order_date->format('Y-m-d') != $today->format('Y-m-d')) {
            return new WP_Error('bancard_refund_error', 'El reembolso vía API de Bancard solo se puede realizar el mismo día de la orden. Para reversar este pedido hazlo por el canal oficial:
            Portal de comercios -> soporte -> anulaciones');
        }
    
        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
        $url = $endpoint . '/vpos/api/0.3/single_buy/rollback';
    
        $token = md5($this->private_key . $shop_process_id . "rollback0.00");
    
        $data = array(
            'public_key' => $this->public_key,
            'operation' => array(
                'token' => $token,
                'shop_process_id' => $shop_process_id,
            )
        );
    
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
        ));
    
        if (is_wp_error($response)) {
            return new WP_Error('bancard_refund_error', 'Refund failed: ' . $response->get_error_message());
        }
    
        $body = json_decode(wp_remote_retrieve_body($response), true);
    
        if ($body['status'] == 'success') {
            $order->update_status('refunded', 'Reembolso procesado correctamente vía Bancard.');
            $order->save();
            return true;
        } else {
            return new WP_Error('bancard_refund_error', 'Reembolso no procesado: ' . $body['messages'][0]['dsc']);
        }
    }
    
    /**
     * Shortcode para mostrar el formulario de pago de Bancard.
     *
     * @param array $atts Atributos del shortcode.
     *
     * @return string El HTML del formulario de pago.
     */
    public function bancard_payment_shortcode($atts) {
        error_log("<!-- Debug: Shortcode ejecutado -->");
        include plugin_dir_path(__FILE__) . '../templates/bancard-payment-page.php';
        return '';
    }

    /**
     * Enqueue scripts and styles for the frontend.
     *
     * This function is hooked into `wp_enqueue_scripts` and is responsible for
     * loading the necessary styles and scripts for the frontend.
     */
    function bancard_enqueue_scripts() {
        // Cargar los scripts y estilos de WooCommerce
        if (function_exists('is_woocommerce')) {
            wp_enqueue_script('wc-checkout');
            wp_enqueue_style('wc-checkout');
        }
    }

    /**
     * Confirma manualmente una transacci n de pago en la pasarela de pagos Bancard.
     *
     * Realiza una solicitud a la API de Bancard para confirmar la transacci n
     * correspondiente al pedido especificado. Si la respuesta es exitosa, marca
     * el pedido como completado y agrega una nota a la orden con el mensaje
     * "Transaction confirmed manually via Bancard." y devuelve true. Si la
     * respuesta es fallida, devuelve un objeto WP_Error con el mensaje de error.
     *
     * @param int $order_id El ID del pedido a confirmar.
     *
     * @return bool|WP_Error True si la confirmación fue exitosa, WP_Error en caso de error.
     */
    public function confirm_transaction($order_id) {
        $order = wc_get_order($order_id);
        $shop_process_id = $order->get_id();
    
        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
        $url = $endpoint . '/vpos/api/0.3/single_buy/confirmations';

        $token = md5($this->private_key . $shop_process_id . "get_confirmation");
    
        $data = array(
            'public_key' => $this->public_key,
            'operation' => array(
                'token' => $token,
                'shop_process_id' => $shop_process_id,
            )
        );
    
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
        ));
    
        if (is_wp_error($response)) {
            return new WP_Error('bancard_confirmation_error', 'Transaction confirmation failed: ' . $response->get_error_message());
        }
    
        $body = json_decode(wp_remote_retrieve_body($response), true);
    
        if ($body['status'] == 'success' && $body['confirmation']['response'] == 'S') {
            if ($order->get_status() != 'processing') {
                $order->payment_complete();
            }
            $order->add_order_note('Transacción Confirmada por Bancard el ' . current_datetime()->format('Y-m-d H:i:s'). '.');
            return true;
        }
        else if ($body['status'] == 'error' && $body['messages'][0]['key'] == 'PaymentNotFoundError') {
            $order->update_status('failed', 'Transaction confirmation failed: ' . $body['messages'][0]['dsc']);
            return new WP_Error('bancard_confirmation_error', 'Transaction confirmation failed: ' . $body['messages'][0]['dsc']);
        }
        else{
            return new WP_Error('bancard_confirmation_error', 'Transaction confirmation failed: ' . $body['messages'][0]['dsc']);
        }
    }
    
}
?>
