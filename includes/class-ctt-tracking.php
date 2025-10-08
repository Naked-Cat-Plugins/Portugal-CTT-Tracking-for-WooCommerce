<?php
/**
 * Main plugin file for Portugal CTT Tracking for WooCommerce
 *
 * This file initializes the plugin, checks for WooCommerce dependency,
 * and sets up the main plugin class and its instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main CTT Tracking Plugin Class
 *
 * Handles CTT (Portuguese postal service) tracking functionality for WooCommerce orders.
 * Provides tracking code management, information retrieval, and display functionality
 * for both admin and customer-facing areas.
 *
 * @since 1.0.0
 */
final class CTT_Tracking {

	// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.VariableComment.WrongStyle, Squiz.Commenting.VariableComment.MissingVar

	/* Internal variables */
	protected $ctt_url      = 'https://www.ctt.pt/feapl_2/app/open/objectSearch/objectSearch.jspx';
	protected $ctt_url_more = 'https://ctt.pt/t/%s';
	protected $wpml_active  = false;
	protected $locale       = false;
	protected $hpos_enabled = false;

	/* Single instance */
	protected static $instance = null;

	// phpcs:enable

	/**
	 * Class constructor.
	 *
	 * Initializes the CTT Tracking plugin by checking for WPML compatibility,
	 * detecting HPOS (High-Performance Order Storage) status, and setting up
	 * all necessary WordPress hooks for the plugin functionality.
	 */
	public function __construct() {
		$this->wpml_active = function_exists( 'icl_object_id' ) && function_exists( 'icl_register_string' );
		if ( wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
			$this->hpos_enabled = true;
		}
		$this->init_hooks();
	}

