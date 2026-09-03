<?php
/* phpcs:disable WordPress.Files.FileName */
/**
 * Restart workflow on GV approval and subscription payment
 *
 * @gravityflow
 * @gravityview
 * @gravityforms
 *
 * GOAL:
 * This code snippet enhances **Gravity Flow** by allowing workflows to be delayed or restarted
 * based on **GravityView approval** and **subscription payments**. It provides options to:
 *
 * - **Delay workflow initiation** until an entry is approved in GravityView instead of starting
 * upon form submission.
 * - **Restart the workflow** upon each subscription payment for recurring payment scenarios.
 * - **Update a date field** when the workflow restarts to reflect the latest payment or approval.
 * - **Expose the restart date** as a `{workflow_restart_date}` merge tag that scheduled steps
 * can still read, since the date field itself is restored as soon as the request ends.
 * - **Manually restart workflows** from the entry detail sidebar in the admin panel.
 *
 * FEATURES:
 * - **Delay Workflow Until Approval** – Prevents workflows from starting immediately on form
 *   submission and instead begins only after **GravityView approval**.
 * - **Restart Workflow on Subscription Payment** – When a new payment is received, the workflow
 *   restarts automatically, making it useful for recurring payments.
 * - **Configurable Options in Gravity Flow Step Settings** – Adds checkboxes in **Workflow Start
 *   steps** to enable these behaviors selectively.
 * - **Auto-Update Date Field** – Updates a selected date field when the workflow restarts,
 *   ensuring accurate tracking.
 * - **Restart Date Merge Tag** – Records each restart's date in entry meta and exposes it as
 *   `{workflow_restart_date}`, so **delayed or scheduled steps** (which run in a later cron pass,
 *   after the date field has been restored) can still map the date the restart actually
 *   represents; falls back to the configured date field when no restart has happened.
 * - **Manual Workflow Restart from Admin Panel** – Adds buttons in the **entry detail sidebar**,
 *   allowing admins to manually restart workflows for **GravityView approval** or **subscription
 *   payments**, with an optional date so a catch-up run records the date it actually represents
 *   rather than the date it was clicked.
 * - **Ensures Workflow Integrity** – Automatically cancels the initial workflow if delayed until
 *   GravityView approval to prevent redundant execution.
 *
 * This snippet integrates seamlessly with **Gravity Flow, GravityView, and Gravity Forms
 * Subscription Payments**, enabling more **dynamic and automated workflow management**.
 */

	// to add checkboxes
add_filter(
	'gravityflow_step_settings_fields',
	static function ( $settings, $current_step_id ) {
		if ( ! function_exists( 'gravity_flow' ) || ! class_exists( 'GFCommon' ) ) {
			return $settings;
		}
		$step = gravity_flow()->get_step( $current_step_id );
		if ( $step && method_exists( $step, 'get_type' ) ) {
			$step_type = $step->get_type();
			if ( 'workflow_start' === $step_type ) {
				$settings[0]['fields'][] = [
					'label'   => 'Delay Workflow',
					'type'    => 'checkbox',
					'name'    => 'delay_workflow_checkbox',
					'choices' => [
						[
							'label' => 'Delay the workflow until Gravity View approval',
							'name'  => 'delay_workflow_checkbox',
						],
					],
				];
				$settings[0]['fields'][] = [
					'label'   => 'Restart Workflow',
					'type'    => 'checkbox',
					'name'    => 'restart_workflow_checkbox',
					'choices' => [
						[
							'label' => 'Restart the workflow on subscription payment',
							'name'  => 'restart_workflow_checkbox',
						],
					],
				];
				$form                    = gravity_flow()->get_current_form();
				$api                     = class_exists( 'Gravity_Flow_API' ) ? new Gravity_Flow_API( $form['id'] ) : null;
				$all_steps               = $api ? $api->get_steps() : [];
				if ( is_array( $all_steps ) && ! empty( $all_steps ) ) {
					array_shift( $all_steps );
				}
				if ( ! empty( $all_steps ) ) {
					$step_choices = [];
					foreach ( $all_steps as $workflow_step ) {
						$step_choices[] = [
							'label' => $workflow_step->get_name(),
							'value' => $workflow_step->get_id(),
						];
					}
					$settings[0]['fields'][] = [
						'name'    => 'restart_target_step',
						'label'   => 'Send to Step',
						'type'    => 'select',
						'choices' => $step_choices,
						'tooltip' => 'Select which step to send the entry to when workflow restarts. Defaults to first step.',
					];
				}
				$form        = gravity_flow()->get_current_form();
				$date_fields = GFCommon::get_fields_by_type( $form, [ 'date' ] );
				if ( ! empty( $date_fields ) ) {
					$date_field_choices = [];
					foreach ( $date_fields as $date_field ) {
						$date_field_choices[] = [
							'label' => $date_field['label'],
							'value' => $date_field['id'],
						];
					}
					array_unshift(
                        $date_field_choices,
                        [
							'label' => 'Select a date field',
							'value' => '',
						]
                        );
					$settings[0]['fields'][] = [
						'name'    => 'update_date_field',
						'label'   => 'Update Date Field',
						'type'    => 'select',
						'choices' => $date_field_choices,
						'fields'  => [
							[
								'name'    => 'also_for_gv',
								'label'   => 'Also update the date field on Gravity View Approval',
								'type'    => 'checkbox',
								'choices' => [
									[
										'label' => '',
										'name'  => 'also_for_gv',
									],
								],
							],
						],
					];
				}
			}
		}
		return $settings;
	},
	10,
	2
);


