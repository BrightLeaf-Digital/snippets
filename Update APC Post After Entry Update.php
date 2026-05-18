<?php
/**
 * Re-sync APC-managed posts when supported entry update paths run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BLD_APC_Post_Resync' ) ) {
	/**
	 * Re-syncs APC-managed posts after entry updates.
	 */
	final class BLD_APC_Post_Resync {

		/**
		 * Per-request dedupe by entry ID (full-update hooks).
		 *
		 * @var array<int, bool>
		 */
		private static $synced = [];

		/**
		 * Per-request dedupe for property-update hook only.
		 * Separate from $synced so that a later full-update hook (gform_after_update_entry,
		 * gform_post_update_entry) can still trigger a clean re-sync after the localizer runs.
		 *
		 * @var array<int, bool>
		 */
		private static $prop_synced = [];

		/**
		 * Re-entrancy guard by entry ID.
		 *
		 * @var array<int, bool>
		 */
		private static $in_flight = [];

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'gform_post_update_entry', [ __CLASS__, 'handle_post_update_entry' ], 20, 1 );
			add_action( 'gform_after_update_entry', [ __CLASS__, 'handle_after_update_entry' ], 20, 2 );
			add_filter( 'gform_entry_post_save', [ __CLASS__, 'handle_entry_post_save' ], 20, 1 );
			add_action( 'gravityview/edit_entry/after_update', [ __CLASS__, 'handle_gravityview_after_update' ], 20, 2 );
			add_action( 'gform_post_update_entry_property', [ __CLASS__, 'handle_post_update_entry_property' ], 20, 1 );
			add_action( 'gform_advancedpostcreation_post_after_creation', [ __CLASS__, 'handle_post_after_creation' ], 20, 3 );
			add_action( 'gform_entry_updated', [ __CLASS__, 'handle_entry_updated' ], 20, 2 );
			add_action( 'gform_post_save_feed_settings', [ __CLASS__, 'handle_post_save_feed_settings' ], 10, 4 );
			add_action( 'init', [ __CLASS__, 'handle_manual_resync_request' ] );
		}

		/**
		 * Handle GFAPI::update_entry() updates.
		 *
		 * @param array $entry The updated entry.
		 *
		 * @return void
		 */
		public static function handle_post_update_entry( $entry ) {
			$entry_id = (int) rgar( $entry, 'id' );
			// Re-fetch from DB so APC sees any localized URLs written by BLD_APC_Remote_Upload_Localizer (priority 5).
			self::resync_entry_by_id( $entry_id );
		}

		/**
		 * Handle admin entry edits.
		 *
		 * @param array $form     The current form.
		 * @param int   $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_after_update_entry( $form, $entry_id ) {
			self::resync_entry_by_id( $entry_id );
		}

		/**
		 * Handle entry post save events.
		 *
		 * @param array $entry The saved entry.
		 *
		 * @return array
		 */
		public static function handle_entry_post_save( $entry ) {
			self::resync_entry( $entry );

			return $entry;
		}

		/**
		 * Handle GravityView frontend edits.
		 *
		 * @param array $form     The current form.
		 * @param int   $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_gravityview_after_update( $form, $entry_id ) {
			self::resync_entry_by_id( $entry_id );
		}

		/**
		 * Handle entry property updates.
		 *
		 * @param int $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_post_update_entry_property( $entry_id ) {
			$entry_id = (int) $entry_id;
			// Use a separate dedup so this hook can run once per request, but the
			// full-update hooks (gform_after_update_entry, gform_post_update_entry)
			// that fire AFTER the localizer are not blocked by $synced.
			if ( isset( self::$prop_synced[ $entry_id ] ) ) {
				return;
			}
			self::$prop_synced[ $entry_id ] = true;
			self::resync_entry_by_id( $entry_id );
			// Clear $synced so full-update hooks can still fire a clean re-sync
			// after the localizer has had a chance to fix the entry values.
			unset( self::$synced[ $entry_id ] );
		}

		/**
		 * Handle APC post creation follow-up updates.
		 *
		 * @param int   $post_id The created post ID.
		 * @param array $feed    The APC feed.
		 * @param array $entry   The entry being processed.
		 *
		 * @return void
		 */
		public static function handle_post_after_creation( $post_id, $feed, $entry ) {
			self::resync_entry( $entry );
		}

		/**
		 * Handle addon-specific entry updates.
		 *
		 * @param array $form     The current form.
		 * @param int   $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_entry_updated( $form, $entry_id ) {
			self::resync_entry_by_id( $entry_id );
		}

		/**
		 * Re-sync APC-managed posts for the supplied entry.
		 *
		 * @param array $entry The entry to re-sync.
		 *
		 * @return void
		 */
		private static function resync_entry( $entry ) {
			$entry_id = (int) rgar( $entry, 'id' );
			if ( ! $entry_id ) {
				return;
			}

			if ( isset( self::$synced[ $entry_id ] ) ) {
				return;
			}

			if ( isset( self::$in_flight[ $entry_id ] ) ) {
				return;
			}

			self::$in_flight[ $entry_id ] = true;

			try {
				if ( ! function_exists( 'gf_advancedpostcreation' ) ) {
					return;
				}

				$form_id = (int) rgar( $entry, 'form_id' );
				if ( ! $form_id ) {
					return;
				}

				$form = GFAPI::get_form( $form_id );
				if ( ! $form ) {
					return;
				}

				$post_meta = gform_get_meta( $entry_id, 'gravityformsadvancedpostcreation_post_id' );
				if ( empty( $post_meta ) || ! is_array( $post_meta ) ) {
					return;
				}

				foreach ( $post_meta as $row ) {
					self::update_single_post(
						(int) rgar( $row, 'post_id' ),
						(int) rgar( $row, 'feed_id' ),
						$entry,
						$form
					);
				}

				self::$synced[ $entry_id ] = true;
			} finally {
				unset( self::$in_flight[ $entry_id ] );
			}
		}

		/**
		 * Re-sync APC-managed posts for an entry ID.
		 *
		 * @param int $entry_id The entry ID.
		 *
		 * @return void
		 */
		private static function resync_entry_by_id( $entry_id ) {
			$entry_id = (int) $entry_id;
			if ( ! $entry_id ) {
				return;
			}

			$entry = GFAPI::get_entry( $entry_id );
			if ( is_wp_error( $entry ) ) {
				return;
			}

			self::resync_entry( $entry );
		}

		/**
		 * Update a single APC-managed post.
		 *
		 * @param int   $post_id The post ID.
		 * @param int   $feed_id The feed ID.
		 * @param array $entry   The current entry.
		 * @param array $form    The current form.
		 *
		 * @return void
		 */
		private static function update_single_post( $post_id, $feed_id, $entry, $form ) {
			$entry_id = (int) rgar( $entry, 'id' );

			if ( ! $post_id || ! $feed_id ) {
				return;
			}

			if ( ! get_post( $post_id ) ) {
				return;
			}

			$addon = gf_advancedpostcreation();
			$feed  = $addon->get_feed( $feed_id );

			if ( empty( $feed ) || empty( $feed['meta'] ) ) {
				return;
			}

			$addon->update_post( $post_id, $feed, $entry, $form );
		}

		/**
		 * Re-sync all posts created by a feed when its settings are saved.
		 *
		 * @param int   $feed_id  The feed ID.
		 * @param int   $form_id  The form ID.
		 * @param array $settings The feed settings.
		 * @param mixed $addon    The current addon instance.
		 *
		 * @return void
		 */
		public static function handle_post_save_feed_settings( $feed_id, $form_id, $settings, $addon ) {

			if ( ! is_object( $addon ) || $addon->get_slug() !== 'gravityformsadvancedpostcreation' ) {
				return;
			}

			$feed_id = (int) $feed_id;
			$form_id = (int) $form_id;

			if ( ! $feed_id || ! $form_id || ! function_exists( 'gf_advancedpostcreation' ) ) {
				return;
			}

			$form = GFAPI::get_form( $form_id );
			if ( ! $form ) {
				return;
			}

			$page_size = 200;
			$offset    = 0;
			$search    = [
				'status'        => 'active',
				'field_filters' => [],
			];

			do {
				$entries = GFAPI::get_entries(
					$form_id,
					$search,
					null,
					[
						'offset'    => $offset,
						'page_size' => $page_size,
					]
				);

				if ( is_wp_error( $entries ) || empty( $entries ) ) {
					break;
				}

				foreach ( $entries as $entry ) {
					$post_meta = gform_get_meta( $entry['id'], 'gravityformsadvancedpostcreation_post_id' );
					if ( empty( $post_meta ) || ! is_array( $post_meta ) ) {
						continue;
					}

					foreach ( $post_meta as $row ) {
						if ( (int) rgar( $row, 'feed_id' ) !== $feed_id ) {
							continue;
						}

						self::update_single_post( (int) rgar( $row, 'post_id' ), $feed_id, $entry, $form );
					}
				}

				$offset += $page_size;
			} while ( count( $entries ) === $page_size );
		}

		/**
		 * Handle the admin-only manual resync query parameter.
		 *
		 * @return void
		 */
		public static function handle_manual_resync_request() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$entry_id = (int) rgget( 'bld_apc_resync_entry' );
			if ( ! $entry_id ) {
				return;
			}

			self::resync_entry_by_id( $entry_id );
		}
	}
}

BLD_APC_Post_Resync::init();
