<?php
/**
 * Send Error Notification When Gravity Flow Workflow Fails to Run
 *
 * @gravityflow
 * @gravityforms
 * @gravityperks
 *
 * GOAL:
 * Monitors Gravity Flow workflows and alerts administrators when workflow fails to start after
 * form submission. Critical for catching workflow configuration errors, conditional logic issues,
 * or system failures that prevent automated processes from running. Checks both parent and nested
 * child forms.
 *
 * CONFIGURATION:
 * - `$to`: Add recipient email address in $to variable. Else, defaults to admin email.
 *
 * FEATURES:
 * - Workflow configuration details
 * - Conditional logic evaluation (shows if conditions are met/not met and why)
 * - Rule-by-rule breakdown with field values vs expected values
 * - Overview of all workflow steps and their conditional logic status
 * - Direct link to entry in admin panel
 * - Works with both parent and nested child forms
 * - Excludes workflows with "delay_workflow_checkbox" (intentionally delayed workflows, see our
 *   snippet here (https://brightleafdigital.io/view/code-snippet-directory/entry/49-restart-
 *   workflow-on-gv-approval-and-subscription-payment/))
 */

add_action(
    'gform_after_submission',
    function ( $entry, $form ) {

        if ( ! function_exists( 'gravity_flow' ) ) {
            return;
        }

        $to = ''; // add email here.

        if ( empty( $to ) ) {
            $to = get_option( 'admin_email' );
        }

        $check_if_workflow_ran = function ( $entry, $form ) use ( $to ) {
            $start_step = gravity_flow()->get_workflow_start_step( $form['id'], $entry );
            if ( $start_step && $start_step->is_active() && ! $start_step->is_queued() && ! $start_step->__get( 'delay_workflow_checkbox' ) ) {
                $step_id = $start_step->get_id();
                if ( empty( $entry[ "workflow_step_status_$step_id" ] ) ) {

                    $time = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )->format( 'm/d/Y g:i A' );

                    $url        = GFCommon::replace_variables( '{embed_url}', $form, $entry );
                    $form_title = $form['title'];
                    $user       = get_user_by( 'id', $entry['created_by'] );
                    $user_id    = $user ? $user->ID : 0;
                    $user_name  = $user_id > 0 ? $user->display_name : 'Guest';
                    $user_email = $user_id > 0 ? $user->user_email : 'N/A';
                    $entry_link = '<a target="_blank" href="' . esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry['id'] ) ) . '">' . $entry['id'] . '</a>';

                    // Get workflow configuration details
                    $workflow_details   = [];
                    $workflow_details[] = 'WORKFLOW START STEP CONFIGURATION:';
                    $workflow_details[] = '  Step Name: ' . $start_step->get_name();

                    // Get next step info
                    $next_step = gravity_flow()->get_next_step( $start_step, $entry, $form );
                    if ( $next_step ) {
                        $workflow_details[] = '  Next Step: ' . $next_step->get_name() . ' (ID: ' . $next_step->get_id() . ')';
                    }

                    // Check conditional logic
                    $conditional_logic_details   = [];
                    $conditional_logic_details[] = PHP_EOL . 'CONDITIONAL LOGIC EVALUATION:';

                    // Check if start step has conditional logic
                    $step_conditional_logic = $start_step->__get( 'feed_condition_conditional_logic_object' );
                    if ( ! empty( $step_conditional_logic ) ) {
                        $conditional_logic_details[] = '  Start Step Conditional Logic: ENABLED';
                        $conditional_logic_details[] = '    Logic Mode: ' . ( $step_conditional_logic['logicType'] ?? 'all' );

                        // Evaluate the conditional logic
                        $is_condition_met            = $start_step->is_condition_met( $form );
                        $conditional_logic_details[] = '    Condition Met: ' . ( $is_condition_met ? 'YES' : 'NO (This may be why workflow did not start)' );

                        // Show the rules
                        if ( isset( $step_conditional_logic['conditionalLogic']['rules'] ) && is_array( $step_conditional_logic['conditionalLogic']['rules'] ) ) {
                            $conditional_logic_details[] = '    Rules (' . count( $step_conditional_logic['conditionalLogic']['rules'] ) . ' total):';
                            foreach ( $step_conditional_logic['conditionalLogic']['rules'] as $index => $rule ) {
                                $field_id    = rgar( $rule, 'fieldId' );
                                $operator    = rgar( $rule, 'operator' );
                                $value       = rgar( $rule, 'value' );
                                $entry_value = rgar( $entry, $field_id );

                                // Get field label
                                $field_obj   = GFAPI::get_field( $form, $field_id );
                                $field_label = $field_obj ? $field_obj->label : 'Field ' . $field_id;

                                $conditional_logic_details[] = sprintf(
                                    '      Rule #%d: %s (field %s) %s "%s" | Entry Value: "%s"',
                                    $index + 1,
                                    $field_label,
                                    $field_id,
                                    $operator,
                                    $value,
                                    $entry_value
                                );
                            }
                        }
                    } else {
                        $conditional_logic_details[] = '  Start Step Conditional Logic: NOT CONFIGURED (always runs)';
                    }

                    // Check for form-level conditional logic on all workflow steps
                    $all_steps = gravity_flow()->get_steps( $form['id'] );
                    if ( ! empty( $all_steps ) ) {
                        $conditional_logic_details[] = PHP_EOL . '  All Workflow Steps (' . count( $all_steps ) . ' total):';
                        foreach ( $all_steps as $step ) {
                            $step_logic                  = $step->__get( 'feed_condition_conditional_logic_object' );
                            $has_logic                   = ! empty( $step_logic );
                            $conditional_logic_details[] = sprintf(
                                '    - %s (ID: %s, Type: %s) - Conditional Logic: %s',
                                $step->get_name(),
                                $step->get_id(),
                                $step->get_type(),
                                $has_logic ? 'YES' : 'NO'
                            );
                        }
                    }

                    $subject = "Workflow failed to start for form $form_title at $time";
                    $message = "Time: $time" . PHP_EOL
                        . "URL: $url" . PHP_EOL
                        . "Form: $form_title (ID: " . $form['id'] . ')' . PHP_EOL
                        . 'Entry ID: ' . $entry['id'] . PHP_EOL
                        . 'Entry: ' . $entry_link . PHP_EOL
                        . "User: $user_name" . PHP_EOL
                        . "User Email: $user_email" . PHP_EOL . PHP_EOL
                        . implode( PHP_EOL, $workflow_details ) . PHP_EOL
                        . implode( PHP_EOL, $conditional_logic_details );

                    wp_mail( $to, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );
                }
            }
        };

        if ( ! rgar( $entry, 'gpnf_entry_parent_form' ) ) {
            foreach ( $form['fields'] as $field ) {
                if ( $field instanceof GP_Field_Nested_Form ) {

                    $child_form_id = $field->gpnfForm; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    $child_form    = GFAPI::get_form( $child_form_id );
                    if ( ! $child_form ) {
                        continue;
                    }

                    $parent_entry = new GPNF_Entry( $entry );
                    $entries      = $parent_entry->get_child_entries();

                    foreach ( $entries as $child_entry ) {
                        $check_if_workflow_ran( $child_entry, $child_form );
                    }
                }
            }

            $check_if_workflow_ran( $entry, $form );
        }
    },
    11, // run after workflow is supposed to start
    2
);
