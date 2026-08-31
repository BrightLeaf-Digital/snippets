<?php
/**
 * Require at least one child entry in batch to have specified value for selected field
 *
 * @gravityforms
 * @gravityperks
 *
 * GOAL:
 * This code snippet enforces validation on a **Gravity Forms Nested Form field**, ensuring that at
 * least one child entry in a batch contains a specific value in a selected field. If no child
 * entry meets the requirement, the form submission is blocked, and a validation message is
 * displayed. **CONFIGURATION**
 *
 * - `$forms`: `nested_field_id`: ID of the Nested Form field on the parent form `child_field_id`:
 *   ID of the field on the child form to check `required_value`: String value required in at least
 *   one child entry `message`: Validation message to show if requirement is not met
 */

( static function () {
    if ( ! class_exists( 'GFAPI' ) ) {
        return;
    }

    // === Configuration: parent form → rules (edit these) =======================
    $forms = [
            71 => [
                    'nested_field_id' => 1,
                    'child_field_id'  => 1,
                // Either a single required_value OR multiple required_values (array or CSV string)
                    'required_value'  => 'test',
                    'required_values' => [],
                // Minimum number of child entries that must match one of the required values
                    'min_count'       => 1,
                    'message'         => 'Please ensure at least one child entry contains the required value.',
            ],
        // Add more parent forms as needed...
    ];
    // ==========================================================================

    $flatten = static function ( $items ) use ( &$flatten ) {
        $out = [];
        foreach ( (array) $items as $item ) {
            if ( is_array( $item ) ) {
                $out = array_merge( $out, $flatten( $item ) );
            } else {
                $out[] = $item;
            }
        }
        return $out;
    };

    add_filter(
            'gform_field_validation',
            static function ( $result, $value, $form, $field ) use ( $forms, $flatten ) {
                $form_id  = isset( $form['id'] ) ? (int) $form['id'] : 0;
                $field_id = isset( $field['id'] ) ? (int) $field['id'] : 0;

                if ( 0 === $form_id || true !== isset( $forms[ $form_id ] ) ) {
                    return $result;
                }
                $cfg = $forms[ $form_id ];
                if ( $field_id !== (int) $cfg['nested_field_id'] ) {
                    return $result;
                }

                $child_ids = [];
                // Prefer GPNF session API when available.
                if ( class_exists( 'GP_Nested_Forms' ) && class_exists( 'GPNF_Session' ) ) {
                    $nf     = new GP_Nested_Forms( $form );
                    $parent = method_exists( $nf, 'get_parent_form_id' ) ? $nf->get_parent_form_id() : 0;
                    if ( 0 !== $parent ) {
                        $session = new GPNF_Session( $parent );
                        $cookie  = method_exists( $session, 'get_cookie' ) ? $session->get_cookie() : [];
                        if ( is_array( $cookie ) && isset( $cookie['nested_entries'] ) ) {
                            $child_ids = $flatten( $session->get_valid_entry_ids( $cookie['nested_entries'] ) );
                        }
                    }
                }
                // Fallback: parse comma-separated IDs from $value string.
                if ( empty( $child_ids ) && is_string( $value ) ) {
                    $child_ids = array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $value ) ) ) );
                }

                if ( empty( $child_ids ) ) {
                    return $result; // Nothing to validate against.
                }

                $child_field_id = (string) (int) $cfg['child_field_id'];

                // Build list of acceptable values
                $values = [];
                if ( ! empty( $cfg['required_values'] ) ) {
                    $rv = $cfg['required_values'];
                    if ( is_string( $rv ) ) {
                        $values = array_map( 'trim', array_filter( explode( ',', $rv ) ) );
                    } elseif ( is_array( $rv ) ) {
                        $values = array_map( 'strval', array_map( 'trim', $rv ) );
                    }
                }
                if ( empty( $values ) && isset( $cfg['required_value'] ) ) {
                    $values = [ (string) $cfg['required_value'] ];
                }
                $values    = array_values( array_unique( array_map( 'strval', $values ) ) );
                $min_count = isset( $cfg['min_count'] ) ? max( 1, (int) $cfg['min_count'] ) : 1;

                $match_count = 0;
                foreach ( $child_ids as $cid ) {
                    $child = GFAPI::get_entry( (int) $cid );
                    if ( is_wp_error( $child ) || empty( $child ) ) {
                        continue;
                    }
                    if ( isset( $child[ $child_field_id ] ) ) {
                        $val = $child[ $child_field_id ];
                        if ( is_array( $val ) ) {
                            // Flatten and compare any sub-values
                            $flat = [];
                            foreach ( $val as $vv ) {
                                $flat[] = (string) $vv; }
                            if ( array_intersect( $values, $flat ) ) {
                                $match_count++;
                            }
                        } elseif ( in_array( (string) $val, $values, true ) ) {
                            $match_count++;
                        }
                    }
                    if ( $match_count >= $min_count ) {
                        break;
                    }
                }

                if ( $match_count < $min_count ) {
                    $result['is_valid'] = false;
                    $result['message']  = isset( $cfg['message'] ) ? (string) $cfg['message'] : 'Please add child entries that meet the required values.';
                }

                return $result;
            },
            10,
            4
    );
} )();
