<?php
/**
 * Prevent duplicate submission if multiple fields match existing entries
 *
 * @gravityforms
 *
 * GOAL:
 * Prevents duplicate submissions when a specific combination of fields (Name, Number, Date)
 * matches an existing active entry. If a duplicate is found, shows custom messages on the relevant
 * fields. **CONFIGURATION**
 *
 * - `$forms`: Add your form ID as a key, and set the field IDs to check. Then set the
 *   corresponding error messages.
 * - Optionally set the `window_days` key to limit check to recent entries. Leave at 0 to remove
 *   limit.
 *
 * NOTES:
 * - All three fields must match to be considered a duplicate.
 * - Dates are normalized to site timezone (Y-m-d) before searching.
 * - Can be used for multiple forms by duplicating the inner array with a new form key, etc.
 */

( static function () {
	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}

	// === Configuration: per-form settings (edit these) =========================
	$forms = [
		77 => [
			'name_field_id' => 5,
			'num_field_id'  => 3,
			'date_field_id' => 6,
			// Optional: limit duplicate check to recent entries. Set to 0 to disable.
			'window_days'   => 0,
			'messages'      => [
				'name' => 'This name has already been used in a previous submission.',
				'num'  => 'This number has already been used in a previous submission.',
				'date' => 'This date has already been used in a previous submission.',
			],
		],
		// Add more forms as needed...
	];
	// ==========================================================================

	$parse_date_to_ymd = static function ( $date_str ) {
		$date_str = is_string( $date_str ) ? trim( $date_str ) : '';
		if ( '' === $date_str ) {
			return '';
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		try {
			$dt = ( $tz instanceof DateTimeZone )
				? new DateTimeImmutable( $date_str, $tz )
				: new DateTimeImmutable( $date_str );
			// Convert to midnight in site timezone to match typical GF storage for Date fields.
			return $dt->format( 'Y-m-d' );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fallback to strtotime if DateTime fails.
			$ts = strtotime( $date_str );
			return false === $ts ? '' : ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d', $ts ) );
		}
	};

	add_filter(
		'gform_validation',
		static function ( $validation_result ) use ( $forms, $parse_date_to_ymd ) {
			$form    = $validation_result['form'] ?? null;
			$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

			if ( 0 === $form_id || true !== in_array( $form_id, array_keys( $forms ), true ) ) {
				return $validation_result;
			}

			$config   = $forms[ $form_id ];
			$name_id  = (int) $config['name_field_id'];
			$num_id   = (int) $config['num_field_id'];
			$date_id  = (int) $config['date_field_id'];
			$messages = isset( $config['messages'] ) && is_array( $config['messages'] ) ? $config['messages'] : [];

			// Retrieve submitted values (sanitize and normalize).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$number_raw = isset( $_POST[ 'input_' . $num_id ] ) ? wp_unslash( $_POST[ 'input_' . $num_id ] ) : '';
			$number_val = class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'to_number' )
				? GFCommon::to_number( (string) $number_raw, class_exists( 'GFCommon' ) ? GFCommon::get_currency() : '' )
				: ( is_numeric( $number_raw ) ? (float) $number_raw : '' );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$first_raw = isset( $_POST[ 'input_' . $name_id . '_3' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'input_' . $name_id . '_3' ] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$last_raw = isset( $_POST[ 'input_' . $name_id . '_6' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'input_' . $name_id . '_6' ] ) ) : '';

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$date_raw = isset( $_POST[ 'input_' . $date_id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'input_' . $date_id ] ) ) : '';
			$date_ymd = $parse_date_to_ymd( $date_raw );

			// Build search criteria for exact match on all configured fields.
			$filters   = [ 'mode' => 'all' ];
			$filters[] = [
				'key'   => (string) $num_id,
				'value' => '' !== $number_val ? (string) $number_val : '',
			];
			$filters[] = [
				'key'   => $name_id . '.3',
				'value' => $first_raw,
			];
			$filters[] = [
				'key'   => $name_id . '.6',
				'value' => $last_raw,
			];
			$filters[] = [
				'key'   => (string) $date_id,
				'value' => $date_ymd,
			];

			$search_criteria = [
				'status'        => 'active',
				'field_filters' => $filters,
			];

			// Optional window: limit to recent entries
			$window_days = isset( $config['window_days'] ) ? (int) $config['window_days'] : 0;
			if ( $window_days > 0 ) {
				$now_ts                        = time();
				$start_ts                      = $now_ts - ( $window_days * DAY_IN_SECONDS );
				$start_date                    = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d 00:00:00', $start_ts ) : gmdate( 'Y-m-d 00:00:00', $start_ts );
				$end_date                      = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $now_ts ) : gmdate( 'Y-m-d H:i:s', $now_ts );
				$search_criteria['start_date'] = $start_date;
				$search_criteria['end_date']   = $end_date;
			}

			$paging   = [
				'offset'    => 0,
				'page_size' => 1,
			];
			$entries  = GFAPI::get_entries( $form_id, $search_criteria, null, $paging );
			$is_error = is_wp_error( $entries );

			if ( true === $is_error ) {
				return $validation_result; // Fail open: do not block submission on API error.
			}

			if ( 0 < count( $entries ) ) {
				$validation_result['is_valid'] = false;

				$fields    = $form['fields'] ?? [];
				$field_ids = is_array( $fields ) ? array_column( $fields, 'id' ) : [];

				$num_index  = array_search( $num_id, $field_ids, true );
				$name_index = array_search( $name_id, $field_ids, true );
				$date_index = array_search( $date_id, $field_ids, true );

				// Helper to set message safely.
				$set_error = static function ( $idx, $key ) use ( &$fields, $messages ) {
					if ( false !== $idx && isset( $fields[ $idx ] ) ) {
						$fields[ $idx ]->failed_validation  = true;
						$fields[ $idx ]->validation_message = $messages[ $key ] ?? 'Duplicate detected.';
					}
				};

				$set_error( $num_index, 'num' );
				$set_error( $name_index, 'name' );
				$set_error( $date_index, 'date' );

				$form['fields']            = $fields;
				$validation_result['form'] = $form;
			}

			return $validation_result;
		},
		10
	);
} )();
