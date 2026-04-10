<?php
/**
 * Plugin Name:          Portugal CTT Tracking for WooCommerce
 * Plugin URI:           https://www.webdados.pt/wordpress/plugins/tracking-ctt-portugal-para-woocommerce-wordpress/
 * Description:          Lets you associate a tracking code with a WooCommerce order so that both the store owner and the client can track the order sent with CTT
 * Version:              2.4
 * Author:               Naked Cat Plugins (by Webdados)
 * Author URI:           https://nakedcatplugins.com
 * Text Domain:          portugal-ctt-tracking-woocommerce
 * Requires at least:    5.8
 * Tested up to:         7.0
 * Requires PHP:         7.2
 * WC requires at least: 8.0
 * WC tested up to:      10.7
 * Requires Plugins:     woocommerce
 **/

/* WooCommerce CRUD ready */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'CTT_TRACKING_WC_VERSION', '8.0' );

/**
 * Initialize the CTT Tracking plugin.
 *
 * Checks if WooCommerce is available and meets the minimum version
 * requirement before initializing the main plugin class. If requirements
 * are not met, displays an admin notice instead.
 *
 * @return void
 */
function ctt_tracking_init() {
	if ( class_exists( 'WooCommerce' ) && version_compare( WC_VERSION, CTT_TRACKING_WC_VERSION, '>=' ) ) { // We check again because WooCommerce could have "died"
		require_once __DIR__ . '/includes/class-ctt-tracking.php';
		$GLOBALS['CTT_Tracking'] = CTT_Tracking();
		/* Add settings links - This is here because inside the main class we cannot call the correct plugin_basename( __FILE__ ) */
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( CTT_Tracking(), 'add_settings_link' ) );
	} else {
		add_action( 'admin_notices', 'admin_notices_ctt_tracking_not_active' );
	}
}
add_action( 'init', 'ctt_tracking_init', 1 );

/**
 * Get the main CTT_Tracking instance.
 *
 * Returns the singleton instance of the CTT_Tracking class.
 * This function provides global access to the main plugin instance.
 *
 * @return CTT_Tracking The main CTT_Tracking instance
 */
function CTT_Tracking() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return CTT_Tracking::instance();
}

/**
 * Display admin notice when plugin requirements are not met.
 *
 * Shows an error notice in the WordPress admin when WooCommerce
 * is not installed/active or doesn't meet the minimum version
 * requirement for this plugin to function properly.
 *
 * @return void
 */
function admin_notices_ctt_tracking_not_active() {
	?>
	<div class="notice notice-error is-dismissible">
		<p>
		<?php
			echo wp_kses_post(
				sprintf(
					/* translators: 1: Required WooCommerce version */
					__( '<strong>Portugal CTT Tracking for WooCommerce</strong> is installed and active but <strong>WooCommerce (%s or above)</strong> is not.', 'portugal-ctt-tracking-woocommerce' ),
					CTT_TRACKING_WC_VERSION
				)
			);
		?>
		</p>
	</div>
	<?php
}

/* HPOS & Blocks Compatible */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);


/* Portuguese Postcodes nag */
add_action(
	'admin_init',
	function () {
		if (
		( ! defined( 'WEBDADOS_PORTUGUESE_POSTCODES_NAG' ) )
		&&
		( ! function_exists( '\Webdados\PortuguesePostcodesWooCommerce\init' ) )
		&&
		empty( get_transient( 'webdados_portuguese_postcodes_nag' ) ) // Not used anymore, but kept for backwards compatibility
		&&
		( intval( get_user_meta( get_current_user_id(), 'webdados_portuguese_postcodes_nag_dismissed_until', true ) ) < time() )
		) {
			define( 'WEBDADOS_PORTUGUESE_POSTCODES_NAG', true );
			require_once 'webdados-portuguese-postcodes-nag/webdados-portuguese-postcodes-nag.php';
		}
	}
);

/* If you're reading this you must know what you're doing ;-) Greetings from sunny Portugal! */
