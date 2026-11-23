<?php
/**
 * Enable the Gravity Forms field label visibility setting
 *
 * @gravityforms
 *
 * GOAL:
 * Turns on the Field Label Visibility option in the form editor, so you can hide the label of
 * an individual field while keeping it for screen readers.
 *
 * USAGE:
 * Edit a field, open its Appearance settings and choose a Field Label Visibility.
 */

add_filter( 'gform_enable_field_label_visibility_settings', '__return_true' );
