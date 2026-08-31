<?php
/**
 * Revert an Approval Step to Any Step
 *
 * @gravityflow
 *
 * GOAL:
 * Gravity Flow's Approval step can show a third "Revert" button beside Approve and Reject, but
 * the step it reverts to can only be a User Input step, and the setting is hidden altogether in
 * a workflow that has no User Input step. Nothing in the engine requires that: the approval
 * step's `process_revert_status()` ends the current step and starts whichever step ID was saved,
 * whatever type that step is. This snippet widens the setting so Revert can target **any** step
 * in the workflow, and adds the setting to workflows that have no User Input step at all.
 *
 * FEATURES:
 * Applies to Approval steps only.
 * - "Revert to User Input step" becomes "Revert to step", and the select lists every step in
 *   the workflow except the Approval step itself.
 * - When Gravity Flow left the setting out because the workflow has no User Input step, the
 *   snippet adds it back along with the pieces Gravity Flow only creates alongside its own
 *   revert setting: the two "Required if reverted" Workflow Note options, the Revert Email tab,
 *   and the "Reverted Confirmation" message.
 *
 * NOTES:
 * - Reverting re-runs every step between the target and this one. That is already how reverting
 *   to an earlier User Input step behaves, since each step is restarted as the workflow walks
 *   forward again; no step statuses are left stale.
 * - Gravity Flow only suppresses the target step's own assignee email when the target is a User
 *   Input step. Revert to any other type and the assignee email for that step is sent as usual,
 *   alongside the Revert Email if you have enabled it.
 * - Steps whose conditions are not met are still listed. Gravity Flow evaluates the condition
 *   when the step starts, so the entry will move straight through such a step after reverting
 *   to it.
 * - Gravity Flow's own confirmation message and timeline note name the target by its step
 *   *type* ("Reverted to step: Approval"), not by the step name. That was always the case, but
 *   it reads oddly once the target can be any type; set a custom "Reverted Confirmation"
 *   message on the step if the default wording matters to you.
 * - Pairs with the "Add Fields on Gravity Flow Approve Step to Customize Approve and Reject
 *   Buttons" snippet, which renames the Revert button the assignee sees.
 */

