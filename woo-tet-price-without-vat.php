<?php
/**
 * Plugin Name:       Price With and Without VAT for WooCommerce
 * Plugin URI:        https://github.com/tetterip/woo-tet-price-without-vat
 * Description:       Shows product prices with and without VAT on the single product page, shop/category archives, and cart/checkout. Audience can be everyone, logged-in users, or selected roles (e.g. wholesale customers).
 * Version:           1.5.1
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

// Cart/Checkout blocks render via the Store API, not the classic
// woocommerce_cart_item_subtotal filter — expose per-line prices there.
add_action( 'woocommerce_blocks_loaded', 'ttrp_pwv_register_store_api_data' );

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

	// Product price on single + archives. We filter the price HTML rather than
	// hook classic template actions so it works with classic themes, block
	// templates, and page builders (Elementor) alike. Variations update natively
	// because each variation's price_html also runs through this filter.
	if ( 'no' !== ttrp_pwv_setting( 'show_single' ) || 'no' !== ttrp_pwv_setting( 'show_archive' ) ) {
		add_filter( 'woocommerce_get_price_html', 'ttrp_pwv_filter_price_html', 99, 2 );
	}

	// Cart / checkout (classic + Elementor) — replace the line subtotal with the
	// with/without VAT prices (exactly two prices, not the original plus two).
	if ( 'no' !== ttrp_pwv_setting( 'show_cart' ) || 'no' !== ttrp_pwv_setting( 'show_checkout' ) ) {
		add_filter( 'woocommerce_cart_item_subtotal', 'ttrp_pwv_cart_item_subtotal', 99, 3 );
	}

	add_action( 'wp_enqueue_scripts', 'ttrp_pwv_enqueue_front_assets' );

	// Cart/Checkout blocks integration.
	add_action( 'wp_enqueue_scripts', 'ttrp_pwv_enqueue_block_assets' );
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
	// No VAT distinction to show when taxes are disabled.
	if ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) {
		return false;
	}

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
 * @return array{suffix:string,line:string} Suffix + complementary line (both '' when nothing to show).
 */
function ttrp_pwv_get_html( $product, $context = 'single' ) {
	$empty = array(
		'suffix' => '',
		'line'   => '',
	);

	if ( ! $product instanceof WC_Product ) {
		return $empty;
	}

	$show_incl = 'no' !== ttrp_pwv_setting( 'show_incl' );
	$show_excl = 'no' !== ttrp_pwv_setting( 'show_excl' );
	if ( ! $show_incl && ! $show_excl ) {
		return $empty;
	}

	// Non-taxable products have no VAT distinction — skip.
	if ( 'taxable' !== $product->get_tax_status() ) {
		return $empty;
	}

	$incl_html = '';
	$excl_html = '';

	if ( $product->is_type( 'variable' ) ) {
		$incl_html = ttrp_pwv_variable_range_html( $product, 'incl' );
		$excl_html = ttrp_pwv_variable_range_html( $product, 'excl' );
	} else {
		// Use the wholesale price when the visitor is a wholesale customer.
		$wholesale = ttrp_pwv_wholesale_prices( $product );
		$incl      = null !== $wholesale ? $wholesale['incl'] : wc_get_price_including_tax( $product );
		$excl      = null !== $wholesale ? $wholesale['excl'] : wc_get_price_excluding_tax( $product );
		$incl_html = wc_price( $incl );
		$excl_html = wc_price( $excl );
	}

	return ttrp_pwv_suffix_parts( $incl_html, $excl_html, $context, ttrp_pwv_display_mode( $context ) );
}

/**
 * Min–max range markup (incl/excl tax) for a variable product, honouring
 * WooCommerce Wholesale Prices when the visitor is a wholesale customer.
 *
 * @param WC_Product_Variable $product Variable product.
 * @param string              $which   'incl' | 'excl'.
 * @return string
 */
function ttrp_pwv_variable_range_html( $product, $which ) {
	$wholesale = ttrp_pwv_wholesale_range( $product );
	if ( null !== $wholesale ) {
		$min_raw = $wholesale['min'];
		$max_raw = $wholesale['max'];
	} else {
		$min_raw = $product->get_variation_price( 'min', true );
		$max_raw = $product->get_variation_price( 'max', true );
	}

	$args = 'incl' === $which ? 'wc_get_price_including_tax' : 'wc_get_price_excluding_tax';
	$min  = call_user_func( $args, $product, array( 'price' => $min_raw ) );
	$max  = call_user_func( $args, $product, array( 'price' => $max_raw ) );

	if ( $min === $max ) {
		return wc_price( $min );
	}
	return wc_format_price_range( $min, $max );
}

// ---------------------------------------------------------------------------
// WooCommerce Wholesale Prices integration
// ---------------------------------------------------------------------------

