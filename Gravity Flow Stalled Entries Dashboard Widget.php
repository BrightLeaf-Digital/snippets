<?php
/**
 * Gravity Flow: stalled entries dashboard widget
 *
 * @gravityforms
 * @gravityflow
 * @cleanup-option bld_gfse_days
 * @cleanup-transient bld_gfse_rows
 *
 * GOAL:
 * Adds a Dashboard widget that counts the entries waiting on each Gravity Flow step for longer
 * than a number of days you choose (7 by default), so workflows that quietly got stuck are
 * visible without opening every form.
 *
 * Each row shows the form, the step, how many entries are stalled there and how long the oldest
 * has been waiting. The count links to the entries list filtered to that step.
 *
 * REQUIREMENTS:
 * - Gravity Forms 2.3 or later.
 * - Gravity Flow.
 *
 * USAGE:
 * Open the WordPress Dashboard. The widget is shown to users with `gform_full_access` or
 * `gravityflow_status_view_all`. To change the number of days, hover the widget's title and
 * click Configure.
 *
 * NOTES:
 * - It counts with Gravity Flow's own entry meta: `workflow_final_status` is pending,
 *   `workflow_step` is the step the entry waits on, and `workflow_step_{ID}_timestamp` is when
 *   it got there.
 * - Steps that are queued for a scheduled start are not counted, nor are trashed or spam
 *   entries.
 * - One grouped query on the entry tables, cached for 10 minutes, and at most 50 form and step
 *   rows.
 * - When GravityOps Workflow Step Runner is active, each row links to it with the form
 *   preselected, so you can send the stuck entries through a step in bulk.
 * - It stores the day setting in the `bld_gfse_days` option and the cached counts in the
 *   `bld_gfse_rows` transient. GravityOps Code Snippet Manager removes both when you delete
 *   the snippet.
 */

add_action( 'wp_dashboard_setup', 'bld_gfse_register_widget' );

/**
 * Whether the current user may see the widget.
 *
 * @return bool
 */
function bld_gfse_can_view() {
	return class_exists( 'GFCommon' ) && GFCommon::current_user_can_any( [ 'gform_full_access', 'gravityflow_status_view_all' ] );
}

/**
 * Register the Dashboard widget and its Configure form.
 *
 * @return void
 */
function bld_gfse_register_widget() {
	if ( ! class_exists( 'GFFormsModel' ) || ! function_exists( 'gravity_flow' ) || ! bld_gfse_can_view() ) {
		return;
	}
	wp_add_dashboard_widget( 'bld_gfse_widget', 'Gravity Flow: stalled entries', 'bld_gfse_render_widget', 'bld_gfse_widget_control' );
}

/**
 * The stalled threshold in days.
 *
 * @return int
 */
function bld_gfse_days() {
	$days = (int) get_option( 'bld_gfse_days', 7 );
	return $days > 0 ? $days : 7;
}

/**
 * The widget's Configure form: saves the threshold, then prints the input.
 *
 * @return void
 */
function bld_gfse_widget_control() {
	if ( isset( $_POST['bld_gfse_days'], $_POST['bld_gfse_nonce'] ) && bld_gfse_can_view() && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bld_gfse_nonce'] ) ), 'bld_gfse_days' ) ) {
		update_option( 'bld_gfse_days', max( 1, min( 365, absint( wp_unslash( $_POST['bld_gfse_days'] ) ) ) ), false );
	}
	wp_nonce_field( 'bld_gfse_days', 'bld_gfse_nonce' );
	printf(
		'<p><label for="bld_gfse_days">Count an entry as stalled after this many days on one step</label><br><input type="number" min="1" max="365" id="bld_gfse_days" name="bld_gfse_days" value="%d"></p>',
		(int) bld_gfse_days()
	);
}

/**
 * Stalled entries grouped by form and current step.
 *
 * @param int $days Entries that reached their current step more than this many days ago.
 *
 * @return array Rows of form_id, step_id, stalled, oldest (Unix time).
 */