add_filter(
	'gravityflow_step_settings_fields',
	static function ( $settings, $current_step_id ) {
		if ( ! is_array( $settings ) || ! function_exists( 'gravity_flow' ) ) {
			return $settings;
		}

		/**
		 * Returns the index of the first field with the given name, or false.
		 * array_column() drops entries that have no 'name' key, which shifts the indexes it
		 * returns out of step with the original array, so walk the list instead.
		 *
		 * @param array  $fields Settings fields.
		 * @param string $name   Field name to find.
		 *
		 * @return int|string|false
		 */
		$index_of = static function ( $fields, $name ) {
			foreach ( $fields as $index => $field ) {
				if ( isset( $field['name'] ) && $name === $field['name'] ) {
					return $index;
				}
			}

			return false;
		};

		// Locate the section holding the Approval step's own settings. approved_message belongs to
		// no other step type, so it identifies these settings without depending on a translated
		// section title, and it is present for a step that has not been saved yet.
		$section_index = false;
		foreach ( $settings as $index => $section ) {
			if ( empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
				continue;
			}
			if ( false !== $index_of( $section['fields'], 'approved_message' ) ) {
				$section_index = $index;
				break;
			}
		}

		if ( false === $section_index ) {
			return $settings;
		}

		$fields  = $settings[ $section_index ]['fields'];
		$step_id = absint( $current_step_id );
		$form_id = absint( rgget( 'id' ) );

		if ( ! $form_id ) {
			return $settings;
		}

		// Every step in the workflow except this one; reverting to itself would loop.
		$choices = [];
		foreach ( gravity_flow()->get_steps( $form_id ) as $step ) {
			if ( (int) $step->get_id() === $step_id ) {
				continue;
			}

			$choices[] = [
				'label' => $step->get_name(),
				'value' => $step->get_id(),
			];
		}

		if ( empty( $choices ) ) {
			return $settings;
		}

		$label   = 'Revert to step';
		$tooltip = 'The Revert setting enables a third option in addition to Approve and Reject which allows the assignee to send the entry directly to another step without changing the status. Enable this setting to show the Revert button next to the Approve and Reject buttons and specify the step the entry will be sent to.';

		$revert_index = $index_of( $fields, 'revert' );

		// Gravity Flow rendered the setting because the workflow has a User Input step: widen the
		// choices and take "User Input" out of the copy.
		if ( false !== $revert_index ) {
			$fields[ $revert_index ]['label']             = $label;
			$fields[ $revert_index ]['tooltip']           = $tooltip;
			$fields[ $revert_index ]['select']['choices'] = $choices;

			$settings[ $section_index ]['fields'] = $fields;

			return $settings;
		}

		// No User Input step in the workflow, so Gravity Flow omitted the revert setting and
		// everything it hangs off. Add them back, in the order Gravity Flow itself uses.
		$revert_field = [
			'name'     => 'revert',
			'label'    => $label,
			'type'     => 'checkbox_and_select',
			'tooltip'  => $tooltip,
			'checkbox' => [
				'label'         => 'Enable',
				'default_value' => '0',
			],
			'select'   => [
				'choices' => $choices,
			],
		];

		// Gravity Flow appends the revert setting immediately before the Emails and Workflow Note
		// settings.
		$insert_at = $index_of( $fields, 'notification_tabs' );
		if ( false === $insert_at ) {
			$insert_at = $index_of( $fields, 'note_mode' );
		}
		if ( false === $insert_at ) {
			$insert_at = count( $fields );
		}

		array_splice( $fields, $insert_at, 0, [ $revert_field ] );

		// The two Workflow Note options that only make sense once a Revert button exists.
		$note_mode_index = $index_of( $fields, 'note_mode' );
		if ( false !== $note_mode_index && ! empty( $fields[ $note_mode_index ]['choices'] ) ) {
			$note_choices = $fields[ $note_mode_index ]['choices'];
			$note_values  = array_column( $note_choices, 'value' );

			if ( ! in_array( 'required_if_reverted', $note_values, true ) ) {
				$note_choices[] = [
					'value' => 'required_if_reverted',
					'label' => 'Required if reverted',
				];
			}
			if ( ! in_array( 'required_if_reverted_or_rejected', $note_values, true ) ) {
				$note_choices[] = [
					'value' => 'required_if_reverted_or_rejected',
					'label' => 'Required if reverted or rejected',
				];
			}

			$fields[ $note_mode_index ]['choices'] = $note_choices;
		}

		// The Revert Email tab, built with the same settings API Gravity Flow uses for it.
		$tabs_index = $index_of( $fields, 'notification_tabs' );
		if ( false !== $tabs_index && isset( $fields[ $tabs_index ]['tabs'] ) && is_array( $fields[ $tabs_index ]['tabs'] ) && class_exists( 'Gravity_Flow_Common_Step_Settings' ) ) {
			$has_revert_tab = false;
			foreach ( $fields[ $tabs_index ]['tabs'] as $tab ) {
				if ( isset( $tab['id'] ) && 'tab_revert_notification' === $tab['id'] ) {
					$has_revert_tab = true;
					break;
				}
			}

			if ( ! $has_revert_tab ) {
				$settings_api = new Gravity_Flow_Common_Step_Settings();

				$fields[ $tabs_index ]['tabs'][] = [
					'label'  => 'Revert Email',
					'id'     => 'tab_revert_notification',
					'fields' => $settings_api->get_setting_notification(
						[
							'name_prefix'      => 'revert',
							'checkbox_label'   => 'Send email when the entry is reverted',
							'checkbox_tooltip' => 'Enable this setting to send an email when the entry is reverted. The assignee email for the step reverted to is only suppressed when that step is a User Input step.',
							'default_message'  => 'Entry {entry_id} has been reverted',
							'send_to_fields'   => true,
							'resend_field'     => false,
						]
					),
				];
			}
		}

		// The Reverted Confirmation message, which Gravity Flow places after Approval Confirmation.
		if ( false === $index_of( $fields, 'reverted_message' ) ) {
			$approved_index = $index_of( $fields, 'approved_message' );
			$insert_at      = false === $approved_index ? count( $fields ) : $approved_index + 1;

			array_splice(
				$fields,
				$insert_at,
				0,
				[
					[
						'name'     => 'reverted_message',
						'label'    => 'Reverted Confirmation',
						'type'     => 'checkbox_and_textarea',
						'tooltip'  => 'Enable this setting to customize the confirmation message displayed when an assignee reverts the entry on this step.',
						'checkbox' => [
							'label' => 'Display a custom confirmation message when an assignee reverts',
							'value' => '0',
						],
						'textarea' => [
							'use_editor'    => true,
							'default_value' => 'Entry Reverted',
							'class'         => 'merge-tag-support mt-hide_all_fields mt-position-right',
						],
					],
				]
			);
		}

		$settings[ $section_index ]['fields'] = $fields;

		return $settings;
	},
	10,
	2
);
