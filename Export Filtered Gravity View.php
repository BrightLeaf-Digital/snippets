<?php
/* phpcs:disable WordPress.Files.FileName */
/**
 * Export Filtered Gravity View
 *
 * @gravityview
 * @gravityforms
 *
 * GOAL:
 * Use the simple shortcode [export_filtered_entries] to render a button that triggers a CSV
 * download of the entire GravityView dataset matching the current filters/search.
 *
 * REQUIREMENTS:
 * - GravityView and Gravity Forms active.
 * - The shortcode should be used on a page where a GravityView is present, or provide
 *   view_id/form_id via attributes.
 *
 * NOTES:
 * **For filtered exports, the shortcode MUST be placed on the same page as the GravityView. If
 * placed elsewhere, it will export all entries without filters.** **USAGE EXAMPLES**
 *
 * - Basic (auto-detects current View): [export_filtered_entries]
 * - With custom label: [export_filtered_entries label="Download All Entries"]
 * - Specific View by ID: [export_filtered_entries view_id="123"]
 * - Tab-delimited with custom filename: [export_filtered_entries delimiter="\t"
 *   filename="my_export"]
 *
 * # SHORTCODE ATTRIBUTES (all optional)
 *
 * - `view_id`: GravityView post ID (defaults to detected current View when used inside a View).
 * - `form_id`: Gravity Forms form ID (defaults to the View’s connected form when detectable).
 * - `filename`: Base filename without extension (default: filtered_entries).
 * - `delimiter`: CSV delimiter character (default: ,). Allowed: ",", ";", "\t" (tab), "|".
 * - `label`: Button text (default: Export Filtered View).
 *
 * - Exports exactly what the GravityView Table layout renders across all pages; HTML is stripped.
 * - If a cell’s output is obfuscated in the DOM (e.g., email), the exporter substitutes the raw
 *   Gravity Forms value when possible.
 */

use GV\Frontend_Request;
use GV\View_Renderer;

