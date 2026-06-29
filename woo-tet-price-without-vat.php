<?php
/**
 * Plugin Name:       Price With and Without VAT for WooCommerce
 * Plugin URI:        https://github.com/tetterip/woo-tet-price-without-vat
 * Description:       Shows product prices with and without VAT on the single product page, shop/category archives, and cart/checkout. Audience can be everyone, logged-in users, or selected roles (e.g. wholesale customers).
 * Version:           1.1.0
 * Author:            Michalis Tetteris
 * Author URI:        https://ttrp.gr
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       price-without-vat
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      7.4
 * WC tested up to:   10.7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'TTRP_PWV_VERSION', get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] );
define( 'TTRP_PWV_MIN_WC', '7.0' );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'init', 'ttrp_pwv_init' );

function ttrp_pwv_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	// Require a minimum WooCommerce version — show a notice and bail otherwise.
	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, TTRP_PWV_MIN_WC, '<' ) ) {
		add_action( 'admin_notices', 'ttrp_pwv_wc_version_notice' );
		return;
	}

	load_plugin_textdomain( 'price-without-vat', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Admin: ttrp.gr Plugins > Price With/Without VAT.
	add_action( 'admin_menu', 'ttrp_pwv_maybe_register_brand_menu', 5 );
	add_action( 'admin_menu', 'ttrp_pwv_admin_menu' );
	add_action( 'admin_enqueue_scripts', 'ttrp_pwv_enqueue_admin_assets' );

	// Front-end rendering hooks (storefront only).
	if ( is_admin() ) {
		return;
	}

	// Single product page — after the price (priority 10).
	if ( 'no' !== ttrp_pwv_setting( 'show_single' ) ) {
		add_action( 'woocommerce_single_product_summary', 'ttrp_pwv_render_single', 11 );
	}

	// Shop / category archives — after the loop price (priority 10).
	if ( 'no' !== ttrp_pwv_setting( 'show_archive' ) ) {
		add_action( 'woocommerce_after_shop_loop_item_title', 'ttrp_pwv_render_archive', 11 );
	}

	// Cart / checkout — appended to the line item subtotal column.
	if ( 'no' !== ttrp_pwv_setting( 'show_cart' ) || 'no' !== ttrp_pwv_setting( 'show_checkout' ) ) {
		add_filter( 'woocommerce_cart_item_subtotal', 'ttrp_pwv_cart_item_subtotal', 10, 3 );
	}

	// Variation support on the single product page.
	add_filter( 'woocommerce_available_variation', 'ttrp_pwv_available_variation', 10, 3 );
	add_action( 'wp_enqueue_scripts', 'ttrp_pwv_enqueue_front_assets' );
}

/**
 * Admin notice shown when the active WooCommerce version is too old.
 */
function ttrp_pwv_wc_version_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$message = sprintf(
		/* translators: 1: required WooCommerce version, 2: active WooCommerce version. */
		esc_html__( 'Price With/Without VAT requires WooCommerce %1$s or newer. You are running %2$s. The plugin is inactive until WooCommerce is updated.', 'price-without-vat' ),
		esc_html( TTRP_PWV_MIN_WC ),
		esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '' )
	);
	printf( '<div class="notice notice-error"><p>%s</p></div>', $message ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Message escaped above.
}

// ---------------------------------------------------------------------------
// Settings helpers
// ---------------------------------------------------------------------------

function ttrp_pwv_defaults() {
	return array(
		'audience'      => 'everyone', // 'everyone' | 'logged_in' | 'roles'.
		'roles'         => array(),
		'show_single'   => 'yes',
		'show_archive'  => 'yes',
		'show_cart'     => 'no',
		'show_checkout' => 'no',
		'show_incl'     => 'yes',
		'show_excl'     => 'yes',
		'label_incl'    => __( 'incl. VAT', 'price-without-vat' ),
		'label_excl'    => __( 'excl. VAT', 'price-without-vat' ),
	);
}

function ttrp_pwv_settings() {
	static $cache = null;
	if ( null === $cache ) {
		$cache = wp_parse_args( get_option( 'ttrp_pwv_settings', array() ), ttrp_pwv_defaults() );
	}
	return $cache;
}

function ttrp_pwv_setting( $key ) {
	$settings = ttrp_pwv_settings();
	return $settings[ $key ] ?? '';
}

// ---------------------------------------------------------------------------
// Audience gate
// ---------------------------------------------------------------------------

/**
 * Pure role-matching helper — true if any user role is in the allowed list.
 *
 * @param string[] $user_roles    Roles the current user has.
 * @param string[] $allowed_roles Roles allowed to see the dual price.
 * @return bool
 */
