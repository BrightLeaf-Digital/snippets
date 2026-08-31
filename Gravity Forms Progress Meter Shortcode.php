<?php
/**
 * Gravity Forms Progress Meter Shortcode
 *
 * @gravityforms
 *
 * GOAL:
 * Creates a visual progress meter that calculates the sum of a specific numeric field from Gravity
 * Forms entries and displays progress toward a goal. Supports filtering by field values, date
 * ranges, and custom multipliers.
 *
 * CONFIGURATION:
 * CSS: Style .gfpm-container, .gfpm-meter, .gfpm-fill, .gfpm-caption, .gfpm-goal CSS: Set width,
 * colors, borders for progress bar appearance Attributes: form_id, field, and goal are required
 *
 * USAGE:
 * - Basic: `[gf_progress_meter form_id="1" field="5" goal="10000"]`
 * - With filter (only sum entries where field 3 = "approved"): `[gf_progress_meter form_id="1"
 *   field="5" goal="10000" filter_key="3" filter_value="approved"]`
 * - With date range: `[gf_progress_meter form_id="1" field="5" goal="10000"
 *   start_date="2025-01-01" end_date="2025-12-31"]`
 * - With multiplier (e.g., employee matching): `[gf_progress_meter form_id="1" field="5"
 *   goal="10000" multiplier="2"]`
 * - Custom labels: `[gf_progress_meter form_id="1" field="5" goal="10000" caption_label="Raised"
 *   goal_label="Target"]`
 *
 * **NOTES** Accepts comma and dollar sign in goal attribute (auto-stripped) Date format: Use any
 * format accepted by strtotime() Progress capped at 100% visually Only processes 'active' status
 * entries (not spam/trash) Multiplier useful for donation matching scenarios
 */

