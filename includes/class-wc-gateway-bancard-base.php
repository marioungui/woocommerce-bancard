<?php

if (!defined('ABSPATH')) {
    exit;
}

abstract class WC_Gateway_Bancard_Base extends WC_Payment_Gateway {
    public $public_key;
    public $private_key;
    public $environment;
    public $enable_3ds;
    public $enable_preauthorization;
    public $default_installments;
    public $additional_data;
    public $enable_billing;
    public $commerce_stamp;
    public $commerce_expedition_point;
    public $commerce_establishment;
    protected $api_client;

    protected function boot_gateway(array $args) {
        $this->id = $args['id'];
        $this->icon = $args['icon'];
        $this->has_fields = !empty($args['has_fields']);
        $this->method_title = $args['method_title'];
        $this->method_description = $args['method_description'];
        $this->supports = $args['supports'];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', $this->method_title);
        $this->description = $this->get_option('description', '');
        $this->public_key = $this->get_option('public_key', '');
        $this->private_key = $this->get_option('private_key', '');
        $this->environment = $this->get_option('environment', 'staging');
        $this->enable_3ds = $this->get_option('enable_3ds', 'yes');
        $this->enable_preauthorization = $this->get_option('enable_preauthorization', 'no');
        $this->default_installments = max(1, absint($this->get_option('default_installments', 1)));
        $this->additional_data = $this->get_option('additional_data', '');
        $this->enable_billing = $this->get_option('enable_billing', 'no');
        $this->commerce_stamp = $this->get_option('commerce_stamp', '');
        $this->commerce_expedition_point = $this->get_option('commerce_expedition_point', '');
        $this->commerce_establishment = $this->get_option('commerce_establishment', '');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    protected function get_common_form_fields(array $overrides = array()) {
        $fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'woocommerce-bancard'),
                'type'    => 'checkbox',
                'label'   => __('Habilitar pasarela Bancard', 'woocommerce-bancard'),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __('Título', 'woocommerce-bancard'),
                'type'        => 'text',
                'description' => __('Texto visible para el cliente durante el checkout.', 'woocommerce-bancard'),
                'default'     => $this->method_title,
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Descripción', 'woocommerce-bancard'),
                'type'        => 'textarea',
                'description' => __('Descripción visible en el checkout.', 'woocommerce-bancard'),
                'default'     => $this->method_description,
            ),
            'public_key' => array(
                'title' => __('Llave pública', 'woocommerce-bancard'),
                'type'  => 'text',
            ),
            'private_key' => array(
                'title' => __('Llave privada', 'woocommerce-bancard'),
                'type'  => 'password',
            ),
            'environment' => array(
                'title'   => __('Servidor de pagos', 'woocommerce-bancard'),
                'type'    => 'select',
                'options' => array(
                    'production' => __('Producción', 'woocommerce-bancard'),
                    'staging'    => __('Pruebas', 'woocommerce-bancard'),
                ),
                'default' => 'staging',
            ),
            'enable_3ds' => array(
                'title'       => __('Secure3D', 'woocommerce-bancard'),
                'type'        => 'checkbox',
                'label'       => __('Intentar flujo 3DS cuando Bancard lo requiera', 'woocommerce-bancard'),
                'default'     => 'yes',
                'description' => __('En pago con token se enviará confirmation.process_id para poder levantar el iframe 3DS.', 'woocommerce-bancard'),
            ),
            'default_installments' => array(
                'title'       => __('Cuotas por defecto', 'woocommerce-bancard'),
                'type'        => 'number',
                'description' => __('Cantidad de cuotas para pagos con token. Para débito Bancard exige 1.', 'woocommerce-bancard'),
                'default'     => 1,
                'custom_attributes' => array(
                    'min'  => 1,
                    'step' => 1,
                ),
            ),
            'enable_preauthorization' => array(
                'title'       => __('Preautorización', 'woocommerce-bancard'),
                'type'        => 'checkbox',
                'label'       => __('Crear operaciones como preautorización por defecto', 'woocommerce-bancard'),
                'default'     => 'no',
                'description' => __('La orden quedará en espera hasta confirmar la preautorización.', 'woocommerce-bancard'),
            ),
            'additional_data' => array(
                'title'       => __('Additional Data', 'woocommerce-bancard'),
                'type'        => 'text',
                'description' => __('Campo opcional para promociones o convenios. También podés sobreescribirlo por filtro u order meta.', 'woocommerce-bancard'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'enable_billing' => array(
                'title'       => __('Factura electrónica', 'woocommerce-bancard'),
                'type'        => 'checkbox',
                'label'       => __('Enviar bloque billing a Bancard', 'woocommerce-bancard'),
                'default'     => 'no',
                'description' => __('Usa los datos del pedido para intentar generar factura electrónica en Bancard.', 'woocommerce-bancard'),
            ),
            'commerce_stamp' => array(
                'title' => __('Timbrado', 'woocommerce-bancard'),
                'type'  => 'text',
            ),
            'commerce_expedition_point' => array(
                'title' => __('Punto de expedición', 'woocommerce-bancard'),
                'type'  => 'text',
            ),
            'commerce_establishment' => array(
                'title' => __('Establecimiento', 'woocommerce-bancard'),
                'type'  => 'text',
            ),
        );

        return array_replace($fields, $overrides);
    }

