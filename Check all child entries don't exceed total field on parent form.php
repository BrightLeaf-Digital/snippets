<?php
/**
 * Check all child entries don't exceed total field on parent form
 *
 * @gravityforms
 * @gravityperks
 *
 * GOAL:
 * This code snippet ensures that the sum of all child entries in a Gravity Forms nested form does
 * not exceed the total specified in the parent form. It validates the "Amount" field in child
 * entries against the "Total" field in the parent form, preventing over-allocation of resources,
 * budgets, or quotas. Shows a detailed, currency-formatted message if the attempt would exceed the
 * total (Total, Used, Remaining, Attempted, Overage).
 *
 * REQUIREMENTS:
 * - Gravity Forms and Gravity Perks Nested Forms are required.
 * - GP Advanced Calculations is optional but recommended if the parent "Total" field uses a
 *   calculation. If Advanced Calculations is active and the target field has a calculation
 *   Formula, the formula is evaluated server-side during child validation.
 * - If you are not using a calculation, set a numeric default value on the parent "Total" field.
 *   If neither a calculation nor a numeric default value is present, this validation gracefully
 *   skips.
 *
 * CONFIGURATION:
 * - `$child_field_label`: label of the Number field on the child form that represents the amount
 *   to add (default: "Amount").
 * - `$parent_field_label`: label of the Calculated/Number field on the parent that represents the
 *   total (default: "Total")
 *
 * FEATURES:
 * - **Validates child form entries** to ensure the cumulative amount does not exceed the total in
 *   the parent form.
 * - **Works with Gravity Forms Nested Forms** to enforce limits across multiple child entries.
 * - **Dynamically calculates totals** from submitted child entries.
 * - **Customizable field labels** (e.g., "Amount" in child forms and "Total" in the parent form).
 * - **Displays a validation message** when the limit is exceeded, preventing submission errors.
 */