add_action(
	'gravityflow_step_start',
	static function ( $step_id, $entry_id, $form_id, $step_status, $step ) {
		$delay_view_approval = $step->__get( 'delay_workflow_checkbox' );
		if ( $delay_view_approval ) {
			$entry = GFAPI::get_entry( (int) $entry_id );
			if ( is_wp_error( $entry ) ) {
				return;
			}
			if ( ! class_exists( 'Gravity_Flow_API' ) || ! function_exists( 'gravity_flow' ) ) {
				return;
			}
			$api    = new Gravity_Flow_API( (int) $form_id );
			$status = $api->cancel_workflow( $entry );
			if ( $status ) {
				gravity_flow()->add_timeline_note( (int) $entry_id, 'Initial workflow cancelled. Set to start on View approval.' );
			}
		}
	},
	10,
	5
);

if ( ! function_exists( 'bld_workflow_restart_record_date' ) ) {
	/**
	 * Records the date a restart represents, in entry meta, so later steps can read it.
	 *
	 * WHY THIS EXISTS
	 * "Update Date Field" writes today into the date field and restores the original
	 * on gravityflow_post_process_workflow — which fires as soon as workflow processing
	 * ends, in the same request. A step that runs immediately therefore sees the new
	 * date, but a SCHEDULED step (any delay at all, even a minute) runs in a later cron
	 * pass, long after the restore, and reads the original date. Downstream steps that
	 * map the date field — a Form Connector step writing a ledger row, say — silently
	 * record the wrong date for every restart.
	 *
	 * Rather than hold a donor-facing field hostage across a cron boundary, the date is
	 * recorded here as entry meta and exposed as the {workflow_restart_date} merge tag,
	 * which resolves correctly whenever the step actually runs. Map that in scheduled
	 * steps. The date-field behaviour above is untouched, so existing setups are
	 * unaffected.
	 *
	 * The date is the payment's own date when the payment action supplies one
	 * ($action['payment_date'] — optional, not part of Gravity Forms' action shape, but
	 * an add-on may include it), otherwise today. A webhook-driven add-on can be several
	 * days behind the charge it reports, and the ledger should carry the date the money
	 * moved, not the date we heard about it. Filter 'bld_workflow_restart_date' for full
	 * control.
	 *
	 * @param array       $entry      The entry being restarted.
	 * @param array|null  $action     The payment action, when a payment triggered this.
	 * @param object|null $start_step The workflow start step.
	 *
	 * @return string The recorded date, Y-m-d.
	 */
	function bld_workflow_restart_record_date( $entry, $action = null, $start_step = null ) {
		$date = '';

		if ( is_array( $action ) && ! empty( $action['payment_date'] ) ) {
			$timestamp = strtotime( (string) $action['payment_date'] );
			if ( $timestamp ) {
				$date = gmdate( 'Y-m-d', $timestamp );
			}
		}

		if ( '' === $date ) {
			$date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', current_time( 'timestamp' ) ) : date( 'Y-m-d' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.DateTime.CurrentTimeTimestamp.Requested
		}

		/**
		 * Filters the date a workflow restart represents.
		 *
		 * @param string      $date       Y-m-d.
		 * @param array       $entry      The entry being restarted.
		 * @param array|null  $action     The payment action, when a payment triggered this.
		 * @param object|null $start_step The workflow start step.
		 */
		$date = apply_filters( 'bld_workflow_restart_date', $date, $entry, $action, $start_step );

		if ( is_string( $date ) && '' !== $date && ! empty( $entry['id'] ) && function_exists( 'gform_update_meta' ) ) {
			gform_update_meta( (int) $entry['id'], 'bld_workflow_restart_date', $date );
		}

		return $date;
	}
}

