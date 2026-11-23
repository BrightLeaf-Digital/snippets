<?php
/**
 * Block PO Box addresses
 *
 * @gravityforms
 *
 * GOAL:
 * Rejects addresses that are PO Boxes (PO Box, P.O. Box, POB, Post Office Box, POBox), for
 * example where you ship by courier. On an Address field it checks both street lines.
 *
 * CONFIGURATION:
 * - `BLD_VAL_NO_PO_BOX_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_NO_PO_BOX_MESSAGE`: the error shown under the field.
 *
 * For example, this checks field 3 on form 1 and fields 2 and 5 on form 4:
 *
 *     [ 1 => [ 3 ], 4 => [ 2, 5 ] ]
 *
 * Installed through GravityOps Code Snippet Manager, these values are settings fields and
 * survive snippet updates. Anywhere else, edit the config block below.
 *
 * USAGE:
 * Use it on an Address field, where it checks Street Address and Address Line 2, or on a Single
 * Line Text field that holds an address line.
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
const BLD_VAL_NO_PO_BOX_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_NO_PO_BOX_MESSAGE = 'Please enter a street address. We cannot deliver to PO Boxes.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_NO_PO_BOX_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			return 1 !== preg_match( '/(^|[^a-z])(p[ .]*o[ .]*(box|b)|post[ .]*office[ .]*box)([^a-z]|$)/i', $value );
		};

		$values = is_array( $value ) ? [ rgar( $value, $field->id . '.1' ), rgar( $value, $field->id . '.2' ) ] : [ $value ];
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
				$result['message']  = BLD_VAL_NO_PO_BOX_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
