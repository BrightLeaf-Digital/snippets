<?php
/**
 * Require a business email (no free webmail)
 *
 * @gravityforms
 *
 * GOAL:
 * Rejects email addresses at free mailbox providers such as Gmail, Outlook and Yahoo, so a lead
 * form only collects work addresses. The list of blocked domains is a setting you can edit.
 *
 * CONFIGURATION:
 * - `BLD_VAL_BUSINESS_EMAIL_FIELDS`: form ID mapped to the IDs of the fields to check. Nothing
 *   is checked until you add at least one form.
 * - `BLD_VAL_BUSINESS_EMAIL_MESSAGE`: the error shown under the field.
 * - `BLD_VAL_BUSINESS_EMAIL_DOMAINS`: the free email domains to block, in lowercase.
 *
 * For example, this checks field 3 on form 1 and fields 2 and 5 on form 4:
 *
 *     [ 1 => [ 3 ], 4 => [ 2, 5 ] ]
 *
 * Installed through GravityOps Code Snippet Manager, these values are settings fields and
 * survive snippet updates. Anywhere else, edit the config block below.
 *
 * USAGE:
 * Use it on an Email field, with or without the confirmation input. A Single Line Text field
 * works too.
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
const BLD_VAL_BUSINESS_EMAIL_FIELDS = [];

/**
 * Error message.
 *
 * @config string Error message shown under the field
 */
const BLD_VAL_BUSINESS_EMAIL_MESSAGE = 'Please use your work email address.';

/**
 * Free email domains to block.
 *
 * @config array Free email domains to block
 */
const BLD_VAL_BUSINESS_EMAIL_DOMAINS = [
	'gmail.com',
	'googlemail.com',
	'outlook.com',
	'hotmail.com',
	'live.com',
	'msn.com',
	'yahoo.com',
	'ymail.com',
	'aol.com',
	'icloud.com',
	'me.com',
	'mac.com',
	'proton.me',
	'protonmail.com',
	'gmx.com',
	'gmx.net',
	'mail.com',
	'zoho.com',
	'yandex.com',
	'hotmail.co.uk',
	'yahoo.co.uk',
	'web.de',
];
// === END CONFIG ===

add_filter(
	'gform_field_validation',
	function ( $result, $value, $form, $field ) {
		if ( ! is_array( $result ) || empty( $result['is_valid'] ) || ! is_object( $field ) ) {
			return $result;
		}
		$field_ids = rgar( BLD_VAL_BUSINESS_EMAIL_FIELDS, (int) rgar( $form, 'id' ) );
		if ( ! is_array( $field_ids ) || ! in_array( (int) $field->id, array_map( 'intval', $field_ids ), true ) ) {
			return $result;
		}

		$is_valid = static function ( $value ) {
			if ( ! is_email( $value ) ) {
				return false;
			}
			$domain = strtolower( substr( strrchr( $value, '@' ), 1 ) );
			return ! in_array( $domain, array_map( 'strtolower', (array) BLD_VAL_BUSINESS_EMAIL_DOMAINS ), true );
		};

		$values = [ is_array( $value ) ? rgar( $value, 0 ) : $value ];
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
				$result['message']  = BLD_VAL_BUSINESS_EMAIL_MESSAGE;
				break;
			}
		}

		return $result;
	},
	10,
	4
);