// {workflow_restart_date} — the date of the most recent restart, for mapping in steps
// that run later than the request the restart happened in.
add_filter(
	'gform_replace_merge_tags',
	static function ( $text, $form, $entry ) {
		// GravityView and Populate Anything hydration hand this filter an array rather
		// than a string; running a string function on it fatals the whole page.
		if ( ! is_string( $text ) || false === strpos( $text, '{workflow_restart_date}' ) ) {
			return $text;
		}
		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			return str_replace( '{workflow_restart_date}', '', $text );
		}

		$date = function_exists( 'gform_get_meta' ) ? gform_get_meta( (int) $entry['id'], 'bld_workflow_restart_date' ) : '';

		// No restart has happened yet: fall back to the configured date field, so a step
		// mapping this tag behaves exactly as one mapping that field used to.
		if ( ! $date && function_exists( 'gravity_flow' ) ) {
			$start_step = gravity_flow()->get_workflow_start_step( isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0, $entry );
			$field_id   = $start_step ? $start_step->__get( 'update_date_field' ) : '';
			if ( $field_id && isset( $entry[ $field_id ] ) ) {
				$date = $entry[ $field_id ];
			}
		}

		if ( ! $date && ! empty( $entry['date_created'] ) ) {
			$date = gmdate( 'Y-m-d', strtotime( (string) $entry['date_created'] ) );
		}

		return str_replace( '{workflow_restart_date}', (string) $date, $text );
	},
	10,
	3
);

// to start workflow on approval
add_action(
	'gravityview/approve_entries/approved',
	static function ( $entry_id ) {
		if ( ! function_exists( 'gravity_flow' ) || ! class_exists( 'GFAPI' ) || ! class_exists( 'Gravity_Flow_API' ) ) {
			return;
		}
		$entry = GFAPI::get_entry( (int) $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry ) ) {
			return;
		}
		$form_id    = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
		$gwf        = gravity_flow();
		$start_step = $gwf->get_workflow_start_step( $form_id, $entry );
		if ( ! $start_step ) {
			return;
		}
		$is_checked = $start_step->__get( 'delay_workflow_checkbox' );
		if ( ! $is_checked ) {
			return;
		}
		$api = new Gravity_Flow_API( $form_id );

		// Same rule as the subscription-payment path: an approval must not yank a
		// workflow that is already live on a step. With this feature the workflow is
		// normally cancelled at start, so a current step here means something else
		// already started it and approving should leave it alone.
		if ( false !== $api->get_current_step( $entry ) ) {
			return;
		}

		$target_step_id = $start_step->__get( 'restart_target_step' );
		$target_step    = null;
		if ( $target_step_id ) {
			$target_step = $gwf->get_step( $target_step_id );
		}
		if ( ! $target_step ) {
			$steps = $api->get_steps();
			if ( is_array( $steps ) && ! empty( $steps ) ) {
				array_shift( $steps );
			}
			$target_step = $steps[0] ?? null;
		}
		if ( ! $target_step ) {
			GFAPI::add_note( (int) $entry_id, 0, 'bld-restart-workflow', 'Step not found, unable to restart workflow.' );
			return;
		}

		// Only bump the date field once the restart is certain — see the matching
		// comment in the subscription-payment handler.
		$field_id = $start_step->__get( 'update_date_field' );
		if ( $start_step->__get( 'also_for_gv' ) && $field_id && isset( $entry[ $field_id ] ) ) {
			bld_workflow_restart_record_date( $entry, null, $start_step );
			$original_date = $entry[ $field_id ];
			$today         = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', current_time( 'timestamp' ) ) : date( 'Y-m-d' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.DateTime.CurrentTimeTimestamp.Requested
			GFAPI::update_entry_field( $entry['id'], $field_id, $today );
			add_action(
				'gravityflow_post_process_workflow',
				static function () use ( $entry, $field_id, $original_date ) {
					GFAPI::update_entry_field( $entry['id'], $field_id, $original_date );
				}
			);
		}

		$gwf->add_timeline_note( $entry['id'], 'Workflow started because of Gravity View Approval.' );
		$api->send_to_step( $entry, $target_step->get_id() );
		GFAPI::send_notifications( GFAPI::get_form( $form_id ), $entry, 'bld_restart_workflow' );
	},
	10,
	1
);

