<?php
/**
 * Send Error Notification When GF Notification Fails
 *
 * @gravityforms
 *
 * GOAL:
 * Alerts administrators when Gravity Forms fails to send email notifications. Critical for
 * ensuring important notifications (confirmations, alerts, assignments) aren't silently lost.
 * Includes full email details and error message to help diagnose SMTP/email configuration issues.
 *
 * CONFIGURATION:
 * - `$to`: Add recipient email address in $to variable (currently empty). Defaults to site admin
 *   email.
 *
 * NOTES:
 * This snippet only catches notifications that Gravity Forms detected as failed. It does not catch
 * errors that occur during email sending, such as SMTP connection issues, invalid email addresses,
 * or wp_mail() failing.
 */

add_action(
    'gform_send_email_failed',
    function ( $error, $email, $entry ) {
        if ( ! $error instanceof WP_Error ) {
            return;
        }

        $to = ''; // add email here

        if ( empty( $to ) ) {
            $to = get_option( 'admin_email' );
        }

        $time = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )->format( 'm/d/Y g:i A' );

        $form          = GFAPI::get_form( $entry['form_id'] );
        $form_title    = $form['title'];
        $subject       = "Email failed to send for form $form_title at $time";
        $user          = wp_get_current_user();
        $user_id       = $user->ID;
        $user_name     = $user_id > 0 ? $user->display_name : 'Guest';
        $user_email    = $user_id > 0 ? $user->user_email : 'N/A';
        $url           = $entry['source_url'];
        $email_content = '';
        $entry_link    = '<a target="_blank" href="' . esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry['id'] ) ) . '">' . $entry['id'] . '</a>';
        foreach ( $email as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            $email_content .= $key . ': ' . $value . PHP_EOL;
        }

        $message = "Time: $time" . PHP_EOL . "URL: $url" . PHP_EOL . "Form: $form_title" . PHP_EOL .
            "Entry: $entry_link" . PHP_EOL . "User: $user_name" . PHP_EOL . "User Email: $user_email" . PHP_EOL .
            'Error: ' . $error->get_error_message() . PHP_EOL . 'Email content:' . PHP_EOL . $email_content;

        wp_mail( $to, $subject, $message );
    },
    10,
    3
);
