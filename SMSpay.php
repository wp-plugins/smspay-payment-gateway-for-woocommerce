<?php
/**
 *
 * @package WordPress
 * @subpackage WooCommerce
 * @author NextLogic / Frontkom
 * @copyright Frontkom
 * @since 1.0.0
 *
 * @wordpress-plugin
 * Plugin Name: SMSpay Payment Gateway for WooCommerce
 * Plugin URI:  http://smspay.io/
 * Description: Provides a gateway for WooCommerce to make payments with SMSpay.
 * Version:     1.0.0
 * Author:      NextLogic / Frontkom
 * Author URI:  https://www.frontkom.no/
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smspay
 * Domain Path: /languages
 */

global $woocommerce, $wp_version;
if ( ! defined( 'ABSPATH' ) ) {
	exit();
}
DEFINE( 'SMSPAY_DIR', plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) . '/' );
add_action( 'plugins_loaded', 'woocommerce_smspay_init', 0 );
// Localization.
load_plugin_textdomain( 'smspay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

function woocommerce_smspay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	};
	class WC_Gateway_SMSpay extends WC_Payment_Gateway {
		public function __construct() {
			/**
			 * Constructor for the gateway.
			 */
			$this->id = 'smspay';
			$this->icon = SMSPAY_DIR . 'assets/images/smspay-logo.png';
			$this->has_fields = true;
			$this->method_title = __( 'SMSpay', 'smspay' );
			$this->method_description = __( 'Allows payments by SMSpay.', 'smspay' );
			$this->supports = array(
				'products',
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_date_changes',
			);
			// Create plugin fields and settings.
			$this->payment_url = 'https://api.smspay.io/v1/payments';
			$this->merchant_login_url = 'https://api.smspay.io/v1/login';
			$this->init_form_fields();
			$this->init_settings();
			// Get setting values.
			foreach ( $this->settings as $key => $val ) {
				$this->$key = $val;
			}
			$this->logg_merchant_user();
			// Add hooks.
			// add_action( 'admin_notices', array( $this, 'smspay_ssl_check' ) );
			add_action( 'admin_notices', array( $this, 'check_login_status' ) );
			if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
			} else {
				add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
			}
			add_action( 'woocommerce_receipt_payu', array( &$this, 'receipt_page' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'add_smspay_styles' ) );
			add_action( 'woocommerce_api_wc_gateway_smspay', array( $this, 'check_response' ) );
		}

		/**
		 * Check if users is logged in or not and sends notice.
		 */
		function check_login_status() {
			if ( $this->loggedIn ) {
				echo '<div id="message" class="updated fade"><p>' . sprintf( __( 'SMSpay: Logged in.', 'smspay' ), admin_url( 'admin.php?page=woocommerce' ) ) . '</p></div>';
			} else {
				echo '<div class="error"><p>' . sprintf( __( 'SMSpay: Login failed.', 'smspay' ), admin_url( 'admin.php?page=woocommerce' ) ) . '</p></div>';
			}
		}

		/**
		 * Login as a merchant user to get id and token https://api.smspay.io/v1/
		 */
		function logg_merchant_user() {
			$merchant = array(
				'user' => $this->user,
				'password' => $this->password,
			);
			$url = $this->merchant_login_url;
			$ch = curl_init();
			$postString = '';
			foreach ( $merchant as $key => $value ) {
				$postString .= $key . '=' . $value . '&';
			}
			$postString = rtrim( $postString, '&' );
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_POST, count( $merchant ) );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $postString );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			$response = curl_exec( $ch );
			curl_close( $ch );
			$merchant_response = json_decode( $response, true );
			if ( array_key_exists( 'statusCode', $merchant_response ) ) {
				if ( $merchant_response['statusCode'] === 401 ) {
					$this->loggedIn = false;
				}
			}
			if ( array_key_exists( 'merchantId', $merchant_response ) ) {
				$this->merchantId = $merchant_response['merchantId'];
				$this->loggedIn = true;
				$this->token = $merchant_response['token'];
			}
			return $this->loggedIn;
		}

		/**
		 * SSL check.
		 */
		function smspay_ssl_check() {
			if ( get_option( 'woocommerce_force_ssl_checkout' ) === 'no' && $this->enabled === 'yes' ) {
				echo '<div class="error"><p>' . sprintf( __( 'SMSpay is enabled and the <a href="%s">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'smspay' ), admin_url( 'admin.php?page=woocommerce' ) ) . '</p></div>';
			}
		}

		/**
		 * Include jQuery and scripts.
		 */
		function add_smspay_styles() {
			wp_enqueue_style( 'smspay_style', plugins_url( 'assets/css/style.css', __FILE__ ) );
		}

		/**
		 * Initialize Gateway Settings Form Fields.
		 */
		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'smspay' ),
					'label' => __( 'Enable SMSpay', 'smspay' ),
					'type' => 'checkbox',
					'description' => 'SMSpay',
					'default' => 'no',
				),
				'title' => array(
					'title' => __( 'Title', 'smspay' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'smspay' ),
					'default' => __( 'SMSpay', 'smspay' ),
				),
				'description' => array(
					'title' => __( 'Description', 'smspay' ),
					'type' => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'smspay' ),
					'default' => 'Pay with your credit card or phone bill via SMSpay.',
				),
				'user' => array(
					'title' => __( 'Username', 'smspay' ),
					'type' => 'text',
					'description' => __( 'This is the API username provided by SMSpay.', 'smspay' ),
					'default' => '',
				),
				'password' => array(
					'title' => __( 'Password', 'smspay' ),
					'type' => 'password',
					'description' => __( 'This is the API password provided by SMSpay.', 'smspay' ),
					'default' => '',
				),
			);
		}

		/**
		 * UI - Admin Panel Options.
		 */
		function admin_options() {
			?>
            <h3><?php _e( 'SMSpay', 'smspay' ); ?></h3>
            <p><?php _e( 'SMSpay is simple and powerful. <a href="https://admin.smspay.io/register">Click here to get an account</a>.', 'smspay' ); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
		}

		/**
		 * UI - Payment page fields for SMSpay.
		 */
		function payment_fields() {
			if ( $this->description ) {
				echo wpautop( wptexturize( $this->description ) ); }
			?>
            <p class="form-row form-row-first phone-input">
                <label for="country_code"><?php echo __( 'Phone', 'smspay' ) ?> <span class="required">*</span></label>
                <select name="country_code" id="phoneprefix" class="woocommerce-select">
                    <option value="47">+47</option>
                    <option value="40">+40</option>
                    <option value="46">+46</option>
                    <option value="35">+351</option>
                </select>
                <input type="number" class="input-phone" id="phone_numb" name="phone_numb"  />
            </p>
            <?php
		}

		/**
		 * Validate Phone Number.
		 */
		public function validate_fields() {
			if ( ! $this->get_post( 'phone_numb' ) || (filter_var( $this->get_post( 'phone_numb' ), FILTER_SANITIZE_NUMBER_INT ) < 10000000) ) {
				wc_add_notice( __( 'SMSpay phone number not valid.', 'smspay' ) );
				return false;
			}
			return true;
		}

		/**
		 * Check required fields.
		 */
		public function check_needed_fields() {
			if ( ! $this->get_post( 'phone_numb' ) || (filter_var( $this->get_post( 'phone_numb' ), FILTER_SANITIZE_NUMBER_INT ) < 10000000) ) {
				return false;
			}
			return true;
		}

		/**
		 * Get a post.
		 */
		private function get_post($name) {
			if ( isset($_POST[$name]) ) {
				return filter_var( $_POST[$name], FILTER_SANITIZE_STRING );
			}
			return null;
		}

		function varDumpToString($var) {
			ob_start();
			var_dump( $var );
			return ob_get_clean();
		}

		/**
		 * Send order for payment at https://api.smspay.io/v1/
		 */
		function send_order_for_payment($smspay_request) {
			$header = array();
			$header[] = 'Authorization: Bearer ' . $this->token;
			$url = $this->payment_url;
			$ch = curl_init();
			$postString = '';
			foreach ( $smspay_request as $key => $value ) {
				$postString .= $key . '=' . $value . '&';
			}
			$postString = rtrim( $postString, '&' );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $header );
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_POST, count( $smspay_request ) );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $postString );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			$response = curl_exec( $ch );
			curl_close( $ch );
			return json_decode( $response, true );
		}

		/**
		 * Process Payment
		 */
		function process_payment($order_id) {
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$currency = strtoupper( get_woocommerce_currency() );
			if ( ! (($currency === 'EUR') || ($currency === 'SEK') || ($currency === 'DKK') || ($currency === 'NOK') || ($currency === 'USD') || ($currency === 'GBP')) ) {
				wc_add_notice( __( 'The shop currency ', 'woothemes' ) . $currency . __( " is not supported by SMSpay. The only currencies accepted are 'NOK', 'SEK', 'DKK', 'EUR', 'USD' and 'GBP' ", 'smspay' ) );
				return;
			}
			if ( ! $this->check_needed_fields() ) {
				return;
			}
			if ( ! $this->merchantId ) {
				if ( ! $this->logg_merchant_user() ) {
					if ( ! $this->logg_merchant_user() ) {
						wc_add_notice( __( 'SMSpay could not process your payment. Please try again later!', 'smspay' ) );
						$order->add_order_note( __( 'Error. Could not logg in to SMSpay.', 'smspay' ) );
						return;
					}
				}
			}
			$smspay_request = array(
				'phone' => $this->get_post( 'country_code' ) . $this->get_post( 'phone_numb' ),
				'invoice' => $order->get_order_number(),
				'currency' => get_woocommerce_currency(),
				'merchant' => $this->merchantId,
				'shipping' => intval( $order->get_total_shipping() * 100 ),
			);
			$items = $order->get_items();
			$index = 1;
			$products_names = '';
			foreach ( $items as $item ) {
				$smspay_request['item_number_' . $index] = $item['product_id'];
				$smspay_request['item_name_' . $index] = $item['name'];
				$smspay_request['amount_' . $index] = intval( ($item['line_subtotal'] / $item['qty']) * 100 );
				$smspay_request['quantity_' . $index] = $item['qty'];
				$smspay_request['shipping_' . $index] = 0 * 100;
				$index = $index + 1;
				$products_names .= $item['name'].',';
			}
			$smspay_request['description'] = trim( $products_names,',' );
			$smspay_request['success_url'] = WC()->api_request_url( 'WC_Gateway_SMSpay' );
			$smspay_request['failure_url'] = WC()->api_request_url( 'WC_Gateway_SMSpay' );
			$smspay_request['update_url'] = WC()->api_request_url( 'WC_Gateway_SMSpay' );

			$smspay_response = $this->send_order_for_payment( $smspay_request );

			if ( isset($smspay_response['statusCode']) ) {
				if ( $smspay_response['statusCode'] === 401 ) {
					if ( ! $this->logg_merchant_user() ) {
						if ( ! $this->logg_merchant_user() ) {
							wc_add_notice( __( 'SMSpay could not process your payment. Please try again later!', 'smspay' ) );
							$order->add_order_note( __( 'Error. Could not logg in to SMSpay.', 'smspay' ) );
						} else {
							$smspay_response = $this->send_order_for_payment( $smspay_request );
						}
					} else {
						$smspay_response = $this->send_order_for_payment( $smspay_request );
					}
				} else if ( $smspay_response['statusCode'] === 400 ) {
					wc_add_notice( __( 'The order seems to be invalid. Please inform the site administrator.'.$this->varDumpToString( $smspay_response ), 'smspay' ) );
					$order->add_order_note( __( 'Order error. Please check order for any error.', 'smspay' ) );
				}
			}
			if ( isset( $smspay_response['statusCode']) ) {
				return;
			} else {
				if ( array_key_exists( 'status', $smspay_response ) ) {
					switch ( strtoupper( $smspay_response['status'] ) ) {
						case 'NEW': $order->update_status( 'on-hold' );
							$order->add_order_note( __( 'Payment was created with the reference id:', 'smspay' ) . $smspay_response['reference'] );
							$order->reduce_order_stock();
							$woocommerce->cart->empty_cart();
							break;
						case 'PENDING': $order->update_status( 'pending' );
							$order->add_order_note( __( 'Waiting customer confirmation/registration', 'smspay' ) );
							$order->reduce_order_stock();
							$woocommerce->cart->empty_cart();
							break;
						case 'PROCESSING': $order->update_status( 'pending' );
							$order->add_order_note( __( 'Processing payment', 'smspay' ) );
							$order->reduce_order_stock();
							$woocommerce->cart->empty_cart();
							break;
						case 'COMPLETED': $order->update_status( 'processing', __( 'Payment complete', 'smspay' ) );
							$order->add_order_note( __( 'Payment complete!', 'smspay' ) );
							$order->payment_complete();
							$order->reduce_order_stock();
							$woocommerce->cart->empty_cart();
							break;
						case 'CANCELLED': $order->update_status( 'on-hold', __( 'Payment cancelled by cutomer or SMSpay', 'smspay' ) );
							$order->add_order_note( __( 'Payment cancelled by cutomer or SMSpay', 'smspay' ) );
							break;
					}
				}
			}
			return array(
			'result' => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// WC()->api_request_url( '' );
		public function check_response() {
			global $woocommerce;

			if ( isset($_REQUEST['invoice']) && isset($_REQUEST['reference']) ) {
				$order_id = filter_var( $_REQUEST['invoice'], FILTER_SANITIZE_NUMBER_INT );
				if ( $order_id ) {
					try {

						$order = new WC_Order( $order_id );
						$reference = filter_var( $_REQUEST['reference'], FILTER_SANITIZE_STRING );
						$amount = filter_var( $_REQUEST['amount'], FILTER_SANITIZE_NUMBER_INT );
						$shipping = filter_var( $_REQUEST['shipping'], FILTER_SANITIZE_NUMBER_INT );
						$merchant_id = filter_var( $_REQUEST['merchantId'], FILTER_SANITIZE_STRING );
						$currency = filter_var( $_REQUEST['currency'], FILTER_SANITIZE_STRING );
						$status = strtoupper( filter_var( $_REQUEST['status'], FILTER_SANITIZE_STRING ) );

						$this_currency = filter_var( get_woocommerce_currency(), FILTER_SANITIZE_STRING );
						if ( ($order->status != 'completed') &&
								($amount === filter_var( $order->get_total() * 100, FILTER_SANITIZE_NUMBER_INT )) &&
								($merchant_id === $this->merchantId) &&
								($currency === $this_currency) &&
								($shipping === $order->get_total_shipping()) ) {
							switch ( $status ) {
								case 'NEW': $order->update_status( 'on-hold' );
									$order->add_order_note( __( 'Payment was created with the reference id:', 'smspay' ) . $reference );
									header( 'HTTP/1.1 202 OK' );
									echo 'ACCEPTED';
									die();
									break;
								case 'PENDING': $order->update_status( 'pending' );
									$order->add_order_note( __( 'Waiting customer confirmation/registration', 'smspay' ) );
									$this->msg['message'] = __( 'Thank you for shopping with us. Right now your payment is waiting for your confirmation or registration.', 'smspay' );
									$this->msg['class'] = 'woocommerce_message woocommerce_message_info';
									header( 'HTTP/1.1 202 OK' );
									echo 'ACCEPTED';
									die();
									break;
								case 'PROCESSING': $order->update_status( 'pending' );
									$order->add_order_note( __( 'Processing payment', 'smspay' ) );
									$this->msg['message'] = __( 'Your payment is being processed;', 'smspay' );
									$this->msg['class'] = 'woocommerce_message woocommerce_message_info';
									header( 'HTTP/1.1 202 OK' );
									echo 'ACCEPTED';
									die();
									break;
								case 'COMPLETED': $order->update_status( 'processing' );
									$order->add_order_note( __( 'Payment complete!', 'smspay' ) );
									$order->payment_complete();
									$this->msg['message'] = __( 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.', 'smspay' );
									$this->msg['class'] = 'woocommerce_message';
									if ( $order->status === 'processing' ) {
										// Do something!?
									} else {
										$order->payment_complete();
										$order->add_order_note( __( 'SMSpay payment successful<br/>Unnique Id from PayU: ', 'smspay' ) . $reference );
									}
									header( 'HTTP/1.1 202 OK' );
									echo 'ACCEPTED';
									die();
									break;
								case 'CANCELLED': $order->update_status( 'failed' );
									$order->add_order_note( __( 'Payment cancelled by customer or SMSpay ', 'smspay' ) . filter_var( $_REQUEST['cancelReason'], FILTER_SANITIZE_STRING ) );
									$this->msg['message'] = __( 'Payment has been cancelled.', 'smspay' );
									$this->msg['class'] = 'error';
									header( 'HTTP/1.1 202 OK' );
									echo 'ACCEPTED';
									die();
									break;
							}
						} else {
							$order->add_order_note( _( 'Error. The order has been completed before confirmation or data does not correspond to payment!', 'smspay' ) );
							$this->msg['message'] = "(($order->status != 'completed') && ($amount === $order->get_total()) && ($merchant_id === $this->merchantId) && ($currency === $this_currency)
                                && ($shipping === $order->get_total_shipping()))";
							$this->msg['class'] = 'error';
						}
						add_action( 'the_content', array( &$this, 'showMessage' ) );
					} catch (Exception $ex) {
						wp_die( 'SMSpay Request Failure', 'SMSpay', array( 'response' => 400 ) );
					}
				} else {
					wp_die( 'SMSpay Request Failure', 'SMSpay', array( 'response' => 400 ) );
				}
			}
		}

		function showMessage($content) {
			return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
		}

		/**
		 * Receipt Page
		 */
		function receipt_page($order) {
			echo '<p>' . __( 'Thank you for your order.', 'smspay' ) . '</p>';
		}
	}

	function add_smspay_class($methods) {
		$methods[] = 'WC_Gateway_SMSpay';
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'add_smspay_class' );
}
?>
