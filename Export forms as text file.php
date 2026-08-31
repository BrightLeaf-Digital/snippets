<?php
/* phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase */
/**
 * Export forms as text file
 *
 * @gravityforms
 * @gravityflow
 *
 * GOAL:
 * This code snippet adds an **"Export Forms as Text"** feature to Gravity Forms, allowing
 * administrators to export form structures, confirmations, notifications, and workflow steps as a
 * downloadable text file. This is useful for documentation, backups, or sharing form
 * configurations without requiring JSON exports.
 *
 * FEATURES:
 * - **Adds an Export Forms as Text tab** in the Gravity Forms export menu.
 * - **Allows selection of multiple forms** for export.
 * - **Exports form titles, fields, confirmations, notifications, and workflow steps** in a
 *   structured text format.
 * - **Includes Gravity Flow workflow notifications** for workflows using Gravity Flow.
 * - **Ensures secure access** by restricting export functionality to users with form editing
 *   permissions.
 * - **Automatically downloads a formatted text file** for easy sharing or documentation.
 * - **Optionally include more data** in the form export, such as field ID's, conditional logic,
 *   form settings, entry count and more.
 * - **Export multiple** forms at once in one zip file.
 *
 * # Developer Note
 *
 * - The `bld_gf_text_export_filename` filter exists to easily customize file names.
 */