// to restart workflow on subscription payment
add_action(
	'gform_post_add_subscription_payment',
	static function ( $entry, $action ) {
		if ( ! is_array( $action ) || ! isset( $action['type'] ) || 'add_subscription_payment' !== $action['type'] ) {
			return;
		}
		if ( ! function_exists( 'gravity_flow' ) || ! class_exists( 'GFAPI' ) || ! class_exists( 'Gravity_Flow_API' ) ) {
			return;
		}
		$entry   = is_array( $entry ) ? $entry : [];
		$form_id = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
		$gwf     = gravity_flow();
		$start   = $gwf->get_workflow_start_step( $form_id, $entry );
		if ( ! $start ) {
			return;
		}
		$is_checked = $start->__get( 'restart_workflow_checkbox' );
		if ( ! $is_checked ) {
			return;
		}
		$api = new Gravity_Flow_API( $form_id );

		// Never disturb a workflow that is currently live on a step: a recurring
		// payment arriving while the entry sits on a step must not discard it.
		//
		// Test for the presence of a current step rather than matching status
		// strings. get_status() returns the *step's* evaluated status while a step is
		// live, and that vocabulary is open-ended — 'pending', but also 'queued'
		// (any scheduled or delayed step parks an entry that way), an approval step's
		// 'approved'/'rejected' mid-processing, a
		// webhook step's 'error_client'/'error_server', and anything a custom step or
		// the gravityflow_step_status_evaluation_approval filter invents. No string
		// list stays correct. get_current_step() returning false is the unambiguous
		// "workflow has ended" signal, whatever final status it ended on.
		if ( false !== $api->get_current_step( $entry ) ) {
			return;
		}

		$target_step_id = $start->__get( 'restart_target_step' );
		$target_step    = null;
		if ( $target_step_id ) {
			$target_step = $gwf->get_step( $target_step_id );
		}
		if ( ! $target_step ) {
			$steps = $api->get_steps();
			if ( is_array( $steps ) && ! empty( $steps ) ) {
				array_shift( $steps );
			}
			$target_step = $steps[0] ?? null;
		}
		if ( ! $target_step ) {
			GFAPI::add_note( $entry['id'], 0, 'bld-restart-workflow', 'Step not found, unable to restart workflow.' );
			return;
		}

		// Bump the date field only once the restart is certain, and register the
		// restore in the same breath. Updating it before the target step was resolved
		// left the field permanently overwritten with today whenever the restart then
		// bailed, because the restore was only ever hooked on the success path.
		bld_workflow_restart_record_date( $entry, $action, $start );

		$field_id = $start->__get( 'update_date_field' );
		if ( $field_id && isset( $entry[ $field_id ] ) ) {
			$original_date = $entry[ $field_id ];
			$today         = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', current_time( 'timestamp' ) ) : date( 'Y-m-d' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.DateTime.CurrentTimeTimestamp.Requested
			GFAPI::update_entry_field( $entry['id'], $field_id, $today );
			add_action(
				'gravityflow_post_process_workflow',
				static function () use ( $entry, $field_id, $original_date ) {
					GFAPI::update_entry_field( $entry['id'], $field_id, $original_date );
				}
			);
		}

		$gwf->add_timeline_note( $entry['id'], 'Workflow started because of subscription payment.' );
		$api->send_to_step( $entry, $target_step->get_id() );
		GFAPI::send_notifications( GFAPI::get_form( $form_id ), $entry, 'bld_restart_workflow' );
	},
	10,
	2
);