function ttrp_pwv_user_matches( array $user_roles, array $allowed_roles ) {
	if ( empty( $allowed_roles ) ) {
		return false;
	}
	return count( array_intersect( $user_roles, $allowed_roles ) ) > 0;
}

/**
 * Whether the dual price should be shown to the current visitor.
 */
function ttrp_pwv_should_display() {
	$audience = ttrp_pwv_setting( 'audience' );

	if ( 'everyone' === $audience ) {
		return true;
	}

	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( 'logged_in' === $audience ) {
		return true;
	}

	// 'roles'.
	$user  = wp_get_current_user();
	$roles = (array) $user->roles;
	return ttrp_pwv_user_matches( $roles, (array) ttrp_pwv_setting( 'roles' ) );
}

// ---------------------------------------------------------------------------
// Price computation
// ---------------------------------------------------------------------------

/**
 * Build the dual-price markup for a product.
 *
 * @param WC_Product $product Product (or variation) to price.
 * @param string     $context 'single' | 'archive' | 'cart'.
 * @return string HTML, or '' when nothing to show.
 */
function ttrp_pwv_get_html( $product, $context = 'single' ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}

	$show_incl = 'no' !== ttrp_pwv_setting( 'show_incl' );
	$show_excl = 'no' !== ttrp_pwv_setting( 'show_excl' );
	if ( ! $show_incl && ! $show_excl ) {
		return '';
	}

	// Non-taxable products have no VAT distinction — skip.
	if ( 'taxable' !== $product->get_tax_status() ) {
		return '';
	}

	$incl_html = '';
	$excl_html = '';

	if ( $product->is_type( 'variable' ) ) {
		$incl_html = ttrp_pwv_variable_range_html( $product, 'incl' );
		$excl_html = ttrp_pwv_variable_range_html( $product, 'excl' );
	} else {
		$incl_html = wc_price( wc_get_price_including_tax( $product ) );
		$excl_html = wc_price( wc_get_price_excluding_tax( $product ) );
	}

	return ttrp_pwv_lines_markup( $incl_html, $excl_html, $context, $show_incl, $show_excl );
}

/**
 * Min–max range markup (incl/excl tax) for a variable product.
 *
 * @param WC_Product_Variable $product Variable product.
 * @param string              $which   'incl' | 'excl'.
 * @return string
 */
function ttrp_pwv_variable_range_html( $product, $which ) {
	$min_raw = $product->get_variation_price( 'min', true );
	$max_raw = $product->get_variation_price( 'max', true );

	$args    = 'incl' === $which ? 'wc_get_price_including_tax' : 'wc_get_price_excluding_tax';
	$min     = call_user_func( $args, $product, array( 'price' => $min_raw ) );
	$max     = call_user_func( $args, $product, array( 'price' => $max_raw ) );

	if ( $min === $max ) {
		return wc_price( $min );
	}
	return wc_format_price_range( $min, $max );
}

/**
 * Assemble the two labelled price lines.
 */
function ttrp_pwv_lines_markup( $incl_html, $excl_html, $context, $show_incl, $show_excl ) {
	$label_incl = ttrp_pwv_setting( 'label_incl' );
	$label_excl = ttrp_pwv_setting( 'label_excl' );

	$lines = '';
	if ( $show_incl && '' !== $incl_html ) {
		$lines .= sprintf(
			'<span class="ttrp-pwv__line ttrp-pwv__incl"><span class="ttrp-pwv__amount">%s</span> <span class="ttrp-pwv__label">%s</span></span>',
			wp_kses_post( $incl_html ),
			esc_html( $label_incl )
		);
	}
	if ( $show_excl && '' !== $excl_html ) {
		$lines .= sprintf(
			'<span class="ttrp-pwv__line ttrp-pwv__excl"><span class="ttrp-pwv__amount">%s</span> <span class="ttrp-pwv__label">%s</span></span>',
			wp_kses_post( $excl_html ),
			esc_html( $label_excl )
		);
	}

	if ( '' === $lines ) {
		return '';
	}

	return sprintf( '<span class="ttrp-pwv ttrp-pwv--%s">%s</span>', esc_attr( $context ), $lines );
}

// ---------------------------------------------------------------------------
// Render — single / archive
// ---------------------------------------------------------------------------

