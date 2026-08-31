<?php
/**
 * Send Error Notification When Specified Form Field Has 0 value
 *
 * @gravityforms
 *
 * GOAL:
 * Monitors donation/ledger forms for critical fields that have zero or empty values after
 * submission. Sends email alert to administrators when amount fields are empty, which could
 * indicate data loss, calculation errors, or payment processing issues. Includes user info and
 * timestamp for debugging.
 *
 * CONFIGURATION:
 * - `$to`: Add the recipient email address (in between the quotes) where notifications should be
 *   sent. Defaults to site admin email.
 * - `$form_ids`: Configure form IDs and field IDs to monitor. As of now it checks fields 4, 8, and
 *   11 on form 30 and fields 13 and 16 on form 60. Modify the id's to your own forms. Remove or
 *   add as necessary.
 */

add_action(
    'gform_after_submission',
    function ( $entry, $form ) {

        $to = ''; // add email here

        if ( empty( $to ) ) {
            $to = get_option( 'admin_email' );
        }

        $form_ids = [
            30 => [
                4,
                8,
                11,
            ],
            60 => [
                13,
                16,
            ],
        ];

        $warning = [];
        if ( in_array( intval( $form['id'] ), array_map( 'intval', array_keys( $form_ids ) ), true ) ) {
            foreach ( $form_ids[ $form['id'] ] as $field_id ) {
                if ( empty( $entry[ $field_id ] ) ) {
                    foreach ( $form['fields'] as $field ) {
                        if ( intval( $field['id'] ) === intval( $field_id ) ) {
                            $warning[] = 'Field ' . $field['label'] . ' with field id of ' . $field_id . ' has a value of 0.';
                        }
                    }
                }
            }
            if ( ! empty( $warning ) ) {
                $time = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )->format( 'm/d/Y g:i A' );

                $form_title = $form['title'];
                $errors     = implode( PHP_EOL, $warning );
                $subject    = "0 value in donation form ledger amount field on form $form_title at $time";
                $user       = wp_get_current_user();
                $user_id    = $user->ID;
                $user_name  = $user_id > 0 ? $user->display_name : 'Guest';
                $user_email = $user_id > 0 ? $user->user_email : 'N/A';
                $url        = $entry['source_url'];

                $message = "Time: $time" . PHP_EOL . "URL: $url" . PHP_EOL . "Form: $form_title" . PHP_EOL . "User: $user_name" . PHP_EOL . "User Email: $user_email" . PHP_EOL . $errors;
                wp_mail( $to, $subject, $message );
            }
        }
    },
    10,
    2
);
