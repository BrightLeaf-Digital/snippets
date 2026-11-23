<?php
/**
 * Scroll to the Gravity Forms confirmation message
 *
 * @gravityforms
 *
 * GOAL:
 * After a submission, scrolls the page to the confirmation message instead of leaving the
 * visitor at the top of the page. Useful when a form sits low on a long page.
 *
 * NOTES:
 * - Applies to every form. For one form only, use `gform_confirmation_anchor_{form_id}` in
 *   place of `gform_confirmation_anchor`, for example `gform_confirmation_anchor_5`.
 */

add_filter( 'gform_confirmation_anchor', '__return_true' );
