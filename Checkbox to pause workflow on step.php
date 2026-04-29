<?php
/**
 * Pause GravityFlow workflow after a step completion if configured.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'gravityflow_step_settings_fields',
	function ( $settings ) {
		$field = [
			'name'    => 'pause_after_step',
			'label'   => 'Pause after this step',
			'type'    => 'checkbox',
			'choices' => [
				[
					'label' => 'Pause workflow after completion',
					'name'  => 'pause_after_step',
				],
			],
			'tooltip' => '<h6>Pause Workflow</h6>If checked, the workflow will be paused after this step is completed.',
		];

		if ( isset( $settings[0]['fields'] ) && is_array( $settings[0]['fields'] ) ) {
			$settings[0]['fields'][] = $field;
		}
		return $settings;
	}
);

add_filter(
	'gravityflow_next_step',
	function ( $step, $current_step, $entry ) {
		// If workflow is paused, do not proceed to the next step
		if ( isset( $entry['workflow_final_status'] ) && 'paused' === $entry['workflow_final_status'] ) {
			return false;
		}
		return $step;
	},
	10,
	3
);

add_action(
	'gravityflow_step_complete',
	function ( $step_id, $entry_id, $form_id, $status, $step ) {
		// Check if the setting is enabled for this step
		$pause = $step->get_setting( 'pause_after_step' );
		if ( ! empty( $pause ) ) {
			// Update workflow status to 'paused'
			gform_update_meta( $entry_id, 'workflow_final_status', 'paused' );

			// Update workflow current status to 'paused' so process_workflow doesn't set it to 'complete'
			gform_update_meta( $entry_id, 'workflow_current_status', 'paused' );

			// Add timeline note
			if ( function_exists( 'gravity_flow' ) ) {
				gravity_flow()->add_timeline_note( $entry_id, 'Workflow paused on this step.' );
			}

			// Set step status to 'pending' so it doesn't show as complete
			$step->update_step_status( 'pending' );
		}
	},
	10,
	5
);
