<?php
/**
 * Capitalize Specific Field Value Before Saving Entry
 *
 * @gravityforms
 *
 * GOAL:
 * By default this code snippet automatically converts the value of a specific text field to
 * uppercase before saving the entry in Gravity Forms. It ensures consistency in formatting and is
 * particularly useful for fields like names, codes, or identifiers that require capitalization.
 * Now also allows numerous other text manipulations and transformations:
 *
 * - Case transformations: uppercase, lowercase, title case, camelCase, PascalCase, sentence case
 * - Formatting: URL-friendly slugs, phone number formatting, whitespace trimming, space collapsing
 * - Cleaning: strip HTML tags, remove non-alphanumeric characters
 * - Special: string reversal
 *
 * CONFIGURATION:
 * - Edit the config block below. Installed through GOCSM, these values are fields and survive
 *   snippet updates.
 * - `BLD_CAP_FORM_ID`: target form ID. Use 0 to apply to every form.
 * - `BLD_CAP_FIELD_ID`: target field ID. Use 0 to apply to every field on the form. Ignored when
 *   the form ID is 0.
 * - `BLD_CAP_APPLY`: transformations to apply, in order. Options: 'upper', 'lower', 'title',
 *   'camel', 'pascal', 'sentence', 'slug', 'trim', 'collapse', 'alphanumeric', 'phone',
 *   'strip_tags', 'reverse'.
 *
 * FEATURES:
 * - Ensures uniform formatting for data consistency.
 * - Applies only to text input fields, preventing unintended changes to other field types.
 * - Easily customizable by modifying the form and field ID in the filter hook.
 * - Multiple transformations can be applied in sequence
 * - Highly configurable with 12+ built-in transformation options
 * - Preserves original value if transformation not applicable
 * - Sanitizes output for security
 */

// === BEGIN CONFIG ===
/** @config int Target form ID. Use 0 to apply to every form */
const BLD_CAP_FORM_ID = 3;

/** @config int Target field ID. Use 0 to apply to every field on the form. Ignored when the form ID is 0 */
const BLD_CAP_FIELD_ID = 16;

/** @config array Transformations to apply, in order */
const BLD_CAP_APPLY = [ 'upper' ];
// === END CONFIG ===

$bld_cap_hook = 'gform_save_field_value';
if ( BLD_CAP_FORM_ID > 0 ) {
    $bld_cap_hook .= '_' . BLD_CAP_FORM_ID;
    if ( BLD_CAP_FIELD_ID > 0 ) {
        $bld_cap_hook .= '_' . BLD_CAP_FIELD_ID;
    }
}

add_filter(
        $bld_cap_hook,
        function ( $value, $entry, $field ) {
            $transformations = [
                    'upper'        => 'strtoupper',           // ALL CAPS
                    'lower'        => 'strtolower',           // all lowercase
                    'title'        => 'ucwords',              // Title Case
                    'camel'        => 'lcfirst',              // camelCase (first char lower)
                    'pascal'       => 'ucfirst',              // PascalCase (first char upper)
                    'sentence'     => function ( $str ) {
                        // Sentence case
                        return ucfirst( strtolower( $str ) );
                    },
                    'slug'         => function ( $str ) {
                        // URL-friendly slug
                        return sanitize_title( $str );
                    },
                    'trim'         => 'trim',                 // Remove whitespace from ends
                    'collapse'     => function ( $str ) {
                        // Collapse multiple spaces to single
                        return preg_replace( '/\s+/', ' ', trim( $str ) );
                    },
                    'alphanumeric' => function ( $str ) {
                        // Remove non-alphanumeric chars
                        return preg_replace( '/[^A-Za-z0-9\s]/', '', $str );
                    },
                    'phone'        => function ( $str ) {
                        // Format as (XXX) XXX-XXXX
                        $cleaned = preg_replace( '/[^0-9]/', '', $str );
                        if ( strlen( $cleaned ) === 10 ) {
                            return sprintf( '(%s) %s-%s', substr( $cleaned, 0, 3 ), substr( $cleaned, 3, 3 ), substr( $cleaned, 6 ) );
                        }
                        return $str;
                    },
                    'strip_tags'   => 'strip_tags',           // Remove HTML/PHP tags
                    'reverse'      => 'strrev',               // Reverse the string
            ];

            if ( $field instanceof GF_Field && $field->get_input_type() === 'text' && ! empty( $value ) ) {
                // Apply multiple transformations in order
                $apply = BLD_CAP_APPLY;

                foreach ( $apply as $transform_type ) {
                    if ( isset( $transformations[ $transform_type ] ) ) {
                        $transformer = $transformations[ $transform_type ];
                        $value       = $transformer( $value );
                    }
                }

                $value = sanitize_text_field( $value );
            }
            return $value;
        },
        10,
        3
);
