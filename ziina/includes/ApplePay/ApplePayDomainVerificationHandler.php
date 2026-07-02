<?php
/**
 * Apple Pay domain association file — fetch on admin action, serve from stored option.
 *
 * @package ZiinaPayment\ApplePay
 */

namespace ZiinaPayment\ApplePay;

use ZiinaPayment\Logger\Main as ZiinaLogger;

defined( 'ABSPATH' ) || exit();

/**
 * Apple Pay domain verification file — fetch on admin action, serve from stored option.
 */
class ApplePayDomainVerificationHandler {

	const S3_SOURCE_URL        = 'https://s3-aws-uae-prd-public-web-assets-01.s3.me-central-1.amazonaws.com/embedded_checkout/apple-developer-merchant-id-domain-association';
	const WELL_KNOWN_PATH      = '/.well-known/apple-developer-merchantid-domain-association';
	const OPTION_KEY           = 'ziina_apple_domain_association_file';
	const ADMIN_ACTION      = 'ziina_setup_apple_domain';
	const PHYSICAL_FILENAME = 'apple-developer-merchantid-domain-association';

	/**
	 * ApplePayDomainVerificationHandler constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve_file' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_serve_file' ), 0 );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_admin_setup' ) );
	}

	/**
	 * Public URL for the hosted verification file.
	 */
	public function get_public_url(): string {
		return home_url( self::WELL_KNOWN_PATH );
	}

	/**
	 * Absolute path to the physical verification file under ABSPATH.
	 */
	public function get_physical_file_path(): string {
		return trailingslashit( ABSPATH ) . '.well-known/' . self::PHYSICAL_FILENAME;
	}

	/**
	 * Whether the physical file exists and is readable.
	 */
	public function physical_file_exists(): bool {
		$path = $this->get_physical_file_path();

		return '' !== $path && is_readable( $path );
	}

	/**
	 * Whether stored file content exists in wp_options.
	 */
	public function has_stored_file(): bool {
		$body = get_option( self::OPTION_KEY, '' );

		return is_string( $body ) && '' !== trim( $body );
	}

