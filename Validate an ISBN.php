<?php
/**
 * Validate an ISBN-10 or ISBN-13
 *
 * @gravityforms
 *
 * GOAL:
 * Checks an ISBN-10 (with X as a possible last character) or ISBN-13 against its check digit.
 * Hyphens and spaces are ignored.
 *
 * CONFIGURATION:
 * - `BLD_VAL_ISBN_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is checked
 *   until you add at least one form.
 * - `BLD_VAL_ISBN_MESSAGE`: the error shown under the field.
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
const BLD_VAL_ISBN_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_ISBN_MESSAGE = 'Please enter a valid ISBN.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_ISBN_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$v = strtoupper( preg_replace( '/[ -]+/', '', $value ) );
			if ( preg_match( '/^[0-9]{9}[0-9X]$/', $v ) ) {
				$sum = 0;
				for ( $i = 0; $i < 10; $i++ ) {
					$sum += ( 'X' === $v[ $i ] ? 10 : (int) $v[ $i ] ) * ( 10 - $i ); }
				return 0 === $sum % 11;
			}
			if ( preg_match( '/^97[89][0-9]{10}$/', $v ) ) {
				$sum = 0;
				for ( $i = 0; $i < 13; $i++ ) {
					$sum += (int) $v[ $i ] * ( 0 === $i % 2 ? 1 : 3 ); }
				return 0 === $sum % 10;
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
				$result['message']  = BLD_VAL_ISBN_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
