<?php
/**
 * Proper Case (capitalize first letter of each word) Field Values before Saving
 *
 * @gravityforms
 *
 * GOAL:
 * This code snippet automatically **capitalizes the first letter of each word** in specified
 * Gravity Forms fields before saving the entry. It ensures that user inputs, such as names or
 * addresses, are stored in **proper case formatting**, enhancing data consistency and readability.
 *
 * CONFIGURATION:
 * - `$form_and_field_ids`: change the key to form ID and sub-array values to field IDs. Example:
 *   `3 => [5,8,12]` - this will apply to form 3, fields 5, 8, and 12. Add more form/field
 *   combinations by duplicating the pattern.
 * - This will apply to text fields, textarea fields, and any field that stores string values.
 * - Note: Consider if email fields, URL fields, or other special fields are in your list.
 * - Note: for more complex transformations, see our other snippet.
 *
 * FEATURES:
 * - **Automatically applies proper case** (capitalizes the first letter of each word).
 * - **Works on specified form and field IDs**, allowing customization for different forms.
 * - **Ensures data consistency** for user-entered text fields like names and addresses.
 * - **Applies the transformation before saving**, ensuring uniform formatting in stored entries.
 * - **Easy to configure** by specifying the relevant form and field IDs.
 */

add_filter(
        'gform_save_field_value',
        function ( $value, $entry, $field, $form ) {
            $form_and_field_ids = [
                    0 => [ 0, 0 ],
            ];
            if ( in_array( intval( $form['id'] ), array_map( 'intval', array_keys( $form_and_field_ids ) ), true )
                 && in_array( intval( $field['id'] ), array_map( 'intval', $form_and_field_ids[ intval( $form['id'] ) ] ), true ) ) {
                $value = ucwords( sanitize_text_field( $value ) );
            }
            return $value;
        },
        10,
        4
);
