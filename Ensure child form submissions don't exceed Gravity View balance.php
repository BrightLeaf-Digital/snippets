<?php
/**
 * Ensure child form submissions don't exceed Gravity View balance
 *
 * @gravityforms
 * @gravityperks
 *
 * GOAL:
 * This code snippet ensures that child form submissions in Gravity Forms do not exceed the
 * available balance recorded in a GravityView ledger. It validates disbursal requests against the
 * user's balance, preventing over-allocation of funds across multiple pending and approved
 * submissions. Each array at the top of the snippet is for a different form with it's own ledger.
 * You can use this snippet for as many forms/ledgers as many as you want, just make sure they all
 * have the same structure and to replace the relevant id's.
 *
 * CONFIGURATION:
 * - `$child_forms`: Add each child form you need to validate. `child_amount_field_id`: The Amount
 *   field ID on the child form. `child_name_field_id`: The field ID on the child form that
 *   identifies the person/entity. `ledger_form_id`: The ID of the separate ledger form holding
 *   deposits/credits. `ledger_name_id`: The field ID on the ledger form that identifies the
 *   person/entity. `ledger_amount_id`: The Amount field ID on the ledger form to total up.
 *
 * FEATURES:
 * - **Prevents over-disbursement** by checking the requested amount against the available balance.
 * - **Validates nested child form entries** to ensure total requests do not exceed the ledger
 *   balance.
 * - **Supports multiple forms** by allowing configuration for different form setups.
 * - **Includes pending disbursals** in the balance calculation for real-time accuracy.
 * - **Displays a detailed validation message** when the requested amount exceeds the available
 *   balance.
 * - **Efficiently retrieves and processes Gravity Forms entries** with pagination for large
 *   datasets.
 */