function ttrp_pwv_render_single() {
	if ( ! ttrp_pwv_should_display() ) {
		return;
	}
	global $product;
	echo ttrp_pwv_get_html( $product, 'single' ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Escaped within ttrp_pwv_get_html().
}

function ttrp_pwv_render_archive() {
	if ( ! ttrp_pwv_should_display() ) {
		return;
	}
	global $product;
	echo ttrp_pwv_get_html( $product, 'archive' ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Escaped within ttrp_pwv_get_html().
}

// ---------------------------------------------------------------------------
// Render — cart / checkout
// ---------------------------------------------------------------------------

/**
 * Append the dual-price note to a cart/checkout line item subtotal.
 *
 * @param string $subtotal  Formatted subtotal HTML.
 * @param array  $cart_item Cart item.
 * @param string $cart_item_key Cart item key.
 * @return string
 */
function ttrp_pwv_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
	if ( ! ttrp_pwv_should_display() ) {
		return $subtotal;
	}

	// Independent cart vs checkout toggles.
	if ( is_checkout() ) {
		if ( 'no' === ttrp_pwv_setting( 'show_checkout' ) ) {
			return $subtotal;
		}
	} elseif ( 'no' === ttrp_pwv_setting( 'show_cart' ) ) {
		return $subtotal;
	}

	if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
		return $subtotal;
	}

	$product = $cart_item['data'];
	if ( 'taxable' !== $product->get_tax_status() ) {
		return $subtotal;
	}

	$qty       = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
	$show_incl = 'no' !== ttrp_pwv_setting( 'show_incl' );
	$show_excl = 'no' !== ttrp_pwv_setting( 'show_excl' );

	$incl_html = $show_incl ? wc_price( wc_get_price_including_tax( $product, array( 'qty' => $qty ) ) ) : '';
	$excl_html = $show_excl ? wc_price( wc_get_price_excluding_tax( $product, array( 'qty' => $qty ) ) ) : '';

	$note = ttrp_pwv_lines_markup( $incl_html, $excl_html, 'cart', $show_incl, $show_excl );
	if ( '' === $note ) {
		return $subtotal;
	}

	return $subtotal . $note;
}

// ---------------------------------------------------------------------------
// Variation live update
// ---------------------------------------------------------------------------

/**
 * Inject per-variation dual-price HTML into the variation JS data.
 *
 * @param array                $data      Variation data passed to JS.
 * @param WC_Product_Variable  $product   Parent product.
 * @param WC_Product_Variation $variation Variation.
 * @return array
 */
function ttrp_pwv_available_variation( $data, $product, $variation ) {
	if ( ! ttrp_pwv_should_display() || 'no' === ttrp_pwv_setting( 'show_single' ) ) {
		return $data;
	}
	if ( 'taxable' !== $variation->get_tax_status() ) {
		$data['ttrp_pwv_html'] = '';
		return $data;
	}

	$show_incl = 'no' !== ttrp_pwv_setting( 'show_incl' );
	$show_excl = 'no' !== ttrp_pwv_setting( 'show_excl' );

	$incl_html = $show_incl ? wc_price( wc_get_price_including_tax( $variation ) ) : '';
	$excl_html = $show_excl ? wc_price( wc_get_price_excluding_tax( $variation ) ) : '';

	$data['ttrp_pwv_html'] = ttrp_pwv_lines_markup( $incl_html, $excl_html, 'single', $show_incl, $show_excl );
	return $data;
}

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------

function ttrp_pwv_enqueue_front_assets() {
	wp_enqueue_style(
		'ttrp-pwv',
		plugin_dir_url( __FILE__ ) . 'assets/price-without-vat.css',
		array(),
		TTRP_PWV_VERSION
	);

	if ( is_product() && ttrp_pwv_should_display() && 'no' !== ttrp_pwv_setting( 'show_single' ) ) {
		wp_enqueue_script(
			'ttrp-pwv-variation',
			plugin_dir_url( __FILE__ ) . 'assets/pwv-variation.js',
			array( 'jquery' ),
			TTRP_PWV_VERSION,
			true
		);
	}
}

// ---------------------------------------------------------------------------
// ttrp.gr Plugins admin menu (shared brand menu)
// ---------------------------------------------------------------------------

