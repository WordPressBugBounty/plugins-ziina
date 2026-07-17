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
use Throwable;
use WP_REST_Response;
use WC_Logger;
use ZiinaPayment\Logger\Main as ZiinaLogger;
use ZiinaPayment\Admin\OrderDetails;
use ZiinaPayment\ApplePay\ApplePayDomainVerificationHandler;

defined( 'ABSPATH' ) || exit();

/**
 * Class Gateway
 *
 * @package ZiinaPayment
 * @since   1.0.0
 */
class Gateway extends WC_Payment_Gateway {

	const CHECKOUT_MODE_REDIRECT   = 'redirect';
	const CHECKOUT_MODE_EMBEDDED   = 'embedded';
	const EMBEDDED_WIDGET_VERSION  = 'latest';
	const EMBEDDED_WIDGET_HEIGHT   = 740;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
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
			'checkout_mode'       => array(
				'title'       => __( 'Checkout mode', 'ziina' ),
				'type'        => 'select',
				'description' => __( 'Embedded keeps customers on your site to pay on the order payment page. Requires domain setup — see Domain verification below.', 'ziina' ),
				'default'     => self::CHECKOUT_MODE_REDIRECT,
				'options'     => array(
					self::CHECKOUT_MODE_REDIRECT => __( 'Redirect', 'ziina' ),
					self::CHECKOUT_MODE_EMBEDDED => __( 'Embedded', 'ziina' ),
				),
				'desc_tip'    => true,
			),
			'embedded_locale'     => array(
				'title'       => __( 'Embedded widget locale', 'ziina' ),
				'type'        => 'ziina_embedded_locale',
				'description' => __( 'Used when checkout mode is Embedded.', 'ziina' ),
				'default'     => '',
				'options'     => array(
					''   => __( 'English', 'ziina' ),
					'ar' => __( 'Arabic', 'ziina' ),
				),
				'desc_tip'    => true,
			),
			'embedded_prerequisites' => array(
				'title' => '',
				'type'  => 'apple_domain_setup',
			),
		);
	}

	/**
	 * Output gateway settings and Apple Pay panel outside the form table.
	 */
	public function admin_options() {
		$prerequisites_field = $this->form_fields['embedded_prerequisites'] ?? null;
		unset( $this->form_fields['embedded_prerequisites'] );

		if ( $this->get_method_title() ) {
			echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
		}

		if ( $this->get_method_description() ) {
			echo wp_kses_post( wpautop( $this->get_method_description() ) );
		}

		echo '<table class="form-table">';
		$this->generate_settings_html();

		if ( $prerequisites_field ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderer.
			echo $this->generate_apple_domain_setup_html( 'embedded_prerequisites', $prerequisites_field );
			$this->form_fields['embedded_prerequisites'] = $prerequisites_field;
		}

		echo '</table>';
	}

	/**
	 * Render embedded locale select, hidden unless embedded checkout is active.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 *
	 * @return string
	 */
	public function generate_ziina_embedded_locale_html( $key, $data ) {
		$html      = $this->generate_select_html( $key, $data );
		$row_style = $this->is_embedded_checkout() ? '' : 'display: none;';

		return preg_replace(
			'/<tr([^>]*)>/',
			'<tr$1 class="ziina-embedded-only-row" style="' . esc_attr( $row_style ) . '">',
			$html,
			1
		);
	}

	/**
	 * Render Apple Pay domain setup field on gateway settings.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 *
	 * @return string
	 */
	public function generate_apple_domain_setup_html( $key, $data ) {
		$field_key   = $this->get_field_key( $key );
		$apple       = ziina_payment()->init_apple_pay_domain_verification();
		$host        = wp_parse_url( home_url(), PHP_URL_HOST );
		$docs_url    = 'https://docs.ziina.com/developers/embedded-checkout';
		$woocommerce_docs_url = 'https://docs.ziina.com/business/woocommerce';
		$support_url = 'https://ziina.com/contact';
		$public_url  = $apple->get_public_url();
		$setup_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . ApplePayDomainVerificationHandler::ADMIN_ACTION ),
			ApplePayDomainVerificationHandler::ADMIN_ACTION
		);
		$error_msg   = isset( $_GET['ziina_apple_domain_msg'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['ziina_apple_domain_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$row_style   = $this->is_embedded_checkout() ? '' : 'display: none;';

		ob_start();
		?>
		<tr valign="top" class="ziina-apple-pay-embedded-row" style="<?php echo esc_attr( $row_style ); ?>">
			<td colspan="2" class="ziina-apple-pay-embedded-cell">
				<div
					id="<?php echo esc_attr( $field_key ); ?>"
					class="ziina-apple-pay-panel"
				>
					<div class="ziina-apple-pay-embedded">
				<h3 class="ziina-apple-pay-embedded__title"><?php esc_html_e( 'Domain verification', 'ziina' ); ?></h3>
					<p class="ziina-apple-pay-embedded__intro">
						<?php esc_html_e( 'Embedded checkout requires domain verification. Follow the steps below.', 'ziina' ); ?>
					</p>

					<?php if ( isset( $_GET['ziina_apple_domain_success'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
						<div class="notice notice-success inline ziina-apple-pay-notice">
							<p>
								<?php esc_html_e( 'Domain verification file has been set up on your site.', 'ziina' ); ?>
								<?php
								printf(
									/* translators: %s: store hostname */
									esc_html__( 'Next step: contact Ziina support to whitelist your domain (%s).', 'ziina' ),
									esc_html( is_string( $host ) ? $host : '' )
								);
								?>
							</p>
							<p>
								<?php
								printf(
									wp_kses(
										/* translators: %s: public well-known URL */
										__( 'Verify: open %s in your browser.', 'ziina' ),
										array(
											'code' => array(),
											'a'    => array(
												'href' => array(),
											),
										)
									),
									'<a href="' . esc_url( $public_url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $public_url ) . '</code></a>'
								);
								?>
							</p>
						</div>
					<?php endif; ?>

					<?php if ( isset( $_GET['ziina_apple_domain_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
						<div class="notice notice-error inline ziina-apple-pay-notice">
							<p>
								<?php
								if ( '' !== $error_msg ) {
									echo esc_html( $error_msg );
								} else {
									esc_html_e( 'Could not set up the Apple Pay domain verification file. Please try again.', 'ziina' );
								}
								?>
							</p>
						</div>
					<?php endif; ?>

					<div class="ziina-apple-pay-steps">
						<ol class="ziina-apple-pay-steps__list">
							<li class="ziina-apple-pay-steps__item">
								<div class="ziina-apple-pay-steps__content">
									<span class="ziina-apple-pay-steps__label"><?php esc_html_e( 'Set up domain verification by clicking the button below', 'ziina' ); ?></span>
									<div class="ziina-apple-pay-steps__action">
										<a href="<?php echo esc_url( $setup_url ); ?>" class="button button-secondary ziina-apple-pay-steps__button ziina-navigate-without-warning">
											<span class="dashicons dashicons-download" aria-hidden="true"></span>
											<?php esc_html_e( 'Set up domain verification', 'ziina' ); ?>
										</a>
									</div>
									<?php if ( $apple->has_stored_file() ) : ?>
										<p class="ziina-apple-pay-steps__status description">
											<?php
											if ( $apple->physical_file_exists() ) {
												esc_html_e( 'Domain verification file: present', 'ziina' );
											} else {
												esc_html_e( 'Domain verification file: missing — click Set up again. If it still fails, your site may not have permission to save files.', 'ziina' );
											}
											?>
										</p>
									<?php endif; ?>
								</div>
							</li>
							<li class="ziina-apple-pay-steps__item">
								<div class="ziina-apple-pay-steps__content">
									<span class="ziina-apple-pay-steps__label"><?php esc_html_e( 'Contact support to verify your domain', 'ziina' ); ?></span>
									<div class="ziina-apple-pay-steps__action">
										<a href="<?php echo esc_url( $support_url ); ?>" class="button button-secondary ziina-apple-pay-steps__button" target="_blank" rel="noopener">
											<span class="dashicons dashicons-email" aria-hidden="true"></span>
											<?php esc_html_e( 'Contact support', 'ziina' ); ?>
										</a>
									</div>
								</div>
							</li>
							<li class="ziina-apple-pay-steps__item">
								<div class="ziina-apple-pay-steps__content">
									<p class="ziina-apple-pay-steps__instruction">
										<?php esc_html_e( 'Once your domain is verified, select "Embedded" checkout mode and click "save changes" button below.', 'ziina' ); ?>
									</p>
								</div>
							</li>
						</ol>
					</div>

					<p class="ziina-apple-pay-embedded__docs description">
						<?php
							printf(
								wp_kses(
										/* translators: %1$s: embedded checkout docs URL, %2$s: WooCommerce docs URL */
										__( 'See the <a href="%1$s" target="_blank" rel="noopener">embedded checkout documentation</a> and <a href="%2$s" target="_blank" rel="noopener">WooCommerce setup guide</a> for details.', 'ziina' ),
										array(
												'a' => array(
														'href'   => array(),
														'target' => array(),
														'rel'    => array(),
												),
										)
								),
								esc_url( $docs_url ),
								esc_url( $woocommerce_docs_url )
							);
						?>
					</p>
					</div>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue admin assets on the Ziina gateway settings screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
    $is_wc_settings_page = 'woocommerce_page_wc-settings' === $hook_suffix;
    if ( ! $is_wc_settings_page ) {
			return;
    }

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $settings_tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    $gateway_section  = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
    $is_ziina_gateway_settings = $settings_tab === 'checkout' && $this->id === $gateway_section;
    if ( ! $is_ziina_gateway_settings ) {
			return;
    }

		$css_path = ziina_payment()->plugin_path . 'assets/css/admin-settings.css';
		$js_path  = ziina_payment()->plugin_path . 'assets/js/admin-settings.js';

		wp_enqueue_style(
			'ziina-admin-settings',
			ziina_payment()->assets_url . 'css/admin-settings.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : ziina_payment()->version
		);

		wp_enqueue_script(
			'ziina-admin-settings',
			ziina_payment()->assets_url . 'js/admin-settings.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : ziina_payment()->version,
			true
		);
	}

	/**
	 * Whether embedded checkout mode is enabled.
	 */
	public function is_embedded_checkout(): bool {
		return self::CHECKOUT_MODE_EMBEDDED === $this->get_option( 'checkout_mode', self::CHECKOUT_MODE_REDIRECT );
	}

	/**
	 * Build iframe src URL from stored embedded_url and gateway settings.
	 *
	 * @param string $embedded_url URL from payment intent API.
	 */
	public function build_embedded_iframe_src( string $embedded_url ): string {
		$version = self::EMBEDDED_WIDGET_VERSION;
		$locale  = $this->get_option( 'embedded_locale', '' );

		$args = array(
			'version' => $version,
		);

		if ( ! empty( $locale ) ) {
			$args['locale'] = $locale;
		}

		return add_query_arg( $args, $embedded_url );
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

		$payment_intent = ziina_payment()->api()->create_payment_intent( $order_id );
		$order = wc_get_order( $order_id );
		$order->set_transaction_id( $payment_intent['id'] );
		$order->save();

		if ( $this->is_embedded_checkout() ) {
			return $this->process_payment_embedded( $order, $payment_intent );
		}

		$redirect_url = $payment_intent['redirect_url'];

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
	 * Embedded checkout: send customer to order-pay page (embedded URL loaded via payment intent).
	 *
	 * @param \WC_Order $order          Order.
	 * @param array     $payment_intent Payment intent API response.
	 *
	 * @return array
	 * @throws Exception
	 */
	private function process_payment_embedded( $order, array $payment_intent ): array {
		$embedded_url = $payment_intent['embedded_url'];

		ZiinaLogger::info(
			'Embedded payment intent created',
			array(
				'order_id'          => $order->get_id(),
				'payment_intent_id' => $payment_intent['id'] ?? null,
				'embedded_url'      => $embedded_url,
			)
		);

		$pay_url = $order->get_checkout_payment_url();

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			wp_safe_redirect( $pay_url );
			die;
		}

		return array(
			'result'   => 'success',
			'redirect' => $pay_url,
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

		if (floatval($amount) <= 0) {
			return new WP_Error('invalid_amount', 'Invalid refund amount. Please set the value');
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
			$webhook_url = get_rest_url(null, 'ziina-webhook/handler');

			// if token is empty or webhook url is localhost request won't succeed
			if (empty($api_token) || strpos($webhook_url, 'http://localhost') === 0) {
				return;
			}

			$response = ziina_payment()->api()->register_webhook($webhook_url);

			if (isset($response["success"]) && $response["success"] === true) {
				$this->update_option('ziina_webhook_registered', true);
				ZiinaLogger::info('Webhook registered', $response);
			} else {
				ZiinaLogger::error('Registering webhook on Ziina server was not successful', $response);
				return new WP_Error('Error while registering webhook on Ziina server');
			}
		} catch ( Throwable $e ) {
			ZiinaLogger::error('Error while registering webhook on Ziina server', ['message' => $e->getMessage()]);
			return new WP_Error('Error while registering webhook on Ziina server', $e->getMessage());
		}
	}

	public function has_valid_signature($request) {
		$raw_body = $request->get_body();
		$signature = $request->get_header('X-Hmac-Signature');

		if (empty($signature)) {
			ZiinaLogger::warn('Invalid or missing webhook signature', ['signature' => $signature]);
			return false;
		}

		$secret_key = ziina_payment()->get_setting('authorization_token') ?? '';

		if (empty($secret_key)) {
			ZiinaLogger::warn('Webhook signature check failed: empty authorization token');
			return false;
		}

		$calculated_signature = hash_hmac(
			'sha256',
			$raw_body,
			$secret_key,
			false
		);

		return hash_equals($signature, $calculated_signature);
	}

	public function process_webhook($request) {
		if (true !== $this->has_valid_signature($request)) {
			ZiinaLogger::warn('Hash is invalid or missing webhook signature', ['request' => $request]);
			return new WP_Error('Invalid signature', 'Missing or invalid signature', ['status' => 400]);
		}

		$body = $request->get_json_params();

		try {
			$event = $body['event'];
			$data = $body['data'];

			if ($event === "payment_intent.status.updated" && $data["status"] === "completed") {
				$payment_id = $data["id"];
				$order = ZiinaPayment::by_payment_id( $data["id"] )->order();

				if (!$order) {
					ZiinaLogger::error('Order not found', ['data' => $data]);
					return;
				}

				$order_id = $order->get_id();
				$payment_completed_result = ZiinaPayment::maybe_complete_payment($order);

				if ($payment_completed_result) {
					ZiinaLogger::info("Payment completed. Order $order_id status updated by webhook", $data);
					OrderDetails::save_payment_details_to_order($order, $data);
				}
			}

			return new WP_REST_Response(['message' => 'Webhook processed successfully'], 200);
		} catch ( Exception $e ) {
			ZiinaLogger::error('Webhook processing error', ['message' => $e->getMessage()]);
			return new WP_Error('Webhook processing error', $e->getMessage());
		}
	}
}
