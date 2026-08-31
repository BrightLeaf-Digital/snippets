<?php
/**
 * Pause GravityFlow workflow after a step completion if configured.
 *
 * The pause can be limited to particular step outcomes. Gravity Flow fires
 * gravityflow_step_complete for every status a step can end on, failures included, so a step with
 * more than one outcome offers a checkbox per status. Ticking none keeps the original behaviour of
 * pausing however the step ends.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'gravityflow_step_settings_fields',
	function ( $settings, $current_step_id = 0 ) {
		if ( ! isset( $settings[0]['fields'] ) || ! is_array( $settings[0]['fields'] ) ) {
			return $settings;
		}

		$settings[0]['fields'][] = [
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

		$step = $current_step_id ? gravity_flow()->get_step( $current_step_id ) : false;
		if ( ! $step || ! method_exists( $step, 'get_status_config' ) ) {
			return $settings;
		}

		// A step with a single outcome has nothing to choose between, so it keeps the plain checkbox.
		$status_config = (array) $step->get_status_config();
		if ( count( $status_config ) < 2 ) {
			return $settings;
		}

		$choices = [];
		foreach ( $status_config as $config ) {
			$status = (string) rgar( $config, 'status' );
			if ( '' === $status ) {
				continue;
			}
			$choices[] = [
				'label' => rgar( $config, 'status_label' ) ?: $status,
				'name'  => 'pause_after_step_status_' . $status,
			];
		}

		if ( empty( $choices ) ) {
			return $settings;
		}

		$settings[0]['fields'][] = [
			'name'       => 'pause_after_step_statuses',
			'label'      => 'Pause only on these outcomes',
			'type'       => 'checkbox',
			'choices'    => $choices,
			// Only relevant once the pause itself is switched on, so the settings framework hides this
			// field until then. 'live' updates it as the box is ticked rather than only on reload, and
			// omitting 'values' makes the rule match the pause checkbox's first (only) choice.
			'dependency' => [
				'live'   => true,
				'fields' => [
					[
						'field' => 'pause_after_step',
					],
				],
			],
			'tooltip'    => '<h6>Pause Only On These Outcomes</h6>Leave these unchecked to pause however the
				step ends. Check one or more to pause only on those outcomes — for example only on Failed,
				so a successful run carries on through the workflow. The step also keeps its real status
				when the pause is limited this way, so the outcome stays visible on the entry.
				<br /><br />The list reflects the step type as it was last saved.',
		];

		return $settings;
	},
	10,
	2
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
		if ( empty( $pause ) ) {
			return;
		}

		// Collect the outcomes the pause has been limited to, if any.
		$selected_statuses = [];
		if ( method_exists( $step, 'get_status_config' ) ) {
			foreach ( (array) $step->get_status_config() as $config ) {
				$step_status = (string) rgar( $config, 'status' );
				if ( '' !== $step_status && $step->get_setting( 'pause_after_step_status_' . $step_status ) ) {
					$selected_statuses[] = $step_status;
				}
			}
		}

		// With outcomes selected, only those pause the workflow. Selecting none pauses on any.
		if ( ! empty( $selected_statuses ) && ! in_array( $status, $selected_statuses, true ) ) {
			return;
		}

		// Update workflow status to 'paused'
		gform_update_meta( $entry_id, 'workflow_final_status', 'paused' );

		// Update workflow current status to 'paused' so process_workflow doesn't set it to 'complete'
		gform_update_meta( $entry_id, 'workflow_current_status', 'paused' );

		// Add timeline note
		if ( function_exists( 'gravity_flow' ) ) {
			gravity_flow()->add_timeline_note( $entry_id, 'Workflow paused on this step.' );
		}

		// Set step status to 'pending' so it doesn't show as complete. Skipped when the pause is
		// limited to particular outcomes, because the outcome that caused the pause — a failure,
		// typically — is the thing worth seeing on the entry, and the pause itself is carried by
		// workflow_final_status rather than by the step status.
		if ( empty( $selected_statuses ) ) {
			$step->update_step_status( 'pending' );
		}
	},
	10,
	5
);