function ttrp_pwv_maybe_register_brand_menu(): void {
	global $menu;
	if ( is_array( $menu ) ) {
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && 'ttrp-plugins' === $item[2] ) {
				return;
			}
		}
	}
	if ( ! function_exists( 'ttrp_brand_dashboard' ) ) {
		function ttrp_brand_dashboard(): void {
			global $submenu;
			$target = null;
			if ( ! empty( $submenu['ttrp-plugins'] ) ) {
				foreach ( $submenu['ttrp-plugins'] as $item ) {
					if ( $item[2] !== 'ttrp-plugins' ) {
						$target = $item[2];
						break;
					}
				}
			}
			if ( $target ) {
				$url = esc_url( admin_url( 'admin.php?page=' . $target ) );
				echo '<meta http-equiv="refresh" content="0;url=' . $url . '">';
				echo '<script>window.location.replace("' . $url . '");</script>';
			}
		}
	}
	$svg_path = plugin_dir_path( __FILE__ ) . 'assets/ttrp-logo.svg';
	$icon     = file_exists( $svg_path )
		? 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $svg_path ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local SVG inlined for the menu icon.
		: 'dashicons-admin-plugins';
	add_menu_page(
		'ttrp.gr Plugins',
		'ttrp.gr Plugins',
		'manage_options',
		'ttrp-plugins',
		'ttrp_brand_dashboard',
		$icon,
		56
	);
	add_action( 'admin_menu', function () {
		remove_submenu_page( 'ttrp-plugins', 'ttrp-plugins' );
	}, 999 );
}

function ttrp_pwv_admin_menu(): void {
	add_submenu_page(
		'ttrp-plugins',
		__( 'Price With/Without VAT', 'price-without-vat' ),
		__( 'Price With/Without VAT', 'price-without-vat' ),
		'manage_options',
		'ttrp-price-without-vat',
		'ttrp_pwv_render_settings'
	);
}

function ttrp_pwv_enqueue_admin_assets(): void {
	if ( ! isset( $_GET['page'] ) || 'ttrp-price-without-vat' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		return;
	}
	wp_enqueue_style( 'woocommerce_admin_styles' );
	wp_enqueue_style( 'ttrp-admin', plugins_url( 'assets/ttrp-admin.css', __FILE__ ), array(), TTRP_PWV_VERSION );

	// Select2 for the roles field (WooCommerce ships it as wc-enhanced-select).
	wp_enqueue_script( 'wc-enhanced-select' );
	wp_enqueue_script(
		'ttrp-pwv-admin',
		plugins_url( 'assets/pwv-admin.js', __FILE__ ),
		array( 'jquery', 'wc-enhanced-select' ),
		TTRP_PWV_VERSION,
		true
	);
}

// ---------------------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------------------

