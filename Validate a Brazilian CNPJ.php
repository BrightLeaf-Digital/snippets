<?php
/**
 * Validate a Brazilian CNPJ
 *
 * @gravityforms
 *
 * GOAL:
 * Checks a Brazilian company number (CNPJ) and its two check digits. Both the numeric format
 * and the alphanumeric format issued since July 2026 (IN RFB 2.229/2024) are accepted, with or
 * without the usual dots, slash and dash.
 *
 * CONFIGURATION:
 * - `BLD_VAL_BR_CNPJ_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_BR_CNPJ_MESSAGE`: the error shown under the field.
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
const BLD_VAL_BR_CNPJ_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_BR_CNPJ_MESSAGE = 'Please enter a valid CNPJ.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_BR_CNPJ_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$d = strtoupper( preg_replace( '/[\s.\/-]+/', '', $value ) );
			if ( ! preg_match( '/^[0-9A-Z]{12}[0-9]{2}$/', $d ) || preg_match( '/^([0-9])\1{13}$/', $d ) ) {
				return false;
			}
			$weights = [ 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ];
			for ( $t = 12; $t < 14; $t++ ) {
				$sum = 0;
				$w   = array_slice( $weights, 13 - $t );
				for ( $i = 0; $i < $t; $i++ ) {
					// Each character counts as its ASCII code minus 48: digits keep their value, A is 17.
					$sum += ( ord( $d[ $i ] ) - 48 ) * $w[ $i ];
				}
				$r = $sum % 11;
				if ( ( $r < 2 ? 0 : 11 - $r ) !== (int) $d[ $t ] ) {
					return false;
				}
			}
			return true;
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
				$result['message']  = BLD_VAL_BR_CNPJ_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
