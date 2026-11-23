<?php
/**
 * Validate a US or Canadian phone number (NANP)
 *
 * @gravityforms
 *
 * GOAL:
 * Checks a 10-digit North American number with an optional +1 or 1: the area code and exchange
 * cannot start with 0 or 1.
 *
 * CONFIGURATION:
 * - `BLD_VAL_NANP_PHONE_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_NANP_PHONE_MESSAGE`: the error shown under the field.
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
 * - Gravity Forms' own Phone field already checks this format when its Phone Format is set to
 *   Standard. Use this snippet on a Single Line Text field, or on a Phone field set to
 *   International when you still want only North American numbers.
 */

// === BEGIN CONFIG ===
/**
 * Fields to check.
 *
 * @config array Form ID mapped to the IDs of the fields to check, for example [ 1 => [ 3 ] ]
 */
const BLD_VAL_NANP_PHONE_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_NANP_PHONE_MESSAGE = 'Please enter a valid 10-digit phone number.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_NANP_PHONE_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$d = preg_replace( '/[ ().+-]+/', '', $value );
			if ( 11 === strlen( $d ) && '1' === $d[0] ) {
				$d = substr( $d, 1 ); }
			return 1 === preg_match( '/^[2-9][0-9]{2}[2-9][0-9]{6}$/', $d );
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
				$result['message']  = BLD_VAL_NANP_PHONE_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
