<?php
/**
 * Add Fields on Gravity Flow Approve Step to Customize Approve and Reject Buttons
 *
 * @gravityflow
 *
 * GOAL:
 * **New****:** The snippet now also supports custom labels for the revert button on an Approval
 * step, as well as Custom Submit Form label, Custom Update label, and Custom Save Progress label
 * for the User Input step. This code snippet enhances Gravity Flow by allowing administrators to
 * customize the text of the "Approve" and "Reject" buttons in an Approval step. Instead of the
 * default labels, users can specify their own button text for each step, improving clarity and
 * workflow customization.
 *
 * FEATURES:
 * - Adds a **"Custom Approval Button Text"** field to Approval step settings.
 * - Adds a **"Custom Rejection Button Text"** field to Approval step settings.
 * - Dynamically updates the button labels based on the custom text entered.
 * - Enhances workflow clarity by allowing tailored approval and rejection options.
 */

add_filter(
	'gravityflow_step_settings_fields',
	function ( $settings, $step ) {

		// Normalize to a step object and determine type in a locale-safe way.
		$flow                = null;
		$type                = null;
		$has_user_input_step = false;

		if ( function_exists( 'gravity_flow' ) ) {
			$flow = gravity_flow();
		}

		if ( $flow && is_object( $flow ) && method_exists( $flow, 'get_step' ) ) {
			if ( ! ( is_object( $step ) && method_exists( $step, 'get_type' ) ) ) {
				$step = $flow->get_step( $step );
			}
			if ( is_object( $step ) && method_exists( $step, 'get_type' ) ) {
				$type = $step->get_type();

				if ( method_exists( $step, 'get_form_id' ) && method_exists( $flow, 'get_steps' ) ) {
					$form_id = $step->get_form_id();
					$steps   = $flow->get_steps( $form_id );
					if ( is_array( $steps ) ) {
						foreach ( $steps as $_s ) {
							if ( is_object( $_s ) && method_exists( $_s, 'get_type' ) && $_s->get_type() === 'user_input' ) {
								$has_user_input_step = true;
								break;
							}
						}
					}
				}
			}
		}

		// Fallback to English titles only if type could not be determined (keeps previous behavior where possible).
		$is_approval   = ( 'approval' === $type );
		$is_user_input = ( 'user_input' === $type );
		if ( ! $is_approval && ! $is_user_input ) {
			$title = rgars( $settings, '1/title' );
			if ( 'Approval' === $title ) {
				$is_approval = true;
			} elseif ( 'User Input' === $title ) {
				$is_user_input = true;
			}
		}

		// Locate the section where the 'expiration' field lives; fallback to the first section with fields.
		$target_section_index = null;
		if ( is_array( $settings ) ) {
			foreach ( $settings as $i => $section ) {
				if ( ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
					continue;
				}
				$names = array_column( $section['fields'], 'name' );
				if ( in_array( 'expiration', $names, true ) ) {
					$target_section_index = $i;
					break;
				}
				if ( is_null( $target_section_index ) ) {
					$target_section_index = $i; // first section with fields as a reasonable fallback
				}
			}
		}

		if ( is_null( $target_section_index ) ) {
			return $settings;
		}

		$fields = isset( $settings[ $target_section_index ]['fields'] ) && is_array( $settings[ $target_section_index ]['fields'] ) ? $settings[ $target_section_index ]['fields'] : [];
		$names  = array_column( $fields, 'name' );
		$anchor = array_search( 'expiration', $names, true );
		if ( false === $anchor ) {
			$anchor = count( $fields );
		}
		$insert_at = $anchor + 1;

		// Approval step: add fields for custom Approve/Reject button labels and conditionally Revert.
		if ( $is_approval ) {
			$approval_text = [
				'name'    => 'custom_approval_text',
				'label'   => 'Custom Approval Button Text',
				'type'    => 'text',
				'class'   => 'merge-tag-support mt-position-right',
				'tooltip' => 'Enter text that you would like to display instead of "Approve" for this step.',
			];
			array_splice( $fields, $insert_at, 0, [ $approval_text ] );
			$insert_at++;

			$rejection_text = [
				'name'    => 'custom_rejection_text',
				'label'   => 'Custom Rejection Button Text',
				'type'    => 'text',
				'class'   => 'merge-tag-support mt-position-right',
				'tooltip' => 'Enter text that you would like to display instead of "Reject" for this step.',
			];
			array_splice( $fields, $insert_at, 0, [ $rejection_text ] );

			// Only show the Revert label field on Approval steps when the workflow has a User Input step,
			// and then only while Gravity Flow's own "Revert to User Input step" setting is enabled.
			if ( $has_user_input_step ) {
				$insert_at++;
				$revert_text = [
					'name'       => 'custom_revert_text',
					'label'      => 'Custom Revert Button Text',
					'type'       => 'text',
					'class'      => 'merge-tag-support mt-position-right',
					'tooltip'    => 'Enter text that you would like to display instead of "Revert" when sending back to a User Input step.',
					'dependency' => [
						'live'   => true,
						'fields' => [
							[
								// Checkbox input of Gravity Flow's 'revert' checkbox_and_select setting.
								'field'  => 'revertEnable',
								'values' => [ '1' ],
							],
						],
					],
				];
				array_splice( $fields, $insert_at, 0, [ $revert_text ] );
			}

			$settings[ $target_section_index ]['fields'] = $fields;
		}

		// User Input step: add fields for the button labels this step actually renders.
		//
		// Which buttons exist is decided by Gravity Flow's "Save Progress" setting (default_status).
		// "submit_buttons" renders a "Save" button and a "Submit" button; "hidden" renders a single
		// button labelled "Submit"; the two radio button options render a single "Update" button.
		// Each field below is shown only for the settings that render its button, so a step never
		// offers a label for a button it does not have.
		if ( $is_user_input ) {
			$save_progress_text = [
				'name'       => 'custom_user_input_save_text',
				'label'      => 'Custom Save Button Text',
				'type'       => 'text',
				'class'      => 'merge-tag-support mt-position-right',
				'tooltip'    => 'Enter text to display instead of "Save" on the button that saves progress without completing this step.',
				'dependency' => [
					'live'   => true,
					'fields' => [
						[
							'field'  => 'default_status',
							'values' => [ 'submit_buttons' ],
						],
					],
				],
			];
			array_splice( $fields, $insert_at, 0, [ $save_progress_text ] );
			$insert_at++;

			$submit_text = [
				'name'       => 'custom_user_input_submit_text',
				'label'      => 'Custom Submit Button Text',
				'type'       => 'text',
				'class'      => 'merge-tag-support mt-position-right',
				'tooltip'    => 'Enter text that you would like to display instead of "Submit" on the button that completes this step.',
				'dependency' => [
					'live'   => true,
					'fields' => [
						[
							'field'  => 'default_status',
							'values' => [ 'submit_buttons', 'hidden' ],
						],
					],
				],
			];
			array_splice( $fields, $insert_at, 0, [ $submit_text ] );
			$insert_at++;

			$update_text = [
				'name'       => 'custom_user_input_update_text',
				'label'      => 'Custom Update Button Text',
				'type'       => 'text',
				'class'      => 'merge-tag-support mt-position-right',
				'tooltip'    => 'Enter text that you would like to display instead of "Update" for this User Input step. The status radio buttons decide whether the entry is saved as in progress or complete.',
				'dependency' => [
					'live'   => true,
					'fields' => [
						[
							'field'  => 'default_status',
							'values' => [ 'in_progress', 'complete' ],
						],
					],
				],
			];
			array_splice( $fields, $insert_at, 0, [ $update_text ] );

			$settings[ $target_section_index ]['fields'] = $fields;
		}

		return $settings;
	},
	10,
	2
);

