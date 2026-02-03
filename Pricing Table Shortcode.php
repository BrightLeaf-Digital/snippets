<?php
/**
 * BrightLeaf Pricing Table — Single‑file Shortcode Snippet
 *
 * Goal
 * - Make it easy to add a clear, modern pricing table to any page with simple shortcodes.
 * - Keep everything in one place: the layout, styles, and behavior live in this single file.
 * - Optionally connect to Freemius for checkout and, if you want, automatically load your plans and prices.
 *
 * Features
 * - Clean pricing cards with a Monthly/Annual toggle.
 * - One or more plans, each with license tiers, a short description, and a feature list.
 * - Site count selector that stays in sync across all plans.
 * - Optional Freemius checkout (Buy and Trial buttons), including license count and billing cycle.
 * - Optional “Automatic” mode to pull plans and prices from your Freemius account.
 * - All CSS and JS are included right here and only appear once per page.
 *
 * Requirements
 * - WordPress page or post where you can paste shortcodes.
 * - For Freemius checkout (manual plan entry): your product’s Public Key, Product ID, and each plan’s Plan ID.
 * - For Freemius “Automatic” mode: your Freemius Product ID and a token stored in a PHP constant.
 *
 * How To Use
 * 1) Parent shortcode
 *    Place the parent shortcode on the page. It controls product information, Freemius options, and overall behavior.
 *
 *    [gopricingtable
 *       public_key="pk_XXXX"
 *       product_name="Your Product"
 *       product_id="12345"
 *       freemius=""|"manual"|"automatic"
 *       buy_url="https://example.com/buy"
 *       trial_url="https://example.com/trial"
 *       product_prefix="your-product"
 *       currency="usd|eur|gbp"
 *       cache_ttl="21600"
 *       site_tiers_label="Single Site|Up to 3 Sites|Up to 10 Sites"
 *       bearer_constant="FS_API_TOKEN"
 *       coupon="123,456"
 *       discount="$10" | "15%"
 *    ]
 *      ...one or more child plan shortcodes...
 *    [/gopricingtable]
 *
 *    Parent attributes (plain language reference):
 *    - public_key
 *      What: Your Freemius public key.
 *      When required: Required when freemius="manual" (for checkout). Optional when freemius is empty (no checkout). In freemius="automatic", it can be filled automatically from your account.
 *    - product_name
 *      What: Shown in checkout and used for button IDs if you don’t set a prefix.
 *      When required: Optional, but recommended so buyers see a friendly name.
 *    - product_id
 *      What: Your Freemius product ID.
 *      When required: Required for freemius="manual" and freemius="automatic". Optional when freemius is empty.
 *    - freemius
 *      What: Turns Freemius features on.
 *      Values: "" (off), "manual" (checkout on, you enter plans), "automatic" (checkout on, plans/prices loaded for you).
 *    - buy_url / trial_url
 *      What: Where the buttons go when freemius is empty.
 *      When required: Optional. Add one or both so the buttons have a destination when not using Freemius.
 *    - product_prefix
 *      What: A short ID added to button IDs (helps with analytics and testing).
 *      When required: Optional. If omitted, a neat prefix is created from your product name.
 *    - currency
 *      What: Currency to show when using freemius="automatic".
 *      Values: usd (default), eur, gbp.
 *    - cache_ttl
 *      What: How long to keep automatic pricing (in seconds). Default: 21600 (6 hours). Use 0 to always refresh.
 *    - coupon
 *      What: Optional comma-separated list of Freemius coupon IDs to apply (automatic Freemius mode only). They will be stacked in the order provided.
 *    - discount
 *      What: Manual/empty modes only. Discount to show in pricing and banners. Use "$10" for flat or "15%" for percentage. Can be overridden per plan.
 *    - site_tiers_label
 *      What: Optional list of labels for the site tiers in automatic mode (pipe‑separated).
 *      Example: "Single Site|Up to 3 Sites|Up to 10 Sites".
 *    - bearer_constant
 *      What: The name of a PHP constant that holds your Freemius token for automatic mode.
 *      When required: Required for freemius="automatic". Example: define('FS_API_TOKEN', 'your_token_here');
 *
 * 2) Child plan shortcode
 *    Add one child for each plan (these act as settings; they don’t print anything by themselves).
 *
 *    [go_plan_column
 *      plan_name="Pro"
 *      plan_id="111"
 *      site_tiers_label="Single Site|Up to 3 Sites"
 *      site_tiers="1|3"
 *      monthly="9.99|14.99"
 *      annual="89.99|134.99"
 *      plan_desc="Great for individuals"
 *      plan_features="Feature A|Feature B|Feature C"
 *      best_value="true"
 *      has_trial="true"
 *    ]
 *
 *    Child attributes (plain language reference):
 *    - plan_name
 *      What: The name shown on the card (e.g., Pro, Premium, Agency).
 *      When required: Yes.
 *    - plan_id
 *      What: The plan’s ID in Freemius (used by checkout).
 *      When required: Required when freemius is on (manual or automatic). Optional when freemius is off.
 *    - site_tiers_label
 *      What: The labels people see for each site tier (pipe‑separated).
 *      Example: "Single Site|Up to 3 Sites".
 *    - site_tiers
 *      What: The matching site counts (pipe‑separated numbers).
 *      Example: "1|3".
 *    - monthly / annual
 *      What: Prices for each tier (pipe‑separated to match your tiers). You can provide one or both. If both are present, Annual highlights the per‑month savings.
 *      Examples: monthly="9.99|14.99" and/or annual="89.99|134.99".
 *    - plan_desc
 *      What: Short sentence under the buttons.
 *      When required: Optional.
 *    - plan_features
 *      What: Bullet points for the feature list (pipe‑separated).
 *      Example: "Feature A|Feature B|Feature C".
 *    - best_value
 *      What: Mark true for the plan you want highlighted as “Best Value”. If you don’t pick one, the table may highlight a plan based on savings.
 *      Values: true or false. Optional.
 *    - has_trial
 *      What: Show or hide the “Free Trial” button for this plan.
 *      Values: true (default) or false.
 *
 * Examples
 * - Manual plans with Freemius checkout:
 *   [gopricingtable public_key="pk_XXXX" product_name="My Add‑on" product_id="12345" freemius="manual"]
 *     [go_plan_column plan_name="Pro" plan_id="111" site_tiers_label="Single Site|Up to 3 Sites" site_tiers="1|3" monthly="9.99|14.99" annual="89.99|134.99" plan_desc="For individual sites" plan_features="Feature A|Feature B|Feature C"]
 *     [go_plan_column plan_name="Premium" plan_id="222" best_value="true" site_tiers_label="Single Site|Up to 3 Sites" site_tiers="1|3" monthly="14.99|24.99" annual="134.99|224.99" plan_desc="For growing teams" plan_features="Everything in Pro|Priority support"]
 *   [/gopricingtable]
 *
 * - Automatic plans (no child plans needed):
 *   [gopricingtable freemius="automatic" product_id="15834" currency="usd" bearer_constant="FS_API_TOKEN"]
 *   Tip: Define your token once, for example in wp-config.php: define('FS_API_TOKEN', 'your_token_here');
 *
 * Behavior Notes
 * - Monthly/Annual toggle: When Annual is selected, each plan shows the average per‑month price and the yearly total underneath.
 * - Site tiers: Picking a site count updates all plans so visitors compare fairly.
 * - Buttons: When Freemius is on, Buy and Trial open checkout with the selected site count and billing cycle. When Freemius is off, buttons go to your links with helpful details added to the URL.
 * - Best Value badge: Use it to guide visitors to the plan you recommend.
 *
 * Accessibility
 * - Keyboard‑friendly controls with visible focus outlines.
 * - Clear labels and logical order of information.
 * - Good color contrast aimed at comfortable reading.
 *
 * Helpful Tips
 * - Keep feature lists short and benefit‑focused.
 * - Use consistent site tiers across plans so comparisons are easy.
 * - If you only sell yearly on a plan, you can leave out the monthly price.
 * - Set a friendly product_name so the checkout panel looks polished.
 * - If you share snippets with your team, add a product_prefix to make button IDs easy to find in analytics.
 *
 * Support & Troubleshooting
 * - If nothing appears, double‑check that at least one child plan is inside the parent (unless you’re using automatic mode).
 * - If buttons do nothing, confirm you set freemius and provided the required product details, or that your links are correct when freemius is off.
 * - In automatic mode, make sure the token constant exists and has the right value.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Class responsible for managing and rendering a pricing table.
 */
class Bld_Go_PricingTable {
    /**
     * Singleton instance.
     *
     * @var Bld_Go_PricingTable|null
     */
    private static $instance = null;
    /**
     * Whether the assets have already been printed.
     *
     * @var bool
     */
    private $assets_printed = false;

    /**
     * Retrieves the singleton instance of the class.
     *
     * @return self The single instance of the class.
     */
    public static function get() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Registers the 'init' action hook.
     *
     * Adds the 'on_init' method to the WordPress 'init' action hook.
     *
     * @return void
     */
    public function register() {
        add_action( 'init', [ $this, 'on_init' ] );
    }

    /**
     * Initializes shortcodes used by the class.
     *
     * @return void
     */
    public function on_init() {
        add_shortcode( 'gopricingtable', [ $this, 'render_parent' ] );
        // Child becomes a no-op; parent will parse its attributes directly.
        add_shortcode( 'go_plan_column', '__return_empty_string' );
    }

    // --- Utilities ---

    /**
     * Parses a pipe-separated string and returns an array of trimmed, decoded elements.
     *
     * @param mixed $raw The raw pipe-separated string to be parsed.
     * @return array An array of trimmed and HTML entity-decoded elements. If the input string is empty or contains only whitespace, an empty array is returned.
     */
    public function parse_pipe_list( $raw ) {
        $raw = (string) $raw;
        if ( '' === trim( $raw ) ) {
            return [];
        }
        $parts = explode( '|', $raw );
        $out   = [];
        foreach ( $parts as $p ) {
            $p = trim( $p );
            if ( '' !== $p ) {
                $out[] = html_entity_decode( $p, ENT_QUOTES );
            }
        }
        return $out;
    }