function ttrp_pwv_render_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['save_ttrp_pwv'] ) && check_admin_referer( 'ttrp_pwv_save' ) ) {
		ttrp_pwv_save_settings();
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'price-without-vat' ) . '</p></div>';
	}

	$s          = ttrp_pwv_settings();
	$all_roles  = ttrp_pwv_get_roles();
	$audiences  = array(
		'everyone'  => __( 'Everyone', 'price-without-vat' ),
		'logged_in' => __( 'Logged-in users only', 'price-without-vat' ),
		'roles'     => __( 'Selected user roles', 'price-without-vat' ),
	);
	?>
	<div class="wrap woocommerce ttrp-wrap">
		<div class="ttrp-plugin-header">
			<h1><?php esc_html_e( 'Price With/Without VAT', 'price-without-vat' ); ?></h1>
			<span class="ttrp-plugin-version">v<?php echo esc_html( TTRP_PWV_VERSION ); ?></span>
		</div>
		<form method="post">
			<?php wp_nonce_field( 'ttrp_pwv_save' ); ?>

			<h2><?php esc_html_e( 'Audience', 'price-without-vat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show prices to', 'price-without-vat' ); ?></th>
					<td>
						<select name="ttrp_pwv[audience]" id="ttrp_pwv_audience">
							<?php foreach ( $audiences as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['audience'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Choose who sees the with/without VAT prices.', 'price-without-vat' ); ?></p>
					</td>
				</tr>
				<tr class="ttrp-pwv-roles-row"<?php echo 'roles' === $s['audience'] ? '' : ' style="display:none;"'; ?>>
					<th scope="row"><?php esc_html_e( 'User roles', 'price-without-vat' ); ?></th>
					<td>
						<select name="ttrp_pwv[roles][]" id="ttrp_pwv_roles" class="wc-enhanced-select ttrp-pwv-roles-select" multiple="multiple" style="min-width:320px;" data-placeholder="<?php esc_attr_e( 'Select roles…', 'price-without-vat' ); ?>">
							<?php foreach ( $all_roles as $role_key => $role_name ) : ?>
								<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( in_array( $role_key, (array) $s['roles'], true ) ); ?>><?php echo esc_html( $role_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Only used when "Selected user roles" is chosen above.', 'price-without-vat' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Display locations', 'price-without-vat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show in these locations', 'price-without-vat' ); ?></th>
					<td>
						<?php
						$locations = array(
							'show_single'   => __( 'Single product page', 'price-without-vat' ),
							'show_archive'  => __( 'Shop', 'price-without-vat' ),
							'show_cart'     => __( 'Cart', 'price-without-vat' ),
							'show_checkout' => __( 'Checkout', 'price-without-vat' ),
						);
						foreach ( $locations as $key => $label ) :
							?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="ttrp_pwv[<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( 'yes', $s[ $key ] ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Price lines', 'price-without-vat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Price including VAT', 'price-without-vat' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ttrp_pwv[show_incl]" value="yes" <?php checked( 'yes', $s['show_incl'] ); ?> />
							<?php esc_html_e( 'Show the price including VAT', 'price-without-vat' ); ?>
						</label>
						<br />
						<input type="text" name="ttrp_pwv[label_incl]" value="<?php echo esc_attr( $s['label_incl'] ); ?>" class="regular-text" style="margin-top:6px;" />
						<p class="description"><?php esc_html_e( 'Label shown next to the price including VAT.', 'price-without-vat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Price excluding VAT', 'price-without-vat' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ttrp_pwv[show_excl]" value="yes" <?php checked( 'yes', $s['show_excl'] ); ?> />
							<?php esc_html_e( 'Show the price excluding VAT', 'price-without-vat' ); ?>
						</label>
						<br />
						<input type="text" name="ttrp_pwv[label_excl]" value="<?php echo esc_attr( $s['label_excl'] ); ?>" class="regular-text" style="margin-top:6px;" />
						<p class="description"><?php esc_html_e( 'Label shown next to the price excluding VAT.', 'price-without-vat' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="save_ttrp_pwv" class="button-primary" value="<?php esc_attr_e( 'Save settings', 'price-without-vat' ); ?>" />
			</p>
		</form>
		<div class="ttrp-settings-footer">
			<img src="<?php echo esc_url( plugins_url( 'assets/ttrp.svg', __FILE__ ) ); ?>" alt="" />
			<span><?php esc_html_e( 'Price With/Without VAT by', 'price-without-vat' ); ?> <a href="https://ttrp.gr" target="_blank">ttrp.gr</a></span>
		</div>
	</div>
	<?php
}

/**
 * Sanitize and persist the settings form.
 */
function ttrp_pwv_save_settings(): void {
	$raw       = isset( $_POST['ttrp_pwv'] ) ? wp_unslash( $_POST['ttrp_pwv'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitized -- Sanitized field-by-field below.
	$defaults  = ttrp_pwv_defaults();
	$all_roles = array_keys( ttrp_pwv_get_roles() );

	$audience = isset( $raw['audience'] ) ? sanitize_key( $raw['audience'] ) : 'everyone';
	if ( ! in_array( $audience, array( 'everyone', 'logged_in', 'roles' ), true ) ) {
		$audience = 'everyone';
	}

	$roles = array();
	if ( ! empty( $raw['roles'] ) && is_array( $raw['roles'] ) ) {
		$roles = array_values( array_intersect( $all_roles, array_map( 'sanitize_key', $raw['roles'] ) ) );
	}

	$clean = array(
		'audience'      => $audience,
		'roles'         => $roles,
		'show_single'   => ! empty( $raw['show_single'] ) ? 'yes' : 'no',
		'show_archive'  => ! empty( $raw['show_archive'] ) ? 'yes' : 'no',
		'show_cart'     => ! empty( $raw['show_cart'] ) ? 'yes' : 'no',
		'show_checkout' => ! empty( $raw['show_checkout'] ) ? 'yes' : 'no',
		'show_incl'     => ! empty( $raw['show_incl'] ) ? 'yes' : 'no',
		'show_excl'     => ! empty( $raw['show_excl'] ) ? 'yes' : 'no',
		'label_incl'    => isset( $raw['label_incl'] ) ? sanitize_text_field( $raw['label_incl'] ) : $defaults['label_incl'],
		'label_excl'    => isset( $raw['label_excl'] ) ? sanitize_text_field( $raw['label_excl'] ) : $defaults['label_excl'],
	);

	update_option( 'ttrp_pwv_settings', $clean );
}

/**
 * All editable WordPress roles as key => display name.
 */
function ttrp_pwv_get_roles() {
	if ( ! function_exists( 'wp_roles' ) ) {
		return array();
	}
	$roles  = wp_roles()->get_names();
	$output = array();
	foreach ( $roles as $key => $name ) {
		$output[ $key ] = translate_user_role( $name );
	}
	return $output;
}

require_once plugin_dir_path( __FILE__ ) . 'update-checker.php';
new TTRP_Update_Checker( __FILE__, 'woo-tet-price-without-vat' );
