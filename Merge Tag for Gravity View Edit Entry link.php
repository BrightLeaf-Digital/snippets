<?php
/**
 * Merge Tag for Gravity View Edit Entry link
 *
 * @gravityview
 * @gravityforms
 *
 * GOAL:
 * Create a custom merge tag that outputs a **secure GravityView Edit Entry link** for the current
 * entry when displayed inside a GravityView context.
 *
 * The snippet registers the merge tags:
 *
 *     {gview_edit_entry_link}
 *     {gview_edit_entry_link:Link Text}
 *
 * These tags generate an edit link to the current entry within the active GravityView.
 *
 * USAGE:
 *     {gview_edit_entry_link:Your Link Text}
 *
 *     {gview_edit_entry_link:Update your submission}
 *
 * # Default Behavior
 *
 * If no link text is provided, the merge tag will automatically use: Edit Entry
 *
 * # Where It Works
 *
 * The merge tag works in any content processed through Gravity Forms merge tags, including:
 *
 * - GravityView **Custom Content fields**
 * - Gravity Forms **confirmations**
 * - Gravity Forms **notifications**
 * - Any text processed through `gform_replace_merge_tags`
 *
 * FEATURES:
 * - Generates a **GravityView Edit Entry link** for the current entry.
 * - Uses the **current View ID automatically** (no configuration required).
 * - Ensures the link is only displayed if the **current user has permission to edit the entry**.
 * - Supports custom link text.
 *
 * # Customizing the Link Text
 *
 * You can customize the text displayed in the edit link by adding text after the merge tag using a
 * colon.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replace the custom merge tag in any GF/GV-processed text (including GravityView Custom Content fields).
 */
add_filter(
	'gform_replace_merge_tags',
	function ( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {

		// Fast exit if tag not present.
		if ( false === strpos( $text, '{gview_edit_entry_link' ) ) {
			return $text;
		}

		// GravityView must be active for link generation.
		if ( ! class_exists( 'GravityView_Edit_Entry' ) || ! class_exists( 'GravityView_View' ) ) {
			return $text;
		}

		// Need a valid entry to build the link.
		if ( empty( $entry ) || empty( $entry['id'] ) ) {
			return $text;
		}

		// Only output for users who can edit this entry (avoid leaking edit URLs).
		if ( method_exists( 'GravityView_Edit_Entry', 'check_user_cap_edit_entry' ) ) {
			if ( ! GravityView_Edit_Entry::check_user_cap_edit_entry( $entry ) ) {
				// Remove the tag(s) cleanly.
				return preg_replace( '/\{gview_edit_entry_link(?::[^}]*)?\}/', '', $text );
			}
		}

		// Get current View ID (GravityView sets a singleton during rendering).
		$view_id = 0;
		$gv_view = GravityView_View::getInstance();
		if ( $gv_view && method_exists( $gv_view, 'getViewId' ) ) {
			$view_id = $gv_view->getViewId();
		}

		// If we cannot determine the View ID, fail closed (no link).
		if ( $view_id <= 0 ) {
			return preg_replace( '/\{gview_edit_entry_link(?::[^}]*)?\}/', '', $text );
		}

		// Replace ALL occurrences, supporting optional :Link Text.
		return preg_replace_callback(
			'/\{gview_edit_entry_link(?::([^}]+))?\}/',
			function ( $m ) use ( $entry, $view_id, $esc_html, $format ) {

				$link_text = isset( $m[1] ) && '' !== $m[1] ? trim( $m[1] ) : 'Edit Entry';

				// Build the secure Edit Entry URL (includes nonce).
				$href = GravityView_Edit_Entry::get_edit_link( $entry, $view_id );

				if ( empty( $href ) ) {
					return '';
				}

				// Respect context. In "url" contexts, return only the URL.
				if ( 'url' === $format ) {
					return $href;
				}

				// Escape output safely.
				$href_esc = esc_url( $href );

				// For HTML contexts, allow link text as plain text only.
				$text_esc = $esc_html ? esc_html( $link_text ) : wp_kses_post( $link_text );

				return sprintf(
					'<a class="gv-edit-entry-link" href="%s" rel="nofollow">%s</a>',
					$href_esc,
					$text_esc
				);
			},
			$text
		);
	},
	10,
	7
);

/**
 * Optional: Add the merge tag to Gravity Forms merge-tag dropdowns.
 * (This only affects GF UIs; GravityView Custom Content fields usually accept manual tags anyway.)
 */
add_filter(
	'gform_custom_merge_tags',
	function ( $merge_tags ) {

		$merge_tags[] = [
			'label' => 'GravityView Edit Entry Link',
			'tag'   => '{gview_edit_entry_link}',
		];

		return $merge_tags;
	}
);
