<?php
/**
 * Validate a US EIN (Employer Identification Number)
 *
 * @gravityforms
 *
 * GOAL:
 * Checks the 9-digit format (12-3456789, 12 3456789 or 123456789) and that the first two digits
 * are a prefix the IRS assigns.
 *
 * CONFIGURATION:
 * - `BLD_VAL_US_EIN_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_US_EIN_MESSAGE`: the error shown under the field.
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
const BLD_VAL_US_EIN_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_US_EIN_MESSAGE = 'Please enter a valid EIN, for example 12-3456789.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_US_EIN_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			if ( ! preg_match( '/^([0-9]{2})[ -]?[0-9]{7}$/', $value, $m ) ) {
				return false; }
			$prefix = (int) $m[1];
			$ranges = [ [ 1, 6 ], [ 10, 16 ], [ 20, 27 ], [ 30, 48 ], [ 50, 68 ], [ 71, 77 ], [ 80, 88 ], [ 90, 95 ], [ 98, 99 ] ];
			foreach ( $ranges as $r ) {
				if ( $prefix >= $r[0] && $prefix <= $r[1] ) {
					return true; }
			}
			return false;
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
				$result['message']  = BLD_VAL_US_EIN_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
