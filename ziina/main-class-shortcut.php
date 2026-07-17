<?php
/**
 * Main class shortcut
 *
 * @package ZiinaPayment
 */

defined( 'ABSPATH' ) || exit;

use ZiinaPayment\Main;

/**
 * Shortcut for getting Main class instance
 *
 * @return Main
 */
function ziina_payment(): Main {
	return Main::get_instance();
}
