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
);
// Bust the static cache populated during load (defaults only).
$markup = ttrp_pwv_lines_markup( '<span>10</span>', '<span>8</span>', 'single', true, true );
check( 'markup wraps in ttrp-pwv container', strpos( $markup, 'ttrp-pwv ttrp-pwv--single' ) !== false );
check( 'markup includes incl line', strpos( $markup, 'ttrp-pwv__incl' ) !== false );
check( 'markup includes excl line', strpos( $markup, 'ttrp-pwv__excl' ) !== false );

$only_excl = ttrp_pwv_lines_markup( '<span>10</span>', '<span>8</span>', 'cart', false, true );
check( 'incl hidden when show_incl false', strpos( $only_excl, 'ttrp-pwv__incl' ) === false );
check( 'excl present when show_excl true', strpos( $only_excl, 'ttrp-pwv__excl' ) !== false );

$none = ttrp_pwv_lines_markup( '', '', 'single', true, true );
check( 'empty inputs yield empty string', $none === '' );

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