	/**
	 * Get the singleton instance of the CTT_Tracking class.
	 *
	 * Ensures only one instance of our plugin is loaded or can be loaded.
	 * This implements the singleton pattern to prevent multiple instances
	 * of the main plugin class from being created.
	 *
	 * @return CTT_Tracking The singleton instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize WordPress hooks and filters.
	 *
	 * Sets up all necessary WordPress action and filter hooks for the plugin,
	 * including meta boxes, order processing, email integration, and settings.
	 * Also provides hooks for third-party integrations.
	 */
	private function init_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'shop_order_add_meta_boxes' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_tracking_field' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'ctt_tracking_order_details' ) );
		switch ( get_option( 'ctt_tracking_email_link_position' ) ) {
			case 'before_order_table':
				add_action( 'woocommerce_email_before_order_table', array( $this, 'ctt_tracking_email_details' ), 10, 3 );
				break;
			case 'after_order_table':
				add_action( 'woocommerce_email_after_order_table', array( $this, 'ctt_tracking_email_details' ), 10, 3 );
				break;
			default:
				add_action( 'woocommerce_email_customer_details', array( $this, 'ctt_tracking_email_details' ), 30, 3 );
				break;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			add_filter( 'woocommerce_shipping_settings', array( $this, 'woocommerce_shipping_settings' ), PHP_INT_MAX );
			add_action( 'woocommerce_admin_field_ctt_tracking_title', array( $this, 'woocommerce_admin_field_ctt_tracking_title' ) );
		}
		// Let 3rd party update tracking number for an order
		add_action( 'portugal_ctt_tracking_set_tracking_code', array( $this, 'ctt_tracking_set_tracking_code_for_order' ), 10, 2 );
		// Let 3rd party update tracking information from CTT for an order
		add_action( 'portugal_ctt_tracking_update_info_for_order', array( $this, 'ctt_tracking_get_info_for_order' ), 10, 1 );
	}

	/**
	 * Add settings link to plugin action links.
	 *
	 * Adds a "Settings" link to the plugin's action links on the plugins page,
	 * allowing users to quickly access the CTT Tracking configuration options.
	 *
	 * @param array $links Array of existing plugin action links.
	 * @return array Modified array of action links with settings link added.
	 */
	public function add_settings_link( $links ) {
		$action_links = array(
			'ctt_tracking_settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=options#ctt_tracking' ) . '">' . __( 'Settings', 'portugal-ctt-tracking-woocommerce' ) . '</a>',
		);
		return array_merge( $action_links, $links );
	}

	/**
	 * Add CTT Tracking settings to WooCommerce shipping settings.
	 *
	 * Injects CTT Tracking configuration options into the WooCommerce shipping
	 * settings page, including email link behavior and user permissions settings.
	 *
	 * @param array $settings Array of WooCommerce shipping settings.
	 * @return array Modified settings array with CTT Tracking options added.
	 */
	public function woocommerce_shipping_settings( $settings ) {
		$updated_settings = array();
		foreach ( $settings as $section ) {
			if ( isset( $section['id'] ) && 'shipping_options' === $section['id'] && isset( $section['type'] ) && 'sectionend' === $section['type'] ) {
				$updated_settings[] = array(
					'title' => __( 'Portugal CTT Tracking', 'portugal-ctt-tracking-woocommerce' ),
					'type'  => 'ctt_tracking_title',
					'id'    => 'shipping_options_ctt_tracking',
				);
				$updated_settings[] = array(
					'title'    => __( 'Email link target', 'portugal-ctt-tracking-woocommerce' ),
					'type'     => 'select',
					'options'  => array(
						'website' => __( 'Order details on the shop', 'portugal-ctt-tracking-woocommerce' ),
						'ctt'     => __( 'Tracking details at ctt.pt', 'portugal-ctt-tracking-woocommerce' ),
						'none'    => __( 'No link', 'portugal-ctt-tracking-woocommerce' ),
					),
					'desc'     => __( 'The link type you want to show on the email information sent to the client', 'portugal-ctt-tracking-woocommerce' ),
					'desc_tip' => true,
					'id'       => 'ctt_tracking_email_link_type',
				);
				$updated_settings[] = array(
					'title'    => __( 'Email link position', 'portugal-ctt-tracking-woocommerce' ),
					'type'     => 'select',
					'options'  => array(
						'before_order_table' => __( 'Top - Before order table', 'portugal-ctt-tracking-woocommerce' ),
						'after_order_table'  => __( 'Middle - After order table', 'portugal-ctt-tracking-woocommerce' ),
						''                   => __( 'Bottom - After customer details', 'portugal-ctt-tracking-woocommerce' ),
					),
					'desc'     => __( 'The link position on the email information sent to the client', 'portugal-ctt-tracking-woocommerce' ),
					'desc_tip' => true,
					'id'       => 'ctt_tracking_email_link_position',
				);
				$updated_settings[] = array(
					'title'    => __( 'Allow users to update info', 'portugal-ctt-tracking-woocommerce' ),
					'type'     => 'select',
					'options'  => array(
						''    => __( 'No', 'portugal-ctt-tracking-woocommerce' ),
						'yes' => __( 'Yes', 'portugal-ctt-tracking-woocommerce' ),
					),
					'desc'     => __( 'Allow users to update CTT tracking information at the order details on their account', 'portugal-ctt-tracking-woocommerce' ),
					'desc_tip' => true,
					'id'       => 'ctt_tracking_allow_users_update',
				);
			}
			$updated_settings[] = $section;
		}
		return $updated_settings;
	}

	/**
	 * Render custom title field for CTT Tracking settings section.
	 *
	 * Outputs a custom HTML title field for the CTT Tracking settings section
	 * in the WooCommerce shipping settings page.
	 *
	 * @param array $value Field configuration array containing title and other settings.
	 */
	public function woocommerce_admin_field_ctt_tracking_title( $value ) {
		?>
		<tr valign="top">
			<td colspan="2" style="padding: 0px;">
				<?php echo '<a name="ctt_tracking"></a><h2>' . esc_html( $value['title'] ) . '</h2>'; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Build CTT tracking URL for external link.
	 *
	 * Constructs the complete URL to view tracking information on the CTT website
	 * using the provided tracking code.
	 *
	 * @param string $ctt_tracking_code The CTT tracking code.
	 * @return string The complete CTT tracking URL.
	 */
	private function build_more_url( $ctt_tracking_code ) {
		return sprintf(
			$this->ctt_url_more,
			$ctt_tracking_code
		);
	}

	/**
	 * Add CTT Tracking meta box to order edit screen.
	 *
	 * Registers a meta box on the order edit screen (both HPOS and legacy)
	 * to display and manage CTT tracking information.
	 */
	public function shop_order_add_meta_boxes() {
		$screen = $this->hpos_enabled ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'ctt-tracking',
			__( 'Portugal CTT Tracking', 'portugal-ctt-tracking-woocommerce' ),
			array( $this, 'shop_order_add_meta_boxes_html' ),
			$screen,
			'normal',
			'default'
		);
	}

	/**
	 * Render HTML content for the CTT Tracking meta box.
	 *
	 * Displays the tracking code input field and current tracking information
	 * in the order edit screen meta box.
	 *
	 * @param WP_Post|WC_Order $post_or_order_object Post object (legacy) or Order object (HPOS).
	 */
	public function shop_order_add_meta_boxes_html( $post_or_order_object ) {
		$order_object      = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
		$ctt_tracking_code = $order_object->get_meta( '_ctt_tracking_code' );
		wp_nonce_field( '_ctt_tracking_nonce', 'ctt_tracking_nonce' );
		?>
		<table class="form-table">
			<tbody>
				<tr>
					<th>
						<label for="ctt_tracking_code"><?php esc_html_e( 'Tracking code', 'portugal-ctt-tracking-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="text" name="ctt_tracking_code" id="ctt_tracking_code" value="<?php echo esc_attr( $ctt_tracking_code ); ?>">
					</td>
				</tr>
				<tr>
					<th>
						<label for="ctt_tracking_code"><?php esc_html_e( 'Information', 'portugal-ctt-tracking-woocommerce' ); ?></label>
					</th>
					<td>
						<?php $this->tracking_information_table( $order_object->get_id(), 'admin' ); ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save tracking code field data from order edit screen.
	 *
	 * Processes and saves the CTT tracking code submitted from the order edit screen,
	 * with nonce verification and automatic tracking information updates.
	 *
	 * @param int $post_or_order_id Order ID (legacy) or Order object ID (HPOS).
	 */
	public function save_tracking_field( $post_or_order_id ) {
		if ( ! isset( $_POST['ctt_tracking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ctt_tracking_nonce'] ) ), '_ctt_tracking_nonce' ) ) {
			return;
		}
		$order_object = wc_get_order( $post_or_order_id );
		$update_info  = false;
		// Tracking code
		if ( isset( $_POST['ctt_tracking_code'] ) ) {
			if ( trim( sanitize_text_field( wp_unslash( $_POST['ctt_tracking_code'] ) ) ) !== $order_object->get_meta( '_ctt_tracking_code' ) ) {
				$update_info = true;
			}
			$order_object->update_meta_data( '_ctt_tracking_code', trim( sanitize_text_field( wp_unslash( $_POST['ctt_tracking_code'] ) ) ) );
		}
		$order_object->save();
		// Tracking info
		if ( isset( $_POST['ctt_tracking_info_force_update'] ) && intval( $_POST['ctt_tracking_info_force_update'] ) === 1 ) {
			$update_info = true;
		}
		if ( $update_info ) {
			$this->ctt_tracking_get_info_for_order( $order_object->get_id() );
		}
	}

	/**
	 * Display the tracking information table.
	 *
	 * Renders the complete tracking information interface including status,
	 * event history, and update buttons. Handles both admin and user contexts
	 * with appropriate styling and functionality.
	 *
	 * @param int    $order_id The WooCommerce order ID.
	 * @param string $context  Display context ('admin' or 'user').
	 */
	private function tracking_information_table( $order_id, $context = 'user' ) {
		if ( $context === 'admin' ) {
			?>
			<style type="text/css">
			@media screen and (min-width: 783px) {
				#ctt-tracking .form-table th {
					width: 150px;
				}
			}
			#ctt-tracking-information p {
				margin-bottom: 1em;
			}
			#ctt-tracking-information table {
				border-collapse: collapse;
				width: 100%;
				margin-bottom: 1em;
			}
			#ctt-tracking-information table th,
			#ctt-tracking-information table td {
				vertical-align: top;
				text-align: left;
				padding: 0.5em;
				margin: 0px;
				width: auto !important;
				line-height: normal;
				font-size: 0.8em;
				border: 1px solid #f1f1f1;
			}
			#ctt-tracking-information table th {
				font-weight: 600;
				background-color: #f1f1f1;
			}
			</style>
			<?php
		}
		?>
		<a name="ctt-tracking-information"></a>
		<div id="ctt-tracking-information">
			<?php
			$order_object      = wc_get_order( $order_id );
			$ctt_tracking_code = $order_object->get_meta( '_ctt_tracking_code' );
			// Get tracking info from the database
			$ctt_tracking_info = $order_object->get_meta( '_ctt_tracking_info' );
			// Force update tracking info? - Has info, has last update, filter true and status not final
			if ( $context === 'user' && isset( $_POST ) && isset( $_POST['ctt_tracking_info_force_update'] ) && intval( $_POST['ctt_tracking_info_force_update'] ) === 1 && get_option( 'ctt_tracking_allow_users_update' ) === 'yes' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$ctt_tracking_info = $this->ctt_tracking_get_info_for_order( $order_object->get_id() );
				?>
				<script>
					jQuery(document).ready(function($) {
						$( 'html, body' ).animate({
							scrollTop: $( '#ctt-tracking-information' ).offset().top
						}, 200);
					});
				</script>
				<?php
			} elseif ( $ctt_tracking_info && $ctt_tracking_info['status'] && $ctt_tracking_info['last_update'] && apply_filters( 'portugal_ctt_tracking_auto_update_info', true ) && ! $ctt_tracking_info['info']['status_final'] ) {
				$date1 = strtotime( date_i18n( 'Y-m-d H:i' ) );
				$date2 = strtotime( $ctt_tracking_info['last_update'] );
				$diff  = abs( $date2 - $date1 );
				// More than 4 hours?
				if ( $diff > intval( intval( apply_filters( 'portugal_ctt_tracking_auto_update_hours', 4 ) ) * 60 * 60 ) ) {
					$ctt_tracking_info = $this->ctt_tracking_get_info_for_order( $order_object->get_id() );
				}
			}
			if ( ! $ctt_tracking_code ) {
				?>
				<p><strong><?php esc_html_e( 'CTT tracking code not available', 'portugal-ctt-tracking-woocommerce' ); ?></strong></p>
				<?php
			} elseif ( ! $ctt_tracking_info ) {
				?>
					<p>
						<strong><?php esc_html_e( 'Tracking code', 'portugal-ctt-tracking-woocommerce' ); ?>:</strong>
						<?php echo esc_html( $ctt_tracking_code ); ?>
					</p>
					<p><strong><?php esc_html_e( 'CTT tracking information not available', 'portugal-ctt-tracking-woocommerce' ); ?></strong></p>
					<?php
					if ( $context === 'user' && get_option( 'ctt_tracking_allow_users_update' ) === 'yes' ) {
						$this->tracking_information_table_update_button_public();
					}
			} else {
				if ( ! $ctt_tracking_info['status'] ) {
					if ( $context === 'admin' ) {
						?>
							<p><strong><?php echo wp_kses_post( $ctt_tracking_info['message'] ); ?></strong></p>
							<?php
					} else {
						?>
							<p><strong><?php esc_html_e( 'CTT tracking information not available', 'portugal-ctt-tracking-woocommerce' ); ?></strong></p>
							<?php
					}
				} else {
					?>
						<p>
							<strong><?php esc_html_e( 'Tracking code', 'portugal-ctt-tracking-woocommerce' ); ?>:</strong>
							<?php echo esc_html( $ctt_tracking_code ); ?>
							<br/>
							<strong><?php esc_html_e( 'Status', 'portugal-ctt-tracking-woocommerce' ); ?>:</strong>
							<?php echo esc_html( $ctt_tracking_info['info']['status'] ); ?>
							<small>(<?php echo esc_html( $ctt_tracking_info['info']['date'] ); ?> <?php echo esc_html( $ctt_tracking_info['info']['time'] ); ?>)</small>
						</p>
						<?php if ( count( $ctt_tracking_info['info']['events'] ) > 0 ) { ?>
							<table>
								<thead>
									<tr>
										<th><?php esc_html_e( 'Date', 'portugal-ctt-tracking-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Hour', 'portugal-ctt-tracking-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Status', 'portugal-ctt-tracking-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Reason', 'portugal-ctt-tracking-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Place', 'portugal-ctt-tracking-woocommerce' ); ?></th>
										<th><?php esc_html_e( 'Receiver', 'portugal-ctt-tracking-woocommerce' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ( $ctt_tracking_info['info']['events'] as $date => $events ) {
										foreach ( $events as $event ) {
											?>
											<tr>
												<td class="ctt-tracking-information-table-date" style="white-space: nowrap;"><?php echo esc_html( $date ); ?></td>
												<td class="ctt-tracking-information-table-hour" style="white-space: nowrap;"><?php echo esc_html( $event['time'] ); ?></td>
												<td class="ctt-tracking-information-table-status"><?php echo esc_html( $event['status'] ); ?></td>
												<td class="ctt-tracking-information-table-reason"><?php echo esc_html( $event['reason'] ); ?></td>
												<td class="ctt-tracking-information-table-place"><?php echo esc_html( ucwords( strtolower( $event['place'] ) ) ); ?></td>
												<td class="ctt-tracking-information-table-receiver"><?php echo esc_html( ucwords( strtolower( $event['receiver'] ) ) ); ?></td>
											</tr>
											<?php
										}
									}
									?>
								</tbody>
							</table>
							<?php
						}
				}
				?>
					<p>
						<small>
							<?php esc_html_e( 'Last update', 'portugal-ctt-tracking-woocommerce' ); ?>:
							<?php echo esc_html( $ctt_tracking_info['last_update'] ); ?>
							-
							<a href="<?php echo esc_url( $this->build_more_url( $ctt_tracking_code ) ); ?>" target="_blank"><?php esc_html_e( 'Check out the latest available information at ctt.pt', 'portugal-ctt-tracking-woocommerce' ); ?></a>
						</small>
					</p>
					<?php
					if ( $context === 'user' && get_option( 'ctt_tracking_allow_users_update' ) === 'yes' ) {
						$this->tracking_information_table_update_button_public();
					}
			}
			if ( $context === 'admin' ) {
				$this->tracking_information_table_update_button();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render admin update button for tracking information.
	 *
	 * Displays a button in the admin area that allows manual update of tracking
	 * information with JavaScript functionality to trigger form submission.
	 */
	private function tracking_information_table_update_button() {
		?>
		<p>
			<input type="hidden" id="ctt_tracking_info_force_update" name="ctt_tracking_info_force_update" value="0"/>
			<button type="button" class="button button-primary" id="ctt_tracking_info_force_update_button"><?php esc_html_e( 'Update code and information', 'portugal-ctt-tracking-woocommerce' ); ?></button>
			<script type="text/javascript">
			jQuery( document ).ready( function( $ ) {
				$( '#ctt_tracking_info_force_update_button' ).on( 'click', function() {
					$( '#ctt_tracking_info_force_update' ).val( '1' );
					$( '#post' ).trigger('submit');  //Non-HPOS
					$( '#order' ).trigger('submit'); //HPOS
				} );
			} );
			</script>
		</p>
		<?php
	}

	/**
	 * Render public update button for tracking information.
	 *
	 * Displays a form with submit button for customers to manually update
	 * tracking information on their account order details page.
	 */
	private function tracking_information_table_update_button_public() {
		?>
		<form action="" method="post">
			<input type="hidden" id="ctt_tracking_info_force_update" name="ctt_tracking_info_force_update" value="1"/>
			<input type="submit" class="button" value="<?php echo esc_attr( __( 'Update CTT tracking information', 'portugal-ctt-tracking-woocommerce' ) ); ?>"/>
		</form>
		<?php
	}

	/**
	 * Display CTT tracking information on customer order details page.
	 *
	 * Outputs the tracking information section on the customer-facing
	 * order details page when a tracking code is available.
	 *
	 * @param WC_Order $order_object The WooCommerce order object.
	 */
	public function ctt_tracking_order_details( $order_object ) {
		$ctt_tracking_code = $order_object->get_meta( '_ctt_tracking_code' );
		if ( ! empty( $ctt_tracking_code ) ) {
			?>
			<a name="ctt_tracking"></a>
			<h2><?php esc_html_e( 'CTT Tracking', 'portugal-ctt-tracking-woocommerce' ); ?></h2>
			<?php
			$this->tracking_information_table( $order_object->get_id() );
		}
	}

	/**
	 * Display CTT tracking information in WooCommerce emails.
	 *
	 * Outputs tracking information in WooCommerce email templates with
	 * configurable link behavior and both HTML and plain text support.
	 *
	 * @param WC_Order $order_object   The WooCommerce order object.
	 * @param bool     $sent_to_admin  Whether email is sent to admin.
	 * @param bool     $plain_text     Whether email is plain text format.
	 */
	public function ctt_tracking_email_details( $order_object, $sent_to_admin = false, $plain_text = false ) {
		ob_start();
		$this->maybe_change_locale( $order_object );
		$ctt_tracking_code = $order_object->get_meta( '_ctt_tracking_code' );
		if ( ! empty( $ctt_tracking_code ) ) {
			switch ( get_option( 'ctt_tracking_email_link_type' ) ) {
				case 'none':
					$link      = false;
					$link_text = '';
					break;
				case 'ctt':
					$link      = $this->build_more_url( $ctt_tracking_code );
					$link_text = __( 'More details at ctt.pt', 'portugal-ctt-tracking-woocommerce' );
					break;
				case 'website':
				default:
					$link      = $order_object->get_view_order_url() . '#ctt_tracking';
					$link_text = __( 'More details on our website', 'portugal-ctt-tracking-woocommerce' );
					break;
			}
			if ( $plain_text ) {
				// Not done yet
				echo "\n" . esc_html( strtoupper( __( 'CTT Tracking', 'portugal-ctt-tracking-woocommerce' ) ) ) . "\n";
				echo esc_html__( 'Tracking code', 'portugal-ctt-tracking-woocommerce' ) . ': ' . esc_html( $ctt_tracking_code ) . "\n";
				if ( $link ) {
					echo esc_html( $link_text ) . ': ' . esc_html( $link ) . "\n";
				}
			} else {
				?>
				<h3><?php esc_html_e( 'CTT Tracking', 'portugal-ctt-tracking-woocommerce' ); ?></h3>
				<p>
					<strong><?php esc_html_e( 'Tracking code', 'portugal-ctt-tracking-woocommerce' ); ?>:</strong>
					<?php echo esc_html( $ctt_tracking_code ); ?>
					<?php if ( $link ) { ?>
						<br/>
						<small>
							<a href="<?php echo esc_url( $link ); ?>" target="blank"><?php echo esc_html( $link_text ); ?></a>
						</small>
					<?php } ?>
				</p>
				<?php
			}
		}
		echo wp_kses_post( apply_filters( 'portugal_ctt_tracking_email_info', ob_get_clean(), $order_object, $sent_to_admin, $plain_text ) );
	}

	/**
	 * Change locale for multilingual order processing.
	 *
	 * Handles locale switching for WPML and multilingual setups to ensure
	 * tracking information is displayed in the correct language for the order.
	 *
	 * @param WC_Order $order_object The WooCommerce order object.
	 */
	public function maybe_change_locale( $order_object ) {
		if ( apply_filters( 'portugal_ctt_tracking_maybe_change_email_locale', false ) ) { // Since 2025-10-08 only try this if forced by filter
			if ( $this->wpml_active ) {
				// Just for WPML
				global $sitepress;
				if ( $sitepress ) {
					$lang = $order_object->get_meta( 'wpml_language' );
					if ( ! empty( $lang ) ) {
						$this->locale = $sitepress->get_locale( $lang );
					}
				}
			} elseif ( is_admin() ) {
				// Store language !== current user/admin language?
				$current_user_lang = get_user_locale( wp_get_current_user() );
				if ( $current_user_lang !== get_locale() ) {
					$this->locale = get_locale();
				}
			}
			if ( ! empty( $this->locale ) ) {
				// Unload
				unload_textdomain( 'portugal-ctt-tracking-woocommerce' );
				add_filter( 'plugin_locale', array( $this, 'set_locale_for_emails' ), 10, 2 );
				load_plugin_textdomain( 'portugal-ctt-tracking-woocommerce' );
				remove_filter( 'plugin_locale', array( $this, 'set_locale_for_emails' ), 10, 2 );
			}
		}
	}

	/**
	 * Set locale for email translations.
	 *
	 * Filter callback to set the appropriate locale for plugin translations
	 * when processing emails in multilingual environments.
	 *
	 * @param string $locale The current locale.
	 * @param string $domain The text domain.
	 * @return string The filtered locale.
	 */
	public function set_locale_for_emails( $locale, $domain ) {
		if ( $domain === 'portugal-ctt-tracking-woocommerce' && $this->locale ) {
			$locale = $this->locale;
		}
		return $locale;
	}

	/**
	 * Get information from CTT Website
	 * Not used anymore
	 *
	 * @param string $ctt_tracking_code The tracking code.
	 * @return array
	 */
	public function ctt_tracking_get_info_body( $ctt_tracking_code ) {
		// POST
		$response = wp_remote_post(
			$this->ctt_url,
			array(
				'body' => array(
					'showResults' => 'true',
					'objects'     => $ctt_tracking_code,
				),
			)
		);
		// OK?
		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => false,
				'message' => __( 'Error getting tracking information', 'portugal-ctt-tracking-woocommerce' ) . ': ' . $response->get_error_message(),
			);
		} elseif ( intval( wp_remote_retrieve_response_code( $response ) ) !== 200 ) {
			// 200?
			return array(
				'status'  => false,
				'message' => __( 'Error getting tracking information', 'portugal-ctt-tracking-woocommerce' ) . ': ' . wp_remote_retrieve_response_message( $response ),
			);
		} else {
			$body = wp_remote_retrieve_body( $response );
			// Some basic tests
			if ( trim( $body ) !== '' && stristr( $body, 'objectSearchResult' ) ) {
				return array(
					'status' => true,
					'body'   => trim( $body ),
				);
			} else {
				return array(
					'status'  => false,
					'message' => __( 'Error getting tracking information', 'portugal-ctt-tracking-woocommerce' ) . ': ' . __( 'HTML not found', 'portugal-ctt-tracking-woocommerce' ),
				);
			}
		}
	}

	/**
	 * Set tracking code for an order (third-party API).
	 *
	 * Public method allowing third-party plugins to programmatically set
	 * a tracking code for an order and trigger automatic information updates.
	 *
	 * @param int    $order_id           The WooCommerce order ID.
	 * @param string $ctt_tracking_code  The CTT tracking code to set.
	 */
	public function ctt_tracking_set_tracking_code_for_order( $order_id, $ctt_tracking_code ) {
		$order_object = wc_get_order( $order_id );
		if ( ! empty( $order_object ) ) {
			$order_object->update_meta_data( '_ctt_tracking_code', $ctt_tracking_code );
			$order_object->save();
			// Update Tracking info
			$this->ctt_tracking_get_info_for_order( $order_object->get_id() );
		}
	}

	/**
	 * Get and store CTT tracking information for an order.
	 *
	 * Retrieves tracking information from CTT and stores it as order metadata.
	 * Currently returns a message indicating CTT API unavailability due to
	 * changes in their systems.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return array|false Array of tracking info or false on failure.
	 */
	public function ctt_tracking_get_info_for_order( $order_id ) {
		$order_object = wc_get_order( $order_id );
		if ( ! empty( $order_object ) ) {
			$ctt_tracking_code = $order_object->get_meta( '_ctt_tracking_code' );
			if ( ! empty( trim( $ctt_tracking_code ) ) ) {

				// phpcs:disable
				// Until 2022-09-01
				// $info = $this->ctt_tracking_get_info( trim( $ctt_tracking_code ) ); // Completely removed on 2.2
				// $info[ 'last_update' ] = date_i18n( 'Y-m-d H:i' );
				// phpcs:enable

				// After 2022-09-01
				$info = array(
					'status'      => 0,
					'message'     => sprintf(
						/* translators: 1: Link to CTT tracking page. */
						__( 'Due to changes in CTT, the tracking information is currently unavailable and can only be accessed through their website, <a href="%s" target="_blank">here</a>.', 'portugal-ctt-tracking-woocommerce' ),
						esc_url( $this->build_more_url( $ctt_tracking_code ) )
					),
					'last_update' => date_i18n( 'Y-m-d H:i' ),
				);

				$order_object->update_meta_data( '_ctt_tracking_info', $info );
				$order_object->save();
				// return it
				return $info;
			}
		}
		return false;
	}

	/**
	 * Clean HTML node content.
	 *
	 * Utility function to clean and decode HTML content from tracking data.
	 *
	 * @param string $content The HTML content to clean.
	 * @return string The cleaned content.
	 */
	private function ctt_tracking_clean_html_node_content( $content ) {
		return html_entity_decode( trim( $content ) );
	}

	/**
	 * Fix and normalize date format.
	 *
	 * Converts various Portuguese date formats to a standardized format
	 * for consistent date handling in tracking information.
	 *
	 * @param string $date The date string to fix.
	 * @return string The normalized date string.
	 */
	private function ctt_tracking_fix_date( $date ) {
		$date = trim( $date );
		// aaaa/mm/dd ?
		if ( strlen( $date ) === 10 && stristr( $date, '/' ) ) {
			$date = str_replace( '/', '-', $date );
		} elseif ( stristr( $date, ',' ) ) {
				$temp = explode( ',', $date );
				$temp = explode( ' ', trim( $temp[1] ) );
				// Year
				$date = array( $temp[2] );
				// Month
				$months = array(
					'Janeiro'   => '01',
					'Fevereiro' => '02',
					'Março'     => '03',
					'Abril'     => '04',
					'Maio'      => '05',
					'Junho'     => '06',
					'Julho'     => '07',
					'Agosto'    => '08',
					'Setembro'  => '09',
					'Outubro'   => '10',
					'Novembro'  => '11',
					'Dezembro'  => '12',
				);
				$date[] = $months[ trim( $temp[1] ) ];
				// Day
				$date[] = intval( $temp[0] ) < 10 ? '0' . intval( $temp[0] ) : intval( $temp[0] );
				$date   = implode( '-', $date );
		}
		return $date;
	}

	/**
	 * Check if tracking status indicates final delivery.
	 *
	 * Determines whether the given tracking status represents a final
	 * delivery state (package delivered) to prevent unnecessary updates.
	 *
	 * @param string $status The tracking status to check.
	 * @return bool True if status indicates final delivery, false otherwise.
	 */
	public function ctt_tracking_is_status_final( $status ) {
		switch ( trim( $status ) ) {
			case 'Objeto entregue':
			case 'Entregue':
				return true;
		}
		return false;
	}
}