	/**
	 * Settings screen URL for redirects and forms.
	 */
	public function get_settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . ziina_payment()->plugin_id );
	}

	/**
	 * Serve stored file at /.well-known/... when content exists.
	 */
	public function maybe_serve_file(): void {
		if ( ! $this->is_well_known_request() ) {
			return;
		}

		$body = get_option( self::OPTION_KEY, '' );

		ZiinaLogger::info(
			'Well-known request matched',
			array(
				'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
				'expected'    => $this->get_expected_path(),
				'has_file'    => is_string( $body ) && '' !== trim( $body ),
				'body_length' => is_string( $body ) ? strlen( $body ) : 0,
			)
		);

		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			ZiinaLogger::warn( 'Serving 404 — no stored Apple domain file' );
			status_header( 404 );
			exit;
		}

		ZiinaLogger::info(
			'Serving 200 Apple domain file via PHP',
			array( 'body_length' => strlen( $body ) )
		);

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain file body from trusted S3 source.
		echo $body;
		exit;
	}

	/**
	 * Fetch file from S3 and store in wp_options + physical path.
	 *
	 * @return true|\WP_Error
	 */
	public function fetch_and_store_from_s3() {
		ZiinaLogger::debug(
			'Fetching Apple domain file from S3',
			array( 'url' => self::S3_SOURCE_URL )
		);

		$response = wp_remote_get(
			self::S3_SOURCE_URL,
			array(
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			ZiinaLogger::error(
				'S3 fetch failed',
				array(
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			ZiinaLogger::error( 'S3 fetch returned non-200', array( 'http_code' => $code ) );
			return new \WP_Error(
				'ziina_apple_domain_fetch_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Could not download the Apple Pay domain file (HTTP %d).', 'ziina' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === trim( (string) $body ) ) {
			ZiinaLogger::error( 'S3 response body was empty' );
			return new \WP_Error(
				'ziina_apple_domain_empty',
				__( 'The Apple Pay domain file from Ziina was empty.', 'ziina' )
			);
		}

		$updated = update_option( self::OPTION_KEY, $body, false );

		ZiinaLogger::info(
			'Stored Apple domain file in option',
			array(
				'option_key'  => self::OPTION_KEY,
				'update_ok'   => (bool) $updated,
				'body_length' => strlen( $body ),
				'verify_read' => strlen( (string) get_option( self::OPTION_KEY, '' ) ),
			)
		);

		$physical_result = $this->write_physical_file( $body );

		if ( is_wp_error( $physical_result ) ) {
			ZiinaLogger::warn(
				'Physical file write failed (option storage succeeded)',
				array(
					'path'  => $this->get_physical_file_path(),
					'error' => $physical_result->get_error_message(),
				)
			);
		}

		return true;
	}

	/**
	 * Write verification file to ABSPATH/.well-known/ for static serving by the web server.
	 *
	 * @param string $body File contents.
	 * @return true|\WP_Error
	 */
	private function write_physical_file( string $body ) {
		$path = $this->get_physical_file_path();
		$dir  = dirname( $path );

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error(
				'ziina_apple_domain_mkdir_failed',
				__( 'Could not create the .well-known directory.', 'ziina' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- direct write to web root is intentional.
		$written = file_put_contents( $path, $body );

		if ( false === $written ) {
			return new \WP_Error(
				'ziina_apple_domain_write_failed',
				__( 'Could not write the Apple Pay domain file to disk.', 'ziina' )
			);
		}

		ZiinaLogger::info(
			'Wrote physical Apple domain file',
			array(
				'path'        => $path,
				'bytes'       => $written,
				'readable'    => is_readable( $path ),
				'body_length' => strlen( $body ),
			)
		);

		return true;
	}

	/**
	 * Admin button handler: fetch from S3 and redirect back to gateway settings.
	 */
	public function handle_admin_setup(): void {
		ZiinaLogger::debug(
			'Admin setup action received',
			array(
				'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'user'   => get_current_user_id(),
			)
		);

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			ZiinaLogger::error( 'Admin setup denied — insufficient capability' );
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ziina' ) );
		}

		check_admin_referer( self::ADMIN_ACTION );

		$settings_url = $this->get_settings_url();
		$result       = $this->fetch_and_store_from_s3();

		if ( is_wp_error( $result ) ) {
			ZiinaLogger::error(
				'Admin setup failed — redirecting with error',
				array( 'error' => $result->get_error_message() )
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'ziina_apple_domain_error' => '1',
						'ziina_apple_domain_msg'   => rawurlencode( $result->get_error_message() ),
					),
					$settings_url
				)
			);
			exit;
		}

		ZiinaLogger::info(
			'Admin setup succeeded — redirecting with success',
			array(
				'public_url'     => $this->get_public_url(),
				'physical_file'  => $this->physical_file_exists(),
				'physical_path'  => $this->get_physical_file_path(),
			)
		);
		wp_safe_redirect( add_query_arg( 'ziina_apple_domain_success', '1', $settings_url ) );
		exit;
	}

	/**
	 * Expected request path for the well-known file (site subdirectory aware).
	 */
	private function get_expected_path(): string {
		$expected = wp_parse_url( home_url( self::WELL_KNOWN_PATH ), PHP_URL_PATH );

		return is_string( $expected ) ? $expected : self::WELL_KNOWN_PATH;
	}

	/**
	 * Whether the current request targets the Apple Pay well-known path.
	 */
	private function is_well_known_request(): bool {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$expected     = $this->get_expected_path();

		if ( ! is_string( $request_path ) ) {
			return false;
		}

		$matches = untrailingslashit( $request_path ) === untrailingslashit( $expected );

		if ( str_contains( $request_path, '.well-known' ) || str_contains( $request_path, 'apple-developer' ) ) {
			ZiinaLogger::debug(
				'Well-known path check',
				array(
					'request_path'  => $request_path,
					'expected_path' => $expected,
					'matches'       => $matches,
					'home_url'      => home_url(),
				)
			);
		}

		return $matches;
	}
}