/**
 * Current user's wholesale role keys (from the WooCommerce Wholesale Prices
 * plugin), or an empty array when the plugin is inactive / user isn't wholesale.
 *
 * @return string[]
 */
function ttrp_pwv_wholesale_roles() {
	if ( ! class_exists( 'WWP_Wholesale_Roles' ) ) {
		return array();
	}
	$roles = WWP_Wholesale_Roles::getInstance()->getUserWholesaleRole();
	return is_array( $roles ) ? $roles : array();
}

/**
 * Wholesale incl/excl VAT amounts for a simple product or a variation.
 *
 * Reads the raw wholesale price the WooCommerce Wholesale Prices plugin stores
 * for the current user's role, then runs it through WooCommerce's own tax
 * functions so the VAT math matches regular products. Returns null to fall back
 * to the regular price (no plugin, not a wholesale user, or no wholesale price).
 *
 * @param WC_Product $product Product or variation.
 * @return array{incl:float,excl:float}|null
 */
function ttrp_pwv_wholesale_prices( $product ) {
	if ( ! class_exists( 'WWP_Wholesale_Prices' ) || ! method_exists( 'WWP_Wholesale_Prices', 'get_product_raw_wholesale_price' ) ) {
		return null;
	}
	$roles = ttrp_pwv_wholesale_roles();
	if ( empty( $roles ) ) {
		return null;
	}

	$raw = WWP_Wholesale_Prices::get_product_raw_wholesale_price( $product->get_id(), $roles );
	if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
		return null;
	}

	return array(
		'incl' => wc_get_price_including_tax( $product, array( 'price' => $raw ) ),
		'excl' => wc_get_price_excluding_tax( $product, array( 'price' => $raw ) ),
	);
}

/**
 * Wholesale min/max raw prices for a variable product, or null to fall back to
 * the regular variation range. The Wholesale Prices plugin stores these on the
 * parent as {role}_min_wholesale_price / {role}_max_wholesale_price.
 *
 * @param WC_Product $product Variable product.
 * @return array{min:string,max:string}|null
 */
function ttrp_pwv_wholesale_range( $product ) {
	if ( ! $product->is_type( 'variable' ) ) {
		return null;
	}
	$roles = ttrp_pwv_wholesale_roles();
	if ( empty( $roles ) ) {
		return null;
	}

	$role    = $roles[0];
	$min_raw = $product->get_meta( $role . '_min_wholesale_price', true );
	$max_raw = $product->get_meta( $role . '_max_wholesale_price', true );

	if ( ! is_numeric( $min_raw ) || ! is_numeric( $max_raw ) ) {
		return null;
	}

	return array(
		'min' => $min_raw,
		'max' => $max_raw,
	);
}

/**
 * Whether the displayed price already includes tax in the given context.
 *
 * @param string $context 'single' | 'archive' | 'cart'.
 * @return string 'incl' | 'excl'.
 */
function ttrp_pwv_display_mode( $context ) {
	$option = ( 'cart' === $context )
		? get_option( 'woocommerce_tax_display_cart' )
		: get_option( 'woocommerce_tax_display_shop' );

	return 'excl' === $option ? 'excl' : 'incl';
}

/**
 * Markup appended after WooCommerce's main price: a suffix label on the main
 * price (matching the store's tax-display mode) plus one line for the
 * complementary VAT price.
 *
 * @param string $incl_html     Formatted including-VAT price HTML.
 * @param string $excl_html     Formatted excluding-VAT price HTML.
 * @param string $context       'single' | 'archive' | 'cart'.
 * @param string $display_mode  'incl' | 'excl' — what the main price represents.
 * @return string
 */
function ttrp_pwv_suffix_and_line( $incl_html, $excl_html, $context, $display_mode ) {
	$parts = ttrp_pwv_suffix_parts( $incl_html, $excl_html, $context, $display_mode );
	return $parts['suffix'] . $parts['line'];
}

/**
 * Same as {@see ttrp_pwv_suffix_and_line()} but returns the suffix and the
 * complementary line separately so callers can place them independently (the
 * single/archive path injects the suffix inside the Wholesale Prices container).
 *
 * @param string $incl_html     Formatted including-VAT price HTML.
 * @param string $excl_html     Formatted excluding-VAT price HTML.
 * @param string $context       'single' | 'archive' | 'cart'.
 * @param string $display_mode  'incl' | 'excl' — what the main price represents.
 * @return array{suffix:string,line:string}
 */
