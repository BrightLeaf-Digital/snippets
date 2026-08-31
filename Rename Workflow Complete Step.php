<?php
/**
 * Rename Workflow Complete Step
 *
 * @gravityflow
 * @gravityview
 *
 * GOAL:
 * This code snippet **renames the "Workflow Complete" step** in Gravity Flow by dynamically
 * replacing it with the actual name of the last completed step in the workflow. This ensures that
 * users see a meaningful step name rather than the generic "Workflow Complete" label when viewing
 * GravityView entries.
 *
 * CONFIGURATION:
 * - `$form_ids`: Update to the list of form IDs this should apply to.
 *
 * NOTES:
 * - Runs only when the field value is exactly "Workflow Complete".
 */

( static function () {
    // === Configure applicable form IDs =========================================
    $form_ids = [ 3, 45, 5, 7, 21, 51, 25, 11, 29 ];
    // ==========================================================================

    add_filter(
            'gravityview_field_output',
            static function ( $html, $args ) use ( $form_ids ) {
                $form_id = ( isset( $args['entry']['form_id'] ) ) ? (int) $args['entry']['form_id'] : 0;
                if ( 0 === $form_id || true !== in_array( $form_id, $form_ids, true ) ) {
                    return $html;
                }
                $value = isset( $args['value'] ) ? (string) $args['value'] : '';
                if ( 'Workflow Complete' !== $value ) {
                    return $html;
                }
                if ( ! class_exists( 'Gravity_Flow' ) || ! function_exists( 'gravity_flow' ) ) {
                    return $html;
                }
                $gf = Gravity_Flow::get_instance();
                if ( ! is_object( $gf ) ) {
                    return $html;
                }
                $entry = $args['entry'] ?? null;
                $step  = $gf->get_workflow_complete_step( $form_id, $entry );
                if ( ! is_object( $step ) || ! method_exists( $step, 'get_name' ) ) {
                    return $html;
                }
                $step_name = (string) $step->get_name();
                if ( '' === $step_name ) {
                    return $html;
                }
                // Replace exact phrase in the rendered HTML.
                return str_replace( 'Workflow Complete', $step_name, $html );
            },
            10,
            2
    );
} )();
