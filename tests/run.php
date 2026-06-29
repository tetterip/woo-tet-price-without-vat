<?php
/**
 * Zero-dependency test runner for woo-tet-price-without-vat.
 *
 * Stubs the handful of WordPress functions the main plugin file touches at
 * load time, includes it, then exercises the pure logic. No Composer/PHPUnit.
 *
 * Usage: php tests/run.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );

// --- Minimal WP stubs needed to load the plugin file -----------------------
function get_file_data( $file, $fields ) {
	return array( 'Version' => '1.0.0' );
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}
function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

// --- Stubs used by the functions under test --------------------------------
$GLOBALS['ttrp_pwv_test_options'] = array();
function get_option( $name, $default = false ) {
	return $GLOBALS['ttrp_pwv_test_options'][ $name ] ?? $default;
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function wp_kses_post( $text ) {
	return $text;
}

require dirname( __DIR__ ) . '/woo-tet-price-without-vat.php';

// --- Tiny assertion helpers ------------------------------------------------
$pass = 0;
$fail = 0;
$failures = array();

function check( $label, $condition ) {
	global $pass, $fail, $failures;
	if ( $condition ) {
		$pass++;
		echo "  \xE2\x9C\x93 {$label}\n";
	} else {
		$fail++;
		$failures[] = $label;
		echo "  \xE2\x9C\x97 {$label}\n";
	}
}

// --- ttrp_pwv_user_matches() ----------------------------------------------
check( 'matching role returns true', ttrp_pwv_user_matches( array( 'customer', 'wholesale_customer' ), array( 'wholesale_customer' ) ) === true );
check( 'no overlap returns false', ttrp_pwv_user_matches( array( 'customer' ), array( 'wholesale_customer' ) ) === false );
check( 'empty allowed list returns false', ttrp_pwv_user_matches( array( 'administrator' ), array() ) === false );
check( 'empty user roles returns false', ttrp_pwv_user_matches( array(), array( 'customer' ) ) === false );
check( 'multiple allowed, one match returns true', ttrp_pwv_user_matches( array( 'subscriber' ), array( 'customer', 'subscriber' ) ) === true );

// --- ttrp_pwv_lines_markup() ----------------------------------------------
$GLOBALS['ttrp_pwv_test_options']['ttrp_pwv_settings'] = array(
	'label_incl' => 'incl. VAT',
	'label_excl' => 'excl. VAT',
	// show_incl / show_excl fall back to the 'yes' defaults.
);

// Store displays incl: main price suffixed "incl. VAT", complementary excl line.
$out_incl = ttrp_pwv_suffix_and_line( '<span>10</span>', '<span>8</span>', 'single', 'incl' );
check( 'incl mode: suffix present', strpos( $out_incl, 'ttrp-pwv__suffix' ) !== false );
check( 'incl mode: suffix labelled incl. VAT', strpos( $out_incl, 'incl. VAT' ) !== false );
check( 'incl mode: complementary line present', strpos( $out_incl, 'ttrp-pwv__comp' ) !== false );
check( 'incl mode: complementary amount is the excl price', strpos( $out_incl, '<span>8</span>' ) !== false );
check( 'incl mode: complementary labelled excl. VAT', strpos( $out_incl, 'excl. VAT' ) !== false );

// Store displays excl: main price suffixed "excl. VAT", complementary incl line.
$out_excl = ttrp_pwv_suffix_and_line( '<span>10</span>', '<span>8</span>', 'single', 'excl' );
check( 'excl mode: complementary amount is the incl price', strpos( $out_excl, '<span>10</span>' ) !== false );
check( 'excl mode: complementary line present', strpos( $out_excl, 'ttrp-pwv__comp' ) !== false );

// ttrp_pwv_display_mode reads the WooCommerce tax-display options.
$GLOBALS['ttrp_pwv_test_options']['woocommerce_tax_display_shop'] = 'incl';
$GLOBALS['ttrp_pwv_test_options']['woocommerce_tax_display_cart'] = 'excl';
check( 'display mode (shop) = incl', 'incl' === ttrp_pwv_display_mode( 'single' ) );
check( 'display mode (cart) = excl', 'excl' === ttrp_pwv_display_mode( 'cart' ) );

// --- ttrp_pwv_wholesale_prices() (WooCommerce Wholesale Prices integration) -
// Stub the WC tax helpers and the Wholesale Prices plugin classes.
function wc_get_price_including_tax( $product, $args = array() ) {
	return (float) $args['price'] * 1.24;
}
function wc_get_price_excluding_tax( $product, $args = array() ) {
	return (float) $args['price'];
}
class WWP_Wholesale_Roles {
	public static function getInstance() {
		static $i;
		if ( ! $i ) {
			$i = new self();
		}
		return $i;
	}
	public function getUserWholesaleRole( $user = null ) {
		return $GLOBALS['ttrp_test_roles'];
	}
}
class WWP_Wholesale_Prices {
	public static function get_product_raw_wholesale_price( $product_id, $roles ) {
		return $GLOBALS['ttrp_test_raw'];
	}
}
$stub_product = new class() {
	public function get_id() {
		return 1; }
};

$GLOBALS['ttrp_test_roles'] = array( 'wholesale_customer' );
$GLOBALS['ttrp_test_raw']   = '100';
$w = ttrp_pwv_wholesale_prices( $stub_product );
check( 'wholesale incl computed from raw price', is_array( $w ) && 124.0 === $w['incl'] );
check( 'wholesale excl computed from raw price', is_array( $w ) && 100.0 === $w['excl'] );

$GLOBALS['ttrp_test_roles'] = array();
check( 'non-wholesale user falls back (null)', null === ttrp_pwv_wholesale_prices( $stub_product ) );

$GLOBALS['ttrp_test_roles'] = array( 'wholesale_customer' );
$GLOBALS['ttrp_test_raw']   = '';
check( 'no wholesale price falls back (null)', null === ttrp_pwv_wholesale_prices( $stub_product ) );

// --- Report ----------------------------------------------------------------
echo "\n";
if ( $failures ) {
	echo "Failures:\n";
	foreach ( $failures as $f ) {
		echo "  - {$f}\n";
	}
}
echo "{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
