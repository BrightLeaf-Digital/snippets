<?php
/**
 * GoGetThis Merge Tag
 * Global {gogetthis:param} merge tag for URL parameters.
 *
 * Usage: {gogetthis:coupon} → value of ?coupon= in the URL
 */

if ( ! function_exists( 'bl_gogetthis_replace_tags' ) ) {
	/**
	 * Replaces placeholders in the given text with sanitized `$_GET` parameter values if they exist.
	 *
	 * This function looks for placeholders in the format `{gogetthis:parameter_name}` within the given text.
	 * It replaces them with the corresponding value from the `$_GET` global if the parameter exists,
	 * otherwise leaves the placeholders unchanged.
	 *
	 * @param string $text The input text containing placeholders to be replaced.
	 *
	 * @return string The processed text with placeholders replaced, or the original text if no replacements were made.
	 */
	function bl_gogetthis_replace_tags( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}

		// Quick bail if tag is not present
		if ( false === strpos( $text, '{gogetthis:' ) ) {
			return $text;
		}

		return preg_replace_callback(
			'/\{gogetthis:([^}]+)\}/',
			function ( $matches ) {
				$param = trim( $matches[1] );

				// If parameter name is empty → empty string
				if ( '' === $param ) {
					return '';
				}

				$raw = sanitize_text_field( wp_unslash( $_GET[ $param ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
				// If URL doesn't contain this parameter → empty string
				if ( empty( $raw ) ) {
					return '';
				}

				// Parameter exists → return sanitized value
				if ( is_array( $raw ) ) {
					$raw = implode( ',', $raw );
				}

				return sanitize_text_field( $raw );
			},
			$text
		);
	}
}

/**
 * 1) Apply in standard WordPress content contexts.
 */
add_filter( 'the_content', 'bl_gogetthis_replace_tags', 12 );
add_filter( 'the_excerpt', 'bl_gogetthis_replace_tags', 12 );
add_filter( 'widget_text', 'bl_gogetthis_replace_tags', 12 ); // Classic text widgets

// If you're using block widgets and want extra safety, you can also do:
add_filter( 'widget_block_content', 'bl_gogetthis_replace_tags', 12 );

/**
 * 2) Apply in Gravity Forms merge tag processing.
 *
 * This runs before GF replaces its own merge tags, so {gogetthis:*}
 * will work in:
 *  - field default values
 *  - confirmations
 *  - notifications
 *  - HTML fields, etc.
 */
add_filter( 'gform_pre_replace_merge_tags', 'bl_gogetthis_replace_tags', 10, 1 );

/**
 * 3) Apply in GravityView field output.
 *
 * This lets you use {gogetthis:*} in GravityView Custom Content fields
 * and any other field output that passes through GravityView’s renderer.
 */
add_filter( 'gravityview_field_output', 'bl_gogetthis_replace_tags', 12, 1 );
