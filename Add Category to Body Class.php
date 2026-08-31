<?php
/**
 * Add Category to Body Class
 *
 * @wordpress
 *
 * GOAL:
 * Adds category slugs as CSS classes to the body tag on single posts and category archives,
 * enabling category-specific styling without custom templates.
 *
 * CONFIGURATION:
 * - None - works out of the box
 * - Optional: Add CSS rules targeting `.category-{slug}` in your theme stylesheet
 *
 * USAGE:
 * - Access in CSS as: `body.category-news { /* styles * / }`
 * - Example output on single post in "News" category: `<body class="single postid-123
 *   category-news">`
 *
 * NOTES:
 * - Only applies to single posts and category archives
 * - Multiple categories will add multiple classes
 * - Uses category slug (not name), e.g., "category-web-design" not "category-Web Design"
 */

/**
 * Add category slug classes to body tag.
 *
 * @param array $classes Existing body classes.
 * @return array Modified body classes with category slugs.
 */
add_filter(
	'body_class',
	function ( $classes ) {
		$category_classes = [];

		if ( is_single() ) {
			global $post;
			$post_id = is_object( $post ) && isset( $post->ID ) ? $post->ID : 0;
			if ( $post_id ) {
				$categories = get_the_category( $post_id );
				if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
					foreach ( $categories as $category ) {
						if ( isset( $category->slug ) ) {
							$category_classes[] = 'category-' . $category->slug;
						}
					}
				}
			}
		} elseif ( is_category() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->slug ) ) {
				$category_classes[] = 'category-' . $term->slug;
			}
		}

		if ( ! empty( $category_classes ) ) {
			$category_classes = array_unique( array_map( 'sanitize_html_class', $category_classes ) );
			$classes          = array_merge( $classes, $category_classes );
		}

		return $classes;
	}
);
