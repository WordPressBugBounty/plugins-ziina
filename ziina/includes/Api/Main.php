<?php
/**
 * Api class
 *
 * @package ZiinaPayment
 */

namespace ZiinaPayment\Api;

use Exception;
use ZiinaPayment\Ajax\Payment;
use ZiinaPayment\Entities\ZiinaPayment;
use ZiinaPayment\Logger\Main as ZiinaLogger;

defined( 'ABSPATH' ) || exit();

/**
 * Class Api
 *
 * @package ZiinaPayment
 * @since   1.0.0
 */
class Main {
	/**
	 * @var string
	 */
	private $api_url = 'https://api-v2.ziina.com/api/';

	/**
	 * @var array
	 */
	private $zero_decimals = [
		'bif',
		'clp',
		'djf',
		'gnf',
		'jpy',
		'kmf',
		'krw',
		'mga',
		'pyg',
		'rwf',
		'ugx',
		'vnd',
		'vuv',
		'xaf',
		'xof',
		'xpf',
	];

	/**
	 * @var array
	 */
	private $two_decimals = [
		'aed',
		'afn',
		'all',
		'amd',
		'ang',
		'aoa',
		'ars',
		'aud',
		'awg',
		'azn',
		'bam',
		'bbd',
		'bdt',
		'bgn',
		'bmd',
		'bnd',
		'bob',
		'brl',
		'bsd',
		'bwp',
		'byn',
		'bzd',
		'cad',
		'cdf',
		'chf',
		'cny',
		'cop',
		'crc',
		'cve',
		'czk',
		'dkk',
		'dop',
		'dzd',
		'egp',
		'etb',
		'eur',
		'fjd',
		'fkp',
		'gbp',
		'gel',
		'gip',
		'gmd',
		'gtq',
		'gyd',
		'hkd',
		'hnl',
		'htg',
		'huf',
		'idr',
		'ils',
		'inr',
		'isk',
		'jmd',
		'kes',
		'kgs',
		'khr',
		'kyd',
		'kzt',
		'lak',
		'lbp',
		'lkr',
		'lrd',
		'lsl',
		'mad',
		'mdl',
		'mkd',
		'mmk',
		'mnt',
		'mop',
		'mro',
		'mur',
		'mvr',
		'mwk',
		'mxn',
		'myr',
		'mzn',
		'nad',
		'ngn',
		'nio',
		'nok',
		'npr',
		'nzd',
		'pab',
		'pen',
		'pgk',
		'php',
		'pkr',
		'pln',
		'qar',
		'ron',
		'rsd',
		'rub',
		'sar',
		'sbd',
		'scr',
		'sek',
		'sgd',
		'shp',
		'sle',
		'sos',
		'srd',
		'std',
		'szl',
		'thb',
		'tjs',
		'top',
		'try',
		'ttd',
		'twd',
		'tzs',
		'uah',
		'usd',
		'uyu',
		'uzs',
		'wst',
		'xcd',
		'yer',
		'zar',
		'zmw',
	];

	/**
	 * @var array
	 */
	private $three_decimals = [
		'bhd',
		'jod',
		'kwd',
		'omr',
		'tnd',
	];

	/**
	 * @var bool
	 */
	private $is_test;

	/**
	 * @var string
	 */
	private $authorization_token;

	/**
	 * Api constructor.
	 */
	public function __construct() {
		$this->is_test             = ziina_payment()->get_setting( 'is_test' ) ?? true;
		$this->authorization_token = ziina_payment()->get_setting( 'authorization_token' ) ?? '';
	}