function bld_gfse_get_rows( $days ) {
	global $wpdb;
	$days = max( 0, (int) $days );
	// One fixed transient name, so the snippet's cleanup declaration can name it exactly.
	$cache = get_transient( 'bld_gfse_rows' );
	if ( is_array( $cache ) && isset( $cache['days'], $cache['rows'] ) && $days === $cache['days'] && is_array( $cache['rows'] ) ) {
		return $cache['rows'];
	}
	$entry_table = GFFormsModel::get_entry_table_name();
	$meta_table  = GFFormsModel::get_entry_meta_table_name();
	$cutoff      = time() - $days * DAY_IN_SECONDS;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.form_id, s.meta_value AS step_id, COUNT(*) AS stalled, MIN(CAST(t.meta_value AS UNSIGNED)) AS oldest
			FROM {$meta_table} s
			INNER JOIN {$entry_table} e ON e.id = s.entry_id AND e.status = 'active'
			INNER JOIN {$meta_table} f ON f.entry_id = s.entry_id AND f.meta_key = 'workflow_final_status' AND f.meta_value = 'pending'
			INNER JOIN {$meta_table} t ON t.entry_id = s.entry_id AND t.meta_key = CONCAT('workflow_step_', s.meta_value, '_timestamp')
			LEFT JOIN {$meta_table} q ON q.entry_id = s.entry_id AND q.meta_key = CONCAT('workflow_step_status_', s.meta_value)
			WHERE s.meta_key = 'workflow_step'
				AND CAST(s.meta_value AS UNSIGNED) > 0
				AND CAST(t.meta_value AS UNSIGNED) < %d
				AND ( q.meta_value IS NULL OR q.meta_value <> 'queued' )
			GROUP BY s.form_id, s.meta_value
			ORDER BY stalled DESC
			LIMIT 50",
			$cutoff
		),
		ARRAY_A
	);
	// phpcs:enable
	$rows = is_array( $rows ) ? $rows : [];
	set_transient(
		'bld_gfse_rows',
		[
			'days' => $days,
			'rows' => $rows,
		],
		10 * MINUTE_IN_SECONDS
	);
	return $rows;
}

/**
 * Widget callback.
 *
 * @return void
 */
function bld_gfse_render_widget() {
	bld_gfse_render( bld_gfse_days() );
}

/**
 * Render the stalled-entries table.
 *
 * @param int $days Stalled threshold in days.
 *
 * @return void
 */
function bld_gfse_render( $days ) {
	$rows = bld_gfse_get_rows( $days );
	if ( ! $rows ) {
		printf( '<p>No entries have been waiting on one Gravity Flow step for more than %d days.</p>', (int) $days );
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Form</th><th>Step</th><th style="text-align:right">Stalled</th><th>Oldest</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$form_id = (int) $row['form_id'];
		$step_id = (int) $row['step_id'];
		$form    = GFAPI::get_form( $form_id );
		$step    = gravity_flow()->get_step( $step_id );
		$title   = $form ? $form['title'] : 'Form #' . $form_id;
		$name    = $step ? $step->get_name() : 'Step #' . $step_id;
		$list    = admin_url( 'admin.php?page=gf_entries&id=' . $form_id . '&field_id=workflow_step&operator=is&s=' . $step_id );
		if ( defined( 'GOPS_WSR_SLUG' ) ) {
			$action = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">Send through a step</a>', esc_url( admin_url( 'admin.php?page=' . GOPS_WSR_SLUG . '&gops_wsr_form_id=' . $form_id ) ) );
		} else {
			$action = '';
		}
		printf(
			'<tr><td>%s</td><td>%s</td><td style="text-align:right"><a href="%s" target="_blank" rel="noopener noreferrer">%d</a></td><td>%s ago</td><td>%s</td></tr>',
			esc_html( $title ),
			esc_html( $name ),
			esc_url( $list ),
			(int) $row['stalled'],
			esc_html( human_time_diff( (int) $row['oldest'], time() ) ),
			$action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped parts.
		);
	}
	echo '</tbody></table>';
	printf( '<p class="description">Stalled means waiting on the same step for more than %d days. Queued (scheduled) steps are not counted.', (int) $days );
	if ( ! defined( 'GOPS_WSR_SLUG' ) ) {
		echo ' To send stuck entries through a step in bulk, see <a href="https://brightleafdigital.io/gravityops-workflow-step-runner/" target="_blank" rel="noopener noreferrer">Workflow Step Runner</a>.';
	}
	echo '</p>';
}
