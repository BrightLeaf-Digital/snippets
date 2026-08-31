<?php
/**
 * Correct Gravity View Links to Work with Tabs
 *
 * @gravityview
 *
 * GOAL:
 * This code snippet ensures that GravityView links correctly navigate back to the appropriate tab
 * when using tabbed navigation (such as those created with the Spectra/UAGB Tabs block). It
 * modifies GravityView back links, entry links, search form actions, and edit links to maintain
 * tab-specific navigation, preventing users from losing their place when interacting with
 * GravityView entries.
 *
 * CONFIGURATION:
 * - `$config_views` Map each GravityView ID to its base URL and tab anchor. Example: `341 => [
 *   'base' => 'https://example.com/dashboard/adminparents/', 'anchor' => '#uagb-tabs__tab0' ],`
 * - `$preserve_query`: Set to false to strip query strings when applying anchors.
 *
 * FEATURES:
 * - **Preserves tab navigation** by appending the correct tab ID to GravityView links.
 * - **Modifies back links** to return users to the correct tab within a tabbed interface.
 * - **Ensures entry links open** in the correct tab instead of defaulting to the first one.
 * - **Updates search form actions** to retain the correct tab after filtering results.
 * - **Adjusts edit links** to keep users within the correct tab context.
 * - **Customizable for multiple views** by mapping view IDs to specific tab hashes.
 */

( static function () {
    // === Configuration: View → { base, anchor } (edit these) ====================
    $config_views   = [
            341  => [
                    'base'   => 'https://example.com/dashboard/adminparents/',
                    'anchor' => '#uagb-tabs__tab0',
            ],
            1103 => [
                    'base'   => 'https://example.com/dashboard/adminparents/',
                    'anchor' => '#uagb-tabs__tab1',
            ],
            1250 => [
                    'base'   => 'https://example.com/dashboard/adminparents/',
                    'anchor' => '#uagb-tabs__tab2',
            ],
            230  => [
                    'base'   => 'https://example.com/dashboard/adminstudents/',
                    'anchor' => '#uagb-tabs__tab0',
            ],
            1101 => [
                    'base'   => 'https://example.com/dashboard/adminstudents/',
                    'anchor' => '#uagb-tabs__tab1',
            ],
            1257 => [
                    'base'   => 'https://example.com/dashboard/adminstudents/',
                    'anchor' => '#uagb-tabs__tab2',
            ],
    ];
    $preserve_query = true; // Set to false to strip query strings when applying anchors.
    // ==========================================================================

    $with_anchor = static function ( $url, $anchor ) use ( $preserve_query ) {
        $url    = (string) $url;
        $anchor = (string) $anchor;
        if ( '' === $anchor ) {
            return $url;
        }
        // Remove existing fragment.
        $hash_pos = strpos( $url, '#' );
        if ( false !== $hash_pos ) {
            $url = substr( $url, 0, $hash_pos );
        }
        // Optionally preserve query string.
        if ( false === $preserve_query ) {
            // Strip any query.
            $q_pos = strpos( $url, '?' );
            if ( false !== $q_pos ) {
                $url = substr( $url, 0, $q_pos );
            }
        }
        return $url . $anchor;
    };

    $resolve_view_id = static function ( $context ) {
        if ( isset( $context->view->ID ) && is_object( $context ) && is_object( $context->view ) ) {
            return (int) $context->view->ID;
        }
        return 0;
    };

    // Back link URL (uses the configured base + anchor).
    add_filter(
            'gravityview/template/links/back/url',
            static function ( $href, $context ) use ( $config_views, $with_anchor, $resolve_view_id ) {
                $view_id = $resolve_view_id( $context );
                if ( 0 !== $view_id && isset( $config_views[ $view_id ] ) ) {
                    $base   = (string) $config_views[ $view_id ]['base'];
                    $anchor = (string) $config_views[ $view_id ]['anchor'];
                    // Preserve any query from the original $href when constructing back link.
                    $query = '';
                    $q_pos = strpos( (string) $href, '?' );
                    if ( false !== $q_pos ) {
                        $query = substr( (string) $href, $q_pos );
                    }
                    return $with_anchor( $base . $query, $anchor );
                }
                return $href;
            },
            10,
            2
    );

    // Entry Link (adds/replaces fragment on the entry link markup output)
    add_filter(
            'gravityview/template/field/entry_link',
            static function ( $output, $permalink, $context ) use ( $config_views, $with_anchor, $resolve_view_id ) {
                $view_id = $resolve_view_id( $context );
                if ( 0 !== $view_id && isset( $config_views[ $view_id ] ) ) {
                    $anchor        = (string) $config_views[ $view_id ]['anchor'];
                    $new_permalink = $with_anchor( $permalink, $anchor );
                    $output        = str_replace( $permalink, $new_permalink, $output );
                }
                return $output;
            },
            10,
            3
    );

    // Search form action (append correct anchor based on view id present in URL fragment)
    add_filter(
            'gravityview/widget/search/form/action',
            static function ( $url ) use ( $config_views, $with_anchor ) {
                if ( ! is_string( $url ) || '' === $url ) {
                    return $url;
                }
                // Try to detect view id from existing fragment '#gv-view-<id>'
                $view_id = 0;
                if ( 1 === preg_match( '/#gv-view-(\d+)/', $url, $m ) ) {
                    $view_id = (int) $m[1];
                }
                if ( 0 !== $view_id && isset( $config_views[ $view_id ] ) ) {
                    $anchor = (string) $config_views[ $view_id ]['anchor'];
                    $url    = $with_anchor( $url, $anchor );
                }
                return $url;
            },
            11
    );

    // Edit link (best-effort: detect view id in URL; if found, apply anchor)
    add_filter(
            'gravityview/edit/link',
            static function ( $url ) use ( $config_views, $with_anchor ) {
                if ( ! is_string( $url ) || '' === $url ) {
                    return $url;
                }
                $view_id = 0;
                // Try query parameter first.
                $qs = [];
                $qp = strpos( $url, '?' );
                if ( false !== $qp ) {
                    parse_str( (string) substr( $url, $qp + 1 ), $qs );
                    if ( isset( $qs['view_id'] ) ) {
                        $view_id = (int) $qs['view_id'];
                    }
                }
                // Fallback to fragment pattern
                if ( 0 === $view_id && 1 === preg_match( '/#gv-view-(\d+)/', $url, $m ) ) {
                    $view_id = (int) $m[1];
                }
                if ( 0 !== $view_id && isset( $config_views[ $view_id ] ) ) {
                    $anchor = (string) $config_views[ $view_id ]['anchor'];
                    $url    = $with_anchor( $url, $anchor );
                }
                return $url;
            },
            11
    );
} )();
