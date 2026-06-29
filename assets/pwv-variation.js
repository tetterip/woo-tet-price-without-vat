/**
 * Price With and Without VAT — live per-variation update.
 *
 * The PHP side (woocommerce_single_product_summary @ 11) renders a
 * .ttrp-pwv block showing the variable product's price range. When a
 * variation is selected, WooCommerce fires `found_variation` on the
 * .variations_form with our injected `ttrp_pwv_html`; we swap the block
 * to the specific variation's prices and restore the range on reset.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '.variations_form' );
		if ( ! $form.length ) {
			return;
		}

		// The range block rendered by PHP for the parent product.
		var $block = $( '.ttrp-pwv--single' ).first();
		if ( ! $block.length ) {
			return;
		}

		// Remember the original range markup so reset can restore it.
		var rangeHtml = $block.prop( 'outerHTML' );

		$form.on( 'found_variation', function ( event, variation ) {
			if ( ! variation || typeof variation.ttrp_pwv_html === 'undefined' ) {
				return;
			}
			var $current = $( '.ttrp-pwv--single' ).first();
			if ( ! $current.length ) {
				return;
			}
			if ( variation.ttrp_pwv_html ) {
				$current.replaceWith( variation.ttrp_pwv_html );
			} else {
				// Variation is not taxable / nothing to show.
				$current.html( '' );
			}
		} );

		$form.on( 'reset_data', function () {
			var $current = $( '.ttrp-pwv--single' ).first();
			if ( $current.length && rangeHtml ) {
				$current.replaceWith( rangeHtml );
			}
		} );
	} );
} )( jQuery );
