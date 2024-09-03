<?php
class WC_Gateway_Bancard_Tokens extends WC_Gateway_Bancard {

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
            <script src="<?= $endpoint; ?>/checkout/javascript/dist/bancard-checkout-4.0.0.js"></script>
            <div id="bancard-payment-form">
                <p>Cargando el formulario de registro de tarjetas...</p>
            </div>
            <script>
                Bancard.Cards.createForm('bancard-payment-form', '<?= $process_id; ?>');
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
        $cards = get_user_meta($user_id, '_bancard_card_ids', true);
        if (!$cards || !is_array($cards)) {
            $cards = array();
        }
        return $cards;
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
        // Obtener el ID del cliente
        $user_id = get_current_user_id();
    
        if (!$user_id) {
            wc_add_notice('Debe estar logueado para ver los métodos de pago.', 'error');
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
    
        if (is_wp_error($response)) {
            wc_add_notice('Error al comunicarse con Bancard: ' . $response->get_error_message(), 'error');
            return;
        }
    
        // Procesar la respuesta
        $body = json_decode(wp_remote_retrieve_body($response), true);
    
        if (!empty($body['cards'])) {
            echo '<table>';
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
                    'card_id' => $card['card_id'], // Asegúrate de que 'card_id' esté disponible en la respuesta de la API
                    'user_id' => $user_id,
                    'card_token' => $card['alias_token'],
                ), wc_get_endpoint_url('delete-payment-method'));
    
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
        } else {
            echo '<p>No tienes métodos de pago guardados.</p>';
        }
    }    
    

    public function delete_payment_method($card_token) {
        // Obtener el ID del cliente
        $user_id = get_current_user_id();

        $endpoint = $this->environment == 'production' ? 'https://vpos.infonet.com.py/card/tokenization/delete' : 'https://vpos.infonet.com.py:8888/card/tokenization/delete';
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
            if (($key = array_search($token_id, array_column($tokens, 'token'))) !== false) {
                unset($tokens[$key]);
                update_user_meta($user_id, '_bancard_payment_tokens', array_values($tokens));
                wc_add_notice('Método de pago eliminado exitosamente.', 'success');
                wp_safe_redirect(wc_get_page_permalink('myaccount'));
                return;
            }
        } else {
            wc_add_notice('Error al eliminar el método de pago: ' . $body['message'], 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            return;
        }
    }
}