function ttrp_pwv_suffix_parts( $incl_html, $excl_html, $context, $display_mode ) {
	$show_incl  = 'no' !== ttrp_pwv_setting( 'show_incl' );
	$show_excl  = 'no' !== ttrp_pwv_setting( 'show_excl' );
	$label_incl = ttrp_pwv_setting( 'label_incl' );
	$label_excl = ttrp_pwv_setting( 'label_excl' );

	$display_is_incl = ( 'incl' === $display_mode );

	// The displayed (main) price is labelled with a suffix; the other VAT
	// variant is shown on its own line below.
	$suffix_label = $display_is_incl ? $label_incl : $label_excl;
	$suffix_show  = $display_is_incl ? $show_incl : $show_excl;
	$comp_html    = $display_is_incl ? $excl_html : $incl_html;
	$comp_label   = $display_is_incl ? $label_excl : $label_incl;
	$comp_show    = $display_is_incl ? $show_excl : $show_incl;

	$suffix = '';
	if ( $suffix_show && '' !== $suffix_label ) {
		$suffix = sprintf( ' <span class="ttrp-pwv__suffix">%s</span>', esc_html( $suffix_label ) );
	}

	$line = '';
	if ( $comp_show && '' !== $comp_html ) {
		$line = sprintf(
			'<span class="ttrp-pwv ttrp-pwv--%s"><span class="ttrp-pwv__line ttrp-pwv__comp"><span class="ttrp-pwv__amount">%s</span> <span class="ttrp-pwv__label">%s</span></span></span>',
			esc_attr( $context ),
			wp_kses_post( $comp_html ),
			esc_html( $comp_label )
		);
	}

	return array(
		'suffix' => $suffix,
		'line'   => $line,
	);
}

// ---------------------------------------------------------------------------
// Render — single / archive (via the price HTML filter)
// ---------------------------------------------------------------------------

/**
 * Whether $product is the product being viewed on a single product page.
 * Variations resolve to their parent so a selected variation still matches.
 *
 * @param WC_Product $product Product or variation.
 * @return bool
 */
function ttrp_pwv_is_main_product( $product ) {
	$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	return get_queried_object_id() === $id;
}

/**
 * Append the with/without VAT lines to a product's price HTML.
 *
 * Runs for classic themes, block templates (woocommerce/product-price), and
 * page builders (Elementor) since they all call WC_Product::get_price_html().
 *
 * @param string     $price_html Existing price HTML.
 * @param WC_Product $product    Product (or variation).
 * @return string
 */
function ttrp_pwv_filter_price_html( $price_html, $product ) {
	if ( is_admin() || ! $product instanceof WC_Product ) {
		return $price_html;
	}
	if ( ! ttrp_pwv_should_display() ) {
		return $price_html;
	}

	$context = '';
	if ( is_product() && 'no' !== ttrp_pwv_setting( 'show_single' ) && ttrp_pwv_is_main_product( $product ) ) {
		$context = 'single';
	} elseif ( ( is_shop() || is_product_taxonomy() ) && 'no' !== ttrp_pwv_setting( 'show_archive' ) ) {
		$context = 'archive';
	}

	if ( '' === $context ) {
		return $price_html;
	}

	$parts = ttrp_pwv_get_html( $product, $context );
	if ( '' === $parts['suffix'] && '' === $parts['line'] ) {
		return $price_html;
	}
	return ttrp_pwv_merge_price_html( $price_html, $parts['suffix'], $parts['line'] );
}

/**
 * Merge the VAT suffix and complementary line into a product's price HTML.
 *
 * Normally the suffix is appended inline after WooCommerce's main price. When
 * the WooCommerce Wholesale Prices plugin is active it renders the wholesale
 * price inside a block-level `.wholesale_price_container`, which would push a
 * trailing suffix onto its own line. In that case the suffix is injected inside
 * the container (right after the wholesale price's suffix slot) so it stays next
 * to the wholesale amount. The complementary line is always appended at the end.
 *
 * @param string $price_html  Existing price HTML.
 * @param string $suffix_html VAT suffix markup (may be '').
 * @param string $line_html   Complementary VAT line markup (may be '').
 * @return string
 */
function ttrp_pwv_merge_price_html( $price_html, $suffix_html, $line_html ) {
	if ( '' !== $suffix_html ) {
		$placed = false;

		// Inject the suffix inside the Wholesale Prices container, after the
		// wholesale price's (usually empty) suffix slot, so it stays inline.
		$anchor = strpos( $price_html, 'wholesale-price-suffix' );
		if ( false !== $anchor ) {
			$close = strpos( $price_html, '</small>', $anchor );
			if ( false !== $close ) {
				$price_html = substr_replace( $price_html, $suffix_html, $close + strlen( '</small>' ), 0 );
				$placed     = true;
			}
		}

		if ( ! $placed ) {
			$price_html .= $suffix_html;
		}
	}

	return $price_html . $line_html;
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
	$incl_html = wc_price( wc_get_price_including_tax( $product, array( 'qty' => $qty ) ) );
	$excl_html = wc_price( wc_get_price_excluding_tax( $product, array( 'qty' => $qty ) ) );

	// Keep WooCommerce's subtotal, add a VAT suffix to it, and append one line
	// for the complementary VAT price.
	$append = ttrp_pwv_suffix_and_line( $incl_html, $excl_html, 'cart', ttrp_pwv_display_mode( 'cart' ) );
	return '' === $append ? $subtotal : $subtotal . $append;
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
}

