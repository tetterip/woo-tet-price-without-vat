/**
 * Price With and Without VAT — settings page behaviour.
 *
 * - Shows the "User roles" row only when the audience is "Selected user roles".
 * - Initialises the roles field as a Select2 (via WooCommerce's selectWoo).
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $audience = $( '#ttrp_pwv_audience' );
		var $rolesRow = $( '.ttrp-pwv-roles-row' );

		function toggleRolesRow() {
			if ( 'roles' === $audience.val() ) {
				$rolesRow.show();
			} else {
				$rolesRow.hide();
			}
		}

		if ( $audience.length ) {
			toggleRolesRow();
			$audience.on( 'change', toggleRolesRow );
		}

		// Initialise Select2. WooCommerce auto-inits .wc-enhanced-select, but call
		// it explicitly so the field is enhanced even if the auto-init missed it.
		var $roles = $( '.ttrp-pwv-roles-select' );
		if ( $roles.length ) {
			if ( $.fn.selectWoo ) {
				$roles.selectWoo();
			} else if ( $.fn.select2 ) {
				$roles.select2();
			}
		}
	} );
} )( jQuery );
