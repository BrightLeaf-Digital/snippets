<?php
/**
 * BrightLeaf AB Test Utilities
 *
 * A production-ready, client-first A/B testing utility designed for WordPress.
 * This utility handles A/B test resolution in the browser, making it fully compatible
 * with page caching (Cloudflare, Varnish, WP Rocket).
 *
 * --- HOW IT WORKS ---
 * 1. The server renders BOTH versions of the content (A and B) in the HTML.
 * 2. High-priority CSS hides all A/B containers initially to prevent flickering (FOUC).
 * 3. JavaScript checks for a cookie (bl_abtest_{slug}). If missing, it randomly assigns a version.
 * 4. JavaScript toggles the 'ab-active' class on the chosen version and unhides it.
 * 5. JavaScript updates any Gravity Forms hidden fields to ensure lead data is 100% accurate.
 *
 * --- USAGE: GUTENBERG BLOCKS ---
 * 1. Create two blocks in the editor (e.g., two different Buttons).
 * 2. In the "Advanced" sidebar for BOTH blocks, add the class: abtestcontainer-{slug}
 *    Example: abtestcontainer-hero-cta
 * 3. The first block found with that slug becomes Version A, the second becomes Version B.
 *
 * --- USAGE: SHORTCODES ---
 * Wrap your content versions like this:
 * [abtest title="my-test-slug"]
 *   [abcontent contentversion="contentversiona"] Version A Content [/abcontent]
 *   [abcontent contentversion="contentversionb"] Version B Content [/abcontent]
 * [/abtest]
 *
 * --- USAGE: GRAVITY FORMS ---
 * A. Tracking which version was seen:
 *    1. Add a Hidden Field to your form.
 *    2. Advanced Tab -> Enable Dynamic Population.
 *    3. Parameter Name: abtestversion_{slug} (e.g., abtestversion_hero-cta).
 *    4. The value saved will be "{slug}:{version}" (e.g., hero-cta:contentversionb).
 *
 * B. Using Merge Tags:
 *    Use {abtestversion:slug} in notifications or confirmations to display the visitor's version.
 *
 * --- USAGE: EXTERNAL TRACKING (GA4, etc.) ---
 * 1. Automatic Resolution Events:
 *    a) 'bl_abtest_resolved' — dispatched on every page where a test is resolved.
 *       Use this to set GA4 User Properties to tie the version to ALL future events.
 *       Example:
 *       document.addEventListener('bl_abtest_resolved', function(e) {
 *         const { slug, version } = e.detail;
 *         // GA4 User Property (Ties ALL future events like clicks/purchases to this version)
 *         if (window.gtag) {
 *           gtag('set', 'user_properties', { ['ab_test_' + slug]: version });
 *           gtag('event', 'ab_test_view', { 'test_name': slug, 'test_version': version });
 *         }
 *       });
 *    b) 'bl_abtest_first_resolved' — dispatched exactly once per slug when the cookie is first created.
 *       Use this to associate a user with a cohort a single time.
 *
 * 2. Manual Retrieval:
 *    You can access resolved versions anytime via window.BL_ABTest.versions
 *    Example: const pricingVersion = window.BL_ABTest.versions['pricing-table'];
 */

/**
 * Core class for handling A/B testing functionality.
 *
 * The `BL_ABTest` class provides utilities for managing A/B testing of
 * content through shortcodes, Gutenberg blocks, and integration with Gravity Forms.
 * It allows the tracking and serving of different content versions to users,
 * maintaining state using cookies and choosing versions through random selection or block-level logic.
 */
class BL_ABTest {
    const COOKIE_PREFIX   = 'bl_abtest_';
    const COOKIE_TTL_DAYS = 30;

    /**
     * Per-request counters for block occurrences
     *
     * @var array
     */
    private static $block_seen = [];

    /**
     * Initializes the class by setting up actions and filters.
     *
     * This method registers the necessary WordPress actions and filters required
     * for the functionality of the class, including shortcodes, block rendering,
     * and integration with Gravity Forms for merge tags and dynamic population.
     *
     * @return void
     */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_shortcodes' ] );
        add_filter( 'render_block', [ __CLASS__, 'filter_render_block' ], 10, 2 );

        // Gravity Forms merge tags
        add_filter( 'gform_replace_merge_tags', [ __CLASS__, 'gf_replace_merge_tags' ] );

        // Gravity Forms dynamic population: {Field} param name "abtestversion_slug"
        add_filter( 'gform_field_value', [ __CLASS__, 'gf_dynamic_population' ], 10, 3 );

