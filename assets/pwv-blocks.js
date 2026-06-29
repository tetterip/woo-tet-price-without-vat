/**
 * Price With and Without VAT — Cart/Checkout blocks integration.
 *
 * The block Cart/Checkout render line items via the Store API (React), so the
 * classic woocommerce_cart_item_subtotal filter never runs. The PHP side
 * exposes per-line incl/excl prices on the cart-items endpoint
 * (extensions['ttrp-price-without-vat']). We reformat the line subtotal so the
 * customer sees exactly two prices: the one WooCommerce already displays
 * (labelled with/without VAT per the store's tax-display setting) plus the
 * complementary one.
 *
 * Uses the wc.blocksCheckout global so no build step is required.
 */
( function () {
	'use strict';

	var data = window.ttrpPwvBlocks || {};
	var NS   = 'ttrp-price-without-vat';

	if ( ! window.wc || ! window.wc.blocksCheckout || 'function' !== typeof window.wc.blocksCheckout.registerCheckoutFilters ) {
		return;
	}

	window.wc.blocksCheckout.registerCheckoutFilters( NS, {
		subtotalPriceFormat: function ( value, extensions, args ) {
			var context = ( args && args.context ) ? args.context : '';
			var d       = extensions && extensions[ NS ];

			if ( ! d || ( ! d.incl && ! d.excl ) ) {
				return value;
			}

			// Honour the independent cart / checkout toggles.
			var isCheckout = 'summary' === context || 'checkout' === context;
			var isCart     = 'cart' === context;
			if ( isCheckout && ! data.showCheckout ) {
				return value;
			}
			if ( isCart && ! data.showCart ) {
				return value;
			}
			if ( ! isCheckout && ! isCart && ! data.showCart && ! data.showCheckout ) {
				return value;
			}

			// <price/> is the store-displayed subtotal (incl or excl per settings).
			var displayedIsExcl   = 'excl' === data.displayMode;
			var displayedLabel    = displayedIsExcl ? data.labelExcl : data.labelIncl;
			var complementary     = displayedIsExcl ? d.incl : d.excl;
			var complementaryLbl  = displayedIsExcl ? data.labelIncl : data.labelExcl;
			// The complementary line follows its own show_incl / show_excl toggle.
			var showComplementary = displayedIsExcl ? data.showIncl : data.showExcl;

			var out = '<price/> ' + ( displayedLabel || '' );
			if ( complementary && showComplementary ) {
				out += ' / ' + complementary + ' ' + ( complementaryLbl || '' );
			}

			return out.replace( /\s+/g, ' ' ).trim();
		},
	} );
} )();
