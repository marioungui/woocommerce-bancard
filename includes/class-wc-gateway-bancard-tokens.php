<?php
class WC_Gateway_Bancard_Tokens extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'bancard_tokens';
        $this->icon = plugins_url('assets/images/bancard.png', __FILE__);
        $this->has_fields = true;
        $this->method_title = 'Pagos con tarjetas registradas de Bancard';
        $this->method_description = 'Pagos con tarjetas registradas de Bancard';
        $this->title = 'Bancard Tokens';
        $this->supports = array(
            'refunds',
            'subscriptions',
            'subscriptions_recurring',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'tokenization'
        );
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->public_key = $this->get_option('public_key');
        $this->private_key = $this->get_option('private_key');
        $this->environment = $this->get_option('environment');

        // Save Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function payment_fields() {
        do_action('woocommerce_credit_card_form_start', $this->id);
        wp_enqueue_style('bancard-token-payment', plugins_url('assets/css/token-payment.css', __FILE__), array(), WC_BANCARD_VERSION);
        ?>
        <div id="info-catastro-field"><p>Pague de forma rápida, segura y sencilla registrando su tarjeta en la sección <i>"Mi Cuenta"</i></p></div>

        <?php

        $user_id = get_current_user_id();
        $response = $this->get_user_cards($user_id);

        if (is_wp_error($response)) {
            wc_add_notice('Error al comunicarse con Bancard: ' . $response->get_error_message(), 'error');
            return;
        }
        
        // Procesar la respuesta
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body['cards'])) {
            foreach ($body['cards'] as $card => $valor) {
                if ($valor['card_type'] == "credit") {
                    $valor['card_type'] = "Tarj. Credito";
                }
                if ($valor['card_type'] == "debit") {
                    $valor['card_type'] = "Tarj. Debito";
                }
                if (stripos($valor['card_brand'],"bancard") !== FALSE) {
                    $card_brand = "bancard";
                }
                if (stripos($valor['card_brand'],"visa") !== FALSE) {
                    $card_brand = "visa";
                }
                if (stripos($valor['card_brand'],"mastercard") !== FALSE) {
                    $card_brand = "mastercard";
                }
                $imgcard = plugin_dir_url( __FILE__ )."../assets/credit-cards/".$card_brand.".png";
                echo ('<div class="bancard-card-box form-row" id="bancard-card-box"><input type="radio" id="card-'.$valor["card_id"].'" class="bancard-nmasked" name="bancard-card-token" value="'.$valor['alias_token'].'"><img class="bancard-cardbrand" src="'.$imgcard.'"><label for="card-'.$valor["card_id"].'" class="bancard-nmaskednumber">'.chunk_split($valor['card_masked_number'],4,' ').'</label>
                <span class="bancard-cardvenc">Venc.</span>
                <span class="bancard-cardvencinfo">'.$valor['expiration_date'].'</span></div>');
            }
            echo '<input type="hidden" value="" name="bancard_card-id" id="bancard_card-id">';
        }

        do_action('woocommerce_credit_card_form_end', $this->id);
    }

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
                'default' => 'Pago con tarjetas registradas de Bancard',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Descripción',
                'type' => 'textarea',
                'description' => 'Este campo controla la descripción que ve el usuario en la página de compras',
                'default' => 'Pagos con tarjetas registradas de Bancard',
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

    public function validate_fields() {
        if( isset($_POST['payment_method']) && $_POST['payment_method'] == $this->id ) {
            if (!isset($_POST['bancard-card-token']) || empty($_POST['bancard-card-token'])) {
                wc_add_notice(  'Favor seleccione una tarjeta para continuar con el pago', 'error' );
                return false;
            }
            else {
                define("CATASTRO_PAGOTOKEN",$_POST['bancard-card-token']);
                return true;
            }
        }
        return true;
    }

    public function add_payment_method() {
        // Obtener el ID del cliente
        $user_id = get_current_user_id();

        if (!$user_id) {
            wc_add_notice('Debe estar logueado para agregar un método de pago.', 'error');
            return;
        }

        // Generar un card_id único y secuencial
        $card_id = $this->generate_card_id($user_id);

        // Lógica para agregar un método de pago a través de la API de Bancard
        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
        $url = $endpoint . '/vpos/api/0.3/cards/new';
        $public_key = $this->public_key;
        $token = md5($this->private_key . $card_id . $user_id . "request_new_card");

        $data = array(
            'public_key' => $public_key,
            'operation' => array(
                'token' => $token,
                'card_id' => $card_id,
                'user_id' => $user_id,
                'user_cell_phone' => get_user_meta($user_id, 'billing_phone', true),
                'user_mail' => get_user_meta($user_id, 'billing_email', true),
                'return_url' => wc_get_endpoint_url('add-payment-method')
            ),
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

        if (!empty($_GET['status']) && $_GET['status'] == 'add_new_card_fail') {
            wc_add_notice('Error al agregar el método de pago: ' . esc_html( $_GET['description'] ), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            return;   
        }
        else if (!empty($_GET['status']) && $_GET['status'] == 'add_new_card_success') {
            wc_add_notice('Tarjeta agregada exitosamente', 'success');
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            return;
        }

        if ($body['status'] == 'success') {
            $process_id = $body['process_id'];

            // Almacenar el card_id en el usermeta
            $this->save_card_id($user_id, $card_id);

            // Render here the script with the process_id
            ?>
            <!-- Include the script with the process_id -->
            <script src="<?= esc_url($endpoint); ?>/checkout/javascript/dist/bancard-checkout-4.0.0.js"></script>
            <div id="bancard-token-form">
                <p>Cargando el formulario de registro de tarjetas...</p>
            </div>
            <style>form#add_payment_method { display: none !important; }</style>
            <script>
                Bancard.Cards.createForm('bancard-token-form', '<?= esc_html($process_id); ?>');
            </script>
            <?php
        } else {
            wc_add_notice('Error al agregar el método de pago: ' . $body['messages'][0]['dsc'], 'error');
        }
    }

    /**
     * Genera un card_id único y secuencial para un usuario.
     *
     * @param int $user_id ID del usuario.
     * @return string card_id generado.
     */
    private function generate_card_id($user_id) {
        // Obtener el número actual de tarjetas del usuario
        $cards = get_user_meta($user_id, '_bancard_card_ids', true);
        if (!$cards || !is_array($cards)) {
            $cards = array();
        }
        
        // Generar un card_id secuencial
        $card_number = count($cards) + 1;
        $card_id = 'card_' . $user_id . '_' . $card_number;
        
        return $card_id;
    }

    /**
     * Guarda el card_id en el usermeta del usuario.
     *
     * @param int $user_id ID del usuario.
     * @param string $card_id ID de la tarjeta.
     */
    private function save_card_id($user_id, $card_id) {
        $cards = get_user_meta($user_id, '_bancard_card_ids', true);
        if (!$cards || !is_array($cards)) {
            $cards = array();
        }
        $cards[] = $card_id;
        update_user_meta($user_id, '_bancard_card_ids', $cards);
    }

    // Opcional: Obtener todas las tarjetas de un usuario
    public function get_user_cards($user_id) {
        // Obtener el ID del cliente
        $user_id = get_current_user_id();
    
        if (!$user_id) {
            wc_add_notice('Debe estar logueado para ver los métodos de pago.', 'error');
            return;
        }

        if (isset($_GET['action']) && $_GET['action'] == 'delete_card' && isset($_GET['card_id']) && isset($_GET['card_token'])) {
            $this->delete_bancard_card($user_id, $_GET['card_token']);
            return;
        }
    
        // Preparar la URL del endpoint y el token de autenticación
        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py' : 'https://vpos.infonet.com.py:8888';
        $url = $endpoint . '/vpos/api/0.3/users/' . $user_id . '/cards';
    
        // Generar el token requerido por la API
        $token = md5($this->private_key . $user_id . "request_user_cards");
    
        // Preparar los datos para la solicitud
        $data = array(
            'public_key' => $this->public_key,
            'operation' => array(
                'token' => $token
            )
        );
    
        // Hacer la solicitud POST a la API de Bancard
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
        ));
        return $response;
    }

    /**
     * Muestra la lista de tarjetas guardadas del usuario.
     *
     * Consulta la API de Bancard para obtener la lista de tarjetas
     * asociadas al usuario actual y las muestra en una tabla con
     * información detallada. Si una tarjeta ya tiene un token
     * asociado en WooCommerce, no se crea uno nuevo.
     *
     * @since 1.0.0
     * @return void
     */
    public function list_payment_methods() {
        $user_id = get_current_user_id();
        $response = $this->get_user_cards($user_id);
    
        if (is_wp_error($response)) {
            wc_add_notice('Error al comunicarse con Bancard: ' . $response->get_error_message(), 'error');
            return;
        }
    
        // Procesar la respuesta
        $body = json_decode(wp_remote_retrieve_body($response), true);
    
        if (!empty($body['cards'])) {
            echo '<table id="Bancard-cards-table" class="wp-list-table" style="margin-top: 20px;">';
            echo '<tr>';
            echo '<th>Número de Tarjeta</th>';
            echo '<th>Fecha Exp.</th>';
            echo '<th>Marca de Tarjeta</th>';
            echo '<th>Tipo de Tarjeta</th>';
            echo '<th>Acciones</th>';
            echo '</tr>';
    
            foreach ($body['cards'] as $card) {
                // Desglosar la fecha de expiración
                list($month, $year) = explode('/', $card['expiration_date']);
                $year = '20' . $year;  // Añadir el prefijo "20" al año
    
                // Buscar si ya existe un token para esta tarjeta
                $token_exists = false;
                $tokens = WC_Payment_Tokens::get_customer_tokens($user_id);
    
                foreach ($tokens as $token) {
                    if ($token->get_last4() == substr($card['card_masked_number'], -4) && 
                        $token->get_expiry_month() == $month && 
                        $token->get_expiry_year() == $year && 
                        $token->get_card_type() == strtolower($card['card_brand'])) {
                        $token_exists = true;
                        break;
                    }
                }
    
                // Si no existe un token, crear uno nuevo
                if (!$token_exists) {
                    $new_token = new WC_Payment_Token_CC();
                    $new_token->set_token($card['alias_token']); // Asocia el token de la tarjeta con WooCommerce
                    $new_token->set_gateway_id('bancard'); // Reemplaza con el ID de tu gateway si es diferente
                    $new_token->set_last4(substr($card['card_masked_number'], -4));
                    $new_token->set_expiry_month($month);
                    $new_token->set_expiry_year($year);
                    $new_token->set_card_type(strtolower($card['card_brand']));
                    $new_token->set_user_id($user_id);
                    $new_token->save();
                }
    
                // Generar la URL para eliminar la tarjeta
                $delete_url = add_query_arg(array(
                    'action' => 'delete_card',
                    'card_id' => $card['card_id'],
                    'card_token' => $card['alias_token'],
                ), wc_get_endpoint_url('payment-methods'));
    
                echo '<tr>';
                echo '<td>' . esc_html($card['card_masked_number']) . '</td>';
                echo '<td>' . esc_html($card['expiration_date']) . '</td>';
                echo '<td>' . esc_html($card['card_brand']) . '</td>';
                echo '<td>' . esc_html($card['card_type']) . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'¿Estás seguro de que deseas eliminar esta tarjeta?\');" class="button">Eliminar</a>';
                echo '</td>';
                echo '</tr>';
            }
    
            echo '</table>';
        }
        if (empty(get_user_meta($user_id, 'billing_phone', true)) || empty(get_user_meta($user_id, 'billing_address_1', true))) {
            // Verificar si el usuario tiene un teléfono y una dirección guardados
            $user_phone = get_user_meta($user_id, 'billing_phone', true);
            $user_address = get_user_meta($user_id, 'billing_address_1', true);

            if (empty($user_phone) || empty($user_address)) {
                wc_add_notice('Debe tener registrado un numero de telefono y una dirección en la sección facturación para agregar un nuevo método de pago', 'error');
                wp_safe_redirect(wc_get_endpoint_url('edit-address'));
                exit;
            }
        }    
        else {
            echo '<p>No tienes métodos de pago guardados.</p>';
        }

    }

    public function delete_bancard_card($user_id, $card_token) {
        $user_id = get_current_user_id();

        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py/vpos/api/0.3/users/' . $user_id . '/cards' : 'https://vpos.infonet.com.py:8888/vpos/api/0.3/users/' . $user_id . '/cards';
        $public_token = $this->public_key;
        $request_token = md5($this->private_key . "delete_card" . $user_id . $card_token);

        $data = array(
            'public_key' => $public_token,
            'operation' => array(
                'token' => $request_token,
                'alias_token' => $card_token,
            ),
        );

        $response = wp_remote_post($endpoint, array(
            'method' => 'DELETE',
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            wc_add_notice('Error al eliminar la tarjeta: ' . $response->get_error_message(), 'error');
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            return false;
        } else {
            $body = json_decode($response['body'], true);
            if ($body['status'] == 'success') {
                wc_add_notice('Tarjeta eliminada exitosamente', 'success');
                wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
                return true;
            }
        }
    }

    public function delete_payment_method($card_token) {
        // Obtener el ID del cliente
        $user_id = get_current_user_id();

        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py/vpos/api/0.3/users/' . $user_id . '/cards' : 'https://vpos.infonet.com.py:8888/vpos/api/0.3/users/' . $user_id . '/cards';
        $public_token = $this->public_key;
        $request_token = md5($this->private_key . "delete_card" . $user_id . $card_token);


        $data = array(
            'public_key' => $public_token,
            'operation' => array(
                'token' => $request_token,
                'alias_token' => $card_token,
            ),
        );

        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            wc_add_notice('Error al comunicarse con Bancard: ' . $response->get_error_message(), 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($body['status'] == 'success') {
            // Eliminar el token de los métodos de pago del cliente
            $tokens = get_user_meta($user_id, '_bancard_payment_tokens', true);
            $token_index = array_search($card_token, array_column($tokens, 'token'));
            if ($token_index !== false) {
                unset($tokens[$token_index]);
                update_user_meta($user_id, '_bancard_payment_tokens', array_values($tokens));
                wc_add_notice(__('Payment method deleted successfully.', 'woocommerce-bancard'), 'success');
                wp_safe_redirect(wc_get_page_permalink('myaccount'));
                return;
            }
        } else {
            wc_add_notice('Error al eliminar el método de pago: ' . $body['message'], 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            return;
        }
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $amount = number_format($order->get_total(), 2, '.', '');
        $token = md5($this->private_key . $order_id . "charge" . $amount . $order->get_currency() . CATASTRO_PAGOTOKEN);


        $args = array(
            'public_key' => $this->public_key,
            'operation' => array(
                'token' => $token,
                'shop_process_id' => $order_id,
                'number_of_payments' => 1,
                'amount' => $amount,
                'description' => "Pago del pedido #" . $order_id,
                'alias_token' => CATASTRO_PAGOTOKEN,
                'currency' => $order->get_currency(),
                'additional_data' => "",
                'return_url' => wc_get_endpoint_url('order-received', $order_id, wc_get_page_permalink('checkout')),
                'extra_response_attributes' => array(
                    'confirmation.process_id'
               ),
            ),
        );

        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py/vpos/api/0.3/charge' : 'https://vpos.infonet.com.py:8888/vpos/api/0.3/charge';
        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'body' => json_encode($args),
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            return array('result' => 'fail', 'message' => "Test 1");
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!$body) {
            return array('resutl' => 'fail', 'message' => "Respuesta de la API no es válida");
        }

        if ($body['status'] === 'error') {
            return array(
                'result' => 'failure',
                'message' => $body['messages'][0]['dsc'],
            );
        }

        if ($body['status'] === 'success' && $body['confirmation']['response'] === "S") {
            $order->payment_complete();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
        if (isset($body['operation']['process_id']) && !empty($body['operation']['process_id'])) {
            $query = add_query_arg(
                array(
                    'process_id' => $body['operation']['process_id'],
                ),
                wc_get_page_permalink('bancard-payment')
            );

            return array(
                'result' => 'pending',
                'redirect' => $query,
            );
        }
        else {
            error_log('Error: ' . print_r($body, true));
            wc_add_notice('Error al realizar la transacción:');
            return array('result' => 'failure', 'message' => "Test 3");
        }
    }
}