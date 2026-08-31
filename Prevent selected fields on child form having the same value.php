<?php
/**
 * Prevent selected fields on child form having the same value
 *
 * @gravityforms
 * @gravityperks
 *
 * GOAL:
 * When using Nested Forms, prevent duplicate values across multiple child entries in the same
 * batch/session for specific fields you choose (e.g., a number field, a name field, or a date
 * field).
 *
 * CONFIGURATION:
 * - `$forms_and_fields`: Add your child form ID as the key, and list the child field IDs you want
 *   to keep unique with the message users should see if they choose a duplicate.
 * - Example: 66 => [ 4 => 'Please choose a unique value.', 2 => 'First and last name must be
 *   unique.' ]
 *
 * NOTES:
 * - Name fields compare First AND Last together. If both match an existing entry, it's considered
 *   a duplicate.
 * - Date fields compare dates using the site's timezone. If parsing fails, the comparison for that
 *   item is skipped.
 */

( static function () {
	// === Settings: Child form → [ field_id => message ] (edit these) ===
	$forms_and_fields = [
		66 => [
			4 => 'Please use a unique number.',
			2 => 'Please use a unique name (first and last together).',
			3 => 'Please use a unique date.',
		],
		// Add more forms as needed...
	];
	// ===============================================================

	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}

	$to_number = static function ( $val ) {
		if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'to_number' ) ) {
			$currency = GFCommon::get_currency();
			return GFCommon::to_number( (string) $val, $currency );
		}
		return is_numeric( $val ) ? (float) $val : 0.0;
	};

	$to_timestamp = static function ( $date_str ) {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		if ( $tz instanceof DateTimeZone ) {
			try {
				$dt = new DateTimeImmutable( (string) $date_str, $tz );
				return $dt->getTimestamp();
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// fall through to strtotime
			}
		}
		$ts = strtotime( (string) $date_str );
		return false === $ts ? null : $ts;
	};

	$flatten = static function ( $items ) use ( &$flatten ) {
		$result = [];
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) ) {
				$result = array_merge( $result, $flatten( $item ) );
			} else {
				$result[] = $item;
			}
		}
		return $result;
	};

	add_filter(
		'gform_field_validation',
		static function ( $validation_result, $value, $form, $field ) use ( $forms_and_fields, $to_number, $to_timestamp, $flatten ) {
			$form_id  = isset( $form['id'] ) ? (int) $form['id'] : 0;
			$field_id = isset( $field['id'] ) ? (int) $field['id'] : 0;

			if ( ! $form_id || ! isset( $forms_and_fields[ $form_id ] ) || ! isset( $forms_and_fields[ $form_id ][ $field_id ] ) ) {
				return $validation_result;
			}

			// Required add-ons.
			if ( ! class_exists( 'GP_Nested_Forms' ) || ! class_exists( 'GPNF_Session' ) ) {
				return $validation_result;
			}

			$nested_form = new GP_Nested_Forms( $form );
			$parent_id   = method_exists( $nested_form, 'get_parent_form_id' ) ? $nested_form->get_parent_form_id() : 0;
			if ( ! $parent_id ) {
				return $validation_result;
			}

			$session = new GPNF_Session( $parent_id );
			$cookie  = method_exists( $session, 'get_cookie' ) ? $session->get_cookie() : [];
			$ids     = is_array( $cookie ) && isset( $cookie['nested_entries'] ) ? $flatten( $session->get_valid_entry_ids( $cookie['nested_entries'] ) ) : [];

			$parent_form_arr = GFAPI::get_form( $parent_id );
			$parent_gpnf     = is_array( $parent_form_arr ) ? new GP_Nested_Forms( $parent_form_arr ) : null;
			$entries         = $parent_gpnf && ! empty( $ids ) ? $parent_gpnf->get_entries( $ids ) : [];

			// Determine comparison strategy based on field type.
			$type    = isset( $field['type'] ) ? (string) $field['type'] : '';
			$message = $forms_and_fields[ $form_id ][ $field_id ];

			foreach ( $entries as $entry ) {
				if ( 'number' === $type ) {
					$submitted = $to_number( $value );
					$existing  = $to_number( rgar( $entry, (string) $field_id ) );
					if ( $submitted === $existing ) {
						$validation_result['is_valid'] = false;
						$validation_result['message']  = $message;
						break;
					}
				} elseif ( 'name' === $type ) {
					$first = rgpost( 'input_' . $field_id . '_3' );
					$last  = rgpost( 'input_' . $field_id . '_6' );
					$ef    = rgar( $entry, $field_id . '.3' );
					$el    = rgar( $entry, $field_id . '.6' );
					if ( '' !== $first && '' !== $last && (string) $first === (string) $ef && (string) $last === (string) $el ) {
						$validation_result['is_valid'] = false;
						$validation_result['message']  = $message;
						break;
					}
				} elseif ( 'date' === $type ) {
					$ts1 = $to_timestamp( is_array( $value ) ? reset( $value ) : $value );
					$ts2 = $to_timestamp( rgar( $entry, (string) $field_id ) );
					if ( $ts1 && $ts2 && $ts1 === $ts2 ) {
						$validation_result['is_valid'] = false;
						$validation_result['message']  = $message;
						break;
					}
				}
			}

			return $validation_result;
		},
		10,
		4
	);
} )();
