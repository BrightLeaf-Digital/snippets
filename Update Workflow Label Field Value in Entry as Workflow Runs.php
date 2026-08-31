<?php
/**
 * Update Workflow Label Field Value in Entry as Workflow Runs
 *
 * @gravityflow
 *
 * GOAL:
 * Keeps a designated field (label default: "Workflow Step Field") up to date with the workflow
 * status:
 *
 * - On step start: current step name
 * - On workflow complete: complete step name
 * - On workflow cancel: "Cancelled"
 *
 * CONFIGURATION:
 * - `$status_field_label`: If you use a different field label, change to your field label.
 */

( static function () {
    if ( ! class_exists( 'GFAPI' ) ) {
        return;
    }

    $status_field_label = 'Workflow Step Field'; // Edit if your label differs

    $get_status_field_id = static function ( $form ) use ( $status_field_label ) {
        static $cache = [];
        $form_id      = isset( $form['id'] ) ? (int) $form['id'] : 0;
        if ( 0 === $form_id ) {
            return 0;
        }
        if ( isset( $cache[ $form_id ] ) ) {
            return (int) $cache[ $form_id ];
        }
        $fields = $form['fields'] ?? [];
        $id     = 0;
        if ( is_array( $fields ) ) {
            foreach ( $fields as $fld ) {
                $label = is_object( $fld ) ? ( $fld->label ?? '' ) : ( $fld['label'] ?? '' );
                if ( (string) $status_field_label === (string) $label ) {
                    $id = (int) ( is_object( $fld ) ? $fld->id : ( $fld['id'] ?? 0 ) );
                    break;
                }
            }
        }
        $cache[ $form_id ] = $id;
        return $id;
    };

    $update_entry_field = static function ( $entry_id, $form, $value ) use ( $get_status_field_id ) {
        $entry = GFAPI::get_entry( (int) $entry_id );
        if ( is_wp_error( $entry ) || empty( $entry ) ) {
            return;
        }
        $field_id = $get_status_field_id( $form );
        if ( 0 === $field_id ) {
            return;
        }
        $entry[ $field_id ] = is_string( $value ) ? trim( $value ) : $value;
        GFAPI::update_entry( $entry );
    };

    // On step start: write current step name.
    add_action(
            'gravityflow_step_start',
            static function ( $step_id, $entry_id, $form_id, $step_status, $step ) use ( $update_entry_field ) {
                $form = GFAPI::get_form( (int) $form_id );
                if ( empty( $form ) || ! is_array( $form ) ) {
                    return;
                }
                $name = is_object( $step ) && method_exists( $step, 'get_name' ) ? (string) $step->get_name() : '';
                if ( '' !== $name ) {
                    $update_entry_field( $entry_id, $form, $name );
                }
            },
            10,
            5
    );

    // On workflow complete: write the complete step name.
    add_action(
            'gravityflow_workflow_complete',
            static function ( $entry_id, $form ) use ( $update_entry_field ) {
                if ( ! function_exists( 'gravity_flow' ) ) {
                    return;
                }
                $entry = GFAPI::get_entry( (int) $entry_id );
                if ( is_wp_error( $entry ) ) {
                    return;
                }
                $gwf = gravity_flow();
                if ( ! is_object( $gwf ) || ! method_exists( $gwf, 'get_workflow_complete_step' ) ) {
                    return;
                }
                $step = $gwf->get_workflow_complete_step( isset( $form['id'] ) ? (int) $form['id'] : 0, $entry );
                if ( is_object( $step ) && method_exists( $step, 'get_name' ) ) {
                    $update_entry_field( $entry_id, $form, (string) $step->get_name() );
                }
            },
            10,
            2
    );

    // On pre-cancel: write "Cancelled".
    add_action(
            'gravityflow_pre_cancel_workflow',
            static function ( $entry, $form ) use ( $update_entry_field ) {
                $eid = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
                if ( 0 !== $eid ) {
                    $update_entry_field( $eid, $form, 'Cancelled' );
                }
            },
            10,
            2
    );
} )();