( static function () {
	// === Child Form Settings (edit these IDs to match your site) ===
	$child_forms = [
		32 => [
			'child_amount_field_id' => 8,
			'child_name_field_id'   => 19,
			'ledger_form_id'        => 30,
			'ledger_name_id'        => 1,
			'ledger_amount_id'      => 4,
		],
		62 => [
			'child_amount_field_id' => 8,
			'child_name_field_id'   => 19,
			'ledger_form_id'        => 60,
			'ledger_name_id'        => 1,
			'ledger_amount_id'      => 16,
		],
		26 => [
			'child_amount_field_id' => 8,
			'child_name_field_id'   => 19,
			'ledger_form_id'        => 28,
			'ledger_name_id'        => 1,
			'ledger_amount_id'      => 4,
		],
		// Add more like: 123 => [ 'child_amount_field_id' => 1, 'child_name_field_id' => 2, 'ledger_form_id' => 10, 'ledger_name_id' => 1, 'ledger_amount_id' => 3 ],
	];
	// ==============================================================

	if ( ! class_exists( 'GFAPI' ) ) {
		return; // Gravity Forms not active.
	}

	$to_number = static function ( $val ) {
		if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'to_number' ) ) {
			$currency = GFCommon::get_currency();
			return GFCommon::to_number( (string) $val, $currency );
		}
		return is_numeric( $val ) ? (float) $val : 0.0;
	};

	$collect_submitted_value = static function ( $field_id ) {
		$single = rgpost( 'input_' . $field_id );
		if ( ! is_null( $single ) && '' !== $single ) {
			return is_string( $single ) ? trim( $single ) : $single;
		}
		$parts = [];
		foreach ( $_POST as $key => $val ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( is_string( $key ) && str_starts_with( $key, 'input_' . $field_id . '_' ) ) {
				$trimmed = is_string( $val ) ? trim( $val ) : $val;
				if ( '' !== $trimmed ) {
					$parts[] = $trimmed;
				}
			}
		}
		return implode( ' ', $parts );
	};

	$get_all_entries = static function ( $form_id, array $search_criteria ) {
		$total_count   = 0;
		$paging_offset = 0;
		$paging        = [
			'offset'    => $paging_offset,
			'page_size' => 25,
		];
		$entries       = GFAPI::get_entries( (int) $form_id, $search_criteria, null, $paging, $total_count );
		if ( is_wp_error( $entries ) ) {
			return [];
		}
		while ( $total_count > count( $entries ) ) {
			$paging_offset += 25;
			$paging         = [
				'offset'    => $paging_offset,
				'page_size' => 25,
			];
			$batch          = GFAPI::get_entries( (int) $form_id, $search_criteria, null, $paging, $total_count );
			if ( is_wp_error( $batch ) ) {
				break;
			}
			foreach ( $batch as $e ) {
				$entries[] = $e;
			}
		}
		return $entries;
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
		'gform_validation',
		static function ( $validation_result ) use ( $child_forms, $to_number, $collect_submitted_value, $get_all_entries, $flatten ) {
			$form = rgar( $validation_result, 'form' );
			if ( empty( $form ) || ! is_array( $form ) ) {
				return $validation_result;
			}

			$form_id = (int) rgar( $form, 'id' );
			if ( ! isset( $child_forms[ $form_id ] ) ) {
				return $validation_result;
			}
			$cfg = $child_forms[ $form_id ];

			$amount_field_id  = (int) $cfg['child_amount_field_id'];
			$name_field_id    = (int) $cfg['child_name_field_id'];
			$ledger_form_id   = (int) $cfg['ledger_form_id'];
			$ledger_name_id   = (int) $cfg['ledger_name_id'];
			$ledger_amount_id = (string) $cfg['ledger_amount_id'];

			// Validate Nested Forms context and session.
			if ( ! class_exists( 'GP_Nested_Forms' ) || ! class_exists( 'GPNF_Session' ) ) {
				return $validation_result; // Required add-on not available.
			}

			$nested_form = new GP_Nested_Forms( $form );
			$parent_id   = method_exists( $nested_form, 'get_parent_form_id' ) ? $nested_form->get_parent_form_id() : 0;
			if ( ! $parent_id ) {
				return $validation_result;
			}

			// Current submitted amount.
			$current_amount = $to_number( rgpost( 'input_' . $amount_field_id ) );
			if ( $current_amount <= 0 ) {
				return $validation_result;
			}

			// Sum existing child entries already in this batch/session.
			$session = new GPNF_Session( $parent_id );
			$cookie  = method_exists( $session, 'get_cookie' ) ? $session->get_cookie() : [];
			$ids     = is_array( $cookie ) && isset( $cookie['nested_entries'] ) ? $flatten( $session->get_valid_entry_ids( $cookie['nested_entries'] ) ) : [];

			$parent_form_arr  = GFAPI::get_form( $parent_id );
			$parent_gpnf      = is_array( $parent_form_arr ) ? new GP_Nested_Forms( $parent_form_arr ) : null;
			$existing_entries = $parent_gpnf && ! empty( $ids ) ? $parent_gpnf->get_entries( $ids ) : [];

			$batch_total = 0.0;

            // Determine if we are currently editing an entry so we don't double-count it
			$editing_entry_id = rgpost( 'gpnf_entry_id' ) ? rgpost( 'gpnf_entry_id' ) : rgget( 'fwp_entry' );

			foreach ( $existing_entries as $entry ) {
                // If the entry we are looping through is the one currently being edited, skip it.
                if ( $editing_entry_id && $editing_entry_id == rgar( $entry, 'id' ) ) {
                    continue;
                }
				$batch_total += $to_number( rgar( $entry, (string) $amount_field_id ) );
			}

			// Identify the person/entity value from the current submission.
			$name_value = $collect_submitted_value( $name_field_id );
			if ( '' === $name_value ) {
				return $validation_result;
			}

			// Get ledger total for this person/entity (cached per request by GFAPI internally).
			$ledger_search  = [
				'status'        => 'active',
				'field_filters' => [
					[
						'key'   => (string) $ledger_name_id,
						'value' => $name_value,
					],
				],
			];
			$ledger_entries = $get_all_entries( $ledger_form_id, $ledger_search );
			$ledger_total   = 0.0;
			foreach ( $ledger_entries as $le ) {
				$ledger_total += $to_number( rgar( $le, (string) $ledger_amount_id ) );
			}

			// Sum pending requests on this child form for same person/entity.
			$filters = [
				[
					'key'   => (string) $name_field_id,
					'value' => $name_value,
				],
			];
			if ( function_exists( 'gravity_flow' ) || class_exists( 'Gravity_Flow' ) ) {
				$filters[] = [
					'key'   => 'workflow_final_status',
					'value' => 'pending',
				];
			}
			$pending_search  = [
				'status'        => 'active',
				'field_filters' => $filters,
			];
			$pending_entries = $get_all_entries( $form_id, $pending_search );
			$pending_total   = 0.0;
			foreach ( $pending_entries as $pe ) {
				$pending_total += $to_number( rgar( $pe, (string) $amount_field_id ) );
			}

			$available_balance = $ledger_total - $pending_total;
			$total_requested   = $current_amount + $batch_total;

			if ( $total_requested > $available_balance ) {
				$money_balance = number_format( max( 0, $available_balance ), 2 );
				$amount_over   = number_format( $total_requested - max( 0, $available_balance ), 2 );

				$fields = rgar( $form, 'fields', [] );
				foreach ( $fields as $f ) {
					if ( isset( $f->id ) && (int) $f->id === $amount_field_id ) {
						$f->failed_validation  = true;
						$f->validation_message = 'With this request, your total requested amount exceeds your available balance of $' . $money_balance . ' by $' . $amount_over . '.';
						break;
					}
				}
				$validation_result['is_valid'] = false;
				$validation_result['form']     = $form;
			}

			return $validation_result;
		},
		10
	);
} )();