( static function () {
    // Admin-post handlers (logged-in and public).
    $action       = 'bld_export_filtered_view_csv';
    $nonce_action = 'bld_export_filtered_view_nonce';

    // Per-snippet export chunk size (rows per rendered page). Adjust if needed.
    if ( ! defined( 'BLD_GV_EXPORT_CHUNK_SIZE' ) ) {
        define( 'BLD_GV_EXPORT_CHUNK_SIZE', 250 );
    }

    $handler = static function () use ( $action, $nonce_action ) {
        // Basic presence check with sanitized, unslashed nonce.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
            header( 'HTTP/1.1 400 Bad Request' );
            echo 'Invalid request.';
            exit;
        }

        $view_id   = isset( $_POST['bld_view_id'] ) ? absint( $_POST['bld_view_id'] ) : 0;
        $form_id   = isset( $_POST['bld_form_id'] ) ? absint( $_POST['bld_form_id'] ) : 0;
        $base_name = isset( $_POST['bld_filename'] ) && is_string( $_POST['bld_filename'] ) ? sanitize_text_field( wp_unslash( $_POST['bld_filename'] ) ) : 'filtered_entries';
        $delimiter = isset( $_POST['bld_delimiter'] ) && is_string( $_POST['bld_delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['bld_delimiter'] ) ) : ',';
        $query_raw = isset( $_POST['bld_query'] ) && is_string( $_POST['bld_query'] ) ? sanitize_text_field( wp_unslash( $_POST['bld_query'] ) ) : '';

        $base_name = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $base_name );
        $base_name = ltrim( (string) $base_name, '.' );

        // Normalize and validate delimiter
        $allowed_delims = [ ',', ';', "\t", '|' ];
        $delimiter      = ( '\t' === $delimiter ) ? "\t" : $delimiter;
        $delimiter      = in_array( $delimiter, $allowed_delims, true ) ? $delimiter : ',';

        // Resolve View/Form if possible using GravityView context when not provided.
        if ( ( ! $view_id || ! $form_id ) && class_exists( 'GravityView_View' ) ) {
            $gv_legacy = GravityView_View::getInstance();
            if ( ! $view_id ) {
                $view_id = absint( $gv_legacy->getViewId() ); }
            if ( ! $form_id ) {
                $form_id = absint( $gv_legacy->getFormId() ); }
        }
        if ( ! $form_id && function_exists( 'gravityview_get_form_id' ) && $view_id ) {
            $form_id = absint( gravityview_get_form_id( $view_id ) );
        }
        if ( ! $view_id || ! $form_id ) {
            header( 'HTTP/1.1 400 Bad Request' );
            echo 'Missing view or form context.';
            exit;
        }

        // Temporarily reconstruct GET params from the page where the button was clicked to preserve GV filters.
        if ( is_string( $query_raw ) && '' !== $query_raw ) {
            parse_str( ltrim( $query_raw, '?' ), $query_vars );
            if ( is_array( $query_vars ) ) {
                // Limit to scalar/simple arrays to avoid odd injections.
                $_GET = [];
                foreach ( $query_vars as $k => $v ) {
                    $k = preg_replace( '/[^A-Za-z0-9_\-\[\]]+/', '', (string) $k );
                    if ( is_array( $v ) ) {
                        $_GET[ $k ] = array_map(
                                static function ( $x ) {
                                    return is_scalar( $x ) ? (string) $x : '';
                                },
                                $v
                        );
                    } else {
                        $_GET[ $k ] = (string) $v;
                    }
                }
            }
        }

        // Build GV parameters: search, sorting; ignore paging so we fetch all.
        $parameters = [];
        if ( class_exists( 'GravityView_frontend' ) ) {
            $args       = [ 'id' => $view_id ];
            $parameters = GravityView_frontend::get_view_entries_parameters( $args, $form_id );
        } elseif ( class_exists( '\GV\View' ) ) {
            // Fallback path: minimal parameters.
            $parameters = [
				'search_criteria' => [],
				'sorting'         => null,
            ];
        }
        $search_criteria = isset( $parameters['search_criteria'] ) ? (array) $parameters['search_criteria'] : [];

        // Compute total matching entries to determine pages.
        try {
            $total_count = GFAPI::count_entries( $form_id, $search_criteria );
        } catch ( Exception $e ) {
            $total_count = 0;
        }

        // Prepare CSV response.
        $ts       = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d-H-i-s' ) : date( 'Y-m-d-H-i-s' );
        $filename = sanitize_file_name( $base_name . '-' . $ts . '.csv' );
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: text/csv; charset=' . get_option( 'blog_charset' ) );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        // BOM for Excel
        echo "\xEF\xBB\xBF";

        $csv_escape = static function ( $v ) use ( $delimiter ) {
            $s     = (string) $v;
            $s     = str_replace( '"', '""', $s );
            $needs = ( false !== strpos( $s, '"' ) || false !== strpos( $s, "\n" ) || false !== strpos( $s, $delimiter ) || false !== strpos( $s, "\r" ) );
            return $needs ? '"' . $s . '"' : $s;
        };

        $header_written   = false;
        $entries_exported = 0;
        $chunk_size       = defined( 'BLD_GV_EXPORT_CHUNK_SIZE' ) ? BLD_GV_EXPORT_CHUNK_SIZE : 250;
        $chunk_size       = max( 1, $chunk_size );
        $total_pages      = max( 1, (int) ceil( $total_count / $chunk_size ) );

        // Helper to render a specific page of the table View using modern GV API.
        $render_view = static function ( $page_num ) use ( $view_id, $chunk_size ) {
            // Ensure pagenum is set for GravityView pagination.
            $_GET['pagenum'] = (int) $page_num;
            // Initialize GravityView frontend context similar to legacy pipeline so templates have data.
            if ( class_exists( 'GravityView_frontend' ) && class_exists( 'GravityView_View_Data' ) ) {
                $gv_frontend = GravityView_frontend::getInstance();
                $gv_frontend->setGvOutputData( GravityView_View_Data::getInstance( $view_id ) );
                $gv_frontend->set_context_view_id( $view_id );
                $gv_frontend->set_entry_data();
            }
            // Render the View HTML for this page with specified page size using \GV\View_Renderer.
            if ( function_exists( 'gravityview' ) && class_exists( '\\GV\\View_Renderer' ) ) {
                $view = gravityview()->views->get( $view_id );
                if ( $view ) {
                    $view->settings->update( [ 'page_size' => $chunk_size ] );
                    $request  = new Frontend_Request();
                    $renderer = new View_Renderer();
                    ob_start();
                    $returned = $renderer->render( $view, $request );
                    $buffer   = (string) ob_get_clean();
                    $html     = '';
                    if ( is_string( $returned ) ) {
                        $html .= $returned;
                    }
                    $html .= $buffer;
                    return $html;
                }
            }
            // Fallback to deprecated function if needed.
            if ( function_exists( 'get_gravityview' ) ) {
                $atts = [
					'id'        => $view_id,
					'page_size' => $chunk_size,
                ];
                $html = get_gravityview( $view_id, $atts );
                return is_string( $html ) ? $html : '';
            }
            return '';
        };

        // Helper to parse table HTML and yield rows as arrays of cell text; the first call can return headers.
        $parse_table = static function ( $html ) use ( $form_id ) {
            $headers = [];
            $rows    = [];
            // Helper: decode Cloudflare email obfuscation hex payload.
            $decode_cf = static function ( $hex ) {
                $hex = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $hex ) );
                if ( strlen( $hex ) < 4 ) {
                    return '';
                }
                $key = hexdec( substr( $hex, 0, 2 ) );
                $out = '';
                for ( $i = 2, $l = strlen( $hex ); $i < $l; $i += 2 ) {
                    $byte = hexdec( substr( $hex, $i, 2 ) );
                    $out .= chr( $byte ^ $key );
                }
                return $out;
            };
            if ( '' === trim( (string) $html ) ) {
                return [ $headers, $rows ];
            }
            if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
                return [ $headers, $rows ];
            }
            $internal = libxml_use_internal_errors( true );
            $dom      = new DOMDocument();
            $loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
            libxml_clear_errors();
            libxml_use_internal_errors( $internal );
            if ( false === $loaded ) {
                return [ $headers, $rows ];
            }
            $xp         = new DOMXPath( $dom );
            $table_node = $xp->query( "//table[contains(concat(' ', normalize-space(@class), ' '), ' gv-table-view ')]" );
            if ( ! $table_node || 0 === $table_node->length ) {
                // Fallback: use the first table in the output if specific class not found.
                $table_node = $xp->query( '//table' );
                if ( ! $table_node || 0 === $table_node->length ) {
                    return [ $headers, $rows ];
                }
            }
            $table = $table_node->item( 0 );
            $ths   = $xp->query( './/thead//th', $table );
            for ( $i = 0; $i < $ths->length; $i++ ) {
                $txt       = $ths->item( $i )->textContent;
                $txt       = is_string( $txt ) ? $txt : '';
                $txt       = wp_strip_all_tags( $txt );
                $txt       = trim( preg_replace( '/\s+/u', ' ', $txt ) );
                $headers[] = $txt;
            }
            $trs = $xp->query( './/tbody/tr', $table );
            // Cache fetched entries per row to avoid repeat lookups
            $entry_cache = [];
            for ( $r = 0; $r < $trs->length; $r++ ) {
                $cells = [];
                $tr    = $trs->item( $r );
                // Try to detect entry ID for this row.
                $entry_id = 0;
                if ( $tr->hasAttribute( 'data-entryid' ) ) {
                    $entry_id = absint( $tr->getAttribute( 'data-entryid' ) );
                } elseif ( $tr->hasAttribute( 'data-entry-id' ) ) {
                    $entry_id = absint( $tr->getAttribute( 'data-entry-id' ) );
                } else {
                    // Fallbacks: row id/class patterns and links carrying entry params.
                    $row_id = (string) $tr->getAttribute( 'id' );
                    $row_cl = (string) $tr->getAttribute( 'class' );
                    if ( preg_match( '/\bgv-entry-(?:\d+-)?(\d+)\b/', $row_id, $m ) ) {
                        $entry_id = absint( $m[1] );
                    } elseif ( preg_match( '/\bgv-entry-id-(\d+)\b/', $row_cl, $m ) ) {
                        $entry_id = absint( $m[1] );
                    } elseif ( preg_match( '/\bentry-id-(\d+)\b/', $row_cl, $m ) ) {
                        $entry_id = absint( $m[1] );
                    } else {
                        // Look for a link carrying entry or entry_id param.
                        $links = $xp->query( './/a[@href]', $tr );
                        for ( $li = 0; $li < $links->length; $li++ ) {
                            $href = (string) $links->item( $li )->getAttribute( 'href' );
                            if ( false !== strpos( $href, 'entry=' ) || false !== strpos( $href, 'entry_id=' ) ) {
                                parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $qs );
                                if ( isset( $qs['entry'] ) ) {
                                    $entry_id = absint( $qs['entry'] );
                                    break;
                                }
                                if ( isset( $qs['entry_id'] ) ) {
                                    $entry_id = absint( $qs['entry_id'] );
                                    break;
                                }
                            }
                        }
                    }
                }
                $tds = $xp->query( './/td', $tr );
                for ( $c = 0; $c < $tds->length; $c++ ) {
                    $cell       = $tds->item( $c );
                    $txt        = '';
                    $mailto_val = '';
                    // 1) Prefer explicit mailto links inside the cell.
                    $mailtos = $xp->query( './/a[starts-with(@href, "mailto:")]', $cell );
                    if ( $mailtos && $mailtos->length > 0 ) {
                        $href       = $mailtos->item( 0 )->getAttribute( 'href' );
                        $href       = (string) $href;
                        $href       = preg_replace( '/^mailto:/i', '', $href );
                        $mailto_val = urldecode( $href );
                        $txt        = $mailto_val;
                    } else {
                        // 2) Cloudflare email protection: elements with data-cfemail.
                        $cf = $xp->query( './/*[@data-cfemail]', $cell );
                        if ( $cf && $cf->length > 0 ) {
                            $hex = $cf->item( 0 )->getAttribute( 'data-cfemail' );
                            $txt = $decode_cf ? $decode_cf( $hex ) : '';
                        }
                    }
                    // 3) Fallback to cell text content for now; may be obfuscated text.
                    if ( '' === $txt ) {
                        $txt = $cell->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    }
                    $raw_txt = is_string( $txt ) ? $txt : '';
                    $raw_txt = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $raw_txt ) ) );
                    // Detect if cell is likely obfuscated.
                    $has_cf_el   = ( $xp->query( './/*[@data-cfemail]', $cell )->length > 0 );
                    $has_cf_link = false;
                    $alinks      = $xp->query( './/a[@href]', $cell );
                    for ( $ai = 0; $ai < $alinks->length; $ai++ ) {
                        $h = (string) $alinks->item( $ai )->getAttribute( 'href' );
                        if ( false !== strpos( $h, '/cdn-cgi/l/email-protection' ) ) {
                            $has_cf_link = true;
                            break;
                        }
                    }
                    $has_placeholder = ( false !== stripos( $raw_txt, 'Email hidden; Javascript is required' ) );
                    // Resolve GF field/input id from native GV id/class pattern: gv-field-{form_id}-{field|input|id|custom}
                    $field_input_id = '';
                    $cell_id        = (string) $cell->getAttribute( 'id' );
                    $cell_cls       = (string) $cell->getAttribute( 'class' );
                    $re             = '/\bgv-field-' . preg_quote( (string) $form_id, '/' ) . '-(custom|id|[0-9]+(?:\.[0-9]+)?)\b/';
                    if ( preg_match( $re, $cell_id, $m ) || preg_match( $re, $cell_cls, $m ) ) {
                        if ( 'id' !== $m[1] && 'custom' !== $m[1] ) {
                            $field_input_id = $m[1];
                        }
                    }
                    // If obfuscated and we know the GF field/input id and entry id, pull raw value from GFAPI.
                    $should_fallback = ( ( '' === $mailto_val ) && ( $has_cf_el || $has_cf_link || $has_placeholder || '' === $raw_txt ) );
                    if ( $should_fallback && $field_input_id && $entry_id ) {
                        $entry = $entry_cache[ $entry_id ] ?? null;
                        if ( null === $entry && class_exists( 'GFAPI' ) ) {
                            $gf_entry = GFAPI::get_entry( $entry_id );
                            if ( is_array( $gf_entry ) && ! is_wp_error( $gf_entry ) ) {
                                $entry_cache[ $entry_id ] = $gf_entry;
                                $entry                    = $gf_entry;
                            }
                        }
                        if ( is_array( $entry ) ) {
                            $val = '';
                            if ( isset( $entry[ $field_input_id ] ) && is_scalar( $entry[ $field_input_id ] ) ) {
                                $val = (string) $entry[ $field_input_id ];
                            } elseif ( preg_match( '/^([0-9]+)$/', $field_input_id, $mm ) ) {
                                // If "3" not set, try "3.x" parts combined.
                                $fid   = $mm[1];
                                $parts = [];
                                foreach ( $entry as $ek => $ev ) {
                                    if ( is_scalar( $ev ) && 0 === strpos( (string) $ek, $fid . '.' ) ) {
                                        $parts[] = (string) $ev;
                                    }
                                }
                                $val = implode( ' ', array_filter( $parts, 'strlen' ) );
                            }
                            $raw_txt = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $val ) ) );
                        }
                    }
                    $cells[] = $raw_txt;
                }
                $rows[] = $cells;
            }
            return [ $headers, $rows ];
        };

        for ( $page = 1; $page <= $total_pages; $page++ ) {
            $html                   = $render_view( $page );
            list( $headers, $rows ) = $parse_table( $html );
            if ( ! $header_written ) {
                if ( empty( $headers ) ) {
                    // Derive headers from first row length if no <thead> is present.
                    $col_count = isset( $rows[0] ) ? count( $rows[0] ) : 0;
                    $headers   = [];
                    for ( $i = 0; $i < $col_count; $i++ ) {
                        $headers[] = 'Column ' . ( $i + 1 ); }
                }
                if ( empty( $headers ) ) {
                    // Not a table View; bail with clear message.
                    echo '# This exporter supports GravityView table layouts only.' . "\n";
                    exit;
                }
                echo implode( $delimiter, array_map( $csv_escape, $headers ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $header_written = true;
            }
            foreach ( $rows as $cells ) {
                echo implode( $delimiter, array_map( $csv_escape, $cells ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $entries_exported++;
            }
            flush();
        }

        if ( 0 === $entries_exported ) {
            echo '# No entries match the current filters' . "\n";
        }

        exit;
    };
    add_action( 'admin_post_' . $action, $handler );
    add_action( 'admin_post_nopriv_' . $action, $handler );

    // Shortcode output: render a form that posts to the handler.
    add_shortcode(
            'export_filtered_entries',
            static function ( $atts ) use ( $action, $nonce_action ) {
                $atts = shortcode_atts(
                        [
							'view_id'   => 0,
							'form_id'   => 0,
							'filename'  => 'filtered_entries',
							'delimiter' => ',',
							'label'     => 'Export Filtered View',
                        ],
                        $atts,
                        'export_filtered_entries'
                );

                $view_id   = absint( $atts['view_id'] );
                $form_id   = absint( $atts['form_id'] );
                $filename  = is_string( $atts['filename'] ) ? $atts['filename'] : 'filtered_entries';
                $delimiter = is_string( $atts['delimiter'] ) ? $atts['delimiter'] : ',';
                $label     = is_string( $atts['label'] ) ? $atts['label'] : 'Export Filtered View';

                // Try to detect current GV context if not passed as attrs.
                if ( ( ! $view_id || ! $form_id ) && class_exists( 'GravityView_View' ) ) {
                    $gv_legacy = GravityView_View::getInstance();
                    if ( ! $view_id ) {
                        $view_id = absint( $gv_legacy->getViewId() ); }
                    if ( ! $form_id ) {
                        $form_id = absint( $gv_legacy->getFormId() ); }
                }

                $action_url = esc_url( admin_url( 'admin-post.php' ) );
                $nonce      = wp_create_nonce( $nonce_action );

                ob_start();
                ?>
                <form method="post" action="<?php echo esc_url( $action_url ); ?>" class="button-wrap gv-export-form" style="display:inline-block">
                    <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
                    <input type="hidden" name="bld_view_id" value="<?php echo esc_attr( $view_id ); ?>" />
                    <input type="hidden" name="bld_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
                    <input type="hidden" name="bld_filename" value="<?php echo esc_attr( $filename ); ?>" />
                    <input type="hidden" name="bld_delimiter" value="<?php echo esc_attr( $delimiter ); ?>" />
                    <input type="hidden" name="bld_query" value="" />
                    <button type="submit" class="button gv-export-btn" style="border-radius:20px"><?php echo esc_html( $label ); ?></button>
                </form>
                <script>
                    (function(){
                        'use strict';
                        function onReady(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
                        onReady(function(){
                            const forms = document.querySelectorAll('form.gv-export-form');
                            forms.forEach(function(f){
                                f.addEventListener('submit', function(){
                                    const qs = window.location.search || '';
                                    const input = f.querySelector('input[name="bld_query"]');
                                    if (input) { input.value = qs; }
                                });
                            });
                        });
                    }());
                </script>
                <?php
                return ob_get_clean();
            }
    );
} )();