( static function () {

    // Helper: render a single form to TXT according to include options.
    $render_form_txt = static function ( $form, $include_details ) {
        $form_id    = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
        $form_title = isset( $form['title'] ) ? (string) $form['title'] : 'Untitled Form';
        $out        = 'Form: ' . $form_title . ' (ID: ' . $form_id . ')' . PHP_EOL;
        if ( ! empty( $include_details['form_settings'] ) ) {
            $out .= 'Settings:' . PHP_EOL;
            $out .= '  Label Placement: ' . ( $form['labelPlacement'] ?? '' ) . PHP_EOL;
            if ( ! empty( $form['description'] ) ) {
                $out .= '  Description: ' . wp_strip_all_tags( (string) $form['description'] ) . PHP_EOL;
            }
        }
        if ( ! empty( $include_details['entry_count'] ) && class_exists( 'GFAPI' ) && $form_id ) {
            try {
                $count = GFAPI::count_entries( $form_id );
            } catch ( Exception $e ) {
                $count = 0;
            }
            $out .= 'Entries: ' . $count . PHP_EOL;
        }
        $out .= PHP_EOL . 'Fields:' . PHP_EOL;
        if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $fid   = is_object( $field ) && isset( $field->id ) ? (int) $field->id : ( isset( $field['id'] ) ? (int) $field['id'] : 0 );
                $label = is_object( $field ) && isset( $field->label ) ? (string) $field->label : ( isset( $field['label'] ) ? (string) $field['label'] : '' );
                $type  = is_object( $field ) && isset( $field->type ) ? (string) $field->type : ( isset( $field['type'] ) ? (string) $field['type'] : '' );
                $admin = '';
                if ( ! empty( $include_details['admin_labels'] ) ) {
                    $admin = is_object( $field ) && isset( $field->adminLabel ) ? (string) $field->adminLabel : ( isset( $field['adminLabel'] ) ? (string) $field['adminLabel'] : '' );
                }
                $out   .= '  - ' . ( ! empty( $include_details['field_ids'] ) ? 'ID ' . $fid . ': ' : '' ) . $label . ( $admin ? ' [' . $admin . ']' : '' ) . ' (' . $type . ')' . PHP_EOL;
                $is_req = (bool) ( is_object( $field ) ? ( $field->isRequired ?? false ) : ( $field['isRequired'] ?? false ) );
                $out   .= '    Required: ' . ( $is_req ? 'true' : 'false' ) . PHP_EOL;
                if ( ! empty( $include_details['choices'] ) ) {
                    $choices = is_object( $field ) && isset( $field->choices ) ? (array) $field->choices : ( isset( $field['choices'] ) ? (array) $field['choices'] : [] );
                    if ( array_filter( $choices ) ) {
                        $out .= '    Choices:' . PHP_EOL;
                        foreach ( $choices as $c ) {
                            $ct   = isset( $c['text'] ) ? (string) $c['text'] : '';
                            $cv   = isset( $c['value'] ) ? (string) $c['value'] : '';
                            $df   = ! empty( $c['isSelected'] ) ? ' (default)' : '';
                            $out .= '      - ' . $ct . ' [' . $cv . ']' . $df . PHP_EOL;
                        }
                    }
                }
                if ( ! empty( $include_details['validation_rules'] ) ) {
                    $max_len   = is_object( $field ) ? ( $field->maxLength ?? '' ) : ( $field['maxLength'] ?? '' );
                    $range_min = is_object( $field ) ? ( $field->rangeMin ?? '' ) : ( $field['rangeMin'] ?? '' );
                    $range_max = is_object( $field ) ? ( $field->rangeMax ?? '' ) : ( $field['rangeMax'] ?? '' );
                    if ( $max_len || '' !== $range_min || '' !== $range_max ) {
                        $out .= '    Validation:' . PHP_EOL;
                        if ( $max_len ) {
                            $out .= '      maxLength: ' . $max_len . PHP_EOL; }
                        if ( '' !== $range_min ) {
                            $out .= '      rangeMin: ' . $range_min . PHP_EOL; }
                        if ( '' !== $range_max ) {
                            $out .= '      rangeMax: ' . $range_max . PHP_EOL; }
                    }
                }
                if ( ! empty( $include_details['conditional_logic'] ) ) {
                    $logic = is_object( $field ) ? ( $field->conditionalLogic ?? [] ) : ( $field['conditionalLogic'] ?? [] );
                    if ( ! empty( $logic ) && isset( $logic['rules'] ) && is_array( $logic['rules'] ) ) {
                        $out   .= '    Conditional Logic:' . PHP_EOL;
                        $action = $logic['actionType'] ?? 'show';
                        $match  = $logic['logicType'] ?? 'all';
                        $out   .= '      action: ' . $action . ', match: ' . $match . PHP_EOL;
                        foreach ( $logic['rules'] as $r ) {
                            $out .= '        - fieldId: ' . $r['fieldId'] . ', operator: ' . $r['operator'] . ', value: ' . $r['value'] . PHP_EOL;
                        }
                    }
                }
            }
        }
        if ( isset( $form['confirmations'] ) && is_array( $form['confirmations'] ) ) {
            $out .= PHP_EOL . 'Confirmations:' . PHP_EOL;
            foreach ( $form['confirmations'] as $confirmation ) {
                $name    = (string) ( $confirmation['name'] ?? '' );
                $message = isset( $confirmation['message'] ) ? wp_strip_all_tags( (string) $confirmation['message'] ) : '';
                $out    .= '  - ' . $name . PHP_EOL;
                if ( isset( $confirmation['url'] ) && '' !== $confirmation['url'] ) {
                    $match = ( 0 === strpos( (string) $confirmation['url'], 'http' ) ) ? 'url' : 'page';
                    $out  .= '    ' . $match . ': ' . $confirmation['url'] . PHP_EOL;
                }
                $out .= '    message: ' . $message . PHP_EOL;
            }
        }
        if ( isset( $form['notifications'] ) && is_array( $form['notifications'] ) ) {
            $out .= PHP_EOL . 'Notifications:' . PHP_EOL;
            foreach ( $form['notifications'] as $notification ) {
                $out .= '  - name: ' . ( $notification['name'] ?? '' ) . PHP_EOL;
                $out .= '    to: ' . ( $notification['to'] ?? '' ) . PHP_EOL;
                $out .= '    event: ' . ( $notification['event'] ?? '' ) . PHP_EOL;
                $out .= '    subject: ' . ( $notification['subject'] ?? '' ) . PHP_EOL;
                $out .= '    message: ' . ( isset( $notification['message'] ) ? wp_strip_all_tags( (string) $notification['message'] ) : '' ) . PHP_EOL;
            }
        }
        if ( function_exists( 'gravity_flow' ) && is_object( gravity_flow() ) ) {
            $output_header = false;
            $steps         = gravity_flow()->get_steps( $form['id'] );
            if ( is_array( $steps ) ) {
                foreach ( $steps as $step ) {
                    if ( is_object( $step ) && method_exists( $step, 'get_type' ) && 'notification' === $step->get_type() ) {
                        if ( ! $output_header ) {
                            $out          .= PHP_EOL . 'Workflow Notifications:' . PHP_EOL;
                            $output_header = true;
                        }
                        $out .= '  - step: ' . ( $step->step_name ?? '' ) . PHP_EOL;
                        $out .= '    subject: ' . wp_strip_all_tags( (string) ( $step->workflow_notification_subject ?? '' ) ) . PHP_EOL;
                        $out .= '    message: ' . wp_strip_all_tags( (string) ( $step->workflow_notification_message ?? '' ) ) . PHP_EOL;
                        $out .= str_repeat( '-', 40 ) . PHP_EOL;
                    }
                }
            }
        }
        return $out;
    };

    // Export runner closure.
    $do_export = static function ( $form_ids, $include_details = [] ) use ( $render_form_txt ) {
        if ( empty( $form_ids ) || ! is_array( $form_ids ) ) {
            return;
        }
        $forms = RGFormsModel::get_form_meta_by_id( $form_ids );
        if ( empty( $forms ) || ! is_array( $forms ) ) {
            GFCommon::add_error_message( 'No forms found to export.' );
            return;
        }

        $defaults        = [
                'field_ids'         => false,
                'admin_labels'      => false,
                'choices'           => false,
                'conditional_logic' => false,
                'validation_rules'  => false,
                'entry_count'       => false,
                'form_settings'     => false,
        ];
        $include_details = wp_parse_args( (array) $include_details, $defaults );

        $context  = [
                'form_ids' => array_map( 'absint', $form_ids ),
        ];
        $ext      = 'txt';
        $filename = sprintf( 'gravityforms_export_%s.%s', ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d-H-i-s' ) : date( 'Y-m-d-H-i-s' ) ), $ext ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $filename = sanitize_file_name( $filename );
        $filename = apply_filters( 'bld_gf_text_export_filename', $filename, $context );
        $filename = sanitize_file_name( $filename );

        // If multiple forms are selected, generate a ZIP with one TXT per form.
        if ( count( $forms ) > 1 && class_exists( 'ZipArchive' ) ) {
            $tmp_zip = tempnam( sys_get_temp_dir(), 'gfexp_' );
            $zip     = new ZipArchive();
            if ( true === $zip->open( $tmp_zip, ZipArchive::OVERWRITE ) || true === $zip->open( $tmp_zip, ZipArchive::CREATE ) ) {
                foreach ( $forms as $form ) {
                    $per_name = ( isset( $form['title'] ) && '' !== $form['title'] ? $form['title'] : 'form-' . ( $form['id'] ?? 'unknown' ) );
                    $per_name = sanitize_file_name( $per_name . '-' . ( $form['id'] ?? '' ) . '.txt' );
                    $body     = $render_form_txt( $form, $include_details );
                    $zip->addFromString( $per_name, $body );
                }
                $zip->close();
                header( 'Content-Description: File Transfer' );
                header( 'Content-Type: application/zip' );
                header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( str_replace( '.txt', '.zip', $filename ) ) );
                header( 'Content-Length: ' . filesize( $tmp_zip ) );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Needed to stream a temp file download efficiently.
                readfile( $tmp_zip );
                wp_delete_file( $tmp_zip );
                exit;
            }
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional raw output for file download (headers + stream)
        header( 'Content-Description: File Transfer' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
        echo $render_form_txt( $forms[0], $include_details );
        exit;
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    };

    $prepare_export = static function ( $selected_forms ) use ( $do_export ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing --already checked
        $include_raw = isset( $_POST['gf_include'] ) && is_array( $_POST['gf_include'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['gf_include'] ) ) : [];
        $include     = [
                'field_ids'         => ! empty( $include_raw['field_ids'] ),
                'admin_labels'      => ! empty( $include_raw['admin_labels'] ),
                'choices'           => ! empty( $include_raw['choices'] ),
                'conditional_logic' => ! empty( $include_raw['conditional_logic'] ),
                'validation_rules'  => ! empty( $include_raw['validation_rules'] ),
                'entry_count'       => ! empty( $include_raw['entry_count'] ),
                'form_settings'     => ! empty( $include_raw['form_settings'] ),
        ];
        $do_export( $selected_forms, $include );
    };

    // Render custom export page content.
    add_action(
            'gform_export_page_export_forms_as_text',
            static function () use ( $do_export, $prepare_export ) {
                if ( true !== GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                    GFCommon::add_error_message( 'You do not have permission to access this page' );
                    wp_safe_redirect( admin_url( 'admin.php?page=gf_export' ) );
                    exit;
                }

                // Handle form submission first to stream the file before page output.
                if ( isset( $_POST['export_forms_as_text'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing --about to check
                    check_admin_referer( 'gf_export_forms_as_text', 'gf_export_forms_as_text_nonce' );
                    $selected_forms = [];
                    if ( isset( $_POST['gf_form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing --already checked
                        $raw_ids        = (array) wp_unslash( $_POST['gf_form_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- about to sanitize and already checked
                        $selected_forms = array_map( 'absint', $raw_ids );
                    }
                    if ( empty( $selected_forms ) ) {
                        GFCommon::add_error_message( 'Please select the forms to be exported' );
                    } else {
                        $prepare_export( $selected_forms );
                        return;
                    }
                }

                GFExport::page_header();
                ?>
                <script type="text/javascript">
                    (function( $ ){
                        $( document ).on( 'click keypress', '#gf_export_forms_all', function( e ) {
                            const checked = e.target.checked,
                                label = $('label[for="gf_export_forms_all"]'),
                                formList = $('#export_form_list');
                            label.find( 'strong' ).html( checked ? label.data( 'deselect' ) : label.data( 'select' ) );
                            $( 'input[name]', formList ).prop( 'checked', checked );
                        } );
                    }( jQuery ));
                </script>
                <div class="gform-settings__content">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="gform_export_as_text" class="gform_settings_form">
                        <?php wp_nonce_field( 'gf_export_forms_as_text', 'gf_export_forms_as_text_nonce' ); ?>
                        <input type="hidden" name="action" value="bld_export_forms_as_text" />
                        <div class="gform-settings-panel gform-settings-panel--full">
                            <header class="gform-settings-panel__header"><legend class="gform-settings-panel__title">Export Forms</legend></header>
                            <div class="gform-settings-panel__content">
                                <div class="gform-settings-description">
                                    Select the forms you would like to export and choose export options. A file will be generated for you to save to your computer.
                                </div>
                                <table class="form-table">
                                    <tr style="vertical-align:top;">
                                        <th scope="row">
                                            <label for="export_fields">Select Forms</label> <?php gform_tooltip( 'export_select_forms' ); ?>
                                        </th>
                                        <td>
                                            <ul id="export_form_list">
                                                <li>
                                                    <input type="checkbox" id="gf_export_forms_all" />
                                                    <label for="gf_export_forms_all" data-deselect="Deselect All" data-select="Select All">Select All</label>
                                                </li>
                                                <?php
                                                $forms = RGFormsModel::get_forms();
                                                foreach ( $forms as $form ) {
                                                    ?>
                                                    <li>
                                                        <input type="checkbox" name="gf_form_id[]" id="gf_form_id_<?php echo absint( $form->id ); ?>" value="<?php echo absint( $form->id ); ?>" />
                                                        <label for="gf_form_id_<?php echo absint( $form->id ); ?>"><?php echo esc_html( $form->title ); ?></label>
                                                    </li>
                                                    <?php
                                                }
                                                ?>
                                            </ul>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Include Details</th>
                                        <td>
                                            <label><input type="checkbox" name="gf_include[field_ids]" value="1" /> Field IDs <?php gform_tooltip( 'bld_include_field_ids' ); ?></label><br />
                                            <label><input type="checkbox" name="gf_include[admin_labels]" value="1" /> Admin Labels <?php gform_tooltip( 'bld_include_admin_labels' ); ?></label><br />
                                            <label><input type="checkbox" name="gf_include[choices]" value="1" /> Field Choices <?php gform_tooltip( 'bld_include_choices' ); ?></label><br />
                                            <label><input type="checkbox" name="gf_include[conditional_logic]" value="1" /> Conditional Logic <?php gform_tooltip( 'bld_include_conditional_logic' ); ?></label><br />
                                            <label><input type="checkbox" name="gf_include[validation_rules]" value="1" /> Validation Rules <?php gform_tooltip( 'bld_include_validation_rules' ); ?></label><br />
                                            <label><input type="checkbox" name="gf_include[entry_count]" value="1" /> Entry Count <?php gform_tooltip( 'bld_include_entry_count' ); ?></label><br />
                                            <label><input type="checkbox" name="gf_include[form_settings]" value="1" /> Form Settings <?php gform_tooltip( 'bld_include_form_settings' ); ?></label>
                                        </td>
                                    </tr>
                                </table>
                                <br /><br />
                                <input type="submit" value="Download Export File" name="export_forms_as_text" class="button large primary" />
                            </div>
                        </div>
                    </form>
                </div>
                <?php
                GFExport::page_footer();
            }
    );

    // Register menu tab in Export screen.
    add_filter(
            'gform_export_menu',
            static function ( $setting_tabs ) {
                if ( true === GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                    $icon               = '<svg width="24" height="24" role="presentation" focusable="false"  viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><title>export form</title><g fill="none" class="nc-icon-wrapper"><path d="M5 3.75h14c.69 0 1.25.56 1.25 1.25v14c0 .69-.56 1.25-1.25 1.25H5c-.69 0-1.25-.56-1.25-1.25V5c0-.69.56-1.25 1.25-1.25z" stroke="#111111" stroke-width="1.5"/><path d="M9 4L5 8.5V4h4z" fill="#111111" stroke="#111111"/><path d="M15.286 11L12 8l-3 3" stroke="#111111" stroke-width="1.5"/><path fill="#111111" d="M11 9h2v8h-2z"/></g></svg>';
                    $setting_tabs['40'] = [
                            'name'  => 'export_forms_as_text',
                            'label' => 'Export Forms As Text',
                            'icon'  => $icon,
                    ];
                }
                return $setting_tabs;
            }
    );

    // Tooltips for Include Details options.
    add_filter(
            'gform_tooltips',
            static function ( $tooltips ) {
                $tooltips['bld_include_field_ids']         = 'Include the internal Field IDs next to each field label.';
                $tooltips['bld_include_admin_labels']      = 'Include Admin Labels (if set) alongside public labels.';
                $tooltips['bld_include_choices']           = 'Include field choice lists (text/value/default) for fields that support choices (e.g., Radio, Checkbox, Select).';
                $tooltips['bld_include_conditional_logic'] = 'Include summaries of conditional logic rules for fields and confirmations (only shown where logic is configured).';
                $tooltips['bld_include_validation_rules']  = 'Include field validation settings such as required, maxLength, and numeric ranges (when defined).';
                $tooltips['bld_include_entry_count']       = 'Include the current number of entries for each form.';
                $tooltips['bld_include_form_settings']     = 'Include select form settings such as label placement and description.';
                return $tooltips;
            }
    );

    // Admin-post handler for exporting forms as text.
    add_action(
            'admin_post_bld_export_forms_as_text',
            static function () use ( $do_export, $prepare_export ) {
                // Capability guard: mirrors GF Export Forms tab capability.
                if ( ! class_exists( 'GFCommon' ) || true !== GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                    header( 'HTTP/1.1 403 Forbidden' );
                    header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
                    echo 'You do not have permission to perform this export.';
                    exit;
                }

                // Nonce check.
                check_admin_referer( 'gf_export_forms_as_text', 'gf_export_forms_as_text_nonce' );

                $selected_forms = [];
                if ( isset( $_POST['gf_form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing --already checked
                    $raw_ids        = (array) wp_unslash( $_POST['gf_form_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- about to sanitize and already checked
                    $selected_forms = array_map( 'absint', $raw_ids );
                }
                if ( empty( $selected_forms ) ) {
                    header( 'HTTP/1.1 400 Bad Request' );
                    header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
                    echo 'Please select at least one form to export.';
                    exit;
                }

                $prepare_export( $selected_forms );
                exit;
            }
    );
} )();
