<?php
/**
 * Fix Form Connectors creation of new entry with upload multi files
 *
 * @gravityflow
 *
 * GOAL:
 * When Gravity Flow Form Connector creates a new entry, ensure multi‑file upload values are
 * arrays (not JSON strings). Decodes JSON when detected and normalizes the array. This allows the
 * entry to be created without errors.
 */

( static function () {
    // Dependency light‑guard: the filter will only run when Form Connector is active.
    add_filter(
            'gravityflowformconnector_new_entry',
            static function ( $new_entry, $entry, $form, $target_form, $step ) {
                // Validate target form structure.
                if ( empty( $target_form ) || ! isset( $target_form['fields'] ) || ! is_array( $target_form['fields'] ) ) {
                    return $new_entry;
                }

                foreach ( $target_form['fields'] as $field ) {
                    if ( ! is_object( $field ) ) {
                        continue;
                    }
                    // Only multi‑file Upload fields.
                    if ( 'fileupload' === $field->type && ! empty( $field->multipleFiles ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                        $field_id = $field->id;
                        if ( isset( $new_entry[ $field_id ] ) && is_string( $new_entry[ $field_id ] ) ) {
                            $raw = trim( (string) $new_entry[ $field_id ] );
                            if ( '' !== $raw ) {
                                $decoded = json_decode( $raw, true );
                                if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                                    // Normalize: remove empties and reindex.
                                    $normalized             = array_values(
                                            array_filter(
                                                    $decoded,
                                                    static function ( $v ) {
                                                        return '' !== ( is_string( $v ) ? trim( $v ) : $v );
                                                    }
                                            )
                                    );
                                    $new_entry[ $field_id ] = $normalized;
                                    /**
                                     * Action: log/debug when a multi‑file field value was decoded from JSON.
                                     */
                                    do_action( 'bld_fc_multifile_decoded', $field_id, $normalized, $entry, $form, $target_form, $step );
                                } elseif ( 0 === strpos( $raw, 'a:' ) ) { // naive serialize check
                                    $un = unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
                                    if ( is_array( $un ) ) {
                                        $new_entry[ $field_id ] = array_values( array_filter( $un ) );
                                    }
                                }
                            }
                        }
                    }
                }

                return $new_entry;
            },
            10,
            5
    );
} )();