add_filter(
        'gform_validation',
        function ( $validation_result ) {
            // Configuration: adjust these labels to match your forms.
            $parent_field_label = 'Total';
            $child_field_label  = 'Amount';

            // Get the current form from the validation result.
            $form = $validation_result['form'] ?? null;
            if ( empty( $form ) || ! is_array( $form ) ) {
                return $validation_result;
            }

            // Ensure required Nested Forms classes exist before proceeding.
            if ( ! class_exists( 'GP_Nested_Forms' ) || ! class_exists( 'GPNF_Session' ) ) {
                // Required add-ons not available; skip validation to avoid fatals.
                return $validation_result;
            }

            // Confirm we are validating a Nested Forms child form submission.
            $maybe_nested_form = new GP_Nested_Forms( $form );
            if ( ! $maybe_nested_form->is_nested_form_submission() ) {
                return $validation_result;
            }

            // Resolve parent form and session once.
            $parent_id       = $maybe_nested_form->get_parent_form_id();
            $parent_form_arr = $parent_id ? GFAPI::get_form( $parent_id ) : null;
            if ( empty( $parent_form_arr ) || ! is_array( $parent_form_arr ) ) {
                // Parent form not available; cannot validate against total.
                return $validation_result;
            }

            $session            = new GPNF_Session( $parent_id );
            $cookie             = method_exists( $session, 'get_cookie' ) ? $session->get_cookie() : [];
            $raw_nested_entries = is_array( $cookie ) && isset( $cookie['nested_entries'] ) ? $cookie['nested_entries'] : [];

            // Currency and numeric parsing helper (locale-aware).
            $currency  = class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'get_currency' ) ? GFCommon::get_currency() : '';
            $to_number = static function ( $val ) use ( $currency ) {
                if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'to_number' ) ) {
                    return GFCommon::to_number( (string) $val, $currency );
                }
                return is_numeric( $val ) ? (float) $val : 0.0;
            };

            // Get valid entry IDs and flatten the array if needed.
            $entry_ids = $session->get_valid_entry_ids( $raw_nested_entries );
            $flatten   = static function ( $items ) use ( &$flatten ) {
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
            $entry_ids = $flatten( $entry_ids );

            // Load existing child entries attached to the parent form.
            $parent_gpnf = new GP_Nested_Forms( $parent_form_arr );
            $entries     = ! empty( $entry_ids ) ? $parent_gpnf->get_entries( $entry_ids ) : [];

            // Build a cache of the child 'Amount' field ID per child form to avoid nested loops.
            $amount_field_id_by_form = [];
            $existing_total          = 0.0;

            foreach ( $entries as $entry ) {
                $child_form_id = $entry['form_id'] ?? 0;
                if ( ! $child_form_id ) {
                    continue;
                }

                if ( ! isset( $amount_field_id_by_form[ $child_form_id ] ) ) {
                    $child_form = GFAPI::get_form( $child_form_id );
                    $field_id   = null;
                    if ( is_array( $child_form ) && isset( $child_form['fields'] ) ) {
                        foreach ( $child_form['fields'] as $child_field ) {
                            if ( isset( $child_field->label ) && $child_field_label === $child_field->label ) {
                                $field_id = $child_field->id;
                                break;
                            }
                        }
                    }
                    $amount_field_id_by_form[ $child_form_id ] = $field_id; // may be null if not found
                }

                $field_id = $amount_field_id_by_form[ $child_form_id ];
                if ( null === $field_id ) {
                    continue; // no matching field on this child form
                }

                $raw_value       = $entry[ (string) $field_id ] ?? ( $entry[ $field_id ] ?? '' );
                $existing_total += $to_number( $raw_value );
            }

            // Find the matching child field(s) on the current (child) form and validate.
            $parent_total     = null;
            $parent_total_set = false;

            // Locate the parent 'Total' field and compute its value safely.
            if ( isset( $parent_form_arr['fields'] ) && is_array( $parent_form_arr['fields'] ) ) {
                foreach ( $parent_form_arr['fields'] as $parent_field ) {
                    if ( isset( $parent_field->label ) && $parent_field_label === $parent_field->label ) {
                        // Prefer Advanced Calculations if available.
                        if ( class_exists( 'GP_Advanced_Calculations' ) && isset( $parent_field->calculationFormula ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                            try {
                                $calc    = new GP_Advanced_Calculations();
                                $formula = $parent_field->calculationFormula; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                                $result  = $calc->eval_formula( $formula );
                                if ( is_numeric( $result ) ) {
                                    $parent_total     = $to_number( $result );
                                    $parent_total_set = true;
                                }
                            } catch ( Throwable $e ) {
                                // Ignore and leave $parent_total unset.
                            }
                        }

                        // If total is still not set, attempt to fall back to a numeric defaultValue or 0.
                        if ( ! $parent_total_set ) {
                            $fallback = $parent_field->defaultValue ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                            if ( is_numeric( $fallback ) ) {
                                $parent_total = $to_number( $fallback );
                            }
                        }

                        break; // Found the target parent field; no need to continue looping.
                    }
                }
            }

            // If we cannot determine a numeric parent total, abort validation gracefully.
            if ( ! is_numeric( $parent_total ) ) {
                $validation_result['form'] = $form;
                return $validation_result;
            }

            // Iterate fields to find the child amount field and apply validation.
            if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
                foreach ( $form['fields'] as &$field ) {
                    if ( isset( $field->label ) && $child_field_label === $field->label ) {
                        // Safely derive the current input value via GF APIs.
                        $submitted = null;
                        if ( method_exists( $field, 'get_value_submission' ) ) {
                            $source    = isset( $_POST ) && is_array( $_POST ) ? $_POST : []; //phpcs:ignore WordPress.Security.NonceVerification.Missing
                            $submitted = $field->get_value_submission( $source );
                        }
                        if ( ( null === $submitted || '' === $submitted ) && function_exists( 'rgpost' ) ) {
                            $submitted = rgpost( 'input_' . $field->id );
                        }

                        $current_value = $to_number( $submitted );
                        $attempted     = $existing_total + $current_value;

                        if ( $attempted > $parent_total ) {
                            // Build a detailed, currency-formatted message.
                            $used_before      = $existing_total;
                            $remaining_before = max( 0.0, $parent_total - $used_before );
                            $overage          = max( 0.0, $attempted - $parent_total );

                            if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'to_money' ) ) {
                                $total_fmt     = GFCommon::to_money( $parent_total, $currency );
                                $used_fmt      = GFCommon::to_money( $used_before, $currency );
                                $remaining_fmt = GFCommon::to_money( $remaining_before, $currency );
                                $current_fmt   = GFCommon::to_money( $current_value, $currency );
                                $overage_fmt   = GFCommon::to_money( $overage, $currency );
                            } else {
                                // Fallback basic formatting.
                                $total_fmt     = (string) $parent_total;
                                $used_fmt      = (string) $used_before;
                                $remaining_fmt = (string) $remaining_before;
                                $current_fmt   = (string) $current_value;
                                $overage_fmt   = (string) $overage;
                            }

                            $message  = 'The amount you entered exceeds your total.';
                            $message .= ' Total available: ' . $total_fmt . '.';
                            $message .= ' Already used: ' . $used_fmt . '.';
                            $message .= ' Remaining: ' . $remaining_fmt . '.';
                            $message .= ' You attempted to add: ' . $current_fmt . ' (over by ' . $overage_fmt . ').';
                            $message .= ' Please enter an amount up to ' . $remaining_fmt . '.';

                            $field->failed_validation      = true;
                            $field->validation_message     = $message;
                            $validation_result['is_valid'] = false;
                        }
                    }
                }
                unset( $field ); // break reference
            }

            $validation_result['form'] = $form;
            return $validation_result;
        }
);
