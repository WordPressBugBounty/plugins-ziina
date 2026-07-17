<?php
/**
 * Admin Order Details class
 *
 * @package ZiinaPayment\Admin
 */

namespace ZiinaPayment\Admin;
use ZiinaPayment\Logger\Main as ZiinaLogger;

defined( 'ABSPATH' ) || exit();

/**
 * Class OrderDetails
 *
 * @package ZiinaPayment\Admin
 * @since   1.0.0
 */
class OrderDetails {

  /**
   * OrderDetails constructor.
   */
  public function __construct() {
    // Add Ziina payment details to admin order page
    add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_ziina_payment_details'));
    // Add Ziina fees to the order totals section
    add_action('woocommerce_admin_order_totals_after_total', array($this, 'display_ziina_fee_in_totals'));
  }

  /**
   * Display Ziina payment details on the order admin page
   * 
   * @param \WC_Order $order The order object
   */
  public function display_ziina_payment_details($order) {
    if ($order->get_payment_method() !== 'ziina') {
      return;
    }

    $payment_id = $order->get_meta('_ziina_payment_id');
    $fee_amount = $order->get_meta('_ziina_fee_amount');

    if (empty($payment_id)) {
      return;
    }

    $receipt_url = 'https://pay.ziina.com/payment_intent/' . esc_attr($payment_id) . '/status';

    echo '<div class="ziina-payment-details">';
    echo '<h3>' . esc_html__('Ziina Payment Details', 'ziina') . '</h3>';
    echo '<p><strong>' . esc_html__('Receipt:', 'ziina') . '</strong> <a href="' . esc_url($receipt_url) . '" target="_blank">' . esc_html($receipt_url) . '</a></p>';
    echo '</div>';
  }
    
  /**
   * Display Ziina fee in the order totals section
   * 
   * @param int $order_id The order ID
   */
  public function display_ziina_fee_in_totals($order_id) {
    $order = wc_get_order($order_id);

    // Only display for Ziina payments
    if ($order->get_payment_method() !== 'ziina') {
      return;
    }
    
    $fee_amount = $order->get_meta('_ziina_fee_amount');
    
    if (!empty($fee_amount)) {
      $fee_amount_formatted = $fee_amount / 100; // Convert back from cents to base currency
      ?>
      <tr>
        <td class="label"><?php esc_html_e('Ziina Fee:', 'ziina'); ?></td>
        <td width="1%"></td>
        <td class="total">
          <?php echo wp_kses_post( wc_price( $fee_amount_formatted, array( 'currency' => $order->get_currency() ) ) ); ?>
        </td>
      </tr>
      <?php
    }
  }
  
  /**
   * Save Ziina payment details to order
   * 
   * @param \WC_Order $order The order object
   * @param array $payment_data The payment data (from API or webhook)
   */
  public static function save_payment_details_to_order($order, $payment_data) {
    if (!$order) {
      ZiinaLogger::error('Cannot save payment details - order object is null', [
        'order' => $order,
        'payment_data' => $payment_data
      ]);
      return;
    }
    
    if (isset($payment_data['fee_amount'])) {
      $order->update_meta_data('_ziina_fee_amount', $payment_data['fee_amount']);
      $order->save();
    }
  }
} 