<?php
/**
 * Embed Form in Admin Dashboard Widget
 *
 * @wordpress
 * @gravityforms
 *
 * GOAL:
 * This snippet adds a widget to your admin dashboard and embeds your selected form in it. Works
 * well with many add ons such as Nested Forms and Populate Anything and Live Merge Tags. Displays
 * a custom confirmation with a link to the entry (opened in a new tab). Can easily reload the form
 * after submissions. Triggers feeds and notifications and anything a regular form submission
 * would.
 */

add_action(
    'wp_dashboard_setup',
    function () {
        // Only add the widget for users with appropriate capability.
        if ( ! current_user_can( 'gform_full_access' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'bl_embed_form_94', // id
            'Embedded Form',       // title
            function () {
                $forms = GFAPI::get_forms();
                $forms = array_map(
                    fn( $form ) => [
                        'id'    => $form['id'],
                        'title' => $form['title'],
                    ],
                    $forms
                );

                $selected_form_id = intval( rgget( 'bld_embed_form_id' ) );

                // APC has an unsafe get_current_screen() usage during admin-ajax requests.
                // Disable GF AJAX in this widget when APC is active to avoid admin-ajax.php submissions.
                $disable_ajax = class_exists( 'GF_Advanced_Post_Creation' ) || defined( 'GF_ADVANCEDPOSTCREATION_VERSION' );
                $ajax         = ! $disable_ajax;

                ?>
                <div class="embed-form-widget">
                    <form method="get" action="<?= esc_url( admin_url( 'index.php' ) ); ?>">
                        <input type="hidden" name="bld_embed_form" value="">
                        <label>
                            Select Form to Embed
                            <select name="bld_embed_form_id" class="widefat">
                                <?php foreach ( $forms as $form ) : ?>
                                    <option value="<?= esc_attr( $form['id'] ); ?>"<?= selected( $selected_form_id, $form['id'], false ); ?>>
                                        <?= esc_html( $form['title'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="button button-primary" type="submit" id="embed-form-button"><?php echo $selected_form_id ? 'Reload Form' : 'Embed Selected Form'; ?></button>
                    </form>
                </div>
                <?php

                // Render the form (title/description disabled, AJAX enabled).
                // gravity_form( $id, $display_title, $display_description, $display_inactive, $field_values, $ajax )
                if ( $selected_form_id ) {
                    gravity_form( $selected_form_id, false, false, false, null, $ajax );
                }
            }
        );
    }
);

// Enqueue the necessary GF scripts/styles on the Dashboard screen.
add_action(
    'admin_enqueue_scripts',
    function ( $hook ) {
        if ( 'index.php' !== $hook ) { // Dashboard screen only
            return;
        }

        $form_id = intval( rgget( 'bld_embed_form_id' ) );
        if ( ! $form_id ) {
            return;
        }

        $disable_ajax = class_exists( 'GF_Advanced_Post_Creation' ) || defined( 'GF_ADVANCEDPOSTCREATION_VERSION' );
        $ajax         = ! $disable_ajax;

        gravity_form_enqueue_scripts( $form_id, $ajax );
    }
);

add_filter(
    'gform_confirmation',
    function ( $confirmation, $form, $entry ) {
        // Only in wp-admin Dashboard and when our widget is active for a form
        if ( ! is_admin() || 'index.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
            return $confirmation;
        }

        $form_id = intval( rgget( 'bld_embed_form_id' ) );
        if ( $form_id && (int) $form['id'] === $form_id ) {
            $entry_id  = (int) rgar( $entry, 'id' );
            $entry_url = admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%d&lid=%d', $form_id, $entry_id ) );

            // Return a specific confirmation message (no need for a form field)
            return sprintf(
                '<div class="notice notice-success bl-embed-form-confirm">Submitted. View entry <a href="%s" target="_blank">#%d</a>.</div>',
                esc_url( $entry_url ),
                $entry_id
            );

            // Or, if you prefer a redirect back to the Dashboard with context:
            // return [ 'redirect' => add_query_arg( [ 'bld_embed_form_id' => $form_id, 'bld_entry' => $entry_id ], admin_url( 'index.php' ) ) ];
        }

        return $confirmation;
    },
    10,
    4
);