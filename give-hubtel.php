<?php
/**
 * Plugin Name: Give Hubtel Gateway
 * Plugin URI: https://ihostghana.com
 * Description: Give plugin for the Ghanaian Hubtel payment gateway
 * Author: iHostGhana
 * Author URI: https://ihostghana.com
 * Version: 1.0.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'GiveHubtel' ) ) {
    final class GiveHubtel {
        protected static $_instance;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        public function __construct() {
            $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
            if (!in_array('give/give.php', $active_plugins)) {
                add_action( 'admin_notices', array( $this, 'give_is_required_notice' ) );
                return;
            }

            register_activation_hook( __FILE__, [$this, 'on_activation']);

            $this->setup_constants();
            $this->includes();
            $this->init_hooks();
        }

        private function init_hooks() {
            add_action('init', [$this, 'init']);
            add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
            add_action('give_init', [$this, 'on_give_init']);
            add_action('parse_query', [$this, 'on_query_parsed']);

            add_filter('query_vars', [$this, 'alter_query_vars']);
        }

        public function on_activation() {
            $this->rewriteUrls();
            flush_rewrite_rules();
        }

        public function init() {
            $this->rewriteUrls();
        }

        public function rewriteUrls() {
            //URL rewrite for Hubtel callback
            add_rewrite_rule('^give-hubtel/([^/]*)$', 'index.php?give-hubtel-action=$matches[1]', 'top');
        }

        public function on_plugins_loaded() {
        }

        public function on_give_init() {
            add_filter('give_payment_gateways', [$this, 'add_hubtel_as_a_gateway']);
            add_filter('give_registered_settings', [$this, 'add_hubtel_settings_fields']);

            if (is_admin()) {
                $current_section = give_get_current_setting_section();
                if ($current_section == 'hubtel') {
                    add_filter('give_get_settings_gateways', [$this, 'add_hubtel_settings_form']);
                }
            }

            add_action('give_hubtel_cc_form', [$this, 'show_hubtel_credit_card_form']);
            add_action('give_gateway_hubtel', [$this, 'process_payment_form']);
            add_filter('give_payment_confirm_hubtel', [$this, 'payment_success_page_content']);
        }

        public function alter_query_vars($vars) {
            $vars[] = 'give-hubtel-action';
            return $vars;
        }

        public function on_query_parsed($content) {
            if (get_query_var('give-hubtel-action') == 'callback') {
                $this->process_hubtel_callback();
                exit;
            }
        }

        public function show_hubtel_credit_card_form() {
            return false;
        }

        public function add_hubtel_as_a_gateway($gateways) {
            $gateways['hubtel'] = [
                'admin_label' => 'Hubtel',
                'checkout_label' => 'Bank Card / Mobile Money (Hubtel)',
            ];
            return $gateways;
        }

        public function add_hubtel_settings_fields($settings) {
            $settings['gateways']['fields'][] = [
                'name' => 'Hubtel',
                'desc' => '',
                'type' => 'give_title',
                'id'   => 'give_title_gateway_settings_hubtel',
            ];

            $settings['gateways']['fields'][] = [
                'name' => 'Api ID',
                'desc' => '',
                'id'   => 'hubtel_api_id',
                'type' => 'text',
            ];

            $settings['gateways']['fields'][] = [
                'name' => 'Api Key',
                'desc' => '',
                'id'   => 'hubtel_api_key',
                'type' => 'text',
            ];

            $settings['gateways']['fields'][] = [
                'name' => 'Account ID',
                'desc' => '',
                'id'   => 'hubtel_account_id',
                'type' => 'text',
            ];

            $settings['gateways']['fields'][] = [
                'name' => 'Logo URL',
                'desc' => '',
                'id'   => 'hubtel_logo_url',
                'type' => 'text',
            ];

            return $settings;
        }

        public function add_hubtel_settings_form($settings) {
            $settings[] = [
                'name' => 'Hubtel',
                'desc' => '',
                'type' => 'give_title',
                'id'   => 'give_title_gateway_settings_hubtel',
            ];

            $settings[] = [
                'name' => 'Api ID',
                'desc' => '',
                'id'   => 'hubtel_api_id',
                'type' => 'text',
            ];

            $settings[] = [
                'name' => 'Api Key',
                'desc' => '',
                'id'   => 'hubtel_api_key',
                'type' => 'text',
            ];

            $settings[] = [
                'name' => 'Account ID',
                'desc' => '',
                'id'   => 'hubtel_account_id',
                'type' => 'text',
            ];

            $settings[] = [
                'name' => 'Logo URL',
                'desc' => '',
                'id'   => 'hubtel_logo_url',
                'type' => 'text',
            ];

            return $settings;
        }

        private function setup_constants() {
        }

        private function includes() {
        }

        public function give_is_required_notice() {
            if ( ! is_admin() ) {
                return;
            }

            $notice_desc  = '<p><strong>Give Donation Plugin is required</strong>. Kindly install and activate the Give donation plugin and try again</p>';
            echo sprintf('<div class="notice notice-error">%1$s</div>', wp_kses_post( $notice_desc));
        }

        public function process_payment_form($data) {
            give_validate_nonce( $data['gateway_nonce'], 'give-gateway' );

            add_filter('give_create_payment', [$this, 'before_create_payment']);

            $payment_id = give_create_payment($data);

            if (empty($payment_id)) {
                give_record_gateway_error('Payment Error', sprintf('Payment creation failed before sending donor to Hubtel. Payment data: %s', json_encode( $data ) ), $payment_id);
            }

            $settings = give_get_settings();

            $invoice_data = [];
            $invoice_data['items'][] = ['name' => 'Donation', 'quantity' => 1, 'unitPrice' => $data['price']];
            $invoice_data['totalAmount'] = $data['price'];
            $invoice_data['description'] = 'Donation by ' . $data['user_info']['first_name'] . ' ' . $data['user_info']['last_name'] . ' ('. $data['user_info']['email'] .')';
            $invoice_data['callbackUrl'] = get_site_url(null, 'give-hubtel/callback');
            $invoice_data['returnUrl'] = add_query_arg(['payment-confirmation' => 'hubtel', 'payment-id' => $payment_id], get_permalink(give_get_option('success_page')));
            $invoice_data['cancellationUrl'] = give_get_failed_transaction_uri('?payment-id=' . $payment_id);
            $invoice_data['merchantBusinessLogoUrl'] = $settings['hubtel_logo_url'];
            $invoice_data['merchantAccountNumber'] = $settings['hubtel_account_id'];
            $invoice_data['clientReference'] = 'dn' . $payment_id;

            $hubtel_create_invoice = $this->make_hubtel_request('onlinecheckout/items/initiate', 'POST', $invoice_data);

            if ($hubtel_create_invoice['status'] == 'success') {
                $response = $hubtel_create_invoice['response'];
                if (isset($response['status']) && $response['status'] == 'Success') {
                    wp_redirect($response['data']['checkoutUrl']);
                    exit;
                }
            }

            give_send_back_to_checkout('?payment-mode=' . $data['post_data']['give-gateway']);
            exit;
        }

        public function before_create_payment($data) {
            $data['gateway'] = 'hubtel';
            return $data;
        }

        public function make_hubtel_request($path, $method = 'GET', $data = [], $options = []) {
            $settings = give_get_settings();

            $apiId = $settings['hubtel_api_id'];

            $url = 'https://api.hubtel.com/v2/pos/' . $path;
            $options['method'] = $method;
            $options['sslverify'] = false;
            $options['timeout'] = 30;
            $options['headers']['Content-Type'] = 'application/json';
            $options['headers']['Authorization'] = 'Basic ' . base64_encode($settings['hubtel_api_id'] . ':' . $settings['hubtel_api_key']);
            if ($data) {
                $options['body'] = json_encode($data);
            }

            $response = wp_remote_request($url, $options);
            $out = ['raw' => $response];
            if (is_wp_error($response)){
                $out['status'] = 'error';
                $out['error'] = $response->get_error_message();
            }
            else {
                $out['status'] = 'success';
                $out['response'] = json_decode(wp_remote_retrieve_body($response), true);
            }
            return $out;
        }

        public function process_hubtel_callback() {
            if (get_query_var('give-hubtel-action') == 'callback') {
                $callback_data = json_decode(@file_get_contents('php://input'), true);
                if ($callback_data) {
                    $payment_id = @substr($callback_data['Data']['ClientReference'], 2);
                    if ($payment_id) {
                        give_insert_payment_note($payment_id, json_encode($callback_data['Data']));
                        if ($callback_data['Status'] == 'Success' && $callback_data['ResponseCode'] == '0000') {
                            if (get_post_status($payment_id) !== 'publish') {
                                give_set_payment_transaction_id($payment_id, $callback_data['Data']['CheckoutId']);
                                give_update_payment_status($payment_id, 'publish');
                            }
                        }
                    }
                }
                exit;
            }
        }

        public function payment_success_page_content($content) {
            if ( ! isset( $_GET['payment-id'] ) && ! give_get_purchase_session() ) {
                return $content;
            }

            $payment_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : false;

            if ( ! $payment_id ) {
                $session = give_get_purchase_session();
                $payment_id = give_get_donation_id_by_key( $session['purchase_key'] );
            }

            $payment = get_post( $payment_id );
            if ($payment && 'pending' === $payment->post_status) {
                add_filter('give_get_success_page_uri', [$this, 'alter_success_page_url_for_pending_payment']);

                ob_start();
                give_get_template_part('payment', 'processing');
                $content = ob_get_clean();
            }

            return $content;
        }

        public function alter_success_page_url_for_pending_payment($url) {
            $url = $url . '?' . $_SERVER['QUERY_STRING'];
            return $url;
        }
    }
}

function GiveHubtel() {
    return GiveHubtel::instance();
}

GiveHubtel();
