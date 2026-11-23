<?php
/**
 * Check EU VAT number format by country
 *
 * @gravityforms
 *
 * GOAL:
 * Checks the format of an EU VAT number (country prefix plus the pattern each member state
 * uses, and XI for Northern Ireland). Format only: it does not ask VIES whether the number is
 * registered.
 *
 * CONFIGURATION:
 * - `BLD_VAL_EU_VAT_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing is
 *   checked until you add at least one form.
 * - `BLD_VAL_EU_VAT_MESSAGE`: the error shown under the field.
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
 * - Greek numbers are accepted with either EL, the prefix used for VAT, or GR.
 * - To confirm a number is registered, check it on the European Commission VIES service.
 */

// === BEGIN CONFIG ===
/**
 * Fields to check.
 *
 * @config array Form ID mapped to the IDs of the fields to check, for example [ 1 => [ 3 ] ]
 */
const BLD_VAL_EU_VAT_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_EU_VAT_MESSAGE = 'Please enter a VAT number in the right format for its country, starting with the country code.';
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_EU_VAT_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			$v  = strtoupper( preg_replace( '/[\s.-]+/', '', $value ) );
			$p  = [
				'AT' => 'U[0-9]{8}',
				'BE' => '[01][0-9]{9}',
				'BG' => '[0-9]{9,10}',
				'CY' => '[0-9]{8}[A-Z]',
				'CZ' => '[0-9]{8,10}',
				'DE' => '[0-9]{9}',
				'DK' => '[0-9]{8}',
				'EE' => '[0-9]{9}',
				'EL' => '[0-9]{9}',
				'ES' => '[A-Z0-9][0-9]{7}[A-Z0-9]',
				'FI' => '[0-9]{8}',
				'FR' => '[A-HJ-NP-Z0-9]{2}[0-9]{9}',
				'HR' => '[0-9]{11}',
				'HU' => '[0-9]{8}',
				'IE' => '([0-9]{7}[A-W][A-I]?|[0-9][A-Z][0-9]{5}[A-W])',
				'IT' => '[0-9]{11}',
				'LT' => '([0-9]{9}|[0-9]{12})',
				'LU' => '[0-9]{8}',
				'LV' => '[0-9]{11}',
				'MT' => '[0-9]{8}',
				'NL' => '[0-9]{9}B[0-9]{2}',
				'PL' => '[0-9]{10}',
				'PT' => '[0-9]{9}',
				'RO' => '[0-9]{2,10}',
				'SE' => '[0-9]{10}01',
				'SI' => '[0-9]{8}',
				'SK' => '[0-9]{10}',
				'XI' => '([0-9]{9}|[0-9]{12}|GD[0-9]{3}|HA[0-9]{3})',
			];
			$cc = substr( $v, 0, 2 );
			if ( 'GR' === $cc ) {
				$cc = 'EL';
			}
			return isset( $p[ $cc ] ) && 1 === preg_match( '/^' . $p[ $cc ] . '$/', substr( $v, 2 ) );
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
				$result['message']  = BLD_VAL_EU_VAT_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
