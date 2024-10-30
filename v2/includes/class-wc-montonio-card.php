<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Montonio_Card extends WC_Payment_Gateway {

    /**
	 * Notices (array)
	 *
	 * @var array
	 */
    protected $admin_notices = array();

    /**
	 * Is test mode active?
	 *
	 * @var bool
	 */
    public $sandbox_mode;

    /**
	 * Display card fields in checkout?
	 *
	 * @var bool
	 */
    public $inline_checkout;

    public function __construct() {
        $this->id                 = 'wc_montonio_card';
        $this->icon               = 'https://public.montonio.com/images/logos/visa-mc-ap-gp.png';
        $this->has_fields         = false;
        $this->method_title       = __( 'Montonio Card Payments (2024)', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Allows card payments via Montonio', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'products', 
            'refunds'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get settings
        $this->title           = __( $this->get_option( 'title', __( 'Card Payment', 'montonio-for-woocommerce' ) ), 'montonio-for-woocommerce' );
        $this->description     = __( $this->get_option( 'description' ), 'montonio-for-woocommerce' );
        $this->enabled         = $this->get_option( 'enabled' );
        $this->sandbox_mode    = $this->get_option( 'sandbox_mode' );
        $this->inline_checkout = $this->get_option( 'inline_checkout' );

        if ( $this->inline_checkout === 'yes' ) {
            $this->has_fields = true;
            $this->icon = 'https://public.montonio.com/images/logos/visa-mc.png';
        }

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'validate_settings' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'get_order_response' ) );
        add_action( 'woocommerce_api_' . $this->id . '_notification', array( $this, 'get_order_notification' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'add_icon_class' ), 10, 3 );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );
    }

    /**
     * Edit gateway icon.
     */
    public function add_icon_class($icon, $id) {
        if ($id == $this->id) {
            return str_replace('src="', 'class="montonio-payment-method-icon montonio-card-icon" src="', $icon);
        }
        
        return $icon;
    }

    /**
    * Plugin options, we deal with it in Step 3 too
    */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'         => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Montonio Card Payments', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'sandbox_mode'        => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => __( 'Use the Sandbox environment for testing only.', 'montonio-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'inline_checkout' => array(
                'title'       => 'Card fields in checkout',
                'label'       => 'Enable card fields in checkout',
                'type'        => 'checkbox',
                'description' => __( 'Add card fields to the checkout instead of redirecting to the gateway. (Apple Pay and Google Pay are not supported with this flow and will be turned off)', 'montonio-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => false,
            ),
            'title'           => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Card Payment', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'      => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay with your credit or debit card via Montonio.', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true,
            ),
        );
    }

    /**
	 * Check if Montonio Card Payments should be available
	 */
    public function is_available() {
        if ( $this->enabled !== 'yes' ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::is_client_currency_supported() ) {
            return false;
        }

        if ( WC()->cart && $this->get_order_total() < 0.5 ) {
            return false;
        }
        
        return true;
    }

    /**
     * Perform validation on settings after saving them
     */
    public function validate_settings( $settings ) {
        if ( is_array( $settings ) ) {

            if ( $settings['enabled'] === 'no' ) {
                return $settings;
            }

            $api_settings = get_option( 'woocommerce_wc_montonio_api_settings' );

            // Disable the payment gateway if API keys are not provided
            if ( $settings['sandbox_mode'] === 'yes' ) {
                if ( empty( $api_settings['sandbox_access_key'] ) || empty( $api_settings['sandbox_secret_key'] ) ) {
                    $this->add_admin_notice( sprintf( __( 'Sandbox API keys missing. Montonio Card Payments was disabled. <a href="%s">Add API keys here</a>.', 'montonio-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) ), 'error' );
                    $settings['enabled'] = 'no';

                    return $settings;
                }
            } else {
                if ( empty( $api_settings['access_key'] ) || empty( $api_settings['secret_key'] ) ) {
                    $this->add_admin_notice( sprintf( __( 'Live API keys missing. Montonio Card Payments was disabled. <a href="%s">Add API keys here</a>.', 'montonio-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) ), 'error' );
                    $settings['enabled'] = 'no';
                    
                    return $settings; 
                }
            }

            try {
                $montonio_api = new WC_Montonio_API( $settings['sandbox_mode'] );
                $response = json_decode( $montonio_api->fetch_payment_methods() );

                if ( ! isset( $response->paymentMethods->cardPayments ) ) {
                    throw new Exception( __( 'Card payments method is not enabled in Montonio partner system.', 'montonio-for-woocommerce' ) );
                }
            } catch (Exception $e) {
                $settings['enabled'] = 'no';

                if ( ! empty( $e->getMessage() ) ) {
                    $this->add_admin_notice( __( 'Montonio API response: ', 'montonio-for-woocommerce' ) . $e->getMessage(), 'error' );
                    WC_Montonio_Logger::log( $e->getMessage() );
                }
            }
        }

        return $settings;       
    }

    /*
    * We're processing the payments here
    */
    public function process_payment( $order_id ) {

        $order = wc_get_order( $order_id );

        try {
            // Prepare Payment Data for Montonio Payments
            $payment_data = array(
                'paymentMethodId' => $this->id,
                'payment'         => array(
                    'method'        => 'cardPayments',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null,
                ),
            );

            if ( $this->inline_checkout === 'yes' ) {
                $intent_data = WC()->session->get( 'montonio_cardPayments_intent_data' );
    
                if ( ! empty( $intent_data ) && isset( $intent_data->uuid ) ) {
                    $payment_data['paymentIntentUuid'] = $intent_data->uuid;
                } else {
                    wc_add_notice( __( 'There was a problem processing this payment. Please refresh the page and try again.', 'montonio-for-woocommerce' ), 'error' );
                    WC_Montonio_Logger::log( 'Failure - Order ID: ' . $order_id . ' Response: paymentIntentUuid is empty. ' . $this->id );

                    return array(
                        'result' => 'failure',
                    );
                }
            }
        
            // Create new Montonio API instance
            $montonio_api = new WC_Montonio_API( $this->sandbox_mode );
            $montonio_api->order = $order;
            $montonio_api->payment_data = $payment_data;

            $response = $montonio_api->create_order();

            $order->update_meta_data( '_montonio_uuid', $response->uuid );

            if ( is_callable( array( $order, 'save' ) ) ) {
                $order->save();
            }
            
            // Return response after which redirect to Montonio Payments will happen
            return array(
                'result'   => 'success',
                'redirect' => $response->paymentUrl,
            );
        } catch ( Exception $e ) {
            wc_add_notice( __( 'There was a problem processing this payment. Please refresh the page and try again.', 'montonio-for-woocommerce' ), 'error' );

            if ( ! empty( $e->getMessage() ) ) {
                $order->add_order_note( __( 'Montonio: There was a problem processing the payment. Response: ', 'montonio-for-woocommerce' ) . $e->getMessage() );
                wc_add_notice( __( 'Montonio API response: ', 'montonio-for-woocommerce' ) . $e->getMessage(), 'error' );
                WC_Montonio_Logger::log( 'Failure - Order ID: ' . $order_id . ' Response: ' . $e->getMessage() . ' ' . $this->id );
            }
        }
    }

    public function payment_fields() {
        $description = $this->get_description();
        
        do_action( 'wc_montonio_before_payment_desc', $this->id );

        if ( $this->sandbox_mode === 'yes' ) {
            echo '<strong>' . __( 'TEST MODE ENABLED!', 'montonio-for-woocommerce' ) . '</strong><br>' . __( 'When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce' ) . '<br>';
		}

        if ( ! empty( $description ) ) {
            echo apply_filters( 'wc_montonio_description', wp_kses_post( $description ), $this->id );
        }

        if ( $this->inline_checkout === 'yes' ) {
            echo '<div id="montonio-card-form"></div>';
        }

        do_action( 'wc_montonio_after_payment_desc', $this->id );
    }

    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) {
			return;
		}
        
        if ( $this->inline_checkout === 'yes' && ! WC_Montonio_Helper::is_checkout_block() ) {
            wp_enqueue_script( 'montonio-inline-card' );

            $wc_montonio_inline_cc_params = array(
                'sandbox_mode' => $this->sandbox_mode,
                'return_url' => (string) apply_filters( 'wc_montonio_return_url', add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) ), $this->id ),
                'locale' => WC_Montonio_Helper::get_locale( apply_filters( 'wpml_current_language', get_locale() ) ),
            );

            wp_localize_script( 'montonio-inline-card', 'wc_montonio_inline_cc', $wc_montonio_inline_cc_params );
        }
    }
    
    /**
	 * Refunds amount from Montonio and return true/false as result
	 *
	 * @param string $order_id order id.
	 * @param string $amount refund amount.
	 * @param string $reason reason of refund.
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $montonio_refund = new WC_Montonio_Refund( $this->sandbox_mode );
		return $montonio_refund->init_refund($order_id, $amount, $reason );
    }
    
    /**
     * Check webhook notfications from Montonio
     */
    public function get_order_notification() {
        new WC_Montonio_Callbacks( 
            $this->sandbox_mode,
            true 
        );
    }

    /**
     * Check callback from Montonio
     * and redirect user: thankyou page for success, checkout on declined/failure
     */
    public function get_order_response() {
        new WC_Montonio_Callbacks( 
            $this->sandbox_mode,
            false 
        );
    }

    /**
     * Edit settings page layout
     */
    public function admin_options() {
        WC_Montonio_Display_Admin_Options::display_options( 
            $this->method_title, 
            $this->generate_settings_html( array(), false ),
            $this->id,
            $this->sandbox_mode
        );
    }

    /**
     * Display admin notices
     */
    public function add_admin_notice( $message, $class ) {
        $this->admin_notices[] = array( 'message' => $message, 'class' => $class );
	}

    public function display_admin_notices() {
		foreach ($this->admin_notices as $notice) {
			echo '<div id="message" class="' . esc_attr( $notice['class'] ) . '">';
			echo '	<p>' . wp_kses_post( $notice['message'] ) . '</p>';
			echo '</div>';
		}
	}
}