add_action(
	'gform_entry_detail_sidebar_middle',
	static function ( $form, $entry ) {
		if ( ! function_exists( 'gravity_flow' ) || ! class_exists( 'GFAPI' ) ) {
			return;
		}
		// Capability guard: only show to users who can view workflow details.
		if ( class_exists( 'GFCommon' ) && ! GFCommon::current_user_can_any( 'gravityflow_workflow_detail' ) ) {
			return;
		}
		$start_step = gravity_flow()->get_workflow_start_step( isset( $form['id'] ) ? (int) $form['id'] : 0, $entry );
		if ( ! $start_step ) {
			return;
		}
		$delay_view_approval       = $start_step->__get( 'delay_workflow_checkbox' );
		$restart_workflow_checkbox = $start_step->__get( 'restart_workflow_checkbox' );
		if ( ! $delay_view_approval && ! $restart_workflow_checkbox ) {
			return;
		}

		// Handle POSTed actions.
        if ( isset( $_POST['restart_workflow_nonce'] ) && isset( $_POST['restart-workflow-button'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$nonce_ok = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['restart_workflow_nonce'] ) ), 'restart_workflow_nonce' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$btn      = sanitize_text_field( wp_unslash( $_POST['restart-workflow-button'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $nonce_ok && ( 'restart_workflow_checkbox' === $btn || 'delay_workflow_checkbox' === $btn ) ) {
				// Emulate send-to-first-step like other triggers above.
				$form_id        = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
				$gwf            = gravity_flow();
				$api            = class_exists( 'Gravity_Flow_API' ) ? new Gravity_Flow_API( $form_id ) : null;
				$target_step_id = $start_step->__get( 'restart_target_step' );
				$target_step    = null;
				if ( $target_step_id ) {
					$target_step = $gwf->get_step( $target_step_id );
				}
				if ( ! $target_step ) {
					$steps = $api ? $api->get_steps() : [];
					if ( is_array( $steps ) && ! empty( $steps ) ) {
						array_shift( $steps );
					}
					$target_step = $steps[0] ?? null;
				}
				if ( $target_step ) {
					// A manual re-run is usually catching up on something that happened
					// earlier, so "today" would be no more accurate than the date already on
					// the entry. The person clicking the button does know the real date
					// though, so record it when they supply one and leave the entry alone
					// when they don't — blank keeps {workflow_restart_date} falling back to
					// the configured date field, exactly as before.
					$manual_date = isset( $_POST['restart_workflow_date'] ) ? sanitize_text_field( wp_unslash( $_POST['restart_workflow_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
					if ( '' !== $manual_date && strtotime( $manual_date ) ) {
						bld_workflow_restart_record_date( $entry, [ 'payment_date' => $manual_date ], $start_step );
					}

					$reason = ( 'restart_workflow_checkbox' === $btn ) ? 'Subscription Payment (manual)' : 'Gravity View Approval (manual)';
					if ( '' !== $manual_date && strtotime( $manual_date ) ) {
						$reason .= ', dated ' . gmdate( 'Y-m-d', strtotime( $manual_date ) );
					}
					$gwf->add_timeline_note( $entry['id'], 'Workflow started manually: ' . $reason );
					$api->send_to_step( $entry, $target_step->get_id() );
                    GFAPI::send_notifications( GFAPI::get_form( $form_id ), $entry, 'bld_restart_workflow' );
                } else {
                    GFAPI::add_note( $entry['id'], 0, 'bld-restart-workflow', 'Step not found, unable to restart workflow.' );
                }
			}
		}

		$nonce = wp_create_nonce( 'restart_workflow_nonce' );
		ob_start();
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle ui-sortable-handle">Restart Workflow</h2>
			</div>
			<div class="inside">
				<input type="hidden" name="restart_workflow_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<p style="margin:0 0 10px">
					<label for="restart_workflow_date" style="display:block;margin-bottom:4px">
						Date this restart represents
					</label>
					<input type="date" id="restart_workflow_date" name="restart_workflow_date" value="" />
					<span class="description" style="display:block">Optional. Leave blank to keep the entry's existing date.</span>
				</p>
				<?php if ( $delay_view_approval ) : ?>
					<button class="button button-large" name="restart-workflow-button" value="delay_workflow_checkbox">GV Approval</button><br /><br />
				<?php endif; ?>
				<?php if ( $restart_workflow_checkbox ) : ?>
					<button class="button button-large" name="restart-workflow-button" value="restart_workflow_checkbox">Subscription Payment</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	},
	10,
	2
);

add_filter(
    'gform_notification_events',
    static function ( $notification_events ) {
		$notification_events['bld_restart_workflow'] = 'BLD Snippet - Restart Workflow on GV Approval or Subscription Payment';
		return $notification_events;
	}
);
