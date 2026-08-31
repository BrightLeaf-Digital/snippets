<?php
/**
 * GravityOps Search special merge tag for GravityMath
 *
 * @gravityops
 * @gravityview
 *
 * GOAL:
 * This snippet creates a special merge tag syntax for GravityMath shortcodes. This is necessary
 * when nesting GravityMath shortcodes inside a GravityOps Search
 * (https://brightleafdigital.io/gravityops-search/) display attribute. See here
 * (https://brightleafdigital.io/docs/nesting-shortcodes/#1-toc-title) for more info. The syntax
 * for the new merge tags is as follows:
 *
 * - It needs to be wrapped in `~` before and after the merge tag
 * - It needs the `gos` prefix after the first `~`
 * - Each section of the merge tag can be broken up with one or two `.` or `_` characters
 *
 * It looks like this `~gos__FIELD_ID__modifier~ ~gos.FIELD_ID.modifier~ ~gos__FIELD_ID.modifier~`
 * For example `~gos__3.2__sum~` becomes `{:3.2:sum}`
 */

add_filter( 'gravityview/math/shortcode/before', function ( $formula ) {
	preg_match_all( '/~gos[._]{1,2}(\d+(?:\.\d+)*)[._]{1,2}([a-z_]+)~/', $formula, $matches, PREG_SET_ORDER );

	foreach ( $matches as $match ) {
		$field_id  = $match[1];
		$modifier  = $match[2];
		$replacement = sprintf( '{:%s:%s}', $field_id, $modifier );

		$formula = str_replace( $match[0], $replacement, $formula );
	}

	return $formula;
}, 9 );
