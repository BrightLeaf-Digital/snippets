<?php
/**
 * Validate a Singapore NRIC or FIN
 *
 * @gravityforms
 *
 * GOAL:
 * Checks a Singapore NRIC or FIN: the S, T, F or G prefix, seven digits and the check letter.
 * FINs in the M series, issued since 2022, are accepted by format.
 *
 * CONFIGURATION:
 * - `BLD_VAL_SG_NRIC_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_SG_NRIC_MESSAGE`: the error shown under the field.
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
 * - This number identifies a person. Gravity Forms stores it in the entry in plain text, so
 *   collect it only when you need it, limit who can see entries, and consider a data retention
 *   policy that deletes old entries.
 * - The M-series check letter is not verified, because ICA releases that algorithm only to
 *   approved organisations.
 */

// === BEGIN CONFIG ===
/**
 * Fields to check.
 *
 * @config array Form ID mapped to the IDs of the fields to check, for example [ 1 => [ 3 ] ]
 */
const BLD_VAL_SG_NRIC_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_SG_NRIC_MESSAGE = 'Please enter a valid NRIC or FIN.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_SG_NRIC_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$v = strtoupper( $value );
			if ( ! preg_match( '/^([STFGM])([0-9]{7})([A-Z])$/', $v, $m ) ) {
				return false;
			}
			// ICA has not published the M-series checksum, so M numbers are checked by format only.
			if ( 'M' === $m[1] ) {
				return true;
			}
			$w   = [ 2, 7, 6, 5, 4, 3, 2 ];
			$sum = ( 'T' === $m[1] || 'G' === $m[1] ) ? 4 : 0;
			for ( $i = 0; $i < 7; $i++ ) {
				$sum += (int) $m[2][ $i ] * $w[ $i ]; }
			$table = ( 'S' === $m[1] || 'T' === $m[1] ) ? 'JZIHGFEDCBA' : 'XWUTRQPNMLK';
			return $table[ $sum % 11 ] === $m[3];
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
				$result['message']  = BLD_VAL_SG_NRIC_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
