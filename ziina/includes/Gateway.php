<?php
/**
 * Gateway class
 *
 * @package ZiinaPayment
 */

namespace ZiinaPayment;

use Exception;
use WC_Payment_Gateway;
use ZiinaPayment\Entities\ZiinaPayment;
use Ramsey\Uuid\Uuid;
use WP_Error;
use WC_Logger;
use ZiinaPayment\Logger\Main as ZiinaLogger;

defined( 'ABSPATH' ) || exit();

/**
 * Class Gateway
 *
 * @package ZiinaPayment
 * @since   1.0.0
 */
class Gateway extends WC_Payment_Gateway {

	/**
	 * Ziina Gateway constructor.
	 */
	public function __construct() {
		$this->id                 = ziina_payment()->plugin_id;
		$this->method_title       = __( 'Ziina Payment', 'ziina' );
		$this->method_description = __( 'Pay via Ziina Payment', 'ziina' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action('rest_api_init', array($this, 'register_webhook_handler'));
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options',
			)
		);
	}

	/**
	 * Initialise settings form fields.
	 *
	 * Add an array of fields to be displayed on the gateway's settings screen.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'       => __( 'Enable/Disable', 'ziina' ),
				'label'       => __( 'Enable Ziina Payment', 'ziina' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'ziina' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'ziina' ),
				'default'     => __( 'Credit/Debit Card, Apple Pay or Google Pay', 'ziina' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'ziina' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'ziina' ),
				'default'     => __( 'Pay with credit card, debit card, Apple Pay or Google Pay', 'ziina' ),
				'desc_tip'    => true,
			),
			'authorization_token' => array(
				'title' => __( 'API key', 'ziina' ),
				'label' => __( 'API key', 'ziina' ),
				'type'  => 'text',
			),
			'is_test'             => array(
				'title'       => __( 'Test Mode', 'ziina' ),
				'label'       => __( 'Enable Test Mode', 'ziina' ),
				'type'        => 'checkbox',
				'description' => __( 'When enabled, you can test payments on your site without charging a card.', 'ziina' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'logging'             => array(
				'title'       => __( 'Logging', 'ziina' ),
				'label'       => __( 'Log debug messages', 'ziina' ),
				'type'        => 'checkbox',
				'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'ziina' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Process Payment.
	 *
	 * Process the payment. Override this in your gateway. When implemented, this should.
	 * return the success and redirect in an array. e.g:
	 *
	 *        return array(
	 *            'result'   => 'success',
	 *            'redirect' => $this->get_return_url( $order )
	 *        );
	 *
	 * @param int $order_id Order ID.
	 *
	 * @throws Exception
	 */
	public function process_payment( $order_id ) {
		if ( isset( $_SERVER['CONTENT_TYPE'] ) && 'application/json' === $_SERVER['CONTENT_TYPE'] ) {
			try {
				$_POST = json_decode( file_get_contents( 'php://input' ), true );
			} catch ( Exception $e ) {
				throw new Exception( esc_html__( 'Request error. Try again or contact us', 'ziina' ) );
			}
		}

		$redirect_url = ziina_payment()->api()->create_payment_intent( $order_id );

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			wp_redirect( $redirect_url );
			die;
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Process refund
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount Refund amount.
	 * @param string $reason Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
    $order = wc_get_order( $order_id );
    
    if (!$order) {
			ZiinaLogger::error('Order not found', ['order_id' => $order_id]);
			return new WP_Error('invalid_order', 'Order not found');
    }

    $payment_intent_id = $order->get_meta('_ziina_payment_id');
    
    if ( empty($payment_intent_id) ) {
			ZiinaLogger::error('Order not found', ['payment_intent_id' => $payment_intent_id]);
			return new WP_Error('invalid_payment', 'Payment information not found');
    }

    try {
			$uuid = Uuid::uuid4()->toString();
			$refund = ziina_payment()->api()->create_refund([
				'id' 								=> $uuid,
				'payment_intent_id' => $payment_intent_id,
				'amount' 						=> ziina_payment()->api()->get_rounded_total($amount, $order->get_currency()),
				'currency_code'     => $order->get_currency(),
			]);

			if ($refund && in_array($refund['status'], ['pending', 'completed'])) {
				$order->add_meta_data('_ziina_refund_id', $refund['id']);
				$order->save();

				$note = sprintf(
					/* translators: 1: refund amount, 2: refund ID */
					__('Refunded %1$s via Ziina. Refund ID: %2$s', 'ziina'),
					wc_price($amount),
					$refund['id']
				);
				
				if ($reason) {
					/* translators: 1: reason */
					$note .= sprintf(__('. Reason: %s', 'ziina'), $reason);
				}
			
				$order->add_order_note($note);
					
				return true;
			}
			
			ZiinaLogger::error('Refund failed', [
				'order_id' => $order_id,
				'payment_intent_id' => $payment_intent_id,
				'message' => $refund["message"]
			]);

			return new WP_Error(
				'refund_failed',
				'Refund failed: ' . ($refund['message'] ?? 'Unknown error')
			);

    } catch (Exception $e) {
			ZiinaLogger::error('Refund error', [
				'order_id' => $order_id,
				'message' => $e->getMessage()
			]);
			return new WP_Error('refund_error', $e->getMessage());
    }
	}

	// Registers rest endpoint on plugin side to handle Webhooks from Ziina server
	public function register_webhook_handler() {
		register_rest_route('ziina-webhook', '/handler', array(
			'methods' => 'POST',
			'callback' => array($this, 'process_webhook'),
			'permission_callback' => '__return_true'
		));

		if (!$this->get_option('ziina_webhook_registered')) {
			$this->register_webhook_on_ziina_server();
		}
	}

	// Creates webhook on Ziina side so that Ziina knows where to send webhooks
	public function register_webhook_on_ziina_server() {
		try {
			$api_token = ziina_payment()->get_setting('authorization_token') ?? '';
			if (empty($api_token)) {
				return;
			}

			$webhook_url = get_rest_url(null, 'ziina-webhook/handler');	
			$response = ziina_payment()->api()->register_webhook($webhook_url);

			if (isset($response["success"]) && $response["success"] === true) {
				$this->update_option('ziina_webhook_registered', true);
				ZiinaLogger::info('Webhook registered', $response);
			} else {
				ZiinaLogger::error('Registering webhook on Ziina server was not successful', $response);
				new WP_Error('Error while registering webhook on Ziina server');
			}
		} catch ( Exception $e ) {
			ZiinaLogger::error('Error while registering webhook on Ziina server', ['message' => $e->getMessage()]);
			new WP_Error('Error while registering webhook on Ziina server', $e->getMessage());
		}
	}

	public function has_valid_signature($request) {
		$raw_body = $request->get_body();
    $signature = $request->get_header('X-Hmac-Signature');

		if (empty($signature)) {
			ZiinaLogger::warn('Invalid or missing webhook signature', ['signature' => $signature]);
			return new WP_Error('Invalid signature', 'Missing signature', ['status' => 400]);
		}

		$secret_key = ziina_payment()->get_setting('authorization_token') ?? '';

		$calculated_signature = hash_hmac(
			'sha256',
			$raw_body,
			$secret_key,
			false
		);

		return hash_equals($signature, $calculated_signature);
	}

	public function process_webhook($request) {
		if (!$this->has_valid_signature($request)) {
			ZiinaLogger::warn('Hash is invalid or missing webhook signature', ['request' => $request]);
			return new WP_Error('Invalid signature', 'Missing signature', ['status' => 400]);
		}

		$body = $request->get_json_params();

		try {
			$event = $body['event'];
			$data = $body['data'];

			if ($event === "payment_intent.status.updated" && $data["status"] === "completed") {
				$payment_id = $data["id"];
				$order = ZiinaPayment::by_payment_id( $data["id"] )->order();
				$order->payment_complete();
			}
		} catch ( Exception $e ) {
			ZiinaLogger::error('Webhook processing error', ['message' => $e->getMessage()]);
			return new WP_Error('Webhook processing error', $e->getMessage());
		}
	}
}
