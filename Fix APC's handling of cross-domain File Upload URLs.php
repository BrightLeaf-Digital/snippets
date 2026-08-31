<?php
/**
 * Fix APC's handling of cross-domain File Upload URLs
 *
 * @gravityforms
 * @gravityview
 *
 * GOAL:
 * This snippet fixes APC's handling of remote URL's in a file upload field. This snippet downloads
 * the remote file and updates the local entry with the new local URL so that APC can access it and
 * add it to the media library and post. This snippet can also be useful without APC when dealing
 * with remote entry creation or updates to download the files and have them hosted on the current
 * site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BLD_APC_Remote_Upload_Localizer' ) ) {
	/**
	 * Localizes remote file upload URLs before APC processes the entry.
	 */
	final class BLD_APC_Remote_Upload_Localizer {

		/**
		 * Cache downloaded URLs per request.
		 *
		 * @var array<string, string>
		 */
		private static $download_cache = [];

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'gform_entry_post_save', [ __CLASS__, 'localize_submission_entry' ], 9, 2 );
			add_action( 'gform_post_add_entry', [ __CLASS__, 'handle_api_added_entry' ], 5, 2 );
			add_action( 'gform_post_update_entry', [ __CLASS__, 'handle_update_entry' ], 5, 1 );
			add_action( 'gform_post_update_entry_property', [ __CLASS__, 'handle_update_entry_property' ], 5, 1 );
			add_action( 'gform_after_update_entry', [ __CLASS__, 'handle_after_update_entry' ], 5, 2 );
			add_action( 'gravityview/edit_entry/after_update', [ __CLASS__, 'handle_gravityview_after_update' ], 5, 2 );
			add_action( 'gform_entry_updated', [ __CLASS__, 'handle_entry_updated' ], 5, 2 );
		}

		/**
		 * Normalize remote file upload URLs before standard feed processing.
		 *
		 * @param array $entry The saved entry.
		 * @param array $form  The current form.
		 *
		 * @return array
		 */
		public static function localize_submission_entry( $entry, $form ) {
			$did_change = false;
			$entry      = self::localize_entry( $entry, $form, $did_change );

			if ( $did_change ) {
				self::store_upload_path_meta_for_entry( $entry, $form );
			}

			return $entry;
		}

		/**
		 * Persist localized entry values after entries created via GFAPI::add_entry().
		 *
		 * @param array $entry The saved entry.
		 * @param array $form  The current form.
		 *
		 * @return void
		 */
		public static function handle_api_added_entry( $entry, $form ) {
			$did_change = false;
			$entry      = self::localize_entry( $entry, $form, $did_change );

			if ( $did_change ) {
				self::store_upload_path_meta_for_entry( $entry, $form );
			}
		}

		/**
		 * Normalize remote file upload URLs after GFAPI::update_entry().
		 *
		 * @param array $entry The updated entry.
		 *
		 * @return void
		 */
		public static function handle_update_entry( $entry ) {
			$entry_id = (int) rgar( $entry, 'id' );
			$form_id  = (int) rgar( $entry, 'form_id' );

			if ( ! $form_id ) {
				return;
			}

			$form = GFAPI::get_form( $form_id );
			if ( ! $form ) {
				return;
			}

			$did_change = false;
			self::localize_entry( $entry, $form, $did_change );

			if ( $did_change ) {
				$entry = GFAPI::get_entry( $entry_id );
				if ( ! is_wp_error( $entry ) ) {
					self::store_upload_path_meta_for_entry( $entry, $form );
				}
			}
		}

		/**
		 * Normalize remote file upload URLs after admin entry edits.
		 *
		 * @param array $form     The current form.
		 * @param int   $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_after_update_entry( $form, $entry_id ) {
			self::localize_entry_by_id( (int) $entry_id, $form );
		}

		/**
		 * Normalize remote file upload URLs after GravityView frontend edits.
		 *
		 * @param array $form     The current form.
		 * @param int   $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_gravityview_after_update( $form, $entry_id ) {
			self::localize_entry_by_id( (int) $entry_id, $form );
		}

		/**
		 * Normalize remote file upload URLs after addon-specific entry updates.
		 *
		 * @param array $form     The current form.
		 * @param int   $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_entry_updated( $form, $entry_id ) {
			self::localize_entry_by_id( (int) $entry_id, $form );
		}

		/**
		 * Normalize remote file upload URLs when individual entry properties are updated.
		 *
		 * Runs at priority 5, before the resync's priority 20, so APC always gets
		 * localized URLs regardless of which update hook fires first.
		 *
		 * @param int $entry_id The entry ID.
		 *
		 * @return void
		 */
		public static function handle_update_entry_property( $entry_id ) {
			$entry_id = (int) $entry_id;

			if ( ! $entry_id ) {
				return;
			}

			$entry = GFAPI::get_entry( $entry_id );
			if ( is_wp_error( $entry ) ) {
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

			$did_change = false;
			self::localize_entry( $entry, $form, $did_change );

			if ( $did_change ) {
				$entry = GFAPI::get_entry( $entry_id );
				if ( ! is_wp_error( $entry ) ) {
					self::store_upload_path_meta_for_entry( $entry, $form );
				}
			}
		}

		/**
		 * Fetch a fresh entry from DB and localize it.
		 *
		 * @param int   $entry_id The entry ID.
		 * @param array $form     The current form.
		 *
		 * @return void
		 */
		private static function localize_entry_by_id( $entry_id, $form ) {
			if ( ! $entry_id ) {
				return;
			}

			$entry = GFAPI::get_entry( $entry_id );
			if ( is_wp_error( $entry ) ) {
				return;
			}

			$did_change = false;
			self::localize_entry( $entry, $form, $did_change );

			if ( $did_change ) {
				$entry = GFAPI::get_entry( $entry_id );
				if ( ! is_wp_error( $entry ) ) {
					self::store_upload_path_meta_for_entry( $entry, $form );
				}
			}
		}

		/**
		 * Localize remote file upload URLs for all file upload fields on an entry.
		 *
		 * @param array $entry      The entry to normalize.
		 * @param array $form       The form for the entry.
		 * @param bool  $did_change Whether any file upload value was updated.
		 *
		 * @return array
		 */
		private static function localize_entry( $entry, $form, &$did_change = false ) {
			$entry_id   = (int) rgar( $entry, 'id' );
			$form_id    = (int) rgar( $form, 'id' );
			$did_change = false;

			if ( ! $form_id ) {
				return $entry;
			}

			$fields = GFAPI::get_fields_by_type( $form, [ 'fileupload' ], true );
			if ( empty( $fields ) ) {
				return $entry;
			}

			foreach ( $fields as $field ) {
				$field_id    = (string) $field->id;
				$field_value = rgar( $entry, $field_id );

				if ( empty( $field_value ) ) {
					continue;
				}

				$normalized_value = self::localize_field_value( $field_value, $field, $form );
				if ( $normalized_value === $field_value ) {
					continue;
				}

				$entry[ $field_id ] = $normalized_value;
				$did_change         = true;

				if ( $entry_id ) {
					GFAPI::update_entry_field( $entry_id, $field_id, $normalized_value );
				}
			}

			return $entry;
		}

		/**
		 * Normalize the value for one file upload field.
		 *
		 * @param string $field_value The stored entry value.
		 * @param object $field       The GF file upload field object.
		 * @param array  $form        The current form.
		 *
		 * @return string
		 */
		private static function localize_field_value( $field_value, $field, $form ) {
			if ( $field->multipleFiles ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$urls = json_decode( $field_value, true );
				if ( ! is_array( $urls ) ) {
					return $field_value;
				}

				// Detect and flatten double-encoded elements. This happens when a workflow
				// stores the GF JSON array string as an array element instead of extracting
				// individual URLs, e.g. ["[\"https://...\"]"] instead of ["https://..."].
				$flattened = [];
				$unwrapped = false;
				foreach ( $urls as $element ) {
					if ( is_string( $element ) ) {
						$inner = json_decode( $element, true );
						if ( is_array( $inner ) ) {
							foreach ( $inner as $inner_url ) {
								$flattened[] = $inner_url;
							}
							$unwrapped = true;
							continue;
						}
					}
					$flattened[] = $element;
				}

				if ( $unwrapped ) {
					$urls = $flattened;
				}

				$changed = $unwrapped;

				foreach ( $urls as $index => $url ) {
					$localized = self::localize_file_url( $url, $field, $form );
					if ( $localized === $url ) {
						continue;
					}

					$urls[ $index ] = $localized;
					$changed        = true;
				}

				return $changed ? wp_json_encode( array_values( $urls ) ) : $field_value;
			}

			return self::localize_file_url( $field_value, $field, $form );
		}

		/**
		 * Download a remote file URL into the current form upload root.
		 *
		 * @param string $file_url The file URL stored in the entry.
		 * @param object $field    The GF file upload field object.
		 * @param array  $form     The current form.
		 *
		 * @return string
		 */
		private static function localize_file_url( $file_url, $field, $form ) {
			if ( ! is_string( $file_url ) ) {
				return $file_url;
			}

			$file_url = trim( $file_url );
			if ( '' === $file_url ) {
				return $file_url;
			}

			$form_id = (int) rgar( $form, 'id' );

			if ( self::is_gf_upload_url( $file_url, $form_id ) ) {
				return $file_url;
			}

			if ( ! self::is_allowed_remote_upload_url( $file_url ) ) {
				return $file_url;
			}

			$cache_key = md5( $form_id . '|' . $file_url );
			if ( isset( self::$download_cache[ $cache_key ] ) ) {
				return self::$download_cache[ $cache_key ];
			}

			$max_bytes = 25 * MB_IN_BYTES;
			if ( ! self::is_remote_upload_size_allowed( $file_url, $max_bytes ) ) {
				self::$download_cache[ $cache_key ] = $file_url;
				return $file_url;
			}

			$local_url = self::download_remote_upload_to_gf_root( $file_url, $form_id, $max_bytes );

			self::$download_cache[ $cache_key ] = $local_url ?: $file_url;

			return self::$download_cache[ $cache_key ];
		}

		/**
		 * Determine whether a URL already points at the current form upload root.
		 *
		 * @param string $file_url The URL to inspect.
		 * @param int    $form_id  The form ID.
		 *
		 * @return bool
		 */
		private static function is_gf_upload_url( $file_url, $form_id ) {
			$upload_root_info = GF_Field_FileUpload::get_upload_root_info( $form_id );
			$root_url         = trailingslashit( rgar( $upload_root_info, 'url' ) );

			return ! empty( $root_url ) && str_starts_with( $file_url, $root_url );
		}

		/**
		 * Validate whether a remote file URL is safe to fetch.
		 *
		 * @param string $file_url The URL to validate.
		 *
		 * @return bool
		 */
		private static function is_allowed_remote_upload_url( $file_url ) {
			if ( ! wp_http_validate_url( $file_url ) ) {
				return false;
			}

			$parts  = wp_parse_url( $file_url );
			$scheme = strtolower( rgar( $parts, 'scheme' ) );
			$host   = strtolower( rgar( $parts, 'host' ) );

			if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
				return false;
			}

			if ( 'localhost' === $host || str_ends_with( $host, '.local' ) ) {
				return false;
			}

			if ( ! self::is_public_remote_host( $host ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Ensure a remote host resolves only to public IPs.
		 *
		 * @param string $host The host to validate.
		 *
		 * @return bool
		 */
		private static function is_public_remote_host( $host ) {
			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return self::is_public_ip( $host );
			}

			$resolved_ips = gethostbynamel( $host );
			if ( empty( $resolved_ips ) || ! is_array( $resolved_ips ) ) {
				return false;
			}

			foreach ( $resolved_ips as $ip ) {
				if ( ! self::is_public_ip( $ip ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Determine whether an IP address is public.
		 *
		 * @param string $ip The IP address.
		 *
		 * @return bool
		 */
		private static function is_public_ip( $ip ) {
			return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false;
		}

		/**
		 * Check the remote file size, when available, before downloading.
		 *
		 * @param string $file_url  The URL to inspect.
		 * @param int    $max_bytes The maximum allowed size in bytes.
		 *
		 * @return bool
		 */
		private static function is_remote_upload_size_allowed( $file_url, $max_bytes ) {
			if ( $max_bytes <= 0 ) {
				return true;
			}

			$response = wp_safe_remote_head(
				$file_url,
				[
					'timeout'            => 15,
					'redirection'        => 3,
					'reject_unsafe_urls' => true,
				]
			);

			if ( is_wp_error( $response ) ) {
				return true;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( $status_code < 200 || $status_code >= 400 ) {
				return true;
			}

			$content_length = (int) wp_remote_retrieve_header( $response, 'content-length' );

			return $content_length <= 0 || $content_length <= $max_bytes;
		}

		/**
		 * Download a remote file into the GF upload root and return the new local URL.
		 *
		 * @param string $file_url  The remote URL.
		 * @param int    $form_id   The form ID.
		 * @param int    $max_bytes The maximum allowed size in bytes.
		 *
		 * @return string
		 */
		private static function download_remote_upload_to_gf_root( $file_url, $form_id, $max_bytes ) {
			/* @noinspection PhpIncludeInspection */
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$temp_file = download_url( $file_url, 30 );
			if ( is_wp_error( $temp_file ) ) {
				return '';
			}

			$temp_size = filesize( $temp_file );
			if ( $max_bytes > 0 && false !== $temp_size && $temp_size > $max_bytes ) {
				wp_delete_file( $temp_file );
				return '';
			}

			$file_name = self::get_remote_file_name( $file_url, $temp_file );
			$target    = GFFormsModel::get_file_upload_path( $form_id, $file_name );

			if ( ! is_array( $target ) || empty( $target['path'] ) || empty( $target['url'] ) ) {
				wp_delete_file( $temp_file );
				return '';
			}

			$copied = copy( $temp_file, $target['path'] );
			wp_delete_file( $temp_file );

			if ( ! $copied ) {
				return '';
			}

			return $target['url'];
		}

		/**
		 * Derive a safe filename from the remote file URL.
		 *
		 * @param string $file_url  The remote URL.
		 * @param string $temp_file The downloaded temporary file path.
		 *
		 * @return string
		 */
		private static function get_remote_file_name( $file_url, $temp_file = '' ) {
			$path      = (string) wp_parse_url( $file_url, PHP_URL_PATH );
			$file_name = wp_basename( rawurldecode( $path ) );
			$file_name = sanitize_file_name( $file_name );

			if ( '' === $file_name ) {
				$file_name = 'remote-upload';
			}

			if ( '' === pathinfo( $file_name, PATHINFO_EXTENSION ) && '' !== $temp_file ) {
				$mime_type = function_exists( 'mime_content_type' ) ? mime_content_type( $temp_file ) : '';
				if ( ! empty( $mime_type ) ) {
					foreach ( get_allowed_mime_types() as $extensions => $allowed_mime_type ) {
						if ( $allowed_mime_type !== $mime_type ) {
							continue;
						}

						$extension = strtok( $extensions, '|' );
						if ( $extension ) {
							$file_name .= '.' . $extension;
						}

						break;
					}
				}
			}

			return $file_name;
		}

		/**
		 * Persist the extra entry metadata GF uses to map file URLs back to local paths.
		 *
		 * @param array $entry The current entry.
		 * @param array $form  The current form.
		 *
		 * @return void
		 */
		private static function store_upload_path_meta_for_entry( $entry, $form ) {
			$entry_id = (int) rgar( $entry, 'id' );
			$form_id  = (int) rgar( $form, 'id' );

			if ( ! $entry_id || ! $form_id ) {
				return;
			}

			$fields = GFAPI::get_fields_by_type( $form, [ 'fileupload' ], true );
			if ( empty( $fields ) ) {
				return;
			}

			foreach ( $fields as $field ) {
				$extra_meta = $field->get_extra_entry_metadata( $form, $entry );
				if ( empty( $extra_meta ) || ! is_array( $extra_meta ) ) {
					continue;
				}

				foreach ( $extra_meta as $meta_key => $meta_value ) {
					gform_update_meta( $entry_id, $meta_key, $meta_value, $form_id );
				}
			}
		}
	}
}

BLD_APC_Remote_Upload_Localizer::init();
