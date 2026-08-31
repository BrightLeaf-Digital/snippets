<?php
/**
 * WP repo plugin widget
 *
 * @wordpress
 *
 * GOAL:
 * Creates a shortcode to display simple information about a plugin hosted in the WP repo.
 */

add_action(
	'init',
	function () {
		add_shortcode(
			'wporg_plugin_widget',
			function ( $atts ) {
				$atts = shortcode_atts(
					[
						'url'               => '',
						'features'          => '',

						// toggles (default true)
						'short_description' => 'true',
						'requires'          => 'true',
						'requires_php'      => 'true',
						'version'           => 'true',
						'downloaded'        => 'true',
						'active_installs'   => 'true',
						'last_updated'      => 'true',
						'rating'            => 'true',
						'num_ratings'       => 'true',
						'homepage'          => 'true',
						'icons'             => 'true',
						'contributors'      => 'true',
					],
					$atts,
					'wporg_plugin_widget'
				);

				$url = trim( $atts['url'] );
				if ( empty( $url ) ) {
					return '<div class="wporg-plugin-widget wporg-plugin-widget--error">No plugin URL provided.</div>';
				}

				// Derive slug from the URL.
				$parts = wp_parse_url( $url );
				if ( empty( $parts['path'] ) ) {
					return '<div class="wporg-plugin-widget wporg-plugin-widget--error">Invalid plugin URL.</div>';
				}

				$segments = array_values(
					array_filter(
						explode( '/', $parts['path'] ),
						static function ( $seg ) {
							return '' !== $seg;
						}
					)
				);

				$slug = end( $segments );
				$slug = sanitize_title( $slug );

				if ( empty( $slug ) ) {
					return '<div class="wporg-plugin-widget wporg-plugin-widget--error">Could not determine plugin slug from URL.</div>';
				}

				// Ensure plugins_api() is available.
				if ( ! function_exists( 'plugins_api' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
				}

				$convert_to_bool = function ( $value, $default_value ) {
					if ( is_bool( $value ) ) {
						return $value;
					}
					if ( is_null( $value ) ) {
						return $default_value;
					}
					return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? $default_value;
				};

				// Boolean toggles.
				$show_short_desc  = $convert_to_bool( $atts['short_description'], true );
				$show_requires    = $convert_to_bool( $atts['requires'], true );
				$show_requiresphp = $convert_to_bool( $atts['requires_php'], true );
				$show_version     = $convert_to_bool( $atts['version'], true );
				$show_downloaded  = $convert_to_bool( $atts['downloaded'], true );
				$show_installs    = $convert_to_bool( $atts['active_installs'], true );
				$show_updated     = $convert_to_bool( $atts['last_updated'], true );
				$show_rating      = $convert_to_bool( $atts['rating'], true );
				$show_numratings  = $convert_to_bool( $atts['num_ratings'], true );
				$show_homepage    = $convert_to_bool( $atts['homepage'], true );
				$show_icons       = $convert_to_bool( $atts['icons'], true );
				$show_contrib     = $convert_to_bool( $atts['contributors'], true );

				$atts_hash     = md5( wp_json_encode( $atts ) );
				$transient_key = 'wporg_pw_' . $slug . '_' . $atts_hash;

				$plugin = get_transient( $transient_key );
				if ( false === $plugin ) {
					$args = [
						'slug'   => $slug,
						'fields' => [
							'short_description' => $show_short_desc,
							'sections'          => true,
							'requires'          => $show_requires,
							'requires_php'      => $show_requiresphp,
							'tested'            => true,
							'version'           => $show_version,
							'downloaded'        => $show_downloaded,
							'active_installs'   => $show_installs,
							'last_updated'      => $show_updated,
							'rating'            => $show_rating,
							'num_ratings'       => $show_numratings,
							'homepage'          => $show_homepage,
							'icons'             => $show_icons,
							'contributors'      => $show_contrib,
						],
					];

					$plugin = plugins_api( 'plugin_information', $args );

					if ( ! is_wp_error( $plugin ) ) {
						// Cache for 12 hours.
						set_transient( $transient_key, $plugin, 12 * HOUR_IN_SECONDS );
					}
				}

				if ( is_wp_error( $plugin ) || ! $plugin ) {
					return '<div class="wporg-plugin-widget wporg-plugin-widget--error">Plugin information is temporarily unavailable.</div>';
				}

				// Parse features attribute.
				$features = [];
				if ( ! empty( $atts['features'] ) ) {
					$raw_features = explode( '|', $atts['features'] );
					foreach ( $raw_features as $feature ) {
						$feature = trim( sanitize_text_field( $feature ) );
						if ( '' !== $feature ) {
							$features[] = $feature;
						}
					}
				}

				ob_start();
				// Determine description HTML:
				// - show BOTH short_description and long "description" section as one flowing unit
				// - only show "Show more" button if there is a long description.
				$description_html = '';
				$has_long_desc    = false;

				$short_html = '';
				$long_html  = '';

				if ( ! empty( $plugin->short_description ) ) {
					// short_description is plain text, so escape and wrap in <p>.
					$short_html = '<p>' . esc_html( $plugin->short_description ) . '</p>';
				}

				if ( ! empty( $plugin->sections ) && ! empty( $plugin->sections['description'] ) ) {
					// sections['description'] is HTML: sanitize but keep formatting.
					$long_html     = wp_kses_post( $plugin->sections['description'] );
					$has_long_desc = true;
				}

				if ( $short_html || $long_html ) {
					$description_html = $short_html . $long_html;
				}

				$icon_url = '';
				if ( $show_icons && ! empty( $plugin->icons ) && is_array( $plugin->icons ) ) {
					if ( ! empty( $plugin->icons['2x'] ) ) {
						$icon_url = $plugin->icons['2x'];
					} elseif ( ! empty( $plugin->icons['1x'] ) ) {
						$icon_url = $plugin->icons['1x'];
					} elseif ( ! empty( $plugin->icons['default'] ) ) {
						$icon_url = $plugin->icons['default'];
					}
				}

				$rating_raw      = isset( $plugin->rating ) ? floatval( $plugin->rating ) : 0; // 0–100.
				$num_ratings     = isset( $plugin->num_ratings ) ? intval( $plugin->num_ratings ) : 0;
				$active_installs = isset( $plugin->active_installs ) ? intval( $plugin->active_installs ) : 0;

				?>
				<div class="wporg-plugin-widget" data-plugin-slug="<?php echo esc_attr( $slug ); ?>" data-rating="<?php echo esc_attr( $rating_raw ); ?>">
					<div class="wporg-plugin-widget__inner">
						<div class="wporg-plugin-widget__header">
							<?php if ( $icon_url ) : ?>
								<div class="wporg-plugin-widget__icon-wrap">
									<img class="wporg-plugin-widget__icon" src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $plugin->name ); ?>">
								</div>
							<?php endif; ?>

							<div class="wporg-plugin-widget__title-block">
								<div class="wporg-plugin-widget__name">
									<?php echo esc_html( $plugin->name ); ?>
								</div>

								<div class="wporg-plugin-widget__meta-line">
									<?php if ( $show_version && ! empty( $plugin->version ) ) : ?>
										<span class="wporg-plugin-widget__meta-item">
									Version <?php echo esc_html( $plugin->version ); ?>
								</span>
									<?php endif; ?>

									<?php if ( $show_requires && ! empty( $plugin->requires ) ) : ?>
										<span class="wporg-plugin-widget__meta-item">
									Requires WP <?php echo esc_html( $plugin->requires ); ?>
								</span>
									<?php endif; ?>

									<?php if ( $show_requiresphp && ! empty( $plugin->requires_php ) ) : ?>
										<span class="wporg-plugin-widget__meta-item">
									Requires PHP <?php echo esc_html( $plugin->requires_php ); ?>
								</span>
									<?php endif; ?>

									<?php if ( $show_installs && $active_installs > 0 ) : ?>
										<span class="wporg-plugin-widget__meta-item">
									<?php echo esc_html( number_format_i18n( $active_installs ) ); ?>+ active installs
								</span>
									<?php endif; ?>

									<?php if ( $show_downloaded && ! empty( $plugin->downloaded ) ) : ?>
										<span class="wporg-plugin-widget__meta-item">
									<?php echo esc_html( number_format_i18n( $plugin->downloaded ) ); ?>+ downloads
								</span>
									<?php endif; ?>

									<?php if ( $show_updated && ! empty( $plugin->last_updated ) ) : ?>
										<span class="wporg-plugin-widget__meta-item">
									Last updated <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $plugin->last_updated ) ) ); ?>
								</span>
									<?php endif; ?>
								</div>
							</div>

							<div class="wporg-plugin-widget__actions">
								<?php if ( $show_rating && $rating_raw > 0 ) : ?>
									<div class="wporg-plugin-widget__rating-wrap" aria-label="<?php echo esc_attr( round( $rating_raw / 20, 1 ) ); ?> out of 5 stars">
										<div class="wporg-plugin-widget__rating-stars">
											<div class="wporg-plugin-widget__rating-bar">
												<div class="wporg-plugin-widget__rating-bar-fill"></div>
											</div>
										</div>
										<?php if ( $show_numratings ) : ?>
											<div class="wporg-plugin-widget__rating-count">
												<?php echo esc_html( $num_ratings ); ?> ratings
											</div>
										<?php endif; ?>
									</div>
								<?php endif; ?>

								<div class="wporg-plugin-widget__buttons">
									<a class="wporg-plugin-widget__button wporg-plugin-widget__button--primary"
									   href="<?php echo esc_url( $plugin->download_link ); ?>"
									   target="_blank" rel="noopener">
										Download
									</a>

									<a class="wporg-plugin-widget__button wporg-plugin-widget__button--ghost"
									   href="<?php echo esc_url( $url ); ?>"
									   target="_blank" rel="noopener">
										View on WordPress.org
									</a>
								</div>
							</div>
						</div>

						<?php if ( $show_short_desc && ! empty( $description_html ) ) : ?>
							<div class="wporg-plugin-widget__description-block">
								<div class="wporg-plugin-widget__description"
								     data-collapsed="<?php echo $has_long_desc ? 'true' : 'false'; ?>">
									<?php
									// Already sanitized above (esc_html for short_description, wp_kses_post for long description).
									echo $description_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</div>

								<?php if ( $has_long_desc ) : ?>
									<button type="button" class="wporg-plugin-widget__description-toggle">
										Show more
									</button>
								<?php endif; ?>
							</div>
						<?php endif; ?>



						<?php if ( ! empty( $features ) ) : ?>
							<div class="wporg-plugin-widget__features">
								<ul class="wporg-plugin-widget__features-list">
									<?php foreach ( $features as $feature ) : ?>
										<li class="wporg-plugin-widget__feature-item">
											<span class="wporg-plugin-widget__feature-check" aria-hidden="true"></span>
											<span class="wporg-plugin-widget__feature-text">
										<?php echo esc_html( $feature ); ?>
									</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<div class="wporg-plugin-widget__footer">
							<div class="wporg-plugin-widget__author">
								<?php
								// $plugin->author is already HTML with link.
								echo wp_kses_post( $plugin->author );
								?>
							</div>

							<?php if ( $show_homepage && ! empty( $plugin->homepage ) ) : ?>
								<div class="wporg-plugin-widget__homepage">
									<a href="<?php echo esc_url( $plugin->homepage ); ?>" target="_blank" rel="noopener">
										Plugin homepage
									</a>
								</div>
							<?php endif; ?>

							<?php if ( $show_contrib && ! empty( $plugin->contributors ) && is_array( $plugin->contributors ) ) : ?>
								<div class="wporg-plugin-widget__contributors">
									<span class="wporg-plugin-widget__contributors-label">Contributors:</span>
									<ul class="wporg-plugin-widget__contributors-list">
										<?php
										$shown = 0;
										foreach ( $plugin->contributors as $username => $data ) {
											if ( $shown >= 5 ) {
												break;
											}
											++$shown;
											$name    = $username;
											$profile = $data['profile'] ?? '';
											$avatar  = $data['avatar'] ?? '';
											?>
											<li class="wporg-plugin-widget__contributor">
												<?php if ( $avatar ) : ?>
													<img class="wporg-plugin-widget__contributor-avatar" src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $name ); ?>">
												<?php endif; ?>
												<?php if ( $profile ) : ?>
													<a href="<?php echo esc_url( $profile ); ?>" target="_blank" rel="noopener">
														<?php echo esc_html( $name ); ?>
													</a>
												<?php else : ?>
													<span><?php echo esc_html( $name ); ?></span>
												<?php endif; ?>
											</li>
											<?php
										}
										?>
									</ul>
								</div>
							<?php endif; ?>
						</div>

					</div><!-- .wporg-plugin-widget__inner -->
				</div><!-- .wporg-plugin-widget -->
				<?php

				return ob_get_clean();
			}
		);
	}
);