    public function get_api_client() {
        if (!$this->api_client instanceof WC_Bancard_API) {
            $this->api_client = new WC_Bancard_API($this->public_key, $this->private_key, $this->environment);
        }

        return $this->api_client;
    }

    protected function get_amount($amount) {
        return $this->get_api_client()->format_amount($amount);
    }

    protected function is_3ds_enabled() {
        return $this->enable_3ds === 'yes';
    }

    protected function is_preauthorization_enabled() {
        return $this->enable_preauthorization === 'yes';
    }

    protected function is_billing_enabled() {
        return $this->enable_billing === 'yes';
    }

    protected function get_order_description(WC_Order $order) {
        return mb_substr(sprintf('Pedido #%s', $order->get_order_number()), 0, 20);
    }

    protected function get_order_return_url(WC_Order $order) {
        return $order->get_checkout_order_received_url();
    }

    protected function get_order_cancel_url() {
        return wc_get_cart_url();
    }

    protected function get_selected_installments(WC_Order $order) {
        $installments = absint($order->get_meta('_bancard_installments', true));
        if (!$installments && isset($_POST['bancard_installments'])) {
            $installments = absint(wp_unslash($_POST['bancard_installments']));
        }

        return max(1, $installments ?: $this->default_installments);
    }

    public function get_api_base_url() {
        return $this->get_api_client()->get_base_url();
    }

    protected function get_additional_data(WC_Order $order, $default = '') {
        $value = $order->get_meta('_bancard_additional_data', true);
        if ($value === '') {
            $value = $default !== '' ? $default : $this->additional_data;
        }

        $value = apply_filters('woocommerce_bancard_additional_data', $value, $order, $this);

        return is_string($value) ? mb_substr(trim($value), 0, 255) : '';
    }

    protected function build_billing_details(WC_Order $order) {
        $details = array();

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $quantity = max(1, (int) $item->get_quantity());
            $total = (float) $item->get_total();
            $line_amount = $quantity > 0 ? $total / $quantity : $total;
            $tax_total = (float) $item->get_total_tax();
            $rate = 0;

            if ($total > 0 && $tax_total > 0) {
                $ratio = round($tax_total / $total, 2);
                if ($ratio >= 0.09) {
                    $rate = 10;
                } elseif ($ratio >= 0.04) {
                    $rate = 5;
                }
            }

            $details[] = array(
                'description' => mb_substr(wp_strip_all_tags($item->get_name()), 0, 255),
                'amount'      => $this->get_amount($line_amount),
                'iva_rate'    => $rate,
                'total_items' => $quantity,
            );
        }

