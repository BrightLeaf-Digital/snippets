<?php
/**
 * Validate a French SIREN or SIRET number
 *
 * @gravityforms
 *
 * GOAL:
 * Checks a 9-digit SIREN or 14-digit SIRET with the Luhn key (and the La Poste SIRET
 * exception). Spaces are ignored.
 *
 * CONFIGURATION:
 * - `BLD_VAL_FR_SIRET_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_FR_SIRET_MESSAGE`: the error shown under the field.
 *
 * For example, this checks field 3 on form 1 and fields 2 and 5 on form 4:
 *
 *     [ 1 => [ 3 ], 4 => [ 2, 5 ] ]
 *
 * Installed through GravityOps Code Snippet Manager, these values are settings fields and
 * survive snippet updates. Anywhere else, edit the config block below.
 *
 * USAGE:
 * Use it on a Single Line Text field.
 *
 * NOTES:
 * - Runs when the form is submitted. With GravityOps Live Field Validation active, the same
 *   check also runs when the visitor leaves the field.
 * - Empty values are left to the field's Required setting, and a field that already failed
 *   another check keeps that error.
 */

// === BEGIN CONFIG ===
/**
 * Fields to check.
 *
 * @config array Form ID mapped to the IDs of the fields to check, for example [ 1 => [ 3 ] ]
 */
const BLD_VAL_FR_SIRET_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_FR_SIRET_MESSAGE = 'Please enter a valid SIREN (9 digits) or SIRET (14 digits).';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_FR_SIRET_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$d = preg_replace( '/ +/', '', $value );
			if ( ! preg_match( '/^([0-9]{9}|[0-9]{14})$/', $d ) ) {
				return false; }

			$sum = 0;
			$alt = false;
			for ( $i = strlen( $d ) - 1; $i >= 0; $i-- ) {
				$n = (int) $d[ $i ];
				if ( $alt ) {
					$n *= 2;
					if ( $n > 9 ) {
						$n -= 9; }
				}
				$sum += $n;
				$alt  = ! $alt;
			}

			if ( 0 === $sum % 10 ) {
				return true; }
			return 14 === strlen( $d ) && str_starts_with( $d, '356000000' ) && 0 === array_sum( str_split( $d ) ) % 5;
		};

		$values = [ $value ];
		foreach ( $values as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			// Pasted values often carry non-breaking spaces.
			$item = trim( str_replace( [ "\u{00A0}", "\u{202F}" ], ' ', $item ) );
			if ( '' === $item ) {
				continue;
			}
			if ( ! $is_valid( $item ) ) {
				$result['is_valid'] = false;
				$result['message']  = BLD_VAL_FR_SIRET_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