	/**
	 * @param string $endpoint ziina api endpoint.
	 * @param string $method   http method.
	 * @param array  $body     request body.
	 *
	 * @return array
	 * @throws Exception If request error.
	 */
	private function request( string $endpoint, $method = 'GET', $body = array() ): array {
		$url = $this->api_url . $endpoint;

		$params = array(
			'body'    => empty( $body ) ? null : wp_json_encode( $body ),
			'method'  => $method,
			'headers' => array(
				'Authorization' => "Bearer $this->authorization_token",
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		$log_params = array(
			'url'    => $url,
			'method' => $method,
			'body'   => $body,
			'params' => $params,
		);

		ziina_payment()->log($log_params);
		ZiinaLogger::info('Sending request', $log_params);

		$res = wp_remote_request( $url, $params );

		if ( is_wp_error( $res ) ) {
			$wp_error_log = array(
				'error' => $res->get_error_message(),
			);
			ziina_payment()->log($wp_error_log);
			ZiinaLogger::error('Wordpress error', $wp_error_log);

			throw new Exception( esc_html( $res->get_error_message() ) );
		}

		$response_log = array(
			'response' => $res,
		);
		ZiinaLogger::info('Received response from Ziina API', $response_log);
		ziina_payment()->log($response_log);

		$res_body = json_decode( $res['body'], true );

		if ( ! empty( $res['body'] ) && is_null( $res_body ) ) {
			$wrong_body_log = array(
				'error' => 'wrong body',
				'body'  => $res['body'],
			);
			ziina_payment()->log($wrong_body_log);
			ZiinaLogger::error('Api request decoding error', $wrong_body_log);

			throw new Exception( esc_html__( 'Api request decoding error', 'ziina' ) );
		}

		return $res_body;
	}

	public function get_rounded_total( $total, $currency ) {		
		ini_set("serialize_precision", -1);

		if(in_array(strtolower($currency), $this->zero_decimals)){
			return round( $total );
		}elseif(in_array(strtolower($currency), $this->two_decimals)){
			return round( $total, 2 ) * 100;
		}elseif(in_array(strtolower($currency), $this->three_decimals)){
			return round( $total, 2 ) * 1000;
		}else{
			throw new Exception( esc_html__( 'Not supported currency', 'ziina' ) );
		}
	}

	/**
	 * @param mixed $order_id order to create payment.
	 *
	 * @return string
	 * @throws Exception If request error.
	 */
	public function create_payment_intent( $order_id ): string {
		if (empty($this->authorization_token)) {
			throw new Exception( esc_html__( "We couldn't process your payment because this payment option isn't set up correctly. Contact the store to complete your order.", 'ziina' ) );
		}

		$order = wc_get_order( $order_id );

		ini_set("serialize_precision", -1);

		$total = $this->get_rounded_total($order->get_total(), $order->get_currency());

		$body = array(
			'amount'             => $total,
			'currency_code'      => $order->get_currency(),
			'transaction_source' => 'woocommerce',
			'success_url'        => Payment::get_action_url(
				'success_url',
				array(
					'order_id' => $order->get_id(),
				)
			),
			'cancel_url'         => Payment::get_action_url(
				'cancel_url',
				array(
					'order_id' => $order->get_id(),
				)
			),
			'test'               => $this->is_test,
		);

		$payment_intent = $this->request(
			'payment_intent',
			'POST',
			$body
		);

		if ( ! empty( $payment_intent ) && ! empty( $payment_intent['id'] ) ) {
			ZiinaPayment::by_order( $order )->set_payment_id( $payment_intent['id'] );
			return $payment_intent['redirect_url'];
		}

		throw new Exception( esc_html__( 'Api request error', 'ziina' ) );
	}

	public function create_refund($params) {
    return $this->request('refund', 'POST', $params);
	}

	/**
	 * @param mixed $order order to check payment.
	 *
	 * @return array
	 * @throws Exception If request error.
	 */
	public function get_payment_intent( $order ): array {
		$order = wc_get_order( $order );

		$payment_id = ZiinaPayment::by_order( $order )->payment_id();

		return $this->request( "payment_intent/$payment_id" );
	}

	/**
	 * Sends logs to Ziina server proxy
	 */
	public function log($log_params) {
		if ( empty($this->authorization_token) ) {
			return;
		}

		try {
			$url = $this->api_url . 'log';
			$version_info = array(
				'php_version' => phpversion(),
				'wp_version' => get_bloginfo('version'),
				'wc_version' => function_exists('WC') ? WC()->version : "not_active",
				'plugin_version' => ziina_payment()->version,
			);

			$enhanced_log_params = $log_params;
			if (isset($enhanced_log_params['data']) && is_array($enhanced_log_params['data'])) {
				$enhanced_log_params['data'] = array_merge($enhanced_log_params['data'], $version_info);
			} else {
				$enhanced_log_params['data'] = $version_info;
			}

			$request_params = array(
				'body'    => wp_json_encode( $enhanced_log_params ),
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => "Bearer $this->authorization_token",
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
			);

			return wp_remote_request( $url, $request_params );
	  } catch ( Exception $e ) {}
	}
	
	public function register_webhook($webhook_url) {
		return $this->request('webhook', 'POST', [
			'url' => $webhook_url,
			'secret' => $this->authorization_token
		]);
	}

	public function delete_webhook() {
		return $this->request('webhook', 'DELETE');
	}
}
