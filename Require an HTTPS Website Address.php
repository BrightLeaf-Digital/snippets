<?php
/**
 * Require an https web address
 *
 * @gravityforms
 *
 * GOAL:
 * Requires a full web address that starts with https:// and has a real domain name.
 * Internationalised domains such as münchen.de are accepted.
 *
 * CONFIGURATION:
 * - `BLD_VAL_HTTPS_URL_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_HTTPS_URL_MESSAGE`: the error shown under the field.
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
const BLD_VAL_HTTPS_URL_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_HTTPS_URL_MESSAGE = 'Please enter a full web address starting with https://';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_HTTPS_URL_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			if ( preg_match( '/\s/u', $value ) ) {
				return false;
			}
			$parts = wp_parse_url( $value );
			if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
				return false;
			}
			// Labels of letters, digits and hyphens in any script, and a letter-only or punycode top-level domain.
			return 1 === preg_match( '/^([\p{L}\p{N}]([\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+(\p{L}{2,}|xn--[a-z0-9-]+)$/iu', $parts['host'] );
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
				$result['message']  = BLD_VAL_HTTPS_URL_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