add_action(
	'init',
	function () {
		add_shortcode(
			'gf_progress_meter',
			function ( $atts ) {
				$atts = shortcode_atts(
					[
						'form_id'         => '',
						'field'           => '', // field to sum
						'goal'            => '',
						'filter_key'      => '',
						'filter_value'    => '',
						'start_date'      => '',
						'end_date'        => '',
						'caption_label'   => 'Donations',
						'goal_label'      => 'Goal',
						'multiplier'      => 1,
						'show'            => 'sum', // sum|count|both
						'cache'           => '1',   // enable transient caching
						'cache_ttl'       => '300', // seconds
						'currency_symbol' => '$',
						'decimal_places'  => 0,
					],
					$atts
				);

				$atts['goal'] = str_replace( ',', '', $atts['goal'] );
				$atts['goal'] = str_replace( '$', '', $atts['goal'] );

				if ( ! class_exists( 'GFAPI' ) || ! is_numeric( $atts['form_id'] ) || ! is_numeric( $atts['field'] ) || ! is_numeric( $atts['goal'] ) ) {
					return ''; // Return early if input validation fails.
				}

				$atts['filter_key']    = sanitize_text_field( $atts['filter_key'] );
				$atts['filter_value']  = sanitize_text_field( $atts['filter_value'] );
				$atts['caption_label'] = sanitize_text_field( $atts['caption_label'] );
				$atts['goal_label']    = sanitize_text_field( $atts['goal_label'] );
				$atts['multiplier']    = max( 0, floatval( $atts['multiplier'] ) );
				$atts['show']          = in_array( strtolower( (string) $atts['show'] ), [ 'sum', 'count', 'both' ], true ) ? strtolower( (string) $atts['show'] ) : 'sum';
				$use_cache             = ! in_array( strtolower( (string) $atts['cache'] ), [ '0', 'false', 'no' ], true );
				$cache_ttl             = max( 0, absint( $atts['cache_ttl'] ) );

				// Validate dates
				if ( ! empty( $atts['start_date'] ) && ! strtotime( $atts['start_date'] ) ) {
					$atts['start_date'] = ''; // Clear invalid dates
				}
				if ( ! empty( $atts['end_date'] ) && ! strtotime( $atts['end_date'] ) ) {
					$atts['end_date'] = ''; // Clear invalid dates
				}

				$search_criteria = [ 'status' => 'active' ];

				// Optional field filtering
				if ( $atts['filter_key'] && $atts['filter_value'] ) {
					$search_criteria['field_filters'] = [
						'mode' => 'all',
						[
							'key'   => $atts['filter_key'],
							'value' => $atts['filter_value'],
						],
					];
				}

				// Optional date filtering
				$timezone = wp_timezone();

				if ( ! empty( $atts['start_date'] ) ) {
					$start_timestamp = strtotime( $atts['start_date'] );
					if ( $start_timestamp ) {
						$search_criteria['start_date'] = wp_date( 'Y-m-d', $start_timestamp, $timezone );
					}
				}

				if ( ! empty( $atts['end_date'] ) ) {
					$end_timestamp = strtotime( $atts['end_date'] );
					if ( $end_timestamp ) {
						$search_criteria['end_date'] = wp_date( 'Y-m-d', $end_timestamp, $timezone );
					}
				}

				// Build a cache key based on inputs
				$cache_key = 'gfpm_' . md5(
						wp_json_encode(
							[
								'form_id'      => (int) $atts['form_id'],
								'field'        => (string) $atts['field'],
								'goal'         => (string) $atts['goal'],
								'filter_key'   => $atts['filter_key'],
								'filter_value' => $atts['filter_value'],
								'start_date'   => (string) $atts['start_date'],
								'end_date'     => (string) $atts['end_date'],
								'multiplier'   => (float) $atts['multiplier'],
							]
						)
					);

				$cached = $use_cache ? get_transient( $cache_key ) : false;
				if ( false !== $cached && is_array( $cached ) && isset( $cached['sum'], $cached['count'] ) ) {
					$sum_raw   = (float) $cached['sum'];
					$entry_cnt = (int) $cached['count'];
				} else {
					$paging = [
						'offset'    => 0,
						'page_size' => 25,
					];

					$num_entries = 0;
					$entries     = GFAPI::get_entries( $atts['form_id'], $search_criteria, null, $paging, $num_entries );
					if ( is_wp_error( $entries ) ) {
						return '';
					}
					while ( count( $entries ) < $num_entries ) {
						$paging['offset'] += $paging['page_size'];
						$entries           = array_merge( $entries, GFAPI::get_entries( $atts['form_id'], $search_criteria, null, $paging, $num_entries ) );
					}
					$sum_raw   = 0.0;
					$entry_cnt = count( $entries );
					foreach ( $entries as $entry ) {
						$value = rgar( $entry, $atts['field'] );
						// Use GFCommon if available for currency parsing
						if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'to_number' ) ) {
							$sum_raw += GFCommon::to_number( $value );
						} else {
							$sum_raw += floatval( $value );
						}
					}
					if ( $use_cache && $cache_ttl > 0 ) {
						set_transient(
							$cache_key,
							[
								'sum'   => $sum_raw,
								'count' => $entry_cnt,
							],
							$cache_ttl
						);
					}
				}

				$sum     = $sum_raw * $atts['multiplier'];
				$goal    = floatval( $atts['goal'] );
				$percent = min( 100, round( ( $goal > 0 ) ? ( $sum / $goal ) * 100 : 0 ) );

				// Threshold CSS class for styling
				$threshold = ( $percent >= 100 ) ? '100' : ( ( $percent >= 75 ) ? '75' : ( ( $percent >= 50 ) ? '50' : ( ( $percent >= 25 ) ? '25' : '0' ) ) );

				ob_start();
				?>
				<div class="gfpm-container gfpm-is-<?php echo esc_attr( $threshold ); ?>">
					<div class="gfpm-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $percent ); ?>" aria-label="<?php echo esc_attr( $atts['caption_label'] . ' ' . $percent . '% of ' . number_format( $goal ) ); ?>">
						<div class="gfpm-fill" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
					</div>
					<div class="gfpm-caption">
						<span class="gfpm-caption-label"><?php echo esc_html( $atts['caption_label'] ); ?></span>
						<?php if ( 'count' === $atts['show'] ) : ?>
							<span class="gfpm-caption-value"><?php echo esc_html( number_format( $entry_cnt ) ); ?></span>
						<?php elseif ( 'both' === $atts['show'] ) : ?>
							<span class="gfpm-caption-value"><?php echo esc_html( $atts['currency_symbol'] . number_format( $sum, absint( $atts['decimal_places'] ) ) ); ?> (<?php echo esc_html( number_format( $entry_cnt ) ); ?>)</span>
						<?php else : ?>
							<span class="gfpm-caption-value"><?php echo esc_html( $atts['currency_symbol'] . number_format( $sum, absint( $atts['decimal_places'] ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<div class="gfpm-goal">
						<span class="gfpm-goal-label"><?php echo esc_html( $atts['goal_label'] ); ?></span>
						<span class="gfpm-goal-value">$<?php echo esc_html( number_format( $goal ) ); ?></span>
					</div>
				</div>
				<?php
				return ob_get_clean();
			}
		);
	}
);