        add_action( 'wp_head', [ __CLASS__, 'print_assets' ] );
        add_action( 'wp_footer', [ __CLASS__, 'print_footer_scripts' ] );
    }

    /**
     * Prints CSS and JS to the head to manage A/B test visibility and selection.
     */
    public static function print_assets() {
        ?>
        <style id="bl-abtest-css">
            [data-abtest-version] { display: none !important; }
            .abtest-resolved [data-abtest-version].ab-active { display: block !important; }
            /* For inline elements, use initial or inline-block if needed */
            span[data-abtest-version].ab-active { display: inline !important; }
            div[data-abtest-version].ab-active { display: block !important; }
        </style>
        <script id="bl-abtest-js">
            (function() {
                const COOKIE_PREFIX = <?php echo wp_json_encode( self::COOKIE_PREFIX ); ?>;
                const TTL_DAYS = <?php echo wp_json_encode( self::COOKIE_TTL_DAYS ); ?>;

                window.BL_ABTest = {
                    versions: {}, // Stores resolved slugs and their versions
                    getCookie: function(name) {
                        let match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
                        return match ? match[2] : null;
                    },
                    setCookie: function(name, value) {
                        let date = new Date();
                        date.setTime(date.getTime() + (TTL_DAYS * 24 * 60 * 60 * 1000));
                        document.cookie = name + "=" + value + "; path=/; expires=" + date.toUTCString();
                    },
                    resolve: function() {
                        const newlyResolvedSlugs = {};
                        const groupContainers = document.querySelectorAll('[data-abtest-group]');
                        groupContainers.forEach(group => {
                            const slug = group.getAttribute('data-abtest-group');
                            const version = this.getAssignedVersion(slug);
                            this.versions[slug] = version;
                            newlyResolvedSlugs[slug] = version;
                            group.querySelectorAll('[data-abtest-version]').forEach(el => {
                                if (el.getAttribute('data-abtest-version') === version) {
                                    el.classList.add('ab-active');
                                } else {
                                    el.classList.remove('ab-active');
                                }
                            });
                        });

                        // Also handle independent blocks (Gutenberg)
                        const independent = document.querySelectorAll('[data-abtest-slug]:not(input)');
                        independent.forEach(el => {
                            const slug = el.getAttribute('data-abtest-slug');
                            const version = this.getAssignedVersion(slug);
                            this.versions[slug] = version;
                            newlyResolvedSlugs[slug] = version;
                            if (el.getAttribute('data-abtest-version') === version) {
                                el.classList.add('ab-active');
                            } else {
                                el.classList.remove('ab-active');
                            }
                        });

                        document.documentElement.classList.add('abtest-resolved');

                        // Gravity Forms update
                        this.updateGF();

                        // Dispatch events for all resolved slugs
                        for (const slug in newlyResolvedSlugs) {
                            document.dispatchEvent(new CustomEvent('bl_abtest_resolved', {
                                detail: { slug: slug, version: newlyResolvedSlugs[slug] }
                            }));
                        }
                    },
                    getAssignedVersion: function(slug) {
                        let version = this.getCookie(COOKIE_PREFIX + slug);
                        if (!version || (version !== 'contentversiona' && version !== 'contentversionb')) {
                            version = Math.random() < 0.5 ? 'contentversiona' : 'contentversionb';
                            this.setCookie(COOKIE_PREFIX + slug, version);
                            // Fire once per slug when the cookie is first created
                            document.dispatchEvent(new CustomEvent('bl_abtest_first_resolved', {
                                detail: { slug: slug, version: version }
                            }));
                        }
                        return version;
                    },
                    updateGF: function() {
                        const gfInputs = document.querySelectorAll('input[name^="input_"][data-abtest-slug]');
                        gfInputs.forEach(input => {
                            const slug = input.getAttribute('data-abtest-slug');
                            const version = this.getCookie(COOKIE_PREFIX + slug);
                            if (version) {
                                input.value = slug + ':' + version;
                            }
                        });
                    }
                };

                document.addEventListener('DOMContentLoaded', () => {
                    window.BL_ABTest.resolve();
                });
            })();
        </script>
        <?php
    }

    /**
     * Scripts for Gravity Forms specifically (handling dynamic population via JS).
     */
    public static function print_footer_scripts() {
        ?>
        <script>
            // Handle Gravity Forms post-render (AJAX forms)
            document.addEventListener('gform_post_render', function() {
                if (window.BL_ABTest) window.BL_ABTest.updateGF();
            });
        </script>
        <?php
    }

    /*
    ---------------------------
    Core selection + persistence
    ---------------------------
     */

    /**
     * Normalizes a given string into a slug format.
     *
     * @param string $raw The raw string input to be normalized.
     *
     * @return string The normalized slug generated from the input string.
     */
    private static function normalize_slug( $raw ) {
        $raw = (string) $raw;

        return sanitize_title( $raw );
    }

    /**
     * Constructs a cookie name by combining a predefined prefix with the given slug.
     *
     * @param string $slug The slug to be appended to the cookie prefix.
     *
     * @return string The complete cookie name generated by concatenating the prefix and the slug.
     */
    private static function cookie_name( $slug ) {
        return self::COOKIE_PREFIX . $slug;
    }

    /**
     * Retrieves the content version associated with the given slug.
     *
     * The method normalizes the provided slug and checks for an existing cookie
     * associated with it. If a valid cookie value is found, it returns the version
     * (e.g., "contentversiona" or "contentversionb"). If not, it randomly selects
     * a version, sets it in a cookie, and returns the newly chosen version.
     *
     * @param string $slug The identifier used to determine or set the content version.
     *
     * @return string The content version associated with the slug ("contentversiona" or "contentversionb").
     */
    public static function get_version( $slug ) {
        $slug = self::normalize_slug( $slug );
        if ( '' === $slug ) {
            return 'contentversiona';
        }

        $cname = self::cookie_name( $slug );

        if ( isset( $_COOKIE[ $cname ] ) ) {
            $v = sanitize_text_field( wp_unslash( $_COOKIE[ $cname ] ) );
            if ( 'contentversiona' === $v || 'contentversionb' === $v ) {
                return $v;
            }
        }

        // Choose randomly (or implement alternating)
        $chosen = ( wp_rand( 0, 1 ) === 0 ) ? 'contentversiona' : 'contentversionb';
        self::set_cookie( $cname, $chosen );
        return $chosen;
    }

    /**
     * Sets a cookie with the specified name and value.
     *
     * @param string $name The name of the cookie to be set.
     * @param string $value The value to be stored in the cookie.
     *
     * @return void
     */
    private static function set_cookie( $name, $value ) {
        // Best-effort: set for entire site
        $ttl    = time() + ( self::COOKIE_TTL_DAYS * DAY_IN_SECONDS );
        $path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
        $domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

        // Avoid warnings if headers already sent
        if ( ! headers_sent() ) {
            setcookie( $name, $value, $ttl, $path, $domain, is_ssl(), true );
        }

        // Ensure current request sees it too
        $_COOKIE[ $name ] = $value;
    }

    /**
     * Generates a token based on a slug and its associated version.
     *
     * @param string $slug The raw slug input to process and generate the token from.
     *
     * @return string The token composed of the normalized slug and its version, separated by a colon.
     */
    public static function get_token( $slug ) {
        $slug = self::normalize_slug( $slug );
        $ver  = self::get_version( $slug );
        return $slug . ':' . $ver;
    }

    /*
    ---------------------------
    Shortcodes
    ---------------------------
     */

    /**
     * Registers custom shortcodes for use within the system.
     *
     * Adds the 'abtest' and 'abcontent' shortcodes and associates them with
     * their respective handler methods.
     *
     * @return void
     */
    public static function register_shortcodes() {
        add_shortcode( 'abtest', [ __CLASS__, 'sc_abtest' ] );
        add_shortcode( 'abcontent', [ __CLASS__, 'sc_abcontent' ] );
    }

    /**
     * Processes an A/B test shortcode by determining the appropriate version of content to display.
     *
     * @param array  $atts Associative array of shortcode attributes. Expects 'title' as a key for the A/B test identifier.
     * @param string $content The nested content within the shortcode, usually containing the A/B test content blocks.
     *
     * @return string The processed output wrapped in a container, with the selected A/B test content version.
     */
    public static function sc_abtest( $atts, $content = '' ) {
        $atts = shortcode_atts(
            [
                'title' => '',
            ],
            $atts,
            'abtest'
        );

        $slug = self::normalize_slug( $atts['title'] );
        if ( '' === $slug ) {
            return do_shortcode( $content );
        }

        // Simply output both versions with data attributes.
        // The JS will handle showing the correct one.
        return sprintf(
            '<div class="abtest-wrapper abtest-%1$s" data-abtest-group="%1$s">%2$s</div>',
            esc_attr( $slug ),
            do_shortcode( $content )
        );
    }

    /**
     * Processes the [abcontent] shortcode within the context of A/B testing.
     *
     * @param array  $atts The attributes passed to the shortcode.
     * @param string $content The enclosed content between the shortcode tags.
     *
     * @return string
     */
    public static function sc_abcontent( $atts, $content = '' ) {
        $atts = shortcode_atts(
            [
                'contentversion' => '',
            ],
            $atts,
            'abcontent'
        );

        $ver = strtolower( trim( (string) $atts['contentversion'] ) );

        if ( 'contentversiona' !== $ver && 'contentversionb' !== $ver ) {
            return do_shortcode( $content );
        }

        return sprintf(
            '<div class="abcontent" data-abtest-version="%s">%s</div>',
            esc_attr( $ver ),
            do_shortcode( $content )
        );
    }

    /*
    ---------------------------
    Gutenberg block filter
    ---------------------------
    */

    /**
     * Filters the rendered block content to modify it for A/B testing purposes.
     *
     * This method checks if the block contains an A/B test configuration based on its class name,
     * assigns a version (A or B) based on the block's occurrence, and adds appropriate
     * data attributes (`data-abtest-slug` and `data-abtest-version`) for JavaScript handling.
     *
     * @param string $block_content The HTML content of the rendered block.
     * @param array  $block The block configuration and attributes array.
     *
     * @return string The modified block content with added data attributes for A/B testing.
     */
    public static function filter_render_block( $block_content, $block ) {
        // Check for the class in the block attributes (Gutenberg way)
        $class_name = $block['attrs']['className'] ?? '';

        if ( ! $class_name || stripos( $class_name, 'abtestcontainer-' ) === false ) {
            return $block_content;
        }

        if ( ! preg_match( '/\babtestcontainer-([a-z0-9-]+)\b/i', $class_name, $match ) ) {
            return $block_content;
        }

        $slug = self::normalize_slug( $match[1] );
        if ( '' === $slug ) {
            return $block_content;
        }

        if ( ! isset( self::$block_seen[ $slug ] ) ) {
            self::$block_seen[ $slug ] = 0;
        }
        ++self::$block_seen[ $slug ];

        $occurrence = self::$block_seen[ $slug ];
        $assigned   = null;

        if ( 1 === $occurrence ) {
            $assigned = 'contentversiona';
        } elseif ( 2 === $occurrence ) {
            $assigned = 'contentversionb';
        }

        if ( ! $assigned ) {
            return $block_content;
        }

        // Add data attributes for the JS to handle selection
        $block_content = self::add_data_attr_to_first_tag( $block_content, 'data-abtest-slug', $slug );

        return self::add_data_attr_to_first_tag( $block_content, 'data-abtest-version', $assigned );
    }

    /**
     * Adds a data attribute to the first HTML tag in a string.
     *
     * @param string $html  HTML string.
     * @param string $attr  Attribute name.
     * @param string $value Attribute value.
     *
     * @return string
     */
    private static function add_data_attr_to_first_tag( $html, $attr, $value ) {
        return preg_replace(
            '/<([a-z0-9-]+)/i',
            '<$1 ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"',
            $html,
            1
        );
    }

    /*
    ---------------------------
        Gravity Forms merge tag replacement
    ---------------------------
    */

    /**
     * Replaces merge tags in the provided text with the corresponding values.
     *
     * @param string $text The text containing merge tags to be replaced.
     *
     * @return string The text with the merge tags replaced by their corresponding values.
     */
    public static function gf_replace_merge_tags( $text ) {
        // Support {abtestversion:slug}
        if ( ! str_contains( $text, '{abtestversion:' ) ) {
            return $text;
        }

        return preg_replace_callback(
            '/{abtestversion:([a-z0-9-_]+)}/i',
            function ( $m ) {
                $slug = self::normalize_slug( $m[1] );
                return esc_html( self::get_token( $slug ) );
            },
            $text
        );
    }

    /**
     * Dynamic population for any field parameter that starts with "abtestversion_".
     * Example parameter name: abtestversion_pricinghero
     *
     * @param string $value value.
     * @param object $field field.
     * @param string $name Name.
     * @return string
     */
    public static function gf_dynamic_population( $value, $field, $name ) {
        if ( stripos( $name, 'abtestversion_' ) !== 0 ) {
            return $value;
        }

        $slug = substr( $name, strlen( 'abtestversion_' ) );
        $slug = self::normalize_slug( $slug );
        if ( '' === $slug ) {
            return $value;
        }

        // We add a data-attribute so JS can find this input and update it correctly.
        // Note: Using a unique hook name per field to avoid conflicts with multiple A/B tests on one form.
        add_filter(
            'gform_field_content',
            function ( $field_content, $field_obj ) use ( $slug, $field ) {
                if ( (int) $field_obj->id === (int) $field->id ) {
                    // We look for name="input_X" and inject data-abtest-slug
                    return str_replace( 'name="input_', 'data-abtest-slug="' . esc_attr( $slug ) . '" name="input_', $field_content );
                }
                return $field_content;
            },
            10,
            2
        );

        return self::get_token( $slug );
    }
}

BL_ABTest::init();
