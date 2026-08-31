<?php
/**
 * Protect Hidden Calculation Fields during Approval Step
 *
 * @gravityflow
 * @gravityforms
 *
 * GOAL:
 * GravityFlow Approval and User Input steps have a little known quirk. Before processing the step
 * (approval, rejection, user input, etc.) they clear the values from fields that are hidden by
 * conditional logic if those fields are marked as editable OR are calculation fields. This snippet
 * adds a multi select field to the bottom of your Approval or User Input step settings that allows
 * you to select any calculation field on the form. If the selected field is hidden by conditional
 * logic during the pre-processing the field is temporarily (for the duration of the step) marked
 * as read only to protect it from being cleared.
 */

add_filter(
    'gravityflow_step_settings_fields',
    function ( $settings ) {
        $form_id = absint( rgget( 'id' ) );
        if ( ! $form_id ) {
            return $settings;
        }

        $get_calc_fields = function () use ( $form_id ) {

            $form = GFAPI::get_form( $form_id );
            if ( empty( $form ) || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
                return [];
            }

            $choices = [];
            foreach ( $form['fields'] as $field ) {
                if ( ! $field instanceof GF_Field ) {
                    continue;
                }

                if ( ! $field->has_calculation() ) {
                    continue;
                }

                $choices[] = [
                    'label' => sprintf( '%s (#%s)', GFFormsModel::get_label( $field ), $field->id ),
                    'value' => $field->id,
                ];
            }

            return $choices;
        };

        $choices = $get_calc_fields();
        if ( empty( $choices ) ) {
            return $settings;
        }

        $field = [
            'name'     => 'protected_hidden_calc_fields[]',
            'label'    => 'Protect Hidden Calculation Fields',
            'type'     => 'select',
            'multiple' => 'multiple',
            'class'    => 'gravityflow-multiselect-ui',
            'choices'  => $choices,
            'tooltip'  => '<h6>Protect Hidden Calculation Fields</h6>Selected calculation fields are treated as read-only when hidden so their values are not cleared during step saves.',
        ];

        foreach ( $settings as &$group ) {
            if ( empty( $group['id'] ) ) {
                continue;
            }

            if ( in_array( $group['id'], [ 'gravityflow-step-settings-approval', 'gravityflow-step-settings-user_input' ], true ) ) {
                if ( ! isset( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
                    $group['fields'] = [];
                }
                $group['fields'][] = $field;
            }
        }
        unset( $group );

        return $settings;
    },
);
add_filter(
    'gform_pre_validation',
    function ( $form ) {

        $get_rest_entry_id = function () {
            $entry_id = absint( rgpost( 'id' ) );
            if ( $entry_id ) {
                return $entry_id;
            }

            if ( empty( $_SERVER['REQUEST_URI'] ) ) {
                return 0;
            }

            if ( preg_match( '#/gf/v2/entries/(\\d+)/workflow/steps/current/process#', wp_unslash( $_SERVER['REQUEST_URI'] ), $matches ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                return absint( $matches[1] );
            }

            return 0;
        };

        $get_current_step_for_validation = function () use ( $form, $get_rest_entry_id ) {

            $entry    = null;
            $entry_id = absint( rgget( 'lid' ) );
            if ( $entry_id ) {
                $entry = GFAPI::get_entry( $entry_id );
                if ( is_wp_error( $entry ) ) {
                    $entry = null;
                }
            }

            $step_id = absint( rgpost( 'step_id' ) );
            if ( $step_id && rgpost( 'gforms_save_entry' ) ) {
                return gravity_flow()->get_step( $step_id, $entry );
            }

            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                $entry_id = $get_rest_entry_id();
                if ( $entry_id ) {
                    $entry = GFAPI::get_entry( $entry_id );
                    if ( is_wp_error( $entry ) ) {
                        $entry = null;
                    }
                }

                if ( is_array( $entry ) && ! empty( $form['id'] ) ) {
                    return gravity_flow()->get_current_step( $form, $entry );
                }
            }

            return false;
        };

        $step = $get_current_step_for_validation();
        if ( ! $step || ! in_array( $step->get_type(), [ 'approval', 'user_input' ], true ) ) {
            return $form;
        }

        $protected_fields = $step->get_setting( 'protected_hidden_calc_fields' );
        if ( empty( $protected_fields ) ) {
            return $form;
        }

        if ( ! is_array( $protected_fields ) ) {
            $protected_fields = [ $protected_fields ];
        }

        if ( empty( $protected_fields ) ) {
            return $form;
        }

        $protected_fields = array_map( 'strval', $protected_fields );

        if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
            return $form;
        }

        $entry = $step->get_entry();
        if ( is_wp_error( $entry ) ) {
            $entry = null;
        }

        foreach ( $form['fields'] as &$field ) {
            if ( ! $field instanceof GF_Field ) {
                continue;
            }

            if ( ! in_array( (string) $field->id, $protected_fields, true ) ) {
                continue;
            }

            if ( ! $field->has_calculation() ) {
                continue;
            }

            $is_hidden = false;
            if ( is_array( $entry ) ) {
                $is_hidden = GFFormsModel::is_field_hidden( $form, $field, [], $entry );
            }

            if ( ! $is_hidden ) {
                continue;
            }

            $field->displayOnly = true; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        }
        unset( $field );

        return $form;
    }
);
