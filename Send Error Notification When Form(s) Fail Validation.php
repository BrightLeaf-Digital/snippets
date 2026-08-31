<?php
/**
 * Send Error Notification When Form(s) Fail Validation
 *
 * @gravityforms
 *
 * GOAL:
 * Sends email notification to administrators whenever any form submission fails validation.
 * Captures all failed fields with their labels and error messages, helping identify problematic
 * forms, confusing validation rules, or user experience issues that need attention.
 *
 * CONFIGURATION:
 * - $to: Add the recipient email address (in between the quotes) where notifications should be
 *   sent. Defaults to site admin email.
 * - $whitelist: Choose which forms to monitor - Leave empty [] to monitor ALL forms - Add form IDs
 *   like [1, 5, 12] to ONLY monitor those specific forms - Find form IDs in WordPress admin: Forms
 *   > Forms (shown in the ID column)
 * - $blacklist: Choose which forms to exclude - Leave empty [] to exclude NO forms - Add form IDs
 *   like [3, 7] to SKIP those specific forms - If both whitelist and blacklist are configured,
 *   blacklist takes priority
 */

add_action(
	'gform_post_process',
	function ( $form ) {

		$whitelist = []; // Empty = monitor all forms. Add form IDs like [1, 5, 12] to monitor only those forms
		$blacklist = []; // Empty = exclude no forms. Add form IDs like [3, 7] to skip those forms. If both configured, blacklist wins
		$to        = ''; // add email here

        if ( empty( $to ) ) {
            $to = get_option( 'admin_email' );
        }

		// Form filtering logic
		if ( ! empty( $blacklist ) && in_array( intval( $form['id'] ), array_map( 'intval', $blacklist ), true ) ) {
			return; // Skip blacklisted forms
		}
		if ( ! empty( $whitelist ) && ! in_array( intval( $form['id'] ), array_map( 'intval', $whitelist ), true ) ) {
			return; // Skip forms not in whitelist
		}

		$failed_fields = [];
		foreach ( $form['fields'] as $field ) {
			if ( $field['failed_validation'] ) {
				$failed_fields[] = 'Field label: ' . $field['label'] . '. Field error: ' . $field['validation_message'];
			}
		}
		if ( ! empty( $failed_fields ) ) {
			$time = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )->format( 'm/d/Y g:i A' );

			$form_title = $form['title'];
			$errors     = implode( PHP_EOL, $failed_fields );
			$subject    = "Form $form_title Failed Validation at $time";
			$user       = wp_get_current_user();
			$user_id    = $user->ID;
			$user_name  = $user_id > 0 ? $user->display_name : 'Guest';
			$user_email = $user_id > 0 ? $user->user_email : 'N/A';
			$url        = RGFormsModel::get_current_page_url();

			$message = "Time: $time" . PHP_EOL . "URL: $url" . PHP_EOL . "Form: $form_title" . PHP_EOL . "User: $user_name" . PHP_EOL . "User Email: $user_email" . PHP_EOL . $errors;
			wp_mail( $to, $subject, $message );
		}
	}
);
