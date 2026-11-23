<?php
/**
 * Validate a vehicle VIN (North American check digit)
 *
 * @gravityforms
 *
 * GOAL:
 * Checks a 17-character VIN (no I, O or Q) and the check digit in position 9 used on North
 * American vehicles. Many European VINs do not use that check digit, so use this where your
 * vehicles are North American.
 *
 * CONFIGURATION:
 * - `BLD_VAL_VIN_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is checked
 *   until you add at least one form.
 * - `BLD_VAL_VIN_MESSAGE`: the error shown under the field.
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
const BLD_VAL_VIN_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_VIN_MESSAGE = 'Please enter a valid 17-character VIN.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_VIN_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$v = strtoupper( preg_replace( '/[ -]+/', '', $value ) );
			if ( ! preg_match( '/^[A-HJ-NPR-Z0-9]{17}$/', $v ) ) {
				return false; }
			$map = [
				'A' => 1,
				'B' => 2,
				'C' => 3,
				'D' => 4,
				'E' => 5,
				'F' => 6,
				'G' => 7,
				'H' => 8,
				'J' => 1,
				'K' => 2,
				'L' => 3,
				'M' => 4,
				'N' => 5,
				'P' => 7,
				'R' => 9,
				'S' => 2,
				'T' => 3,
				'U' => 4,
				'V' => 5,
				'W' => 6,
				'X' => 7,
				'Y' => 8,
				'Z' => 9,
			];
			$w   = [ 8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2 ];
			$sum = 0;
			for ( $i = 0; $i < 17; $i++ ) {
				$c    = $v[ $i ];
				$sum += ( preg_match( '/[0-9]/', $c ) ? (int) $c : $map[ $c ] ) * $w[ $i ]; }
			$r = $sum % 11;
			return ( 10 === $r ? 'X' : (string) $r ) === $v[8];
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
				$result['message']  = BLD_VAL_VIN_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
