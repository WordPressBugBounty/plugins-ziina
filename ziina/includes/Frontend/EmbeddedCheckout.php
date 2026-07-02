<?php
/**
 * Embedded checkout on order-pay page.
 *
 * @package ZiinaPayment\Frontend
 */

namespace ZiinaPayment\Frontend;

use Exception;
use WC_Order;
use ZiinaPayment\Ajax\Payment;
use ZiinaPayment\Entities\ZiinaPayment;
use ZiinaPayment\Gateway;
use ZiinaPayment\Logger\Main as ZiinaLogger;

defined( 'ABSPATH' ) || exit();

/**
 * Renders Ziina embedded iframe on the WooCommerce order payment page.
 */
class EmbeddedCheckout {

	/**
	 * Whether the pay page layout wrapper is open and needs closing on shutdown.
	 *
	 * @var bool
	 */
	private static $layout_open = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect_paid_order' ), 5 );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'before_woocommerce_pay_form', array( $this, 'open_pay_layout' ), 1 );
		add_action( 'before_woocommerce_pay_form', array( $this, 'open_order_column' ), 2 );
		add_action( 'woocommerce_pay_order_before_payment', array( $this, 'close_order_column_and_open_widget' ), 10 );
		add_action( 'woocommerce_pay_order_before_submit', array( $this, 'hide_pay_button' ), 5 );
	}

	/**
	 * @return Gateway|null
	 */
	private function gateway(): ?Gateway {
		$gateway = ziina_payment()->gateway();

		if ( $gateway instanceof Gateway && $gateway->is_embedded_checkout() ) {
			return $gateway;
		}

		return null;
	}

	/**
	 * @return WC_Order|null
	 */
	private function get_pay_page_order(): ?WC_Order {
		if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
			return null;
		}

		global $wp;
		$order_id = absint( $wp->query_vars['order-pay'] ?? 0 );

		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== ziina_payment()->plugin_id ) {
			return null;
		}

		return $order;
	}

	/**
	 * Whether embedded UI should show for the current pay page request.
	 */
	public function should_show_embedded_checkout( ?WC_Order $order = null ): bool {
		if ( ! $this->gateway() ) {
			return false;
		}

		$order = $order ?? $this->get_pay_page_order();

		if ( ! $order ) {
			return false;
		}

		if ( ! $order->needs_payment() ) {
			return false;
		}

		return ! empty( ZiinaPayment::by_order( $order )->payment_id() );
	}

	/**
	 * Redirect to thank-you if order is already paid.
	 */
	public function maybe_redirect_paid_order(): void {
		$order = $this->get_pay_page_order();

		if ( ! $order || $this->should_show_embedded_checkout( $order ) ) {
			return;
		}

		if ( ! $this->gateway() ) {
			return;
		}

		if ( ! $order->needs_payment() && $order->has_status( array( 'processing', 'completed' ) ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
	}

	/**
	 * @param array $classes Body classes.
	 * @return array
	 */
	public function add_body_class( array $classes ): array {
		if ( $this->should_show_embedded_checkout() ) {
			$classes[] = 'ziina-embedded-pay';
		}

		return $classes;
	}

	/**
	 * Enqueue scripts on order-pay when embedded checkout is active.
	 */
	public function enqueue_assets(): void {
		$order = $this->get_pay_page_order();

		if ( ! $this->should_show_embedded_checkout( $order ) ) {
			return;
		}

		wp_enqueue_style(
			'ziina-embedded-checkout',
			ziina_payment()->assets_url . 'css/embedded-checkout.css',
			array(),
			ziina_payment()->version
		);

		wp_enqueue_script(
			'ziina-embedded-checkout',
			ziina_payment()->assets_url . 'js/embedded-checkout.js',
			array(),
			ziina_payment()->version,
			true
		);

		wp_localize_script(
			'ziina-embedded-checkout',
			'ziinaEmbeddedCheckout',
			array(
				'successUrl'          => Payment::get_action_url(
					'success_url',
					array( 'order_id' => $order->get_id() )
				),
				'thankYouUrl'         => $order->get_checkout_order_received_url(),
				'checkoutUrl'         => wc_get_checkout_url(),
				'orderPaid'           => ! $order->needs_payment(),
				'i18n'                => array(
					'paymentFailed'  => __( 'Payment failed. Please try again or choose another payment method.', 'ziina' ),
					'paymentCanceled' => __( 'Payment was canceled. You can try again below.', 'ziina' ),
				),
			)
		);
	}

	/**
	 * Hide default pay button; payment happens in the iframe.
	 */
	public function hide_pay_button(): void {
		if ( ! $this->should_show_embedded_checkout() ) {
			return;
		}

		echo '<style>#place_order,.woocommerce-pay #place_order{display:none!important;}</style>';
	}

	/**
	 * Open pay page grid layout.
	 */
	public function open_pay_layout(): void {
		if ( ! $this->should_show_embedded_checkout() ) {
			return;
		}

		self::$layout_open = true;
		add_action( 'shutdown', array( $this, 'close_pay_layout_on_shutdown' ), 5 );

		echo '<div class="ziina-embedded-pay-layout">';
	}

	/**
	 * Open left column wrapper and order table block.
	 */
	public function open_order_column(): void {
		if ( ! $this->should_show_embedded_checkout() ) {
			return;
		}

		echo '<div class="ziina-embedded-pay-layout__order_summary"><div class="ziina-embedded-pay-layout__order">';
	}

	/**
	 * Close order/left columns and render widget in the right column.
	 *
	 * @param WC_Order $order Order.
	 */
	public function close_order_column_and_open_widget( $order ): void {
		if ( ! $this->should_show_embedded_checkout( $order instanceof WC_Order ? $order : null ) ) {
			return;
		}

		echo '</div></div><div class="ziina-embedded-pay-layout__widget">';
		$this->render_embedded_checkout( $order );
		echo '</div>';
	}

	/**
	 * Close layout wrapper after the full pay form markup is rendered.
	 */
	public function close_pay_layout_on_shutdown(): void {
		if ( ! self::$layout_open ) {
			return;
		}

		echo '</div><!-- ziina-embedded-pay-layout -->';
		self::$layout_open = false;
	}

	/**
	 * Output embedded checkout iframe container.
	 *
	 * @param WC_Order|null $order Order.
	 */
	public function render_embedded_checkout( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = $this->get_pay_page_order();
		}

		if ( ! $this->should_show_embedded_checkout( $order ) ) {
			return;
		}

		$gateway = $this->gateway();

		if ( ! $gateway ) {
			return;
		}

		$embedded_url = $this->resolve_embedded_url( $order );

		if ( empty( $embedded_url ) ) {
			echo '<p class="woocommerce-error">' . esc_html__(
				'Unable to load the payment form. Please refresh the page or contact the store.',
				'ziina'
			) . '</p>';
			return;
		}

		$iframe_src = $gateway->build_embedded_iframe_src( $embedded_url );

		?>
		<div
			id="ziina-embedded-checkout-wrapper"
			class="ziina-embedded-checkout-wrapper"
		>
			<iframe
				id="ziina-checkout"
				class="ziina-embedded-checkout-iframe"
				src="<?php echo esc_url( $iframe_src ); ?>"
				frameborder="0"
				allow="payment"
				title="<?php echo esc_attr__( 'Ziina payment', 'ziina' ); ?>"
			></iframe>
			<div id="ziina-embedded-checkout-notice" class="ziina-embedded-checkout-notice" role="alert" hidden></div>
		</div>
		<?php
	}

	/**
	 * Load embedded_url from the order's payment intent.
	 *
	 * @param WC_Order $order Order.
	 */
	private function resolve_embedded_url( WC_Order $order ): string {
		try {
			$payment_intent = ziina_payment()->api()->get_payment_intent( $order );

			return $payment_intent['embedded_url'] ?? '';
		} catch ( Exception $e ) {
			ZiinaLogger::error(
				'Failed to load embedded payment intent on pay page',
				array(
					'order_id' => $order->get_id(),
					'message'  => $e->getMessage(),
				)
			);
		}

		return '';
	}
}
