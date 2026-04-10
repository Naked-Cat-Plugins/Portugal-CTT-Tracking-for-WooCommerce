<?php
/**
 * Portuguese Postcodes for WooCommerce Promotion Notice
 *
 * This file handles the display and dismissal of promotional notices for the
 * "Portuguese Postcodes for WooCommerce" plugin. It shows an admin notice to
 * promote the complementary plugin that provides automatic postal code filling
 * functionality for Portuguese addresses.
 *
 * The notice includes:
 * - Visual promotional content with plugin icon
 * - Description of the Portuguese Postcodes plugin benefits
 * - Direct link to the plugin page
 * - Discount coupon information
 * - AJAX-powered dismissal functionality with 90-day suppression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Add Portuguese Postcodes for WooCommerce nag
 *
 * @return void
 */
function webdados_portuguese_postcodes_nag() {
	?>
		<script type="text/javascript">
		jQuery(function($) {
			$( document ).on( 'click', '#webdados_portuguese_postcodes_nag .notice-dismiss', function () {
				// AJAX SET TRANSIENT FOR 120 DAYS
				$.ajax( ajaxurl, {
					type: 'POST',
					data: {
						action: 'dismiss_webdados_portuguese_postcodes_nag',
					}
				});
			});
		});
		</script>
		<div id="webdados_portuguese_postcodes_nag" class="notice notice-info is-dismissible">
			<p style="line-height: 1.4em;">
				<img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'icon-portuguese-postcodes.svg' ); ?>" width="60" height="60" style="float: left; max-width: 60px; height: auto; margin-right: 1em;"/>
				<strong><?php esc_html_e( 'Do your customers still write the full postal code manually on the checkout?', 'portugal-ctt-tracking-woocommerce' ); ?></strong>
				<br/>
				<?php
					echo wp_kses_post(
						sprintf(
						/* translators: 1: Link start tag, 2: Link end tag */
							esc_html__( 'Activate the automatic filling of the postal code at the checkout, avoiding incorrect data at the time of sending, with our plugin %1$sPortuguese Postcodes for WooCommerce%2$s', 'portugal-ctt-tracking-woocommerce' ),
							sprintf(
								'<a href="%s" target="_blank">',
								esc_url( __( 'https://www.webdados.pt/wordpress/plugins/codigos-postais-portugueses-para-woocommerce/', 'portugal-ctt-tracking-woocommerce' ) )
							),
							'</a>'
						)
					);
				?>
				<br/>
				<?php echo wp_kses_post( __( 'Use the coupon <strong>webdados</strong> for 10% discount!', 'portugal-ctt-tracking-woocommerce' ) ); ?>
			</p>
			<div style="clear: both;"></div>
		</div>
		<?php
}
add_action( 'admin_notices', 'webdados_portuguese_postcodes_nag' );

/**
 * Dismiss nag for 120 days
 *
 * @return void
 */
function dismiss_webdados_portuguese_postcodes_nag() {
	$days                 = 120;
	$expiration_timestamp = time() + ( $days * DAY_IN_SECONDS );
	update_user_meta( get_current_user_id(), 'webdados_portuguese_postcodes_nag_dismissed_until', $expiration_timestamp );
	wp_die();
}
add_action( 'wp_ajax_dismiss_webdados_portuguese_postcodes_nag', 'dismiss_webdados_portuguese_postcodes_nag' );