    /**
     * Converts a formatted money string into a float.
     *
     * @param string $s The money string to convert, which may include symbols, commas, or spaces.
     * @return float The numeric representation of the money string. Returns 0.0 if the input is not numeric.
     */
    public function money_to_float( $s ): float {
        $s = str_replace( [ '$', ',', ' ' ], '', trim( (string) $s ) );
        return is_numeric( $s ) ? (float) $s : 0.0;
    }

    /**
     * Fetches the Freemius pricing table for a given product.
     *
     * @param string $product_id The Freemius product ID.
     * @param string $currency The currency code (default is 'USD'). Supported values are 'USD', 'EUR', and 'GBP'.
     * @param int    $cache_ttl The time-to-live for the cached response in seconds (default is 21600).
     * @param string $bearer The Freemius API token for authentication.
     *
     * @return array|WP_Error The pricing table as an associative array on success, or a WP_Error object on failure.
     */
    public function fetch_freemius_pricing_table( $product_id, $currency = 'USD', $cache_ttl = 21600, $bearer = '' ) {
        $product_id = trim( $product_id );
        if ( '' === $product_id ) {
            return new WP_Error( 'go_pt_fs_missing_product', 'Missing Freemius product_id.' );
        }
        if ( '' === trim( $bearer ) ) {
            return new WP_Error( 'go_pt_fs_missing_token', 'Missing Freemius API token.' );
        }

        $endpoint = sprintf( 'https://api.freemius.com/v1/products/%s/pricing.json', rawurlencode( $product_id ) );
        $currency = strtoupper( trim( $currency ) );
        if ( ! in_array( $currency, [ 'USD', 'EUR', 'GBP' ], true ) ) {
            return new WP_Error( 'go_pt_fs_invalid_currency', 'Invalid currency code. Supported: USD, EUR, GBP.' );
        }
        $url = add_query_arg(
                [
                        'currency'    => $currency,
                        'type'        => 'visible',
                        'is_enriched' => 'true',
                ],
                $endpoint
        );

        $cache_key = 'bld_go_pt_fs_' . md5( $url );
        if ( $cache_ttl > 0 ) {
            $cached = get_transient( $cache_key );
            if ( $cached ) {
                return $cached;
            }
        }

        $args = [
                'timeout' => 20,
                'headers' => [
                        'Accept'        => 'application/json',
                        'Authorization' => 'Bearer ' . $bearer,
                ],
        ];

        $resp = wp_remote_get( $url, $args );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'go_pt_fs_http_' . $code, 'Freemius API error: HTTP ' . $code );
        }
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'go_pt_fs_bad_json', 'Freemius API returned invalid JSON.' );
        }

        if ( $cache_ttl > 0 ) {
            set_transient( $cache_key, $data, $cache_ttl );
        }

        return $data;
    }

    /**
     * Fetch a single Freemius coupon (enriched) for the given product.
     *
     * @param string $product_id The Freemius product ID.
     * @param string $coupon_id  The Freemius coupon ID.
     * @param string $currency   The currency code (default is 'USD'). Supported values are 'USD', 'EUR', and 'GBP'.
     * @param int    $cache_ttl  The time-to-live for the cached response in seconds (default is 21600).
     * @param string $bearer     The Freemius API token for authentication.
     *
     * @return array|WP_Error The coupon details as an associative array on success, or a WP_Error object on failure.
     */
    public function fetch_freemius_coupon( $product_id, $coupon_id, $currency = 'USD', $cache_ttl = 21600, $bearer = '' ) {
        $product_id = trim( $product_id );
        $coupon_id  = trim( $coupon_id );
        if ( '' === $product_id || '' === $coupon_id ) {
            return new WP_Error( 'go_pt_fs_coupon_missing_params', 'Missing Freemius product_id or coupon_id.' );
        }
        if ( '' === trim( $bearer ) ) {
            return new WP_Error( 'go_pt_fs_coupon_missing_token', 'Missing Freemius API token.' );
        }

        $endpoint = sprintf(
                'https://api.freemius.com/v1/products/%s/coupons/%s.json',
                rawurlencode( $product_id ),
                rawurlencode( $coupon_id )
        );

        $currency = strtoupper( trim( $currency ) );
        if ( ! in_array( $currency, [ 'USD', 'EUR', 'GBP' ], true ) ) {
            $currency = 'USD';
        }

        $url = add_query_arg(
                [
                        'is_enriched' => 'true',
                        'currency'    => $currency,
                ],
                $endpoint
        );

        $cache_key = 'bld_go_pt_fs_coupon_' . md5( $url );
        if ( $cache_ttl > 0 ) {
            $cached = get_transient( $cache_key );
            if ( $cached ) {
                return $cached;
            }
        }

        $args = [
                'timeout' => 20,
                'headers' => [
                        'Accept'        => 'application/json',
                        'Authorization' => 'Bearer ' . $bearer,
                ],
        ];

        $resp = wp_remote_get( $url, $args );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'go_pt_fs_coupon_http_' . $code, 'Freemius API error: HTTP ' . $code );
        }
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'go_pt_fs_coupon_bad_json', 'Freemius API returned invalid JSON for coupon.' );
        }

        if ( $cache_ttl > 0 ) {
            set_transient( $cache_key, $data, $cache_ttl );
        }

        return $data;
    }

    /**
     * Parse a comma-separated list of coupon IDs.
     *
     * @param string $raw Raw string.
     * @return array Sanitized list of coupon IDs (strings).
     */
    public function parse_coupon_ids( $raw ) {
        $out = [];
        foreach ( explode( ',', (string) $raw ) as $id ) {
            $id = trim( $id );
            if ( '' === $id ) {
                continue;
            }
            $out[] = $id;
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Parse a discount string like "$10" or "15%".
     *
     * @param string $raw Raw discount string.
     * @return array|null Array with keys type ('dollar'|'percentage') and amount (float) or null if invalid.
     */
    public function parse_discount_string( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return null;
        }
        if ( preg_match( '/^\\$\\s*([0-9]+(?:\\.[0-9]+)?)$/', $raw, $m ) ) {
            return [
                    'type'   => 'dollar',
                    'amount' => (float) $m[1],
            ];
        }
        if ( preg_match( '/^([0-9]+(?:\\.[0-9]+)?)\\s*%$/', $raw, $m ) ) {
            return [
                    'type'   => 'percentage',
                    'amount' => (float) $m[1],
            ];
        }
        return null;
    }

    /**
     * Normalize a coupon response into a structured array for evaluation.
     *
     * @param array $coupon   Raw coupon array.
     *
     * @return array Normalized coupon details.
     */
    public function normalize_coupon_record( $coupon ) {
        if ( ! is_array( $coupon ) ) {
            return [ 'is_valid' => false ];
        }

        $discount_type = strtolower( (string) ( $coupon['discount_type'] ?? '' ) );
        if ( ! in_array( $discount_type, [ 'percentage', 'dollar' ], true ) ) {
            $discount_type = '';
        }
        $discount_val = $this->money_to_float( $coupon['discount'] ?? 0 );
        $discount_map = [];
        if ( isset( $coupon['discounts'] ) && is_array( $coupon['discounts'] ) ) {
            foreach ( [ 'usd', 'eur', 'gbp' ] as $cur ) {
                if ( isset( $coupon['discounts'][ $cur ] ) ) {
                    $discount_map[ $cur ] = $this->money_to_float( $coupon['discounts'][ $cur ] );
                }
            }
        }

        $plans_raw  = isset( $coupon['plans'] ) ? explode( ',', (string) $coupon['plans'] ) : [];
        $plans_list = [];
        foreach ( $plans_raw as $pid ) {
            $pid = trim( $pid );
            if ( '' !== $pid ) {
                $plans_list[] = $pid;
            }
        }

        $licenses_raw  = isset( $coupon['licenses'] ) ? explode( ',', (string) $coupon['licenses'] ) : [];
        $licenses_list = [];
        foreach ( $licenses_raw as $lic ) {
            $lic = trim( $lic );
            if ( '' !== $lic ) {
                $licenses_list[] = (int) $lic;
            }
        }

        $cycles_raw = isset( $coupon['billing_cycles'] ) ? explode( ',', (string) $coupon['billing_cycles'] ) : [];
        $cycles     = [];
        foreach ( $cycles_raw as $c ) {
            $c = trim( $c );
            if ( '' === $c ) {
                continue;
            }
            $label = $this->coupon_cycle_label_from_value( $c );
            if ( $label ) {
                $cycles[] = $label;
            }
        }

        $start_ts = null;
        if ( ! empty( $coupon['start_date'] ) ) {
            $tmp = strtotime( (string) $coupon['start_date'] );
            if ( false !== $tmp ) {
                $start_ts = $tmp;
            }
        }
        $end_ts = null;
        if ( ! empty( $coupon['end_date'] ) ) {
            $tmp = strtotime( (string) $coupon['end_date'] );
            if ( false !== $tmp ) {
                $end_ts = $tmp;
            }
        }

        $redemptions       = (int) ( $coupon['redemptions'] ?? 0 );
        $redemptions_limit = isset( $coupon['redemptions_limit'] ) ? (int) $coupon['redemptions_limit'] : null;

        $is_active = ! empty( $coupon['is_active'] );
        $now       = time();
        if ( $start_ts && $now < $start_ts ) {
            $is_active = false;
        }
        if ( $end_ts && $now > $end_ts ) {
            $is_active = false;
        }
        if ( null !== $redemptions_limit && $redemptions >= $redemptions_limit ) {
            $is_active = false;
        }

        $code = (string) ( $coupon['code'] ?? '' );

        return [
                'is_valid'          => $is_active && '' !== $code && '' !== $discount_type && ( $discount_val > 0 || ! empty( $discount_map ) ),
                'id'                => (string) ( $coupon['id'] ?? '' ),
                'code'              => $code,
                'discount_type'     => $discount_type,
                'discount'          => $discount_val,
                'discounts'         => $discount_map,
                'plans'             => $plans_list,
                'licenses'          => $licenses_list,
                'cycles'            => $cycles,
                'redemptions_limit' => $redemptions_limit,
                'redemptions'       => $redemptions,
                'start_ts'          => $start_ts,
                'end_ts'            => $end_ts,
        ];
    }

    /**
     * Map Freemius billing cycle values to internal labels.
     *
     * @param string|int $val Billing cycle value.
     * @return string|null
     */
    private function coupon_cycle_label_from_value( $val ) {
        $val = (string) $val;
        if ( '1' === $val || 'monthly' === strtolower( $val ) ) {
            return 'Monthly';
        }
        if ( '12' === $val || 'annual' === strtolower( $val ) || 'yearly' === strtolower( $val ) ) {
            return 'Annual';
        }
        return null;
    }

    /**
     * Determine if a coupon applies to a plan.
     *
     * @param array  $coupon Normalized coupon data.
     * @param string $plan_id Current plan ID.
     * @return bool
     */
    private function coupon_supports_plan( $coupon, $plan_id ) {
        if ( empty( $coupon['plans'] ) ) {
            return true;
        }
        return in_array( (string) $plan_id, $coupon['plans'], true );
    }

    /**
     * Determine if a coupon applies to the requested licenses count.
     *
     * @param array $coupon Normalized coupon data.
     * @param int   $licenses License count.
     * @return bool
     */
    private function coupon_supports_license( $coupon, $licenses ) {
        if ( empty( $coupon['licenses'] ) ) {
            return true;
        }
        if ( in_array( 0, $coupon['licenses'], true ) ) {
            return true;
        }
        return in_array( (int) $licenses, $coupon['licenses'], true );
    }

    /**
     * Determine if a coupon applies to the billing cycle.
     *
     * @param array  $coupon Normalized coupon data.
     * @param string $cycle  Cycle label ('Monthly'|'Annual').
     * @return bool
     */
    private function coupon_supports_cycle( $coupon, $cycle ) {
        if ( empty( $coupon['cycles'] ) ) {
            return true;
        }
        return in_array( $cycle, $coupon['cycles'], true );
    }

    /**
     * Get the fixed discount value for a currency, if present.
     *
     * @param array  $coupon   Normalized coupon data.
     * @param string $currency Currency code (lowercase).
     * @return float
     */
    private function coupon_fixed_amount( $coupon, $currency ) {
        if ( isset( $coupon['discounts'][ $currency ] ) ) {
            return (float) $coupon['discounts'][ $currency ];
        }
        return (float) ( $coupon['discount'] ?? 0 );
    }

    /**
     * Apply stacked coupons to plan pricing.
     *
     * @param array  $plans    Plans array.
     * @param array  $coupons  Normalized coupon array.
     * @param string $currency Currency code (lowercase).
     * @return array Adjusted pricing map.
     */
    public function apply_coupons_to_pricing( $plans, $coupons, $currency = 'usd' ) {
        $currency = strtolower( $currency );
        $out      = [];

        foreach ( $plans as $pl ) {
            $plan_id = (string) ( $pl['plan_id'] ?? '' );
            if ( '' === $plan_id ) {
                continue;
            }
            $out[ $plan_id ] = [];
            foreach ( $pl['terms'] as $licenses => $term_row ) {
                $current_monthly = isset( $term_row['Monthly'] ) ? $this->money_to_float( $term_row['Monthly'] ) : null;
                $current_annual  = isset( $term_row['Annual'] ) ? $this->money_to_float( $term_row['Annual'] ) : null;

                $licenses_int = (int) $licenses;

                foreach ( [ 'Monthly', 'Annual' ] as $cycle ) {
                    if ( 'Monthly' === $cycle && null === $current_monthly ) {
                        continue;
                    }
                    if ( 'Annual' === $cycle && null === $current_annual ) {
                        continue;
                    }
                    $base_price = ( 'Monthly' === $cycle ) ? $current_monthly : $current_annual;
                    $final      = $base_price;
                    $applied    = [];

                    foreach ( $coupons as $coupon ) {
                        if ( empty( $coupon['is_valid'] ) ) {
                            continue;
                        }
                        if ( ! $this->coupon_supports_plan( $coupon, $plan_id ) ) {
                            continue;
                        }
                        if ( ! $this->coupon_supports_license( $coupon, $licenses_int ) ) {
                            continue;
                        }
                        if ( ! $this->coupon_supports_cycle( $coupon, $cycle ) ) {
                            continue;
                        }

                        $amount_off = 0.0;
                        if ( 'percentage' === $coupon['discount_type'] ) {
                            $amount_off = $final * ( (float) ( $coupon['discount'] ?? 0 ) / 100 );
                        } elseif ( 'dollar' === $coupon['discount_type'] ) {
                            $amount_off = $this->coupon_fixed_amount( $coupon, $currency );
                        }

                        $amount_off = max( 0.0, $amount_off );
                        $amount_off = min( $final, $amount_off );
                        $final     -= $amount_off;

                        if ( $amount_off > 0 ) {
                            $applied[] = [
                                    'id'          => $coupon['id'],
                                    'code'        => $coupon['code'],
                                    'type'        => $coupon['discount_type'],
                                    'scope'       => empty( $coupon['plans'] ) ? 'global' : 'plan',
                                    'amount_off'  => $amount_off,
                                    'cycle'       => $cycle,
                                    'license'     => $licenses_int,
                                    'plan_id'     => $plan_id,
                                    'base_price'  => $base_price,
                                    'final_price' => $final,
                            ];
                        }
                    }

                    $out[ $plan_id ][ $licenses ][ $cycle ] = [
                            'base'    => $base_price,
                            'final'   => $final,
                            'applied' => $applied,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Transforms Freemius pricing data into a structured format for product plans.
     *
     * @param array $fs_data The Freemius data containing product and pricing information.
     * @param array $labels_override Optional. An associative array of custom label overrides for site tiers.
     *
     * @return array An associative array containing 'product' details and 'plans' information.
     */
    public function transform_fs_pricing_to_plans( $fs_data, $labels_override = [] ) {
        $product = [
                'product_name' => '',
                'public_key'   => '',
        ];
        if ( isset( $fs_data['plugin'] ) && is_array( $fs_data['plugin'] ) ) {
            $product['product_name'] = (string) ( $fs_data['plugin']['title'] ?? '' );
            $product['public_key']   = (string) ( $fs_data['plugin']['public_key'] ?? '' );
        }

        $plans_out     = [];
        $best_plan_idx = -1;
        $best_plan_pct = -1;

        $plans = $fs_data['plans'] ?? [];
        foreach ( $plans as $idx => $pl ) {
            if ( ! is_array( $pl ) ) {
                continue;
            }
            $plan_id    = isset( $pl['id'] ) ? (string) $pl['id'] : '';
            $plan_title = (string) ( $pl['title'] ?? ( $pl['name'] ?? '' ) );
            if ( '' === $plan_id || '' === $plan_title ) {
                continue;
            }

            $pricing    = is_array( $pl['pricing'] ?? null ) ? $pl['pricing'] : [];
            $site_tiers = [];
            $terms      = [];
            foreach ( $pricing as $p_idx => $p ) {
                if ( ! is_array( $p ) ) {
                    continue;
                }
                $licenses = (int) ( $p['licenses'] ?? 0 );
                if ( $licenses <= 0 ) {
                    continue;
                }
                if ( isset( $labels_override[ $p_idx ] ) ) {
                    $label = (string) $labels_override[ $p_idx ];
                } else {
                    $label = ( 1 === $licenses ) ? 'Single Site' : ( 'Up to ' . $licenses . ' Sites' );
                }
                $site_tiers[ $label ] = (string) $licenses;

                $row = [];
                if ( isset( $p['monthly_price'] ) && '' !== $p['monthly_price'] ) {
                    $row['Monthly'] = (float) $p['monthly_price'];
                }
                if ( isset( $p['annual_price'] ) && '' !== $p['annual_price'] ) {
                    $row['Annual'] = (float) $p['annual_price'];
                }
                if ( ! empty( $row ) ) {
                    $terms[ (string) $licenses ] = $row;
                }
            }

            if ( empty( $terms ) ) {
                continue;
            }

            $has_trial = false;
            if ( isset( $pl['trial_period'] ) ) {
                $has_trial = ( (int) $pl['trial_period'] ) > 0;
            }

            $plan_pct = 0;
            foreach ( $terms as $row ) {
                if ( isset( $row['Monthly'], $row['Annual'] ) && $row['Monthly'] > 0 && $row['Annual'] > 0 ) {
                    $pct = round( max( 0, ( $row['Monthly'] * 12 ) - $row['Annual'] ) / ( $row['Monthly'] * 12 ) * 100 );
                    if ( $pct > $plan_pct ) {
                        $plan_pct = $pct;
                    }
                }
            }

            $desc     = (string) ( $pl['description'] ?? '' );
            $features = [];
            if ( isset( $pl['features'] ) && is_array( $pl['features'] ) ) {
                foreach ( $pl['features'] as $f ) {
                    if ( is_array( $f ) ) {
                        $title = (string) ( $f['title'] ?? ( $f['name'] ?? '' ) );
                        if ( '' !== $title ) {
                            $features[] = $title;
                        }
                    }
                }
            }

            $is_featured = ! empty( $pl['is_featured'] );
            if ( ! $is_featured && $plan_pct > $best_plan_pct ) {
                $best_plan_pct = $plan_pct;
                $best_plan_idx = $idx;
            }

            $plans_out[] = [
                    'plan_name'  => $plan_title,
                    'plan_id'    => $plan_id,
                    'best_value' => $is_featured,
                    'site_tiers' => $site_tiers,
                    'terms'      => $terms,
                    'desc'       => $desc,
                    'features'   => $features,
                    'has_trial'  => $has_trial,
            ];
        }

        $any_featured = false;
        foreach ( $plans_out as $p ) {
            if ( ! empty( $p['best_value'] ) ) {
                $any_featured = true;
                break;
            }
        }
        if ( ! $any_featured && $best_plan_idx >= 0 && isset( $plans_out[ $best_plan_idx ] ) ) {
            $plans_out[ $best_plan_idx ]['best_value'] = true;
        }

        return [
                'product' => $product,
                'plans'   => $plans_out,
        ];
    }

    /**
     * Parses the provided content to extract and process child shortcodes, generating structured data for plans.
     *
     * @param string $content Content string to parse for child shortcodes.
     * @return array An array of parsed plan details, including metadata, features, site tiers, terms, and other attributes.
     */
    protected function parse_child_shortcodes( $content ) {
        if ( '' === trim( $content ) ) {
            return [];
        }

        $plans   = [];
        $pattern = get_shortcode_regex( [ 'go_plan_column' ] );
        if ( preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                // $m[3] is the attributes string, $m[5] is the inner content (unused here)
                $atts_raw = $m[3] ?? '';
                $atts     = shortcode_parse_atts( $atts_raw );
                if ( ! is_array( $atts ) ) {
                    $atts = [];
                }

                // Normalize keys to lowercase to make attributes case-insensitive.
                $norm = [];
                foreach ( $atts as $k => $v ) {
                    $norm[ strtolower( (string) $k ) ] = is_scalar( $v ) ? (string) $v : '';
                }

                $plan_name        = $norm['plan_name'] ?? '';
                $plan_id          = $norm['plan_id'] ?? '';
                $site_tiers_label = $norm['site_tiers_label'] ?? ( $norm['site_tierslabel'] ?? '' );
                $site_tiers_vals  = $norm['site_tiers'] ?? ( $norm['site_tiersvalue'] ?? '' );
                $monthly          = $norm['monthly'] ?? '';
                $annual           = $norm['annual'] ?? '';
                $plan_desc        = $norm['plan_desc'] ?? '';
                $plan_features    = $norm['plan_features'] ?? '';
                $best_value_raw   = $norm['best_value'] ?? '';
                $has_trial_raw    = $norm['has_trial'] ?? 'true';
                $plan_coupon      = $norm['coupon'] ?? '';
                $plan_discount    = $norm['discount'] ?? '';

                $labels    = array_map( 'trim', $this->parse_pipe_list( $site_tiers_label ) );
                $tiers     = array_map( 'trim', $this->parse_pipe_list( $site_tiers_vals ) );
                $monthly_a = array_map( 'trim', $this->parse_pipe_list( $monthly ) );
                $annual_a  = array_map( 'trim', $this->parse_pipe_list( $annual ) );
                $features  = $this->parse_pipe_list( $plan_features );

                $site_tiers_map = [];
                $count          = max( count( $labels ), count( $tiers ) );
                for ( $i = 0; $i < $count; $i++ ) {
                    $label                    = $labels[ $i ] ?? ( $tiers[ $i ] ?? (string) ( $i + 1 ) );
                    $val                      = $tiers[ $i ] ?? (string) ( $i + 1 );
                    $site_tiers_map[ $label ] = $val;
                }

                $terms     = [];
                $max_terms = max( count( $tiers ), count( $monthly_a ), count( $annual_a ) );
                for ( $i = 0; $i < $max_terms; $i++ ) {
                    $site = isset( $tiers[ $i ] ) ? (string) $tiers[ $i ] : (string) ( $i + 1 );
                    $row  = [];
                    if ( isset( $monthly_a[ $i ] ) && '' !== $monthly_a[ $i ] ) {
                        $row['Monthly'] = $monthly_a[ $i ];
                    }
                    if ( isset( $annual_a[ $i ] ) && '' !== $annual_a[ $i ] ) {
                        $row['Annual'] = $annual_a[ $i ];
                    }
                    if ( ! empty( $row ) ) {
                        $terms[ $site ] = $row;
                    }
                }

                $best_value = false;
                if ( '' !== $best_value_raw ) {
                    $bv         = strtolower( trim( $best_value_raw ) );
                    $best_value = in_array( $bv, [ '1', 'true', 'yes', 'on' ], true );
                }
                $has_trial = true;
                if ( '' !== $has_trial_raw ) {
                    $ht        = strtolower( trim( $has_trial_raw ) );
                    $has_trial = ! in_array( $ht, [ '0', 'false', 'no', 'off' ], true );
                }

                $plan = [
                        'plan_name'  => (string) $plan_name,
                        'plan_id'    => (string) $plan_id,
                        'best_value' => $best_value,
                        'site_tiers' => $site_tiers_map,
                        'terms'      => $terms,
                        'desc'       => (string) $plan_desc,
                        'features'   => $features,
                        'has_trial'  => $has_trial,
                        'coupon'     => (string) $plan_coupon,
                        'discount'   => (string) $plan_discount,
                ];

                if ( '' !== $plan['plan_id'] || '' !== $plan['plan_name'] ) {
                    $plans[] = $plan;
                }
            }
        }

        return $plans;
    }

    // --- Rendering ---

    /**
     * Renders the parent pricing table with various configuration options.
     *
     * @param array       $atts {
     *           Array of attributes for configuring the pricing table.
     *           - public_key (string): The public key used for Freemius integration.
     *           - product_name (string): The name of the product being showcased.
     *           - product_id (string): The unique identifier for the product.
     *           - freemius (string): Defines Freemius mode, options are '', 'manual', or 'automatic'.
     *           - buy_url (string): The URL used for purchasing the product when Freemius mode is empty.
     *           - trial_url (string): The URL for a trial option when Freemius mode is empty.
     *           - product_prefix (string): Prefix used to generate predictable button IDs.
     *           - currency (string): The pricing currency for automatic mode. Default is 'usd'.
     *           - cache_ttl (int): Cache duration (in seconds) for automatic mode API data. Default is 21600 (6 hours).
     *           - site_tiers_label (string): Optional override for site tier labels in automatic mode.
     *           - bearer_constant (string): The name of a constant storing the Freemius Bearer token for authentication.
     *       }.
     * @param string|null $content Content passed to child shortcodes. Used in manual Freemius mode.
     * @return string The generated HTML for the pricing table or an error message if configuration is invalid.
     */
    public function render_parent( $atts, $content = null ) {
        $a = shortcode_atts(
                [
                        'public_key'       => '',
                        'product_name'     => '',
                        'product_id'       => '',
                        'freemius'         => '', // '', 'manual', 'automatic'
                        'buy_url'          => '', // used when freemius is empty. requires full url including http(s)://
                        'trial_url'        => '', // used when freemius is empty. requires full url including http(s)://
                        'product_prefix'   => '', // used to generate predictable button IDs
                    // Automatic mode optional controls:
                        'currency'         => 'usd', // pricing currency for automatic mode
                        'cache_ttl'        => '21600', // seconds (6h) for automatic mode API cache
                        'site_tiers_label' => '', // optional global labels override for automatic mode
                        'bearer_constant'  => '', // name of a defined() constant that stores the Freemius Bearer token
                        'coupon'           => '', // comma-separated Freemius coupon IDs (automatic mode only)
                        'discount'         => '', // manual/empty mode: "$10" or "15%" global discount
                        'redirect_url'     => '', // redirect URL after purchase (skips confirmation dialog)
                ],
                $atts,
                'gopricingtable'
        );

        $freemius_mode = strtolower( trim( (string) $a['freemius'] ) );
        if ( in_array( $freemius_mode, [ 'manual', 'automatic' ], true ) ) {
            wp_enqueue_script( 'freemius-checkout', 'https://checkout.freemius.com/js/v1/', [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }

        $auto_product_name = '';
        $auto_public_key   = '';
        $currency          = 'usd';
        $coupon_meta       = [
                'codes_global' => [],
                'codes_plan'   => [],
                'ids'          => [],
        ];

        if ( 'automatic' === $freemius_mode ) {
            $product_id = (string) $a['product_id'];
            if ( '' === trim( $product_id ) ) {
                return '<div class="go-pt-error">Automatic mode requires product_id.</div>';
            }
            $const_name = trim( (string) $a['bearer_constant'] );
            if ( '' === $const_name ) {
                return '<div class="go-pt-error">Automatic mode requires bearer_constant (the name of a defined API token constant).</div>';
            }
            if ( ! defined( $const_name ) ) {
                return '<div class="go-pt-error">Freemius API token constant not defined: ' . esc_html( $const_name ) . '.</div>';
            }
            $bearer = (string) constant( $const_name );
            if ( '' === trim( $bearer ) ) {
                return '<div class="go-pt-error">Freemius API token is empty.</div>';
            }
            $currency = strtolower( preg_replace( '/[^a-z]/i', '', (string) $a['currency'] ) );
            if ( '' === $currency ) {
                $currency = 'usd';
            }
            $cache_ttl = (int) $a['cache_ttl'];
            if ( $cache_ttl < 0 ) {
                $cache_ttl = 0;
            }

            $fs_data = $this->fetch_freemius_pricing_table( $product_id, $currency, $cache_ttl, $bearer );
            if ( is_wp_error( $fs_data ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'GO Pricing Table automatic mode error: ' . $fs_data->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
                return '<div class="go-pt-error">Unable to load pricing at the moment.</div>';
            }

            $labels_override   = $this->parse_pipe_list( (string) $a['site_tiers_label'] );
            $transformed       = $this->transform_fs_pricing_to_plans( $fs_data, $labels_override );
            $plans             = $transformed['plans'];
            $auto_product_name = $transformed['product']['product_name'] ?? '';
            $auto_public_key   = $transformed['product']['public_key'] ?? '';

            // Optional coupons (automatic mode only)
            $pricing_with_coupons = [];
            $coupon_ids           = $this->parse_coupon_ids( (string) $a['coupon'] );
            if ( ! empty( $coupon_ids ) ) {
                $normalized_coupons = [];
                foreach ( $coupon_ids as $cid ) {
                    $coupon_raw = $this->fetch_freemius_coupon( $product_id, $cid, $currency, $cache_ttl, $bearer );
                    if ( is_wp_error( $coupon_raw ) ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'GO Pricing Table coupon fetch error (' . $cid . '): ' . $coupon_raw->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        }
                        continue;
                    }
                    $norm = $this->normalize_coupon_record( $coupon_raw );
                    if ( ! empty( $norm['is_valid'] ) ) {
                        $normalized_coupons[] = $norm;
                        if ( empty( $norm['plans'] ) ) {
                            $coupon_meta['codes_global'][] = $norm['code'];
                        } else {
                            foreach ( $norm['plans'] as $pid ) {
                                if ( ! isset( $coupon_meta['codes_plan'][ $pid ] ) ) {
                                    $coupon_meta['codes_plan'][ $pid ] = [];
                                }
                                $coupon_meta['codes_plan'][ $pid ][] = $norm['code'];
                            }
                        }
                        $coupon_meta['ids'][] = $norm['id'];
                    }
                }

                if ( ! empty( $normalized_coupons ) ) {
                    $pricing_with_coupons = $this->apply_coupons_to_pricing( $plans, $normalized_coupons, $currency );
                }
            }
        } else {
            $plans                = $this->parse_child_shortcodes( (string) $content );
            $pricing_with_coupons = [];

            // Manual/global coupons & discounts
            $manual_coupons = [];

            $global_discount = $this->parse_discount_string( (string) $a['discount'] );
            $global_code     = trim( (string) $a['coupon'] );
            if ( $global_discount || '' !== $global_code ) {
                $manual_coupons[] = [
                        'is_valid'          => true,
                        'id'                => 'manual-global',
                        'code'              => '' !== $global_code ? $global_code : 'DISCOUNT',
                        'discount_type'     => $global_discount['type'] ?? 'dollar',
                        'discount'          => $global_discount['amount'] ?? 0,
                        'discounts'         => [],
                        'plans'             => [],
                        'licenses'          => [],
                        'cycles'            => [],
                        'redemptions_limit' => null,
                        'redemptions'       => 0,
                        'start_ts'          => null,
                        'end_ts'            => null,
                ];
                if ( '' !== $global_code ) {
                    $coupon_meta['codes_global'][] = $global_code;
                    $coupon_meta['ids'][]          = 'manual-global';
                }
            }

            foreach ( $plans as $pl ) {
                $plan_disc  = $this->parse_discount_string( $pl['discount'] ?? '' );
                $plan_code  = trim( (string) ( $pl['coupon'] ?? '' ) );
                $plan_id    = (string) ( $pl['plan_id'] ?? '' );
                $use_coupon = ( $plan_disc || '' !== $plan_code ) && '' !== $plan_id;
                if ( $use_coupon ) {
                    $manual_coupons[] = [
                            'is_valid'          => true,
                            'id'                => 'manual-plan-' . $plan_id,
                            'code'              => '' !== $plan_code ? $plan_code : 'DISCOUNT',
                            'discount_type'     => $plan_disc['type'] ?? 'dollar',
                            'discount'          => $plan_disc['amount'] ?? 0,
                            'discounts'         => [],
                            'plans'             => [ $plan_id ],
                            'licenses'          => [],
                            'cycles'            => [],
                            'redemptions_limit' => null,
                            'redemptions'       => 0,
                            'start_ts'          => null,
                            'end_ts'            => null,
                    ];
                    if ( '' !== $plan_code ) {
                        if ( ! isset( $coupon_meta['codes_plan'][ $plan_id ] ) ) {
                            $coupon_meta['codes_plan'][ $plan_id ] = [];
                        }
                        $coupon_meta['codes_plan'][ $plan_id ][] = $plan_code;
                        $coupon_meta['ids'][]                    = 'manual-plan-' . $plan_id;
                    }
                }
            }

            if ( ! empty( $manual_coupons ) ) {
                $pricing_with_coupons = $this->apply_coupons_to_pricing( $plans, $manual_coupons, $currency );
            }
        }

        if ( empty( $plans ) ) {
            return '<div class="go-pt-error">No plans found in [gopricingtable].</div>';
        }

        // Compute global site tiers
        $global_site_tiers = [];
        foreach ( $plans as $pl ) {
            foreach ( $pl['site_tiers'] as $label => $licenses ) {
                if ( ! isset( $global_site_tiers[ $label ] ) ) {
                    $global_site_tiers[ $label ] = $licenses;
                }
            }
        }

        // Detect available billing cycles and compute save pct
        $has_monthly = false;
        $has_annual  = false;
        $save_pct    = 0;
        foreach ( $plans as $pl ) {
            foreach ( $pl['terms'] as $t ) {
                if ( isset( $t['Monthly'] ) ) {
                    $has_monthly = true;
                }
                if ( isset( $t['Annual'] ) ) {
                    $has_annual = true;
                }
                if ( ! isset( $t['Monthly'], $t['Annual'] ) ) {
                    continue;
                }
                $m        = $this->money_to_float( $t['Monthly'] );
                $annually = $this->money_to_float( $t['Annual'] );
                if ( $m > 0 && $annually > 0 ) {
                    $pct = round( max( 0, ( $m * 12 ) - $annually ) / ( $m * 12 ) * 100 );
                    if ( $pct > $save_pct ) {
                        $save_pct = $pct;
                    }
                }
            }
        }
        $show_toggle = $has_monthly && $has_annual;

        // Prepare predictable prefix for button IDs
        $prefix = (string) $a['product_prefix'];
        if ( '' === trim( $prefix ) ) {
            $fallback = (string) ( ( $auto_product_name ?: $a['product_name'] ) ?: 'go-pt' );
            $prefix   = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $fallback ) );
            $prefix   = trim( $prefix, '-' );
        }

        $payload = [
                'product'           => [
                        'product_name' => (string) ( $auto_product_name ?: $a['product_name'] ),
                        'product_id'   => (string) $a['product_id'],
                        'public_key'   => (string) ( $auto_public_key ?: $a['public_key'] ),
                ],
                'plans'             => $plans,
                'global_site_tiers' => $global_site_tiers,
                'ui'                => [
                        'default_cycle' => $has_annual ? 'Annual' : 'Monthly',
                        'save_pct'      => $save_pct,
                        'show_toggle'   => $show_toggle,
                ],
                'freemius'          => $freemius_mode,
                'pricing'           => $pricing_with_coupons,
                'coupons'           => [
                        'codes'        => $coupon_meta['codes_global'],
                        'codes_global' => $coupon_meta['codes_global'],
                        'codes_plan'   => $coupon_meta['codes_plan'],
                        'ids'          => $coupon_meta['ids'],
                        'stack'        => true,
                ],
                'urls'              => [
                        'buy'      => esc_url_raw( (string) $a['buy_url'] ),
                        'trial'    => esc_url_raw( (string) $a['trial_url'] ),
                        'redirect' => esc_url_raw( (string) $a['redirect_url'] ),
                ],
                'ident'             => [
                        'prefix' => $prefix,
                ],
                'currency'          => strtoupper( $currency ),
        ];

        ob_start();
        ?>
        <section class="go-pt" data-component="go-pricing-table">
            <?php if ( $show_toggle ) : ?>
                <div class="go-pt-toggle" role="group" aria-label="Billing cycle">
                    <span class="go-pt-toggle__label" data-go-pt="label-monthly">Pay monthly</span>
                    <button class="go-pt-toggle__switch" type="button" aria-pressed="true" data-go-pt="cycle-switch">
                        <span class="go-pt-toggle__knob" aria-hidden="true"></span>
                    </button>
                    <span class="go-pt-toggle__label is-active" data-go-pt="label-annually">Pay annually</span>
                    <span class="go-pt-toggle__save" data-go-pt="save-banner">Save up to <?php echo esc_html( $save_pct ); ?>%</span>
                </div>
            <?php endif; ?>

            <div class="go-pt-coupon-banner" data-go-pt="coupon-banner" aria-live="polite"></div>

            <div class="go-pt-grid" data-go-pt="grid">
                <?php
                foreach ( $plans as $idx => $pl ) :
                    $plan_key = 'plan-' . ( $idx + 1 );
                    $is_best  = ! empty( $pl['best_value'] );
                    ?>
                    <article class="go-pt-card <?php echo $is_best ? 'is-best' : ''; ?>" data-go-pt="card" data-plan-key="<?php echo esc_attr( $plan_key ); ?>" data-plan-id="<?php echo esc_attr( $pl['plan_id'] ); ?>" data-plan-name="<?php echo esc_attr( $pl['plan_name'] ); ?>">
                        <?php if ( $is_best ) : ?>
                            <div class="go-pt-card__badge">Best Value</div>
                        <?php endif; ?>

                        <div class="go-pt-card__coupon" data-go-pt="card-coupon-banner" aria-live="polite"></div>

                        <header class="go-pt-card__header">
                            <h3 class="go-pt-card__title"><?php echo esc_html( $pl['plan_name'] ); ?></h3>
                            <div class="go-pt-card__price" data-go-pt="price">
                                <div class="go-pt-card__price-main">
                                    <span class="go-pt-card__price-old" data-go-pt="price-old"></span>
                                    <span class="go-pt-card__price-amount" data-go-pt="price-amount">—</span>
                                </div>
                                <span class="go-pt-card__price-term" data-go-pt="price-term">per year</span>
                                <div class="go-pt-card__price-sub" data-go-pt="price-sub">
                                    <span class="go-pt-card__price-sub-old" data-go-pt="price-sub-old"></span>
                                    <span class="go-pt-card__price-sub-new" data-go-pt="price-sub-new"></span>
                                </div>
                            </div>
                        </header>

                        <div class="go-pt-card__controls">
                            <?php
                            $tiers = $pl['site_tiers'];
                            if ( count( $tiers ) > 1 ) :
                                ?>
                                <label class="go-pt-card__label" for="<?php echo esc_attr( $plan_key ); ?>-tier">Sites</label>
                                <select class="go-pt-card__select" id="<?php echo esc_attr( $plan_key ); ?>-tier" data-go-pt="tier-select">
                                    <?php foreach ( $tiers as $label => $val ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php
                            else :
                                $label = array_key_first( $tiers );
                                $val   = $tiers[ $label ];
                                ?>
                                <div class="go-pt-card__tier" data-go-pt="tier-static" data-tier-value="<?php echo esc_attr( $val ); ?>">
                                    <?php echo esc_html( $label ); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="go-pt-card__cta">
                            <?php $btn_base_id = $prefix . '-' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $pl['plan_name'] ) ); ?>
                            <button id="<?php echo esc_attr( $btn_base_id ); ?>-buy-button" class="go-pt-btn go-pt-btn--buy <?php echo $is_best ? 'go-pt-btn--best' : ''; ?>"
                                    data-go-pt="buy"
                                    data-plan-id="<?php echo esc_attr( $pl['plan_id'] ); ?>"
                                    data-plan-name="<?php echo esc_attr( $pl['plan_name'] ); ?>">
                                Buy Now
                            </button>
                            <?php if ( ! empty( $pl['has_trial'] ) ) : ?>
                                <button id="<?php echo esc_attr( $btn_base_id ); ?>-trial-button" class="go-pt-btn go-pt-btn--trial <?php echo $is_best ? 'go-pt-btn--best-alt' : ''; ?>"
                                        data-go-pt="trial"
                                        data-plan-id="<?php echo esc_attr( $pl['plan_id'] ); ?>"
                                        data-plan-name="<?php echo esc_attr( $pl['plan_name'] ); ?>">
                                    Free Trial
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ( ! empty( $pl['desc'] ) ) : ?>
                            <p class="go-pt-card__desc"><?php echo esc_html( $pl['desc'] ); ?></p>
                        <?php endif; ?>

                        <?php if ( ! empty( $pl['features'] ) ) : ?>
                            <ul class="go-pt-features" data-go-pt="features">
                                <?php foreach ( $pl['features'] as $feat ) : ?>
                                    <li class="go-pt-feature">
                                        <span class="go-pt-feature__icon" aria-hidden="true">✓</span>
                                        <span class="go-pt-feature__text"><?php echo esc_html( $feat ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                                <li class="go-pt-feature" data-go-pt="live-sites">
                                    <span class="go-pt-feature__icon" aria-hidden="true">✓</span>
                                    <span class="go-pt-feature__text">Use on up to <strong data-go-pt="live-sites-count">—</strong> live sites</span>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <script type="application/json" class="go-pt-data"><?php echo wp_json_encode( $payload ); ?></script>
        </section>
        <?php

        // Print CSS + JS once per page load
        if ( ! $this->assets_printed ) {
            $this->assets_printed = true;
            $this->render_assets_once();
        }

        return ob_get_clean();
    }

    /**
     * Renders the necessary assets (CSS and JavaScript) for the page, ensuring they are included only once.
     *
     * This method generates inline styles and JavaScript for the component functionality,
     * including but not limited to features such as toggles, grids, and dynamic content updates.
     *
     * @return void
     */
    private function render_assets_once() {
        ?>
        <style>
            /* Root   --go-pt-muted: #b6b2d6; */
            .go-pt {
                --go-pt-bg: #0d0b1a;
                --go-pt-surface: #15122a;
                --go-pt-accent: #7637E1;
                --go-pt-accent-2: #30d2ff;
                --go-pt-text: #e9e7ff;
                --go-pt-muted: #b6b2d6;
                --go-pt-green: #38c172;
                --go-pt-yellow: #ffd166;

                color: var(--go-pt-text);
                background: transparent;
                font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
                position: relative;
            }

            /* --- Toggle --- */
            .go-pt-toggle {
                display: flex;
                align-items: center;
                gap: .75rem;
                margin-bottom: 1.25rem;
                justify-content: center;
                flex-wrap: wrap;
                row-gap: .5rem;
            }

            .go-pt-toggle__label {
                color: black;
                font-size: 14px;
                line-height: 1;
                opacity: .55;
                transition: opacity .2s, color .2s
            }

            .go-pt-toggle__label.is-active {
                opacity: 1;
                color: black !important;
                font-weight: 600
            }

            .go-pt-toggle__switch {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: flex-end; /* default = Annual */
                width: 58px;
                height: 28px;
                border-radius: 999px;
                background: #3a3a4a;
                padding: 3px;
                border: 2px solid #65d3ff;
                cursor: pointer;
                transition: background .2s, justify-content .2s
            }

            .go-pt-toggle__switch[aria-pressed="false"] { /* Monthly */
                justify-content: flex-start;
                background: #6a5acd;
            }

            .go-pt-toggle__knob {
                width: 20px;
                height: 20px;
                border-radius: 50%;
                background: #fff;
                box-shadow: 0 1px 2px rgba(0, 0, 0, .25)
            }

            .go-pt-toggle__save {
                padding: .25rem .6rem;
                margin-left: .25rem;
                font-size: 13px !important;
                font-weight: 800;
                letter-spacing: .02em;
                text-transform: uppercase;

                color: #fff;
                background: linear-gradient(135deg, var(--go-pt-accent), var(--go-pt-accent-2));
                border: 1px solid rgba(255, 255, 255, .10);
                border-radius: 999px;

                box-shadow: 0 10px 22px rgba(0,0,0,.28);
                white-space: nowrap;
            }

            /* Coupon banner */
            .go-pt-coupon-banner{
                position:absolute;
                top:0;
                left: 50%;
                transform:translate(-50%,-110%);
                background: linear-gradient(135deg, var(--go-pt-accent), var(--go-pt-accent-2));
                color:#fff;
                padding:.45rem 2.4rem;
                font-weight:800;
                letter-spacing:.05em;
                text-transform:uppercase;
                font-size:.85rem;
                box-shadow:0 12px 28px rgba(0,0,0,.35);
                z-index:3;
                display:none;
                pointer-events:none;
                border-radius: 50px;
                white-space: nowrap;
            }
            .go-pt-coupon-banner.is-active{display:block;}
            @media (max-width: 899.98px) {
                .go-pt-coupon-banner{
                    padding:.4rem 1rem;
                    font-size:.8rem;
                    width: 90%;
                    text-align: center;
                    white-space: normal;
                }
            }

            /* Plan-specific coupon bar */
            .go-pt-card__coupon{
                position:absolute;
                top:-0.75rem;
                left:auto;
                right:1.25rem;
                transform:translate(0,-100%);
                background: linear-gradient(135deg, var(--go-pt-accent), var(--go-pt-accent-2));
                color:#fff;
                padding:.35rem .9rem;
                border-radius: 8px;
                font-weight:700;
                font-size:.85rem;
                box-shadow:0 10px 22px rgba(0,0,0,.28);
                display:none;
                pointer-events:none;
                white-space:nowrap;
            }
            .go-pt-card__coupon.is-active{display:inline-block;}


            /* Grid: desktop = columns, mobile = stacked cards */
            .go-pt-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1rem; }
            @media (min-width: 900px) { .go-pt-grid > .go-pt-card { grid-column: span 4; } }
            @media (max-width: 899.98px) {
                .go-pt-grid { grid-template-columns: 1fr; }
            }

            /* Card */
            .go-pt-card {
                background: var(--go-pt-surface);
                border: 1px solid rgba(255,255,255,.06);
                border-radius: 16px;
                padding: 1.25rem;
                position: relative;
                box-shadow: 0 6px 24px rgba(0,0,0,.25);
            }
            .go-pt-card.is-best {
                border-color: var(--go-pt-accent);
                box-shadow: 0 10px 28px rgba(118,55,225,.35);
                background-image: linear-gradient(90deg, rgba(117,58,225,0.2), rgba(51,201,253,0.2));
            }
            .go-pt-card__badge {
                position: absolute; top: -16px; left: 16px;
                background: var(--go-pt-accent);
                color: #fff; font-weight: 700; font-size: .8rem;
                padding: .25rem .6rem; border-radius: 999px;
            }

            /* Header */
            .go-pt-card__header { display: flex; justify-content: space-between; align-items: baseline; gap: .5rem; }
            .go-pt-card__title { margin: 0; font-weight: 700; font-size: 1.35rem; color:white; }
            .go-pt-card__price { text-align: right; }
            .go-pt-card__price-main { display: flex; gap: .4rem; align-items: baseline; justify-content: flex-end; }
            .go-pt-card__price-amount { font-size: 1.6rem; font-weight: 800; color: var(--go-pt-text); }
            .go-pt-card__price-amount.is-discounted { color: #4ade80; }
            .go-pt-card__price-amount.is-error { font-size: .9rem; font-weight: 600; color: var(--go-pt-muted); }
            .go-pt-card__price-old { font-size: .95rem; color: var(--go-pt-muted); text-decoration: line-through; opacity: .8; min-width: 0; }
            .go-pt-card__price-term { display: block; font-size: .9rem; color: var(--go-pt-muted); }
            .go-pt-card__price-sub { display:flex; gap:.35rem; justify-content:flex-end; align-items: baseline; font-size:.85rem; color: var(--go-pt-muted); }
            .go-pt-card__price-sub-old { text-decoration: line-through; opacity: .8; }
            .go-pt-card__price-sub-new { font-weight: 700; color: var(--go-pt-text); }

            /* Controls */
            .go-pt-card__controls { display: flex; align-items: center; gap: .5rem; margin-top: 1rem; }
            .go-pt-card__label { font-size: .9rem; color: var(--go-pt-muted); }
            .go-pt-card__select {
                width: 100%;
                background: #0f0d21; color: var(--go-pt-text);
                border: 1px solid #2c274a; border-radius: 10px;
                padding: .5rem .6rem;
            }
            .go-pt-card__tier { font-size: .95rem; font-weight: 600; color: var(--go-pt-text); }

            /* CTAs */
            .go-pt-card__cta { display: flex; gap: .6rem; margin-top: 1rem; }
            .go-pt-btn {
                appearance: none;
                border: 0; border-radius: 12px;
                padding: .7rem 1rem; cursor: pointer; font-weight: 700;
                background: #2e2951; color: #fff;
            }
            .go-pt-btn--buy    { background: var(--go-pt-accent); }
            .go-pt-btn--trial  { background: #2e2951; border: 1px solid #3f396a; }
            .go-pt-btn--best       { background: linear-gradient(90deg, var(--go-pt-accent), var(--go-pt-accent-2)); }
            .go-pt-btn--best-alt   { background: #1b1734; border: 1px solid var(--go-pt-accent); }

            /* Text */
            .go-pt-card__desc { margin: .9rem 0; color: var(--go-pt-muted); }

            /* Features */
            .go-pt-features { list-style: none; padding: 0; margin: .25rem 0 0 0; display: grid; gap: .35rem; }
            .go-pt-feature { display: grid; grid-template-columns: 20px 1fr; align-items: start; gap: .5rem; }
            .go-pt-feature__icon { color: var(--go-pt-green); font-weight: 800; }
            .go-pt-feature__text { color: var(--go-pt-text); text-align:left; }

            /* Accessibility focus */
            .go-pt-btn:focus,
            .go-pt-card__select:focus,
            .go-pt-toggle__switch:focus {
                outline: 2px solid var(--go-pt-accent-2);
                outline-offset: 2px;
            }
        </style>

        <script>
            /* BrightLeaf GO Pricing Table logic */
            (function () {
                function parseDataPayload(root) {
                    const tag = root.querySelector('.go-pt-data');
                    if (!tag) return null;
                    try { return JSON.parse(tag.textContent); } catch { return null; }
                }
                function money(x) {
                    const num = typeof x === 'number' ? x : parseFloat(String(x).replace(/[$,]/g, '')) || 0;
                    return '$' + num.toFixed(2);
                }
                const FALLBACK_TEXT = 'Temporarily unavailable. Please refresh.';
                function setErrorForCard(card) {
                    const priceAmount = card.querySelector('[data-go-pt="price-amount"]');
                    const priceTerm   = card.querySelector('[data-go-pt="price-term"]');
                    const priceSubOld = card.querySelector('[data-go-pt="price-sub-old"]');
                    const priceSubNew = card.querySelector('[data-go-pt="price-sub-new"]');
                    if (priceAmount) {
                        priceAmount.textContent = FALLBACK_TEXT;
                        priceAmount.classList.add('is-error');
                        priceAmount.classList.remove('is-discounted');
                    }
                    if (priceTerm) { priceTerm.style.display = 'none'; }
                    if (priceSubOld) { priceSubOld.textContent = ''; priceSubOld.style.display = 'none'; }
                    if (priceSubNew) { priceSubNew.textContent = ''; priceSubNew.style.display = 'none'; }
                }
                function updateCardPrice(card, cycle, licenses, priceInfo) {
                    const priceAmount = card.querySelector('[data-go-pt="price-amount"]');
                    const priceOld    = card.querySelector('[data-go-pt="price-old"]');
                    const priceTerm   = card.querySelector('[data-go-pt="price-term"]');
                    const priceSubOld = card.querySelector('[data-go-pt="price-sub-old"]');
                    const priceSubNew = card.querySelector('[data-go-pt="price-sub-new"]');
                    const liveSites   = card.querySelector('[data-go-pt="live-sites-count"]');

                    let basePrice = null;
                    if (priceInfo) {
                        if (typeof priceInfo.base === 'number') {
                            basePrice = priceInfo.base;
                        }
                    }
                    let finalPrice = basePrice;
                    if (priceInfo) {
                        if (typeof priceInfo.final === 'number') {
                            finalPrice = priceInfo.final;
                        }
                    }
                    let hasDiscount = false;
                    if (basePrice != null) {
                        if (finalPrice != null) {
                            if (finalPrice + 1e-3 < basePrice) {
                                hasDiscount = true;
                            }
                        }
                    }

                    if (cycle === 'Annual') {
                        const baseAnnual  = basePrice;
                        const finalAnnual = finalPrice;
                        const baseMonthly = baseAnnual != null ? baseAnnual / 12 : null;
                        const finalMonthly = finalAnnual != null ? finalAnnual / 12 : null;

                        if (priceAmount) {
                            if (finalMonthly != null) {
                                priceAmount.textContent = money(finalMonthly);
                                priceAmount.classList.remove('is-error');
                            } else {
                                priceAmount.textContent = FALLBACK_TEXT;
                                priceAmount.classList.add('is-error');
                            }
                        }
                        if (priceOld) {
                            priceOld.textContent = hasDiscount ? (baseMonthly != null ? money(baseMonthly) : '') : '';
                            priceOld.style.display = priceOld.textContent ? '' : 'none';
                        }
                        if (priceTerm)   priceTerm.textContent   = 'per month (paid annually)';
                        if (priceSubNew) {
                            priceSubNew.textContent = finalAnnual != null ? money(finalAnnual) + ' per year' : '';
                            priceSubNew.style.display = priceSubNew.textContent ? '' : 'none';
                        }
                        if (priceSubOld) {
                            priceSubOld.textContent = hasDiscount ? (baseAnnual != null ? money(baseAnnual) + ' per year' : '') : '';
                            priceSubOld.style.display = priceSubOld.textContent ? '' : 'none';
                        }
                    } else {
                        if (priceAmount) {
                            if (finalPrice != null) {
                                priceAmount.textContent = money(finalPrice);
                                priceAmount.classList.remove('is-error');
                            } else {
                                priceAmount.textContent = FALLBACK_TEXT;
                                priceAmount.classList.add('is-error');
                            }
                        }
                        if (priceOld) {
                            priceOld.textContent = hasDiscount ? (basePrice != null ? money(basePrice) : '') : '';
                            priceOld.style.display = priceOld.textContent ? '' : 'none';
                        }
                        if (priceTerm)   priceTerm.textContent   = 'per month';
                        if (priceSubNew) { priceSubNew.textContent = ''; priceSubNew.style.display = 'none'; }
                        if (priceSubOld) { priceSubOld.textContent = ''; priceSubOld.style.display = 'none'; }
                    }

                    if (priceAmount) priceAmount.classList.toggle('is-discounted', !!hasDiscount);
                    if (liveSites)   liveSites.textContent   = String(licenses);
                }
                function syncTierDropdowns(root, selectedVal) {
                    root.querySelectorAll('[data-go-pt="tier-select"]').forEach(sel => {
                        if ([...sel.options].some(o => o.value === String(selectedVal))) {
                            sel.value = String(selectedVal);
                        }
                    });
                }
                function resolveLicenseRow(map, licenses) {
                    if (map[String(licenses)]) return { row: map[String(licenses)], license: licenses };
                    const candidates = Object.keys(map).map(n => parseInt(n, 10)).filter(n => !Number.isNaN(n)).sort((a, b) => a - b);
                    if (!candidates.length) return { row: null, license: licenses };
                    let nearest = candidates[0];
                    let bestDiff = Math.abs(nearest - licenses);
                    candidates.forEach(c => {
                        const d = Math.abs(c - licenses);
                        if (d < bestDiff) { nearest = c; bestDiff = d; }
                    });
                    return { row: map[String(nearest)], license: nearest };
                }

                function init(container) {
                    const data = parseDataPayload(container);
                    if (!data) {
                        // mark visible, but indicate error to the user for this container
                        container.querySelectorAll('[data-go-pt="card"]').forEach(setErrorForCard);
                        return false;
                    }

                    const product = data.product || {};
                    const plans   = data.plans || [];
                    const globalTiers = data.global_site_tiers || {};
                    const ui = data.ui || { default_cycle: 'Annual', save_pct: 0 };
                    const pricing = data.pricing || {};
                    const coupons = data.coupons || {};
                    let couponGlobalCodes = [];
                    if (Array.isArray(coupons.codes_global)) {
                        couponGlobalCodes = coupons.codes_global;
                    } else if (Array.isArray(coupons.codes)) {
                        couponGlobalCodes = coupons.codes;
                    }
                    const couponPlanCodes = coupons.codes_plan || {};

                    let billingCycle = ui.default_cycle || 'Annual';
                    let globalTierValue = parseInt(Object.values(globalTiers)[0] || 1, 10);

                    const planTermsBase = {};
                    plans.forEach(pl => {
                        const map = {};
                        Object.keys(pl.terms || {}).forEach(siteN => {
                            const t = pl.terms[siteN] || {};
                            const out = {};
                            if (t.Monthly != null) out.Monthly = parseFloat(String(t.Monthly).replace(/[$,]/g, '')) || 0;
                            if (t.Annual  != null) out.Annual  = parseFloat(String(t.Annual).replace(/[$,]/g, ''))  || 0;
                            map[siteN] = out;
                        });
                        planTermsBase[pl.plan_id] = map;
                    });

                    const toggle = container.querySelector('[data-go-pt="cycle-switch"]');
                    const saveBanner = container.querySelector('[data-go-pt="save-banner"]');
                    const labelMonthly  = container.querySelector('[data-go-pt="label-monthly"]');
                    const labelAnnually = container.querySelector('[data-go-pt="label-annually"]');
                    const couponBanner = container.querySelector('[data-go-pt="coupon-banner"]');
                    const showToggle = ui.show_toggle !== false;

                    function getPriceInfo(planId, licenses, cycle) {
                        const adjustedPlan = pricing[planId] || {};
                        const basePlan = planTermsBase[planId] || {};
                        const adjusted = resolveLicenseRow(adjustedPlan, licenses);
                        const base = resolveLicenseRow(basePlan, licenses);
                        const adjustedRow = adjusted.row;
                        const baseRow = base.row;

                        const slotAdjusted = adjustedRow ? adjustedRow[cycle] : null;
                        const slotBase = baseRow ? baseRow[cycle] : null;

                        let baseValue = null;
                        if (slotBase != null) {
                            baseValue = parseFloat(slotBase);
                        } else if (slotAdjusted != null) {
                            if (slotAdjusted.base != null) {
                                baseValue = parseFloat(slotAdjusted.base);
                            }
                        }

                        let finalValue = null;
                        if (slotAdjusted != null) {
                            if (typeof slotAdjusted.final === 'number') {
                                finalValue = slotAdjusted.final;
                            }
                        }
                        if (finalValue === null && slotBase != null) {
                            finalValue = parseFloat(slotBase);
                        }

                        let applied = [];
                        if (slotAdjusted != null) {
                            if (Array.isArray(slotAdjusted.applied)) {
                                applied = slotAdjusted.applied;
                            }
                        }

                        return {
                            base: baseValue,
                            final: finalValue,
                            applied,
                            licenseUsed: adjusted.row ? adjusted.license : (base.row ? base.license : licenses)
                        };
                    }

                    function updateCouponBanner() {
                        if (!couponBanner) return;
                        const primary = plans.find(p => p.best_value) || plans[0];
                        if (!primary) {
                            couponBanner.classList.remove('is-active');
                            couponBanner.textContent = '';
                            return;
                        }
                        const info = getPriceInfo(primary.plan_id, globalTierValue, billingCycle);
                        const base = typeof info.base === 'number' ? info.base : null;
                        const final = typeof info.final === 'number' ? info.final : null;
                        let diff = 0;
                        if (base != null) {
                            if (final != null) {
                                diff = base - final;
                            }
                        }
                        const globalApplied = (info.applied || []).filter(a => a.scope !== 'plan');
                        if (!globalApplied.length || !diff || diff <= 0) {
                            couponBanner.classList.remove('is-active');
                            couponBanner.textContent = '';
                            return;
                        }
                        if (!diff || diff <= 0) {
                            couponBanner.classList.remove('is-active');
                            couponBanner.textContent = '';
                            return;
                        }
                        const cycleLabel = billingCycle === 'Annual' ? 'year' : 'month';
                        couponBanner.textContent = `${money(diff)} off this ${cycleLabel}`;
                        couponBanner.classList.add('is-active');
                    }

                    if (saveBanner) saveBanner.textContent = `Save up to ${ui.save_pct || 0}%`;

                    const setCycle = (cycle) => {
                        billingCycle = cycle;

                        if (cycle === 'Annual') {
                            if (toggle) toggle.setAttribute('aria-pressed', 'true');
                            if (labelAnnually) labelAnnually.classList.add('is-active');
                            if (labelMonthly) labelMonthly.classList.remove('is-active');
                            if (saveBanner) saveBanner.style.display = 'none';
                        } else {
                            if (toggle) toggle.setAttribute('aria-pressed', 'false');
                            if (labelMonthly) labelMonthly.classList.add('is-active');
                            if (labelAnnually) labelAnnually.classList.remove('is-active');
                            if (saveBanner) { saveBanner.style.display = ''; saveBanner.textContent = `Switch to Pay Annually and save up to ${ui.save_pct || 0}%`; }
                        }

                        container.querySelectorAll('[data-go-pt="card"]').forEach(card => {
                            const planId = card.getAttribute('data-plan-id');
                            let licenses = globalTierValue;
                            const sel = card.querySelector('[data-go-pt="tier-select"]');
                            if (sel) licenses = parseInt(sel.value, 10);
                            else {
                                const staticEl = card.querySelector('[data-go-pt="tier-static"]');
                                if (staticEl) licenses = parseInt(staticEl.getAttribute('data-tier-value') || globalTierValue, 10);
                            }
                            const info = getPriceInfo(planId, licenses, billingCycle);
                            updateCardPrice(card, billingCycle, info.licenseUsed || licenses, info);
                            const cardBanner = card.querySelector('[data-go-pt="card-coupon-banner"]');
                            if (cardBanner) {
                                const planApplied = (info.applied || []).filter(a => a.scope === 'plan');
                                const cardBase = typeof info.base === 'number' ? info.base : null;
                                const cardFinal = typeof info.final === 'number' ? info.final : null;
                                let cardDiff = 0;
                                if (cardBase != null) {
                                    if (cardFinal != null) {
                                        cardDiff = cardBase - cardFinal;
                                    }
                                }
                                if (planApplied.length > 0) {
                                    if (cardDiff > 0) {
                                        const codesLabel = planApplied.map(a => a.code).filter(Boolean).slice(0,3).join(' + ') || (couponPlanCodes[planId] || []).slice(0,3).join(' + ');
                                        const cycleLabel = billingCycle === 'Annual' ? 'year' : 'month';
                                        cardBanner.textContent = `${codesLabel}: -${money(cardDiff)} this ${cycleLabel}`;
                                        cardBanner.classList.add('is-active');
                                    } else {
                                        cardBanner.classList.remove('is-active');
                                        cardBanner.textContent = '';
                                    }
                                } else {
                                    cardBanner.classList.remove('is-active');
                                    cardBanner.textContent = '';
                                }
                            }
                        });
                        updateCouponBanner();
                    };

                    if (toggle && showToggle) {
                        toggle.addEventListener('click', () => {
                            setCycle(billingCycle === 'Annual' ? 'Monthly' : 'Annual');
                        });
                    }

                    container.addEventListener('change', (e) => {
                        const sel = e.target.closest('[data-go-pt="tier-select"]');
                        if (!sel) return;
                        globalTierValue = parseInt(sel.value, 10);
                        syncTierDropdowns(container, globalTierValue);
                        setCycle(billingCycle);
                    });

                    const firstSel = container.querySelector('[data-go-pt="tier-select"]');
                    if (firstSel) globalTierValue = parseInt(firstSel.value, 10);

                    setCycle(billingCycle);

                    function currentLicensesForCard(card) {
                        const sel = card.querySelector('[data-go-pt="tier-select"]');
                        if (sel) return parseInt(sel.value, 10);
                        const staticEl = card.querySelector('[data-go-pt="tier-static"]');
                        if (staticEl) return parseInt(staticEl.getAttribute('data-tier-value') || globalTierValue, 10);
                        return globalTierValue;
                    }

                    function openCheckout(button, opts) {
                        const openNow = () => {
                            const planId = String(button.getAttribute('data-plan-id') || '');
                            const handler = new FS.Checkout({
                                product_id: String(product.product_id || ''),
                                public_key: String(product.public_key || '')
                            });
                            let couponValue = undefined;
                            const planCodes = couponPlanCodes[planId];
                            if (planCodes) {
                                if (planCodes.length) {
                                    couponValue = planCodes.join(',');
                                }
                            }
                            if (couponValue === undefined) {
                                if (couponGlobalCodes.length) {
                                    couponValue = couponGlobalCodes.join(',');
                                }
                            }
                            const plan_id = String(button.getAttribute('data-plan-id') || '');
                            const redirect_url = data.urls.redirect || '';
                            handler.open({
                                name: String(product.product_name || ''),
                                plan_id,
                                licenses: currentLicensesForCard(button.closest('[data-go-pt="card"]')),
                                trial: opts.trialMode ? true : undefined,
                                show_monthly_switch: true,
                                billing_cycle_selector: 'responsive_list',
                                multisite_discount: false,
                                show_reviews: true,
                                show_refund_badge: true,
                                billing_cycle: billingCycle,
                                coupon: couponValue,
                                purchaseCompleted: (response) => {
                                    const purchase = response.purchase;
                                    const isTrial = null !== purchase.trial_ends;
                                    const isSub = null !== purchase.initial_amount;
                                    const total = isTrial ? 0 : (isSub ? purchase.initial_amount : purchase.gross);
                                    const ORDER_ID = purchase.id ?? -1;

                                    // (Optional) prevent double-firing on refresh
                                    const firedKey = "fb_purchase_" + ORDER_ID;
                                    if (!localStorage.getItem(firedKey)) {
                                        fbq('track', 'Purchase', {
                                            value: total,
                                            currency: purchase.currency.toUpperCase(),
                                            content_type: 'product',
                                            contents: [{id: plan_id, quantity: 1}],
                                            num_items: 1,
                                            is_trial: isTrial,
                                        }, {eventID: ORDER_ID});

                                        localStorage.setItem(firedKey, "1");
                                    }
                                    if (typeof gtag === 'function') {
                                        gtag('event', 'purchase', {
                                            transaction_id: ORDER_ID,
                                            value: total,
                                            currency: purchase.currency.toUpperCase(),
                                            items: [{
                                                id: plan_id,
                                                quantity: 1,
                                                item_id: item_id.product_id,
                                                item_name: product.product_name
                                            }],
                                            is_trial: isTrial,
                                        });
                                    }
                                },
                                track: () => {},
                                success: (response) => {
                                    if (redirect_url) {
                                        const purchase = response.purchase;
                                        const isTrial = null !== purchase.trial_ends;
                                        const ORDER_ID = purchase.id ?? -1;

                                        const user = response.user;
                                        const url = new URL(redirect_url);
                                        url.searchParams.set('order_id', `${ORDER_ID}`);
                                        url.searchParams.set('is_trial', `${isTrial}`);
                                        url.searchParams.set('product_name', product.product_name || '');
                                        url.searchParams.set('first_name', user.first || '');
                                        url.searchParams.set('last_name', user.last || '');

                                        window.location.href = url.toString();
                                    }
                                }
                            });
                        };
                        const waitForFs = () => {
                            let waited = 0;
                            const iv = setInterval(() => {
                                if (window.FS) {
                                    if (window.FS.Checkout) {
                                        clearInterval(iv);
                                        openNow();
                                        return;
                                    }
                                }
                                waited += 100;
                                if (waited > 6000) {
                                    clearInterval(iv);
                                }
                            }, 100);
                        };

                        if (window.FS) {
                            if (window.FS.Checkout) {
                                openNow();
                            } else {
                                waitForFs();
                            }
                        } else {
                            waitForFs();
                        }
                    }
                    function openManualUrl(baseUrl, button) {
                        if (!baseUrl) return;
                        const card = button.closest('[data-go-pt="card"]');
                        const params = new URLSearchParams();
                        params.set('plan_id', String(card.getAttribute('data-plan-id') || ''));
                        params.set('plan_name', String(card.getAttribute('data-plan-name') || ''));
                        params.set('licenses', String(currentLicensesForCard(card)));
                        params.set('billing_cycle', billingCycle);
                        if (product.product_id) params.set('product_id', String(product.product_id));
                        if (product.product_name) params.set('product_name', String(product.product_name));
                        window.location.href = baseUrl.indexOf('?') === -1 ? `${baseUrl}?${params}` : `${baseUrl}&${params}`;
                    }
                    container.addEventListener('click', (e) => {
                        const buy = e.target.closest('[data-go-pt="buy"]');
                        if (buy) {
                            e.preventDefault();
                            if ((data.freemius || '').toLowerCase() === 'manual' || (data.freemius || '').toLowerCase() === 'automatic') {
                                openCheckout(buy, { trialMode: false, eventName: 'click_buy' });
                            } else {
                                openManualUrl((data.urls||{}).buy || '', buy);
                            }
                            return;
                        }
                        const trial = e.target.closest('[data-go-pt="trial"]');
                        if (trial) {
                            e.preventDefault();
                            if ((data.freemius || '').toLowerCase() === 'manual' || (data.freemius || '').toLowerCase() === 'automatic') {
                                openCheckout(trial, { trialMode: true, eventName: 'click_trial' });
                            } else {
                                openManualUrl((data.urls||{}).trial || '', trial);
                            }
                        }
                    });
                    return true;
                }

                const retryDelays = [250, 1000, 3000];
                let retryIndex = 0;
                let retryTimer = null;
                let moTimer = null;

                function bootstrap() {
                    const nodes = document.querySelectorAll('[data-component="go-pricing-table"]');
                    let remaining = 0;
                    nodes.forEach(el => {
                        if (el.getAttribute('data-go-pt-init') === '1') return;
                        try {
                            const ok = init(el);
                            if (ok) {
                                el.setAttribute('data-go-pt-init', '1');
                            } else {
                                remaining++;
                            }
                        } catch (e) {
                            // show error to the user and leave uninitialized for a later retry
                            el.querySelectorAll('[data-go-pt="card"]').forEach(setErrorForCard);
                            remaining++;
                        }
                    });

                    // schedule another pass only if there are sections not yet initialized
                    clearTimeout(retryTimer);
                    if (remaining > 0 && retryIndex < retryDelays.length) {
                        const delay = retryDelays[retryIndex++];
                        retryTimer = setTimeout(bootstrap, delay);
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', bootstrap);
                } else {
                    bootstrap();
                }
                // safety: run again after a tick in case markup renders after script tag
                setTimeout(bootstrap, 0);

                // Observe DOM for late-inserted pricing tables or payloads
                try {
                    const observer = new MutationObserver(() => {
                        clearTimeout(moTimer);
                        moTimer = setTimeout(bootstrap, 60);
                    });
                    observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
                } catch (e) { /* no-op */ }
            })();
        </script>
        <?php
    }
}

// Bootstrap
Bld_Go_PricingTable::get()->register();
