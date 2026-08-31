<?php
/**
 * Display Pricing Fields on Forms in Workflow Inbox
 *
 * @gravityflow
 * @gravityforms
 *
 * GOAL:
 * Allows editing of pricing fields in the Gravity Flow inbox entry view, replacing the usual
 * "Pricing fields are not editable" message with the real field input.
 *
 * CONFIGURATION:
 * - `$workflow_form_ids`: Update to include the form IDs where pricing fields should be editable.
 * - Optionally restrict to certain pricing field types via `$allowed_pricing_types`.
 *
 * NOTES:
 * - Applies only on the Gravity Flow inbox entry view (page=gravityflow-inbox&view=entry).
 */

( static function () {
    // === Configure forms and optional allowed pricing types =====================
    $workflow_form_ids     = [ 5 ]; // e.g., [ 5, 12, 23 ]
    $allowed_pricing_types = [ 'product', 'option', 'shipping', 'quantity', 'subtotal', 'total' ]; // leave as [] to allow all pricing types
    // ==========================================================================

    if ( ! class_exists( 'GFCommon' ) || ! class_exists( 'GFAPI' ) ) {
        return;
    }

    $get_var = static function ( $key, $default_value = '' ) {
        if ( function_exists( 'rgget' ) ) {
            $val = rgget( $key );
            return is_string( $val ) ? $val : $default_value;
        }
        return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default_value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    };

    add_filter(
            'gform_field_input',
            static function ( $input, $field, $value, $entry_id, $form_id ) use ( $workflow_form_ids, $allowed_pricing_types, $get_var ) {
                // Only process if no input has been set yet and we are in the Gravity Flow inbox entry view.
                if ( '' !== $input ) {
                    return $input;
                }
                if ( 'entry' !== $get_var( 'view' ) ) {
                    return $input;
                }
                if ( 'gravityflow-inbox' !== $get_var( 'page' ) ) {
                    return $input;
                }
                if ( true !== in_array( (int) $form_id, $workflow_form_ids, true ) ) {
                    return $input;
                }

                // Only pricing fields; optionally restrict by specific pricing types.
                $type = ( is_object( $field ) && isset( $field->type ) ) ? (string) $field->type : '';
                if ( true !== GFCommon::is_pricing_field( $type ) ) {
                    return $input;
                }
                if ( ! empty( $allowed_pricing_types ) && true !== in_array( $type, $allowed_pricing_types, true ) ) {
                    return $input;
                }

                $form = GFAPI::get_form( (int) $form_id );
                if ( empty( $form ) || ! is_array( $form ) ) {
                    return $input;
                }
                $entry = $entry_id ? GFAPI::get_entry( (int) $entry_id ) : null;
                if ( is_wp_error( $entry ) ) {
                    $entry = null;
                }

                if ( is_object( $field ) && method_exists( $field, 'get_field_input' ) ) {
                    // Return the actual field input instead of the "not editable" notice.
                    return $field->get_field_input( $form, $value, $entry );
                }

                return $input;
            },
            10,
            5
    );
} )();
