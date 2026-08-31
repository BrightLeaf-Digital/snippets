<?php
/* phpcs:disable WordPress.Files.FileName */
/**
 * Update Entry and Create Entry Workflow Steps can Save a field value as entry note
 *
 * @gravityflow
 *
 * GOAL:
 * This snippet enhances the **Update Entry** and **Create Entry** workflow steps in **Gravity
 * Flow** by allowing a field value to be automatically saved as an **entry note**. The note is
 * recorded when a new entry is created or an existing entry is updated via workflow automation.
 *
 * FEATURES:
 * ✔ **Save Field Value as Entry Note** – Select a field whose value should be recorded as a
 * note when the workflow step runs. ✔ **Works with “Create Entry” and “Update Entry”
 * Steps** – Supports workflows that create new entries or update existing ones. ✔ **Merge Tag
 * Support** – Dynamically replaces variables within the selected field to generate meaningful
 * notes. ✔ **User Identification** – Captures the user ID and display name when saving the
 * entry note. ✔ **Seamless Automation** – Automatically applies to workflow steps without
 * requiring additional manual intervention. This snippet is useful for keeping a record of form
 * updates or tracking important changes directly within the **Gravity Forms** entry notes.
 */

( static function () {
	if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GFCommon' ) ) {
		return;
	}

	// Insert setting field near 'destination_complete' if present; otherwise append.
	add_filter(
		'gravityflow_step_settings_fields',
		static function ( $settings ) {
			$title = rgars( $settings, '1/title' );
			if ( 'New Entry' !== $title && 'Update an Entry' !== $title ) {
				return $settings;
			}
			$fields = $settings[1]['fields'] ?? [];
			$idx    = -1;
			$len    = is_array( $fields ) ? count( $fields ) : 0;
			for ( $i = 0; $i < $len; $i++ ) {
				if ( isset( $fields[ $i ]['name'] ) && 'destination_complete' === $fields[ $i ]['name'] ) {
					$idx = $i;
					break;
				}
			}
			$field_select = [
				'name'  => 'field_for_entry_note',
				'label' => 'Select a field to be saved as the entry note',
				'type'  => 'text',
				'class' => 'merge-tag-support mt-position-right',
			];
			if ( -1 !== $idx ) {
				array_splice( $settings[1]['fields'], $idx, 0, [ $field_select ] );
			} else {
				$settings[1]['fields'][] = $field_select;
			}
			return $settings;
		},
		10,
		2
	);

	$add_note = static function ( $entry, $step, $note_entry_id ) {
		$user_id = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
		$name    = ( $user && isset( $user->display_name ) ) ? $user->display_name : 'System';

		$note_text = (string) $step->__get( 'field_for_entry_note' );
		$form      = GFAPI::get_form( isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0 );
		$note      = GFCommon::replace_variables( $note_text, $form, $entry );
		$note      = is_string( $note ) ? trim( $note ) : '';
		if ( '' === $note ) {
			return;
		}
		GFAPI::add_note( (int) $note_entry_id, $user_id, $name, $note );
	};

	// After Create Entry step runs.
	add_action(
		'gravityflowformconnector_post_new_entry',
		static function ( $new_entry_id, $entry, $form, $step ) use ( $add_note ) {
			$add_note( $entry, $step, (int) $new_entry_id );
		},
		10,
		4
	);

	// On Update Entry step start, add note to the resolved entry ID field.
	add_action(
		'gravityflow_step_start',
		static function ( $step_id, $entry_id, $form_id, $status, $step ) use ( $add_note ) {
			if ( method_exists( $step, 'get_type' ) && 'update_entry' === $step->get_type() ) {
				$entry = GFAPI::get_entry( (int) $entry_id );
				if ( is_wp_error( $entry ) ) {
					return;
				}
				$field_id = (int) $step->__get( 'update_entry_id' );
				$target   = isset( $entry[ $field_id ] ) ? (int) $entry[ $field_id ] : 0;
				if ( 0 !== $target ) {
					$add_note( $entry, $step, $target );
				}
			}
		},
		10,
		5
	);
} )();
