<?php
/**
 * Validate a Spanish DNI or NIE
 *
 * @gravityforms
 *
 * GOAL:
 * Checks a DNI (8 digits plus control letter) or NIE (X, Y or Z, 7 digits and control letter)
 * against the mod-23 letter.
 *
 * CONFIGURATION:
 * - `BLD_VAL_ES_DNI_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_ES_DNI_MESSAGE`: the error shown under the field.
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
 */

// === BEGIN CONFIG ===
/**
 * Fields to check.
 *
 * @config array Form ID mapped to the IDs of the fields to check, for example [ 1 => [ 3 ] ]
 */
const BLD_VAL_ES_DNI_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_ES_DNI_MESSAGE = 'Please enter a valid DNI or NIE.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_ES_DNI_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$v = strtoupper( preg_replace( '/[ -]+/', '', $value ) );
			if ( ! preg_match( '/^([XYZ]?)([0-9]{7,8})([A-Z])$/', $v, $m ) ) {
				return false; }
			if ( '' !== $m[1] && 7 !== strlen( $m[2] ) ) {
				return false; }
			if ( '' === $m[1] && 8 !== strlen( $m[2] ) ) {
				return false; }
			$num = (int) ( ( '' === $m[1] ? '' : strpos( 'XYZ', $m[1] ) ) . $m[2] );
			return substr( 'TRWAGMYFPDXBNJZSQVHLCKE', $num % 23, 1 ) === $m[3];
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
				$result['message']  = BLD_VAL_ES_DNI_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