add_filter(
	'gravityflow_approve_label_workflow_detail',
	function ( $approve_label, $step ) {
		return empty( $step->__get( 'custom_approval_text' ) ) ? $approve_label : $step->__get( 'custom_approval_text' );
	},
	10,
	2
);

add_filter(
	'gravityflow_reject_label_workflow_detail',
	function ( $reject_label, $step ) {
		return empty( $step->__get( 'custom_rejection_text' ) ) ? $reject_label : $step->__get( 'custom_rejection_text' );
	},
	10,
	2
);

add_filter(
	'gravityflow_revert_label_workflow_detail',
	function ( $revert_label, $step ) {
		return empty( $step->__get( 'custom_revert_text' ) ) ? $revert_label : $step->__get( 'custom_revert_text' );
	},
	10,
	2
);

// User Input labels: override Submit/Update button texts on workflow detail based on custom step setting.
add_filter(
	'gravityflow_submit_button_text_user_input',
	function ( $label, $form, $step ) {
		$custom = is_object( $step ) && method_exists( $step, '__get' ) ? $step->__get( 'custom_user_input_submit_text' ) : '';
		return empty( $custom ) ? $label : $custom;
	},
	10,
	3
);

// This filter covers the single button rendered when Save Progress is not set to "Submit buttons".
// Gravity Flow labels that button "Submit" when Save Progress is disabled and "Update" otherwise, so
// read whichever setting matches the button the assignee actually sees.
add_filter(
	'gravityflow_update_button_text_user_input',
	function ( $label, $form, $step ) {
		if ( ! is_object( $step ) || ! method_exists( $step, '__get' ) ) {
			return $label;
		}

		if ( 'hidden' === $step->__get( 'default_status' ) ) {
			$custom = $step->__get( 'custom_user_input_submit_text' );
			// Steps configured before this button had its own setting stored the label as the update text.
			if ( empty( $custom ) ) {
				$custom = $step->__get( 'custom_user_input_update_text' );
			}
		} else {
			$custom = $step->__get( 'custom_user_input_update_text' );
		}

		return empty( $custom ) ? $label : $custom;
	},
	10,
	3
);

add_filter(
	'gravityflow_save_progress_button_text_user_input',
	function ( $label, $form, $step ) {
		$custom = is_object( $step ) && method_exists( $step, '__get' ) ? $step->__get( 'custom_user_input_save_text' ) : '';
		return empty( $custom ) ? $label : $custom;
	},
	10,
	3
);
