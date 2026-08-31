<?php
/**
 * (Re)Start workflow on entry update
 *
 * @gravityflow
 * @gravityforms
 *
 * GOAL:
 * This snippet adds a checkbox to your workflow steps. When checked the snippet will send the
 * entry, when it is updated, to the workflow step. If the step has conditional logic it will check
 * the logic and do nothing if it doesn't pass. Only one step per workflow can have this setting
 * checked at a time. If the entry is on a step already this does nothing. Useful for situations
 * where your entry does not meet the conditions for your workflow at submission time but will be
 * updated later (manually or automatically) and you want to run it when it now meets the
 * condition. Note that this will run the workflow every time the entry is updated and it meets the
 * conditions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "send to this step on update" checkbox to a workflow step's settings.
 *
 * Because only one step per form may own this setting, the checkbox is hidden on every other
 * step once one step already has it enabled. It still renders on the step that owns it (so it can
 * be turned off) and on all steps while none owns it yet.
 *
 * @param array $settings        The step settings fields.
 * @param int   $current_step_id The step being edited (0 for a new, unsaved step).
 *
 * @return array
 */
add_filter(
	'gravityflow_step_settings_fields',
	function ( $settings, $current_step_id = 0 ) {
		// If another step on this form already owns the setting, don't show the checkbox here.
		if ( function_exists( 'gravity_flow' ) ) {
			$form_id = absint( rgget( 'id' ) );
			if ( $form_id ) {
				foreach ( gravity_flow()->get_feeds( $form_id ) as $feed ) {
					if ( (int) $feed['id'] === (int) $current_step_id ) {
						continue;
					}

					if ( ! empty( rgar( $feed['meta'], 'send_here_on_entry_update' ) ) ) {
						return $settings;
					}
				}
			}
		}

		$field = [
			'name'    => 'send_here_on_entry_update',
			'label'   => 'Send to this step when the entry is updated',
			'type'    => 'checkbox',
			'choices' => [
				[
					'label' => 'When an entry is updated and now meets this step\'s conditions, (re-)start the workflow at this step',
					'name'  => 'send_here_on_entry_update',
				],
			],
			'tooltip' => '<h6>(Re-)start on update</h6>If checked, editing this entry (admin, GravityView, or programmatically) sends the workflow to this step - but only when the workflow is not already active on another step and this step\'s conditional logic is now met. Only one step per form can use this, so it is hidden on the other steps while one step already has it enabled.',
		];

		if ( isset( $settings[0]['fields'] ) && is_array( $settings[0]['fields'] ) ) {
			$settings[0]['fields'][] = $field;
		}

		return $settings;
	},
	10,
	2
);

/**
 * Safety net for the single-flagged-step-per-form rule. The checkbox is already hidden on other
 * steps once one owns the setting, so a conflict can't normally be created through the UI - but if
 * one arises another way (e.g. feed import or direct DB edit), saving a step with the setting
 * enabled clears the flag from every other step on the same form.
 */
add_action(
	'gform_post_save_feed_settings',
	function ( $feed_id, $form_id, $settings, $addon ) {
		if ( ! is_object( $addon ) || ! method_exists( $addon, 'get_slug' ) || 'gravityflow' !== $addon->get_slug() ) {
			return;
		}

		if ( empty( rgar( $settings, 'send_here_on_entry_update' ) ) ) {
			return;
		}

		$feeds = $addon->get_feeds( $form_id );
		foreach ( $feeds as $feed ) {
			if ( (int) $feed['id'] === (int) $feed_id ) {
				continue;
			}

			if ( ! empty( rgar( $feed['meta'], 'send_here_on_entry_update' ) ) ) {
				$feed['meta']['send_here_on_entry_update'] = '0';
				$addon->update_feed_meta( $feed['id'], $feed['meta'] );
			}
		}
	},
	10,
	4
);

/**
 * (Re-)starts the workflow at the flagged step when an updated entry newly meets its conditions.
 *
 * @param int $entry_id The ID of the updated entry.
 *
 * @return void
 */
function bld_restart_workflow_on_entry_update( $entry_id ) {
	// Guard against re-entrancy: send_to_step()/process_workflow() may touch the entry again.
	static $in_progress = [];

	$entry_id = (int) $entry_id;
	if ( ! $entry_id || isset( $in_progress[ $entry_id ] ) ) {
		return;
	}

	if ( ! function_exists( 'gravity_flow' ) || ! class_exists( 'Gravity_Flow_API' ) ) {
		return;
	}

	$entry = GFAPI::get_entry( $entry_id );
	if ( is_wp_error( $entry ) || empty( $entry ) ) {
		return;
	}

	// Do not disturb a workflow that is currently active on a step ("if not on any other step").
	if ( 'pending' === rgar( $entry, 'workflow_final_status' ) ) {
		return;
	}

	$form_id = (int) $entry['form_id'];
	$form    = GFAPI::get_form( $form_id );
	if ( ! $form ) {
		return;
	}

	$steps = gravity_flow()->get_steps( $form_id, $entry );
	if ( empty( $steps ) ) {
		return;
	}

	foreach ( $steps as $step ) {
		if ( empty( $step->get_setting( 'send_here_on_entry_update' ) ) ) {
			continue;
		}

		// "What changed" filtering (v1): only move the entry if the step's conditions are now met.
		$should_send = $step->is_condition_met( $form );

		/**
		 * Filters whether an updated entry should be (re-)started at the flagged step.
		 *
		 * @param bool              $should_send Whether the workflow should be sent to the step.
		 * @param Gravity_Flow_Step $step        The flagged step.
		 * @param array             $entry       The current entry.
		 * @param array             $form        The current form.
		 */
		$should_send = apply_filters( 'bld_restart_workflow_should_send', $should_send, $step, $entry, $form );

		if ( $should_send ) {
			$in_progress[ $entry_id ] = true;
			$api                      = new Gravity_Flow_API( $form_id );
			$api->send_to_step( $entry, $step->get_id() );
			unset( $in_progress[ $entry_id ] );
		}

		// Only one step can be flagged per form; stop after the first.
		break;
	}
}

/**
 * Admin entry-detail edits.
 */
add_action(
	'gform_after_update_entry',
	function ( $form, $entry_id ) {
		bld_restart_workflow_on_entry_update( $entry_id );
	},
	10,
	2
);

/**
 * GravityView Edit Entry and programmatic GFAPI::update_entry() calls.
 */
add_action(
	'gform_post_update_entry',
	function ( $entry ) {
		bld_restart_workflow_on_entry_update( rgar( $entry, 'id' ) );
	},
	10,
	1
);