// ---------------------------------------------------------------------------
// Cart / Checkout blocks (Store API + registered checkout filters)
// ---------------------------------------------------------------------------

/**
 * A clean, locale-formatted price string (no HTML) for the Store API / JS.
 *
 * @param float $amount Amount to format.
 * @return string
 */
function ttrp_pwv_plain_price( $amount ) {
	return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
}

/**
 * Register incl/excl VAT prices on each Store API cart line item.
 */
function ttrp_pwv_register_store_api_data() {
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		return;
	}
	woocommerce_store_api_register_endpoint_data(
		array(
			'endpoint'        => 'cart-items',
			'namespace'       => 'ttrp-price-without-vat',
			'data_callback'   => 'ttrp_pwv_store_api_item_data',
			'schema_callback' => 'ttrp_pwv_store_api_item_schema',
			'schema_type'     => ARRAY_A,
		)
	);
}

/**
 * Per-line-item data added to the Store API cart-items response.
 *
 * @param array $cart_item Cart item array.
 * @return array{incl:string,excl:string}
 */
function ttrp_pwv_store_api_item_data( $cart_item ) {
	$empty = array(
		'incl' => '',
		'excl' => '',
	);

	if ( ! ttrp_pwv_should_display() ) {
		return $empty;
	}
	if ( 'no' === ttrp_pwv_setting( 'show_cart' ) && 'no' === ttrp_pwv_setting( 'show_checkout' ) ) {
		return $empty;
	}
	if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
		return $empty;
	}

	$product = $cart_item['data'];
	if ( 'taxable' !== $product->get_tax_status() ) {
		return $empty;
	}

	$qty       = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
	$show_incl = 'no' !== ttrp_pwv_setting( 'show_incl' );
	$show_excl = 'no' !== ttrp_pwv_setting( 'show_excl' );

	return array(
		'incl' => $show_incl ? ttrp_pwv_plain_price( wc_get_price_including_tax( $product, array( 'qty' => $qty ) ) ) : '',
		'excl' => $show_excl ? ttrp_pwv_plain_price( wc_get_price_excluding_tax( $product, array( 'qty' => $qty ) ) ) : '',
	);
}

/**
 * Schema for the Store API cart-item data.
 */
function ttrp_pwv_store_api_item_schema() {
	return array(
		'incl' => array(
			'description' => __( 'Line subtotal including VAT, formatted.', 'price-without-vat' ),
			'type'        => array( 'string', 'null' ),
			'readonly'    => true,
		),
		'excl' => array(
			'description' => __( 'Line subtotal excluding VAT, formatted.', 'price-without-vat' ),
			'type'        => array( 'string', 'null' ),
			'readonly'    => true,
		),
	);
}

/**
 * Enqueue the block integration script on cart/checkout pages.
 */
function ttrp_pwv_enqueue_block_assets() {
	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}
	if ( 'no' === ttrp_pwv_setting( 'show_cart' ) && 'no' === ttrp_pwv_setting( 'show_checkout' ) ) {
		return;
	}
	if ( ! ttrp_pwv_should_display() ) {
		return;
	}
	if ( ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
		return; // Classic cart/checkout — the woocommerce_cart_item_subtotal filter handles it.
	}

	wp_enqueue_script(
		'ttrp-pwv-blocks',
		plugin_dir_url( __FILE__ ) . 'assets/pwv-blocks.js',
		array( 'wc-blocks-checkout' ),
		TTRP_PWV_VERSION,
		true
	);
	wp_localize_script(
		'ttrp-pwv-blocks',
		'ttrpPwvBlocks',
		array(
			'showCart'     => 'no' !== ttrp_pwv_setting( 'show_cart' ),
			'showCheckout' => 'no' !== ttrp_pwv_setting( 'show_checkout' ),
			'showIncl'     => 'no' !== ttrp_pwv_setting( 'show_incl' ),
			'showExcl'     => 'no' !== ttrp_pwv_setting( 'show_excl' ),
			'labelIncl'    => ttrp_pwv_setting( 'label_incl' ),
			'labelExcl'    => ttrp_pwv_setting( 'label_excl' ),
			// 'incl' or 'excl' — what the block's displayed subtotal already represents.
			'displayMode'  => 'excl' === get_option( 'woocommerce_tax_display_cart' ) ? 'excl' : 'incl',
		)
	);
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
			<p class="description"><?php esc_html_e( 'The price that matches your WooCommerce tax display setting is shown as a suffix on the main price; the other is added on a separate line below.', 'price-without-vat' ); ?></p>
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
