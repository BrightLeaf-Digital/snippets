<?php
/**
 * Validate form for no duplicate values across X, Y, Z fields
 *
 * @gravityforms
 *
 * GOAL:
 * This snippet ensures that specific groups of fields within a **Gravity Forms** form do not
 * contain duplicate values. It allows you to define multiple sets of fields for validation,
 * ensuring that no two fields within a group share the same value. If duplicates are found, a
 * **custom validation error message** is displayed for each affected field.
 *
 * CONFIGURATION:
 * - `$forms`: Each group is an array of field_id => 'error message'. If two or more fields in that
 *   group have the same value, those fields will fail validation with the provided message.
 *
 * FEATURES:
 * ✔ **Prevents Duplicate Values** – Ensures that no two fields within specified groups have
 * the same value. ✔ **Supports Multiple Field Groups** – Each form can have multiple sets of
 * fields that must be unique within their group. ✔ **Custom Error Messages** – Define a
 * specific validation error message for each field when duplicates are detected. ✔ **Works
 * Across Multiple Forms** – The validation can be applied to different forms with custom field
 * configurations. ✔ **Supports Multi-Input Fields** – Handles complex field types such as
 * **Name, Address, and Checkbox fields**. ✔ **Optimized Duplicate Detection** – Uses efficient
 * logic to check and flag duplicate values only when necessary. This snippet is particularly
 * useful for scenarios like **ensuring unique user IDs, email addresses, product SKUs, or any
 * other critical values** in Gravity Forms.
 *
 * NOTES:
 * - Multi‑input fields (Name, Address, Checkbox) are concatenated using a pipe (|) separator for
 *   comparison to avoid accidental matches on spaces.
 */

( static function () {
    if ( ! class_exists( 'GFAPI' ) ) {
        return;
    }

    // === Configuration: per‑form duplicate groups (edit these) ==================
    $forms = [
            63 => [
                    [
                            21 => 'Please provide different values.',
                            23 => 'Please provide different values.',
                            27 => 'Please provide different values.',
                    ],
                    [
                            21 => 'These two fields must not match.',
                            24 => 'These two fields must not match.',
                    ],
                    [
                            25 => 'Please use unique values in these fields.',
                            26 => 'Please use unique values in these fields.',
                    ],
            ],
            32 => [
                    [
                            3  => 'Duplicate detected in this group.',
                            17 => 'Duplicate detected in this group.',
                            46 => 'Duplicate detected in this group.',
                    ],
                    [
                            22 => 'Please ensure unique values.',
                            17 => 'Please ensure unique values.',
                    ],
            ],
        // Add more forms as needed...
    ];
    // ==========================================================================

    // Helpers.
    $is_multi = static function ( $field ) {
        $type = is_object( $field ) ? $field->type : ( isset( $field['type'] ) ? $field['type'] : '' );
        return in_array( $type, [ 'name', 'address', 'checkbox' ], true );
    };

    $to_number = static function ( $val ) {
        if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'to_number' ) ) {
            $currency = GFCommon::get_currency();
            return GFCommon::to_number( (string) $val, $currency );
        }
        return is_numeric( $val ) ? (float) $val : ( '' === $val ? '' : (string) $val );
    };

    $collect_value = static function ( $field_id, $field ) use ( $is_multi, $to_number ) {
        $field_id = (int) $field_id;
        $type     = is_object( $field ) ? $field->type : ( $field['type'] ?? '' );

        if ( true === $is_multi( $field ) ) {
            $parts   = [];
            $pattern = '/^input_' . preg_quote( (string) $field_id, '/' ) . '_\d+$/';
            foreach ( array_keys( $_POST ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( 1 === preg_match( $pattern, (string) $key ) ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $val = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
                    if ( '' !== $val || '0' === $val ) {
                        $parts[] = is_array( $val ) ? implode( ' | ', array_map( 'strval', $val ) ) : (string) $val;
                    }
                }
            }
            return implode( ' | ', $parts );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw = isset( $_POST[ 'input_' . $field_id ] ) ? wp_unslash( $_POST[ 'input_' . $field_id ] ) : '';
        if ( 'number' === $type ) {
            $raw = $to_number( $raw );
        }
        return is_array( $raw ) ? implode( ' | ', array_map( 'strval', $raw ) ) : (string) $raw;
    };

    add_filter(
            'gform_validation',
            static function ( $validation_result ) use ( $forms, $collect_value ) {
                $form    = $validation_result['form'] ?? null;
                $form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
                if ( 0 === $form_id || true !== isset( $forms[ $form_id ] ) ) {
                    return $validation_result;
                }

                $fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
                $by_id  = [];
                foreach ( $fields as $idx => $fld ) {
                    $fid = is_object( $fld ) ? (int) $fld->id : ( isset( $fld['id'] ) ? (int) $fld['id'] : 0 );
                    if ( 0 !== $fid ) {
                        $by_id[ $fid ] = [
                                'index' => $idx,
                                'field' => $fld,
                        ];
                    }
                }

                foreach ( $forms[ $form_id ] as $group ) {
                    $values = [];
                    foreach ( $group as $fid => $message ) {
                        $fid = (int) $fid;
                        if ( true !== isset( $by_id[ $fid ] ) ) {
                            continue;
                        }
                        $val            = $collect_value( $fid, $by_id[ $fid ]['field'] );
                        $values[ $fid ] = (string) $val;
                    }

                    // Determine duplicates within this group.
                    $seen     = [];
                    $dupe_ids = [];
                    foreach ( $values as $fid => $val ) {
                        if ( '' === $val ) {
                            continue;
                        }
                        if ( true === isset( $seen[ $val ] ) ) {
                            $dupe_ids[ $seen[ $val ] ] = true;
                            $dupe_ids[ $fid ]          = true;
                        } else {
                            $seen[ $val ] = $fid;
                        }
                    }

                    if ( false === empty( $dupe_ids ) ) {
                        $validation_result['is_valid'] = false;
                        foreach ( $group as $fid => $message ) {
                            $fid = (int) $fid;
                            if ( true === isset( $dupe_ids[ $fid ] ) && true === isset( $by_id[ $fid ] ) ) {
                                $idx                                = $by_id[ $fid ]['index'];
                                $fields[ $idx ]->failed_validation  = true;
                                $fields[ $idx ]->validation_message = (string) $message;
                            }
                        }
                    }
                }

                $form['fields']            = $fields;
                $validation_result['form'] = $form;
                return $validation_result;
            },
            10
    );
} )();