        return $details;
    }

    protected function get_order_client_ruc(WC_Order $order) {
        $candidates = array(
            $order->get_meta('_billing_ruc', true),
            $order->get_meta('billing_ruc', true),
            $order->get_meta('_billing_document', true),
            $order->get_meta('billing_document', true),
        );

        foreach ($candidates as $candidate) {
            if (!empty($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    protected function build_billing_data(WC_Order $order, $confirmation_context = false) {
        if (!$this->is_billing_enabled()) {
            return null;
        }

        $details = $this->build_billing_details($order);
        if (empty($details)) {
            return null;
        }

        $billing = array(
            'details' => $details,
        );

        if (!$confirmation_context) {
            $billing = array_merge(
                $billing,
                array(
                    'client_ruc'                => $this->get_order_client_ruc($order),
                    'client_name'               => trim($order->get_formatted_billing_full_name()),
                    'client_email'              => $order->get_billing_email(),
                    'commerce_stamp'            => $this->commerce_stamp,
                    'commerce_expedition_point' => $this->commerce_expedition_point,
                    'commerce_establishment'    => $this->commerce_establishment,
                )
            );
        }

        return apply_filters('woocommerce_bancard_billing_data', $billing, $order, $this, $confirmation_context);
    }

    protected function get_payment_page_url($process_id, $mode = 'checkout', WC_Order $order = null) {
        $payment_page = get_page_by_path('bancard-payment');
        if (!$payment_page) {
            return '';
        }

        $args = array(
            'process_id' => $process_id,
            'mode'       => $mode,
            'gateway'    => $this->id,
        );

        if ($order) {
            $args['order_id'] = $order->get_id();
        }

        return add_query_arg($args, get_permalink($payment_page->ID));
    }

    protected function get_confirmation_url() {
        return WC()->api_request_url('WC_Gateway_Bancard');
    }

    protected function store_process_meta(WC_Order $order, $process_id, $mode = 'checkout') {
        if ($process_id === '') {
            return;
        }

        $order->update_meta_data('_bancard_process_id', $process_id);
        $order->update_meta_data('_bancard_process_mode', $mode);
        $order->save();
    }

    protected function persist_confirmation_meta(WC_Order $order, array $confirmation) {
        $map = array(
            '_bancard_authorization_number'         => 'authorization_number',
            '_bancard_ticket_number'                => 'ticket_number',
            '_bancard_response'                     => 'response',
            '_bancard_response_code'                => 'response_code',
            '_bancard_response_description'         => 'response_description',
            '_bancard_extended_response_description'=> 'extended_response_description',
            '_bancard_amount'                       => 'amount',
            '_bancard_currency'                     => 'currency',
            '_bancard_response_details'             => 'response_details',
            '_bancard_token_hash'                   => 'token',
        );

        foreach ($map as $meta_key => $field) {
            if (isset($confirmation[$field])) {
                $order->update_meta_data($meta_key, $confirmation[$field]);
            }
        }

        if (!empty($confirmation['payment_card_type'])) {
            $order->update_meta_data('_bancard_payment_card_type', $confirmation['payment_card_type']);
        }

        if (!empty($confirmation['billing_response'])) {
            $order->update_meta_data('_bancard_billing_response', wp_json_encode($confirmation['billing_response']));
        }

        if (!empty($confirmation['security_information']) && is_array($confirmation['security_information'])) {
            $security = $confirmation['security_information'];
            $security_map = array(
                '_bancard_customer_ip'  => 'customer_ip',
                '_bancard_card_source'  => 'card_source',
                '_bancard_card_country' => 'card_country',
                '_bancard_api_version'  => 'version',
                '_bancard_risk_index'   => 'risk_index',
            );

            foreach ($security_map as $meta_key => $field) {
                if (isset($security[$field])) {
                    $order->update_meta_data($meta_key, $security[$field]);
                }
            }
        }

        $order->save();
    }

    protected function is_successful_confirmation(array $confirmation) {
        return isset($confirmation['response'], $confirmation['response_code'])
            && $confirmation['response'] === 'S'
            && (string) $confirmation['response_code'] === '00';
    }

    protected function get_confirmation_error(array $confirmation) {
        $message = !empty($confirmation['response_description']) ? $confirmation['response_description'] : __('Pago rechazado.', 'woocommerce-bancard');

        if (!empty($confirmation['extended_response_description'])) {
            $message .= ' - ' . $confirmation['extended_response_description'];
        }

        return $message;
    }

    protected function mark_order_as_preauthorized(WC_Order $order, array $confirmation, $context_note) {
        $this->persist_confirmation_meta($order, $confirmation);
        $order->update_meta_data('_bancard_is_preauthorization', 'yes');
        $order->save();
        $order->update_status('on-hold', $context_note);
    }

    protected function mark_order_as_paid(WC_Order $order, array $confirmation, $context_note) {
        $this->persist_confirmation_meta($order, $confirmation);

        if (!$order->is_paid()) {
            $order->payment_complete(!empty($confirmation['ticket_number']) ? $confirmation['ticket_number'] : '');
        }

        $order->add_order_note($context_note);
    }

    protected function maybe_add_notice_from_response($response, $fallback = '') {
        $message = $this->get_api_client()->parse_error_message($response, $fallback);
        wc_add_notice($message, 'error');
    }

    public function is_available() {
        if ('yes' !== $this->enabled) {
            return false;
        }

        return $this->get_api_client()->is_configured();
    }
}
