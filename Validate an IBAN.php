<?php
/**
 * Validate an IBAN (ISO 13616 registry and mod-97 check)
 *
 * @gravityforms
 *
 * GOAL:
 * Checks that an IBAN comes from a country that issues IBANs, has that country's length, and
 * has correct mod-97 check digits. Spaces and dashes are ignored, and lowercase is accepted.
 *
 * CONFIGURATION:
 * - `BLD_VAL_IBAN_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is checked
 *   until you add at least one form.
 * - `BLD_VAL_IBAN_MESSAGE`: the error shown under the field.
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
 * - The country table follows the SWIFT IBAN registry (89 countries, May 2026). Countries with
 *   only partial or experimental IBANs, such as Morocco or Algeria, are rejected; add them to
 *   `$lengths` in the code if you need them.
 * - A valid IBAN can still belong to a closed account. Only the bank can confirm the account
 *   exists.
 */

// === BEGIN CONFIG ===
/**
 * Fields to check.
 *
 * @config array Form ID mapped to the IDs of the fields to check, for example [ 1 => [ 3 ] ]
 */
const BLD_VAL_IBAN_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_IBAN_MESSAGE = 'Please enter a valid IBAN.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_IBAN_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$lengths = [
				'AD' => 24,
				'AE' => 23,
				'AL' => 28,
				'AT' => 20,
				'AZ' => 28,
				'BA' => 20,
				'BE' => 16,
				'BG' => 22,
				'BH' => 22,
				'BI' => 27,
				'BR' => 29,
				'BY' => 28,
				'CH' => 21,
				'CR' => 22,
				'CY' => 28,
				'CZ' => 24,
				'DE' => 22,
				'DJ' => 27,
				'DK' => 18,
				'DO' => 28,
				'EE' => 20,
				'EG' => 29,
				'ES' => 24,
				'FI' => 18,
				'FK' => 18,
				'FO' => 18,
				'FR' => 27,
				'GB' => 22,
				'GE' => 22,
				'GI' => 23,
				'GL' => 18,
				'GR' => 27,
				'GT' => 28,
				'HN' => 28,
				'HR' => 21,
				'HU' => 28,
				'IE' => 22,
				'IL' => 23,
				'IQ' => 23,
				'IS' => 26,
				'IT' => 27,
				'JO' => 30,
				'KW' => 30,
				'KZ' => 20,
				'LB' => 28,
				'LC' => 32,
				'LI' => 21,
				'LT' => 20,
				'LU' => 20,
				'LV' => 21,
				'LY' => 25,
				'MC' => 27,
				'MD' => 24,
				'ME' => 22,
				'MK' => 19,
				'MN' => 20,
				'MR' => 27,
				'MT' => 31,
				'MU' => 30,
				'NI' => 28,
				'NL' => 18,
				'NO' => 15,
				'OM' => 23,
				'PK' => 24,
				'PL' => 28,
				'PS' => 29,
				'PT' => 25,
				'QA' => 29,
				'RO' => 24,
				'RS' => 22,
				'RU' => 33,
				'SA' => 24,
				'SC' => 31,
				'SD' => 18,
				'SE' => 24,
				'SI' => 19,
				'SK' => 24,
				'SM' => 27,
				'SO' => 23,
				'ST' => 25,
				'SV' => 28,
				'TL' => 23,
				'TN' => 24,
				'TR' => 26,
				'UA' => 29,
				'VA' => 22,
				'VG' => 24,
				'XK' => 20,
				'YE' => 30,
			];
			$v       = strtoupper( preg_replace( '/[\s-]+/', '', $value ) );
			if ( ! preg_match( '/^([A-Z]{2})[0-9]{2}[A-Z0-9]+$/', $v, $m ) || ! isset( $lengths[ $m[1] ] ) || strlen( $v ) !== $lengths[ $m[1] ] ) {
				return false;
			}
			$moved = substr( $v, 4 ) . substr( $v, 0, 4 );
			$rem   = 0;
			foreach ( str_split( $moved ) as $ch ) {
				$num = preg_match( '/[A-Z]/', $ch ) ? (string) ( ord( $ch ) - 55 ) : $ch;
				foreach ( str_split( $num ) as $digit ) {
					$rem = ( $rem * 10 + (int) $digit ) % 97;
				}
			}
			return 1 === $rem;
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
				$result['message']  = BLD_VAL_IBAN_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
