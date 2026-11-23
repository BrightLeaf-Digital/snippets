<?php
/**
 * Disable the Gravity Forms form view counter
 *
 * @gravityforms
 *
 * GOAL:
 * Stops Gravity Forms writing to the database every time a form is viewed, which saves a query
 * per page view on busy sites.
 *
 * NOTES:
 * - The Views and Conversion columns on the Forms list stop updating.
 */

add_filter( 'gform_disable_view_counter', '__return_true' );
