<?php
/**
 * BrightLeaf GF Form Styler (Feed Add-On)
 *
 * Goal
 * - Provide a user-friendly, non-destructive way to style Gravity Forms without writing manual CSS.
 * - Allow granular control over form appearance at the global, field-type, and individual field levels.
 * - Ensure that styles are only applied when explicitly configured, preserving the theme's natural look by default.
 *
 * Features
 * - Integrated UI: A custom styling panel built directly into the Gravity Forms Feed settings.
 * - Global Tokens: Define base font sizes, colors, spacing, and borders that apply to the entire form.
 * - Type Overrides: Set styles for all fields of a specific type (e.g., all text inputs or all buttons).
 * - Field Overrides: Target specific fields by ID for high-specificity styling that wins over global/type settings.
 * - Live CSS Preview: See the generated CSS in real-time within the admin UI before saving.
 * - Base64 Storage: Style configurations are stored as base64-encoded JSON to avoid common character-filtering issues in database fields.
 * - Conditional Emission: Only emits CSS for properties that have been explicitly set, reducing bloat and preventing style conflicts.
 * - Admin Preview Support: Option to apply custom styles even within the Gravity Forms admin preview screens.
 * - Debug Mode: Console logging for troubleshooting feed application and CSS injection.
 *
 * Requirements
 * - A hidden field on the form you would like to style named `style_b64`.
 *
 * How To Use
 * 1) Create a Styling Profile
 *    - Go to Forms -> [Your Form] -> Settings -> Form Styler.
 *    - Click "Add New" to create a new styling feed.
 *    - Give your profile a name (e.g., "Dark Theme" or "Contact Page Style").
 *
 * 2) Configure Styles
 *    - Open the "Styling" section in the feed settings.
 *    - Global Tokens: Use sections like Typography, Colors, and Spacing to set general form styles.
 *    - Type Overrides: Select a field type from the dropdown, then click "Type overrides" in the navigation to edit.
 *    - Field Overrides: Click a specific field in the field list on the left to apply styles only to that field.
 *
 * 3) Activate and Apply
 *    - Mark the feed as "Default" if you want it applied to all instances of this form.
 *    - Use the "Apply in admin preview" checkbox to see your styles while building the form.
 *    - To apply a specific (non-default) feed via URL for testing, append `?blfs_feed=[FEED_ID]` to your page URL.
 *
 * 4) Advanced Management
 *    - Export/Import: Copy the JSON payload to move styles between forms or sites.
 *    - Reset: Use the "Reset defaults" button to clear all configurations and start fresh.
 *
 * Developer Notes
 * - CSS Injection: Styles are injected into the page head via `<style></style>` tags, scoped to the specific form and feed.
 * - Scoping: CSS rules use dual selectors (`.blfs-scope-[ID]` and `#gform_wrapper_[ID]`) for maximum compatibility.
 * - Payload: The authoritative data source is the base64-encoded JSON stored in the `style_b64` feed meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'gform_loaded',
	function () {

		if ( ! class_exists( 'GFForms' ) || ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		GFForms::include_addon_framework();

		if ( class_exists( 'GF_BrightLeaf_Form_Styler_AddOn' ) ) {
			return;
		}

		/**
		 * Class GF_BrightLeaf_Form_Styler_AddOn
		 * Extends the GFFeedAddOn class to provide custom form-styling functionality for Gravity Forms.
		 *
		 * This add-on enables the addition of conditional styling configurations, feed management for form styling,
		 * and admin preview capabilities. It integrates seamlessly with Gravity Forms to allow the configuration
		 * and application of styles at both global and field-level granularity.
		 */
		class GF_BrightLeaf_Form_Styler_AddOn extends GFFeedAddOn {
			// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore,PHPCompatibility.FunctionDeclarations.NewClosure.ThisFoundOutsideClass
			/**
			 * The version of the add-on.
			 *
			 * @var string
			 */
			protected $_version = '1.5.0';

			/**
			 * The minimum required version of Gravity Forms.
			 *
			 * @var string
			 */
			protected $_min_gravityforms_version = '2.6';

			/**
			 * The slug for the add-on.
			 *
			 * @var string
			 */
			protected $_slug = 'bl-gf-form-styler';

			/**
			 * The path to the file containing the add-on.
			 *
			 * @var string
			 */
			protected $_path = __FILE__;

			/**
			 * The full path to the file containing the add-on.
			 *
			 * @var string
			 */
			protected $_full_path = __FILE__;

			/**
			 * The title of the add-on.
			 *
			 * @var string
			 */
			protected $_title = 'BrightLeaf Form Styler';

			/**
			 * The short title of the add-on.
			 *
			 * @var string
			 */
			protected $_short_title = 'Form Styler';
			// phpcs:enable PSR2.Classes.PropertyDeclaration.Underscore
			/**
			 * The singleton instance of the class.
			 *
			 * @var self|null
			 */
			private static $instance = null;
			/**
			 * Keep track of feeds already injected on this page load to prevent duplicate <style> blocks.
			 *
			 * @var array
			 */
			private static $injected_feeds = [];

			/**
			 * Retrieves the singleton instance of the class.
			 *
			 * Ensures that only one instance of the class is created and reused across the application.
			 *
			 * @return self The singleton instance of the class.
			 */
			public static function get_instance() {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}

			/**
			 * Initialize the add-on.
			 *
			 * Registers filters for form styling injection and preview management.
			 */
			public function init() {
				parent::init();

				add_filter( 'gform_get_form_filter', [ $this, 'inject_styling_into_form_html' ], 10, 2 );
				add_filter( 'gform_form_tag', [ $this, 'filter_form_tag_add_scope_class' ], 10, 2 );
				add_filter( 'gform_pre_render', [ $this, 'maybe_set_feed_override_from_request' ], 10, 1 );
				add_filter( 'gform_admin_pre_render', [ $this, 'maybe_set_feed_override_from_request' ], 10, 1 );
			}

			/*
			--------------------------------------------------------------------
			Safe helpers
			--------------------------------------------------------------------
			*/

			/**
			 * Get form ID from current context.
			 *
			 * Checks both GET params and current Gravity Forms context.
			 *
			 * @return int The form ID.
			 */
			protected function blfs_get_current_form_id_safe() {
				$form_id = absint( rgget( 'id' ) );
				if ( $form_id ) {
					return $form_id;
				}
				$form = $this->get_current_form();
				return absint( rgar( $form, 'id' ) );
			}

			/**
			 * Get feed ID from current request.
			 *
			 * @return int The feed ID from the 'fid' GET parameter.
			 */
			protected function blfs_get_current_feed_id_safe() {
				return absint( rgget( 'fid' ) );
			}

			/**
			 * Safely retrieve a feed by ID.
			 *
			 * @param int $feed_id The feed ID.
			 *
			 * @return array|null The feed object array or null if not found.
			 */
			protected function blfs_get_feed_safe( $feed_id ) {
				if ( ! $feed_id ) {
					return null;
				}
				$feed = GFAPI::get_feed( $feed_id );
				return is_array( $feed ) ? $feed : null;
			}

			/*
			--------------------------------------------------------------------
			Feed list columns
			--------------------------------------------------------------------
			*/

			/**
			 * Define columns for the feed list table.
			 *
			 * @return array Associative array of column keys and labels.
			 */
			public function feed_list_columns() {
				return [
					'feedName'   => 'Name',
					'is_default' => 'Default',
					'updated'    => 'Updated',
				];
			}

			/**
			 * Get value for the 'is_default' column in feed list.
			 *
			 * @param array $feed The feed object.
			 *
			 * @return string HTML/Emoji representation of default status.
			 */
			public function get_column_value_is_default( $feed ) {
				$enabled = (bool) rgar( $feed, 'is_active' );
				$default = rgar( rgar( $feed, 'meta' ), 'is_default' );
				if ( ! $enabled ) {
					return '<span style="opacity:.6;">—</span>';
				}
				return $default ? '✅' : '—';
			}

			/**
			 * Get value for the 'updated' column in feed list.
			 *
			 * @param array $feed The feed object.
			 *
			 * @return string Formatted date string or spacer.
			 */
			public function get_column_value_updated( $feed ) {
				$ts = (int) rgar( rgar( $feed, 'meta' ), 'updated_ts' );
				if ( ! $ts ) {
					return '<span style="opacity:.6;">—</span>';
				}
				return esc_html( date_i18n( 'Y-m-d H:i', $ts ) );
			}

			/*
			--------------------------------------------------------------------
			Feed settings fields
			--------------------------------------------------------------------
			*/

			/**
			 * Define feed settings fields.
			 *
			 * @return array Gravity Forms Add-on settings fields configuration.
			 */
			public function feed_settings_fields() {
				return [
					[
						'title'  => 'Feed Settings',
						'fields' => [
							[
								'name'     => 'feedName',
								'label'    => 'Profile name',
								'type'     => 'text',
								'class'    => 'medium',
								'required' => true,
							],
							[
								'name'    => 'is_default',
								'label'   => 'Default profile for this form',
								'type'    => 'checkbox',
								'choices' => [
									[
										'label' => 'Use this feed as the default styling profile',
										'name'  => 'is_default',
									],
								],
							],
							[
								'name'    => 'apply_admin_preview',
								'label'   => 'Apply in admin preview',
								'type'    => 'checkbox',
								'choices' => [
									[
										'label' => 'Apply on admin preview screens (recommended)',
										'name'  => 'apply_admin_preview',
									],
								],
							],
						],
					],
					[
						'title'       => 'Styling',
						'description' => 'Configure global tokens, type overrides, and field overrides. This UI saves a JSON payload (base64-encoded) in the feed meta.',
						'fields'      => [
							[
								'name'  => 'style_b64',
								'label' => '',
								'type'  => 'text',
								'class' => 'large',
								'style' => 'display:none;',
							],
							[
								'name'  => 'styler_ui',
								'label' => '',
								'type'  => 'blfs_markup',
							],
						],
					],
					[
						'title'  => 'Advanced',
						'fields' => [
							[
								'name'    => 'debug_mode',
								'label'   => 'Debug logging',
								'type'    => 'checkbox',
								'choices' => [
									[
										'label' => 'Enable console.log diagnostics (recommended during setup)',
										'name'  => 'debug_mode',
									],
								],
							],
						],
					],
				];
			}

			/*
			--------------------------------------------------------------------
			Custom settings field renderer — "type" => "blfs_markup"
			--------------------------------------------------------------------
			*/

			/**
			 * Render the custom styling UI markup.
			 */
			public function settings_blfs_markup() {

				$form_id = $this->blfs_get_current_form_id_safe();
				if ( ! $form_id ) {
					echo '<div style="color:#b32d2e;">Form Styler: Could not determine form ID.</div>';
					return;
				}

				$feed_id = $this->blfs_get_current_feed_id_safe(); // 0 on "new feed"
				$feed    = $this->blfs_get_feed_safe( $feed_id );
				$meta    = is_array( $feed ) ? rgar( $feed, 'meta' ) : [];

				$stored_b64 = rgar( $meta, 'style_b64' );
				$json       = '';

				if ( ! empty( $stored_b64 ) && is_string( $stored_b64 ) ) {
					$decoded = base64_decode( $stored_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if ( false !== $decoded ) {
						$json = $decoded;
					}
				}

				$factory_payload = $this->default_style_payload();
				$factory_json    = wp_json_encode( $factory_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

				if ( empty( $json ) ) {
					$json = $factory_json;
				}

				$form        = GFAPI::get_form( $form_id );
				$fields      = [];
				$type_counts = [];

				if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
					foreach ( $form['fields'] as $f ) {
						$field_id    = is_object( $f ) ? $f->id : rgar( $f, 'id' );
						$field_type  = is_object( $f ) ? $f->type : rgar( $f, 'type' );
						$field_label = is_object( $f ) && method_exists( $f, 'get_field_label' )
							? $f->get_field_label( false, '' )
							: rgar( $f, 'label' );

						$field_type = (string) $field_type;
						if ( '' !== $field_type ) {
							$type_counts[ $field_type ] = isset( $type_counts[ $field_type ] )
								? ( $type_counts[ $field_type ] + 1 )
								: 1;
						}

						$fields[] = [
							'id'    => $field_id,
							'label' => $field_label ?: '(no label)',
							'type'  => $field_type ?: '',
						];
					}
				}

				$types = [];
				foreach ( $type_counts as $t => $count ) {
					$types[] = [
						'type'  => $t,
						'count' => $count,
					];
				}
				?>
				<div id="blfs-root" style="max-width:1100px;">
					<style>
                        #blfs-root { margin-top: 10px; }
                        .blfs-grid { display: grid; grid-template-columns: 270px 1fr; gap: 16px; align-items: start; }
                        .blfs-card { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 14px; }
                        .blfs-card h3 { margin: 0 0 10px; font-size: 14px; }
                        .blfs-nav { display: flex; flex-direction: column; gap: 6px; }
                        .blfs-nav button { text-align: left; width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #dcdcde; background: #f6f7f7; cursor: pointer; }
                        .blfs-nav button[aria-current="true"] { background: #fff; border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1 inset; }
                        .blfs-row { display: grid; grid-template-columns: 220px 1fr; gap: 10px; margin-bottom: 10px; align-items: center; }
                        .blfs-row label { font-weight: 600; }
                        .blfs-help { color: #646970; font-size: 12px; margin-top: 2px; }
                        .blfs-split { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
                        .blfs-field-list { max-height: 260px; overflow: auto; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; }
                        .blfs-field-item { padding: 8px 10px; border-bottom: 1px solid #f0f0f1; cursor: pointer; }
                        .blfs-field-item:hover { background: #f6f7f7; }
                        .blfs-field-item[aria-current="true"] { background: #e7f5ff; }
                        .blfs-inline { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
                        .blfs-pill { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #f0f0f1; border: 1px solid #dcdcde; }
                        .blfs-preview-note { color: #1d2327; font-size: 12px; background: #f6f7f7; border: 1px solid #dcdcde; padding: 8px 10px; border-radius: 8px; }
                        .blfs-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
                        .blfs-actions button { padding: 7px 12px; border-radius: 8px; border: 1px solid #2271b1; background: #2271b1; color: #fff; cursor: pointer; }
                        .blfs-actions button.secondary { background: #fff; color: #2271b1; }
                        .blfs-actions button.danger { border-color: #d63638; background: #d63638; }
                        .blfs-textarea { width: 100%; min-height: 160px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; }
                        .blfs-css-preview { width: 100%; height: 200px; max-height: 500px; overflow-y: auto !important; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 8px; padding: 10px; }
                        .blfs-warn { color: #b32d2e; background: #fcf0f1; border: 1px solid #f5c6cb; border-radius: 8px; padding: 8px 10px; font-size: 12px; margin-top: 8px; }
                        @media (max-width: 980px) { .blfs-grid { grid-template-columns: 1fr; } }
					</style>

					<div class="blfs-preview-note">
						<strong>How this works:</strong>
						Global tokens become CSS variables on a feed-specific scope class. Type overrides apply to all fields of a given type.
						Field overrides apply only to a specific field and win over type overrides.
					</div>

					<div class="blfs-grid" style="margin-top:12px;">
						<div class="blfs-card">
							<h3>Sections</h3>
							<div class="blfs-nav" id="blfs-nav"></div>

							<div style="margin-top:14px;">
								<h3>Types</h3>
								<select id="blfs-type-select" class="widefat"></select>
								<div class="blfs-help">Pick a type, then open "Type overrides".</div>
							</div>

							<div style="margin-top:14px;">
								<h3>Fields</h3>
								<input type="text" id="blfs-field-search" class="widefat" placeholder="Search fields…" />
								<div class="blfs-field-list" id="blfs-field-list" style="margin-top:8px;"></div>
							</div>
						</div>

						<div class="blfs-card">
							<div class="blfs-actions" style="justify-content:space-between;">
								<div class="blfs-inline">
									<span class="blfs-pill">Form ID: <?php echo esc_html( $form_id ); ?></span>
									<span class="blfs-pill">Feed ID: <?php echo esc_html( $feed_id ?: 'new' ); ?></span>
								</div>
								<div class="blfs-inline">
									<button type="button" class="secondary" id="blfs-export">Export JSON</button>
									<button type="button" class="secondary" id="blfs-import">Import JSON</button>
									<button type="button" class="danger"    id="blfs-reset">Reset defaults</button>
								</div>
							</div>

							<hr style="margin:14px 0;">

							<div id="blfs-panel"></div>

							<hr style="margin:14px 0;">
							<h3 style="margin-bottom:8px;">Generated CSS (preview)</h3>
							<textarea class="blfs-css-preview" id="blfs-css-preview"></textarea>
							<div class="blfs-help">Preview only — CSS is injected at runtime via a &lt;style&gt; tag and is not stored in a GF settings field.</div>

							<hr style="margin:14px 0;">
							<h3 style="margin-bottom:8px;">JSON payload (advanced)</h3>
							<textarea class="blfs-textarea" id="blfs-json-editor"></textarea>
							<div class="blfs-help">This is the authoritative saved payload. The UI edits this value. Invalid JSON will be rejected on save.</div>
						</div>
					</div>
				</div>

				<script>
                    (function(){
                        const BLFS_FORM_ID      = <?php echo $form_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped --already casted to int ?>;
                        const BLFS_FEED_ID      = <?php echo $feed_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped --already casted to int ?>;
                        const BLFS_FIELDS       = <?php echo wp_json_encode( $fields ); ?>;
                        const BLFS_TYPES        = <?php echo wp_json_encode( $types ); ?>;
                        const BLFS_SAVED_JSON   = <?php echo wp_json_encode( $json ); ?>;
                        const BLFS_FACTORY_JSON = <?php echo wp_json_encode( $factory_json ); ?>;

                        /* -------------------------------------------------------------- */
                        /* DOM refs                                                        */
                        /* -------------------------------------------------------------- */

                        // GF Add-On Framework generates names like: _gaddon_setting_style_b64
                        const hiddenField =
                            document.querySelector('input[name$="_style_b64"]') ||
                            document.querySelector('input[name*="style_b64"]')  ||
                            document.querySelector('#gaddon-setting-row-style_b64 input');

                        // FIX: warn loudly if the hidden field is missing so the issue is obvious.
                        if ( ! hiddenField ) {
                            console.warn('[BLFS] ⚠ Could not find style_b64 hidden input — styles will NOT be saved! Check that the feed settings field name matches.');
                        }

                        const jsonEditor = document.getElementById('blfs-json-editor');
                        const cssPreview = document.getElementById('blfs-css-preview');
                        const typeSelect = document.getElementById('blfs-type-select');

                        /* -------------------------------------------------------------- */
                        /* Utilities                                                       */
                        /* -------------------------------------------------------------- */

                        function safeParse(str){ try { return JSON.parse(str); } catch(e){ return null; } }
                        function pretty(obj)   { return JSON.stringify(obj, null, 2); }
                        function log(...args)  { console.log('[BLFS]', ...args); }

                        function b64Decode(str){
                            try {
                                const binary = atob(str);
                                const bytes = new Uint8Array(binary.length);
                                for (let i = 0; i < binary.length; i++) {
                                    bytes[i] = binary.charCodeAt(i);
                                }
                                return new TextDecoder().decode(bytes);
                            } catch(e){ return null; }
                        }
                        function b64Encode(str){
                            try {
                                const bytes = new TextEncoder().encode(str);
                                let binary = '';
                                for (let i = 0; i < bytes.byteLength; i++) {
                                    binary += String.fromCharCode(bytes[i]);
                                }
                                return btoa(binary);
                            } catch(e){ return null; }
                        }

                        function setHidden(jsonStr){
                            if ( hiddenField ) hiddenField.value = b64Encode(jsonStr ) || '';
                        }

                        /* -------------------------------------------------------------- */
                        /* Initialise from stored value or default                        */
                        /* -------------------------------------------------------------- */

                        let initialJson = BLFS_SAVED_JSON;

                        if ( hiddenField && hiddenField.value ) {
                            const decoded = b64Decode(hiddenField.value);
                            if ( decoded ) initialJson = decoded;
                        }

                        jsonEditor.value = initialJson;
                        setHidden(initialJson);

                        let state         = safeParse(jsonEditor.value) || safeParse(BLFS_SAVED_JSON) || {};
                        let activeSection = 'Global';
                        let activeFieldId = null;
                        let activeType    = '';

                        /* -------------------------------------------------------------- */
                        /* Section definitions                                            */
                        /* -------------------------------------------------------------- */

                        const SECTIONS = [
                            { key: 'Global',         label: 'Global tokens'        },
                            { key: 'Typography',     label: 'Typography'           },
                            { key: 'Colors',         label: 'Colors'               },
                            { key: 'Spacing',        label: 'Spacing'              },
                            { key: 'Borders',        label: 'Borders'              },
                            { key: 'Buttons',        label: 'Buttons'              },
                            { key: 'States',         label: 'States (focus/error)' },
                            { key: 'TypeOverrides',  label: 'Type overrides'       },
                            { key: 'FieldOverrides', label: 'Field overrides'      },
                        ];

                        /* -------------------------------------------------------------- */
                        /* State helpers                                                   */
                        /* -------------------------------------------------------------- */

                        function ensureDefaults(){
                            // Only scaffold the object structure — never inject hardcoded values.
                            // Empty strings mean "not set", so no CSS is emitted for that property.
                            state = state || {};
                            state.version = state.version || 1;
                            state.tokens  = state.tokens  || {};

                            state.tokens.typography = state.tokens.typography || { base_font_size:'', label_font_size:'', input_font_size:'' };
                            state.tokens.colors     = state.tokens.colors     || { text:'', label:'', choice_label:'', description:'', input_bg:'', input_border:'', focus:'', error:'', button_bg:'', button_text:'' };
                            state.tokens.spacing    = state.tokens.spacing    || { field_margin_bottom:'', input_padding:'', section_padding:'' };
                            state.tokens.borders    = state.tokens.borders    || { radius:'', border_width:'' };
                            state.tokens.buttons    = state.tokens.buttons    || { radius:'', padding:'' };
                            state.tokens.states     = state.tokens.states     || { focus_ring:'', error_border:'' };

                            if ( ! state.type_overrides || Array.isArray( state.type_overrides ) ) {
                                state.type_overrides = {};
                            }
                            if ( ! state.field_overrides || Array.isArray( state.field_overrides ) ) {
                                state.field_overrides = {};
                            }
                        }

                        function syncJson(){
                            ensureDefaults();
                            const text = pretty(state);
                            jsonEditor.value = text;
                            setHidden(text);          // FIX: was using undefined `hiddenTextarea`
                            updateCssPreview();
                        }

                        function updateCssPreview(){
                            const obj = safeParse(jsonEditor.value);
                            if ( !obj ) {
                                cssPreview.value = 'Invalid JSON (preview not available).';
                                return;
                            }
                            const css = buildCssFromState(BLFS_FORM_ID, BLFS_FEED_ID || 0, obj);
                            cssPreview.value = css || '(no css generated)';
                        }

                        jsonEditor.addEventListener('input', () => {
                            const obj = safeParse(jsonEditor.value);
                            if ( obj ) {
                                state = obj;
                                setHidden(jsonEditor.value);  // FIX: was missing b64 encoding in some paths
                            }
                            updateCssPreview();
                        });

                        /* -------------------------------------------------------------- */
                        /* Form submit guard                                               */
                        /* -------------------------------------------------------------- */

                        document.addEventListener('submit', function(e){
                            const obj = safeParse(jsonEditor.value);
                            if ( !obj ) {
                                e.preventDefault();
                                alert('Form Styler: Invalid JSON. Please fix before saving.');
                                log('Invalid JSON prevented save.');
                            } else {
                                // FIX: was `if ( hiddenTextarea )` — hiddenTextarea was never defined.
                                // The hidden field is already kept in sync by setHidden(); re-sync here as a safety net.
                                setHidden(jsonEditor.value);
                                log('JSON validated, saving feed…');
                            }
                        }, true);

                        /* -------------------------------------------------------------- */
                        /* Escape helper                                                   */
                        /* -------------------------------------------------------------- */

                        function escapeHtml(s){
                            return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
                        }

                        /* -------------------------------------------------------------- */
                        /* Nav render                                                      */
                        /* -------------------------------------------------------------- */

                        function renderNav(){
                            const nav = document.getElementById('blfs-nav');
                            nav.innerHTML = '';
                            SECTIONS.forEach(s => {
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.textContent = s.label;
                                btn.setAttribute('aria-current', s.key === activeSection ? 'true' : 'false');
                                btn.addEventListener('click', () => {
                                    activeSection = s.key;
                                    activeFieldId = null;
                                    renderNav(); renderPanel(); renderFieldList();
                                    log('Section changed:', activeSection);
                                });
                                nav.appendChild(btn);
                            });
                        }

                        /* -------------------------------------------------------------- */
                        /* Type select render                                              */
                        /* -------------------------------------------------------------- */

                        function renderTypeSelect(){
                            typeSelect.innerHTML = '';
                            const opt0 = document.createElement('option');
                            opt0.value       = '';
                            opt0.textContent = '— Select a type —';
                            typeSelect.appendChild(opt0);

                            BLFS_TYPES.forEach(t => {
                                const opt       = document.createElement('option');
                                opt.value       = t.type;
                                opt.textContent = `${t.type} (${t.count})`;
                                typeSelect.appendChild(opt);
                            });

                            typeSelect.value = activeType || '';
                            typeSelect.addEventListener('change', () => {
                                activeType = typeSelect.value;
                                if ( activeSection === 'TypeOverrides' ) renderPanel();
                                log('Active type:', activeType);
                            });
                        }

                        /* -------------------------------------------------------------- */
                        /* Field list render                                               */
                        /* -------------------------------------------------------------- */

                        function renderFieldList(){
                            const box = document.getElementById('blfs-field-list');
                            const q   = (document.getElementById('blfs-field-search').value || '').toLowerCase().trim();
                            let list  = BLFS_FIELDS.slice();
                            if ( q ) {
                                list = list.filter(f =>
                                    String(f.label||'').toLowerCase().includes(q) ||
                                    String(f.type||'').toLowerCase().includes(q)  ||
                                    String(f.id).includes(q)
                                );
                            }
                            box.innerHTML = '';
                            list.forEach(f => {
                                const item = document.createElement('div');
                                item.className = 'blfs-field-item';
                                item.setAttribute('aria-current', String(f.id) === String(activeFieldId) ? 'true' : 'false');
                                item.innerHTML = `<div style="display:flex;justify-content:space-between;gap:10px;">
							<div><strong>${escapeHtml(f.label||'(no label)')}</strong>
							<div class="blfs-help">ID ${f.id} • ${escapeHtml(f.type||'')}</div></div>
							<div class="blfs-pill">#field_${BLFS_FORM_ID}_${f.id}</div>
						</div>`;
                                item.addEventListener('click', () => {
                                    activeSection = 'FieldOverrides';
                                    activeFieldId = String(f.id);
                                    renderNav(); renderPanel(); renderFieldList();
                                    log('Active field override:', activeFieldId);
                                });
                                box.appendChild(item);
                            });
                        }

                        document.getElementById('blfs-field-search').addEventListener('input', renderFieldList);

                        /* -------------------------------------------------------------- */
                        /* Generic input row builder                                       */
                        /* -------------------------------------------------------------- */

                        function inputRow(label, value, onChange, opts={}){
                            const row = document.createElement('div');
                            row.className = 'blfs-row';

                            const lab = document.createElement('div');
                            lab.innerHTML = `<label>${escapeHtml(label)}</label>${opts.help ? `<div class="blfs-help">${escapeHtml(opts.help)}</div>` : ''}`;

                            const ctl = document.createElement('div');
                            const el  = document.createElement('input');
                            el.type        = 'text';
                            el.value       = value || '';
                            el.className   = 'regular-text';
                            el.placeholder = opts.placeholder || '';
                            el.addEventListener('input', () => onChange(el.value));
                            ctl.appendChild(el);

                            row.appendChild(lab);
                            row.appendChild(ctl);
                            return row;
                        }

                        /* -------------------------------------------------------------- */
                        /* Override editor (shared by type + field overrides)             */
                        /* -------------------------------------------------------------- */

                        function renderOverrideEditor(targetObj, onClear){
                            const panel = document.createElement('div');

                            // Label section
                            const labelHead = document.createElement('div');
                            labelHead.style.cssText = 'font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#646970; margin:0 0 8px;';
                            labelHead.textContent = 'Label';
                            panel.appendChild(labelHead);
                            panel.appendChild(inputRow('Label color',     targetObj.label_color     || '', v => { targetObj.label_color     = v; syncJson(); }, { placeholder:'e.g. #1d2327' }));
                            panel.appendChild(inputRow('Label font size', targetObj.label_font_size || '', v => { targetObj.label_font_size = v; syncJson(); }, { help:'e.g. 14px — leave blank to inherit' }));

                            // Input section
                            const inputHead = document.createElement('div');
                            inputHead.style.cssText = 'font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#646970; margin:14px 0 8px;';
                            inputHead.textContent = 'Input';
                            panel.appendChild(inputHead);
                            panel.appendChild(inputRow('Background',   targetObj.input_bg     || '', v => { targetObj.input_bg     = v; syncJson(); }, { placeholder:'e.g. #ffffff' }));
                            panel.appendChild(inputRow('Text color',   targetObj.input_text   || '', v => { targetObj.input_text   = v; syncJson(); }, { placeholder:'e.g. #1d2327' }));
                            panel.appendChild(inputRow('Border color', targetObj.input_border || '', v => { targetObj.input_border = v; syncJson(); }, { placeholder:'e.g. #dcdcde' }));
                            panel.appendChild(inputRow('Radius',       targetObj.input_radius || '', v => { targetObj.input_radius = v; syncJson(); }, { help:'e.g. 10px — leave blank to inherit' }));

                            const actions  = document.createElement('div');
                            actions.className       = 'blfs-actions';
                            actions.style.marginTop = '14px';

                            const clearBtn       = document.createElement('button');
                            clearBtn.type        = 'button';
                            clearBtn.className   = 'secondary';
                            clearBtn.textContent = 'Clear overrides';
                            clearBtn.addEventListener('click', () => {
                                onClear();
                                syncJson();
                                renderPanel();
                                log('Cleared overrides');
                            });

                            actions.appendChild(clearBtn);
                            panel.appendChild(actions);
                            return panel;
                        }

                        /* -------------------------------------------------------------- */
                        /* Panel render                                                    */
                        /* -------------------------------------------------------------- */

                        function renderPanel(){
                            ensureDefaults();
                            const panel = document.getElementById('blfs-panel');
                            panel.innerHTML = '';

                            const title = document.createElement('h3');
                            if ( activeSection === 'FieldOverrides' && activeFieldId ) {
                                title.textContent = `Field Overrides: #${activeFieldId}`;
                            } else if ( activeSection === 'TypeOverrides' ) {
                                title.textContent = `Type Overrides${activeType ? ': ' + activeType : ''}`;
                            } else {
                                title.textContent = (SECTIONS.find(s => s.key === activeSection)?.label || 'Settings');
                            }
                            panel.appendChild(title);

                            if ( activeSection === 'Global' ) {
                                const note = document.createElement('div');
                                note.className   = 'blfs-help';
                                note.textContent = 'Use the sections on the left to edit token groups. These apply to the entire form unless overridden by type or field.';
                                panel.appendChild(note);
                                return;
                            }

                            if ( activeSection === 'Typography' ) {
                                panel.appendChild(inputRow('Base font size',  state.tokens.typography.base_font_size,  v => { state.tokens.typography.base_font_size  = v; syncJson(); }, { help:'Example: 16px or 1rem' }));
                                panel.appendChild(inputRow('Label font size', state.tokens.typography.label_font_size, v => { state.tokens.typography.label_font_size = v; syncJson(); }));
                                panel.appendChild(inputRow('Input font size', state.tokens.typography.input_font_size, v => { state.tokens.typography.input_font_size = v; syncJson(); }));
                                return;
                            }

                            if ( activeSection === 'Colors' ) {
                                panel.appendChild(inputRow('Text',             state.tokens.colors.text,         v => { state.tokens.colors.text         = v; syncJson(); }, { placeholder:'#1d2327' }));
                                panel.appendChild(inputRow('Label',            state.tokens.colors.label,        v => { state.tokens.colors.label        = v; syncJson(); }, { placeholder:'#1d2327' }));
                                panel.appendChild(inputRow('Choice label',     state.tokens.colors.choice_label, v => { state.tokens.colors.choice_label = v; syncJson(); }, { placeholder:'#1d2327', help:'Color for radio/checkbox option text (blank = inherit label color)' }));
                                panel.appendChild(inputRow('Description',      state.tokens.colors.description,  v => { state.tokens.colors.description  = v; syncJson(); }, { placeholder:'#646970' }));
                                panel.appendChild(inputRow('Input background', state.tokens.colors.input_bg,     v => { state.tokens.colors.input_bg     = v; syncJson(); }, { placeholder:'#ffffff' }));
                                panel.appendChild(inputRow('Input border',     state.tokens.colors.input_border, v => { state.tokens.colors.input_border = v; syncJson(); }, { placeholder:'#dcdcde' }));
                                panel.appendChild(inputRow('Focus',            state.tokens.colors.focus,        v => { state.tokens.colors.focus        = v; syncJson(); }, { placeholder:'#2271b1' }));
                                panel.appendChild(inputRow('Error',            state.tokens.colors.error,        v => { state.tokens.colors.error        = v; syncJson(); }, { placeholder:'#d63638' }));
                                panel.appendChild(inputRow('Button background',state.tokens.colors.button_bg,    v => { state.tokens.colors.button_bg    = v; syncJson(); }, { placeholder:'#2271b1' }));
                                panel.appendChild(inputRow('Button text',      state.tokens.colors.button_text,  v => { state.tokens.colors.button_text  = v; syncJson(); }, { placeholder:'#ffffff' }));
                                return;
                            }

                            if ( activeSection === 'Spacing' ) {
                                panel.appendChild(inputRow('Field margin bottom', state.tokens.spacing.field_margin_bottom, v => { state.tokens.spacing.field_margin_bottom = v; syncJson(); }, { help:'Example: 16px' }));
                                panel.appendChild(inputRow('Input padding',       state.tokens.spacing.input_padding,       v => { state.tokens.spacing.input_padding       = v; syncJson(); }, { help:'Example: 10px 12px' }));
                                panel.appendChild(inputRow('Section padding',     state.tokens.spacing.section_padding,     v => { state.tokens.spacing.section_padding     = v; syncJson(); }, { help:'Example: 16px' }));
                                return;
                            }

                            if ( activeSection === 'Borders' ) {
                                panel.appendChild(inputRow('Border radius', state.tokens.borders.radius,       v => { state.tokens.borders.radius       = v; syncJson(); }, { help:'Example: 10px' }));
                                panel.appendChild(inputRow('Border width',  state.tokens.borders.border_width, v => { state.tokens.borders.border_width = v; syncJson(); }, { help:'Example: 1px'  }));
                                return;
                            }

                            if ( activeSection === 'Buttons' ) {
                                panel.appendChild(inputRow('Button radius',  state.tokens.buttons.radius,  v => { state.tokens.buttons.radius  = v; syncJson(); }));
                                panel.appendChild(inputRow('Button padding', state.tokens.buttons.padding, v => { state.tokens.buttons.padding = v; syncJson(); }, { help:'Example: 10px 14px' }));
                                return;
                            }

                            if ( activeSection === 'States' ) {
                                panel.appendChild(inputRow('Focus ring',   state.tokens.states.focus_ring,   v => { state.tokens.states.focus_ring   = v; syncJson(); }, { help:'Example: 0 0 0 3px rgba(34,113,177,.20)' }));
                                panel.appendChild(inputRow('Error border', state.tokens.states.error_border, v => { state.tokens.states.error_border = v; syncJson(); }, { placeholder:'#d63638' }));
                                return;
                            }

                            if ( activeSection === 'TypeOverrides' ) {
                                if ( !activeType ) {
                                    const note = document.createElement('div');
                                    note.className   = 'blfs-help';
                                    note.textContent = 'Select a field type on the left, then edit overrides here.';
                                    panel.appendChild(note);
                                    return;
                                }
                                state.type_overrides[activeType] = state.type_overrides[activeType] || {};
                                panel.appendChild(renderOverrideEditor(state.type_overrides[activeType], () => {
                                    delete state.type_overrides[activeType];
                                }));
                                return;
                            }

                            if ( activeSection === 'FieldOverrides' ) {
                                if ( !activeFieldId ) {
                                    const note = document.createElement('div');
                                    note.className   = 'blfs-help';
                                    note.textContent = 'Click a field on the left to edit overrides.';
                                    panel.appendChild(note);
                                    return;
                                }
                                state.field_overrides[activeFieldId] = state.field_overrides[activeFieldId] || {};
                                panel.appendChild(renderOverrideEditor(state.field_overrides[activeFieldId], () => {
                                    delete state.field_overrides[activeFieldId];
                                }));
                            }
                        }

                        /* -------------------------------------------------------------- */
                        /* Export / Import / Reset                                        */
                        /* -------------------------------------------------------------- */

                        document.getElementById('blfs-export').addEventListener('click', () => {
                            syncJson();
                            navigator.clipboard.writeText(jsonEditor.value)
                                .then(() => { alert('Exported JSON copied to clipboard.'); log('Exported JSON.'); })
                                .catch(()  => alert('Could not copy to clipboard. Please copy manually from the JSON textarea.'));
                        });

                        document.getElementById('blfs-import').addEventListener('click', () => {
                            const input = prompt('Paste JSON to import:');
                            if ( !input ) return;
                            const obj = safeParse(input);
                            if ( !obj ) { alert('Invalid JSON. Import canceled.' ); return; }
                            state = obj;
                            syncJson();
                            renderPanel();
                            log('Imported JSON.');
                        });

                        document.getElementById('blfs-reset').addEventListener('click', () => {
                            if ( !confirm('Reset to factory defaults? This will overwrite your current payload.' ) ) return;
                            state = safeParse(BLFS_FACTORY_JSON) || {};
                            jsonEditor.value = pretty(state);
                            setHidden(jsonEditor.value);
                            activeSection = 'Global';
                            activeFieldId = null;
                            activeType    = '';
                            renderNav();
                            renderPanel();
                            renderTypeSelect();
                            renderFieldList();
                            updateCssPreview();
                            log('Reset to defaults.');
                        });

                        /* -------------------------------------------------------------- */
                        /* CSS builder — mirrors PHP generate_css_from_json() exactly     */
                        /* -------------------------------------------------------------- */

                        function buildOverrideCss(scopes, expand, ov, selectorSub) {
                            if (!ov || typeof ov !== 'object') return '';
                            const labelSel = expand(selectorSub + ' .gfield_label');
                            const inputSel = expand(selectorSub + ' input:not([type="checkbox"]):not([type="radio"]), ' + selectorSub + ' textarea, ' + selectorSub + ' select');
                            const choiceLabelSel = expand(selectorSub + ' .gchoice label');

                            const labelColor = String(ov.label_color || '').trim();
                            const labelSize = String(ov.label_font_size || '').trim();
                            const inputBg = String(ov.input_bg || '').trim();
                            const inputText = String(ov.input_text || '').trim();
                            const inputBorder = String(ov.input_border || '').trim();
                            const inputRadius = String(ov.input_radius || '').trim();

                            let css = '';
                            if (labelColor || labelSize) {
                                css += `\n${labelSel} {`;
                                if (labelColor) css += ` color: ${labelColor} !important;`;
                                if (labelSize) css += ` font-size: ${labelSize} !important;`;
                                css += ' }\n';
                            }

                            if (inputBg || inputText || inputBorder || inputRadius) {
                                css += `\n${inputSel} {`;
                                if (inputBg) css += ` background: ${inputBg} !important;`;
                                if (inputText) css += ` color: ${inputText} !important;`;
                                if (inputBorder) css += ` border-color: ${inputBorder} !important;`;
                                if (inputRadius) css += ` border-radius: ${inputRadius} !important;`;
                                css += ' }\n';
                            }

                            if (inputText) {
                                css += `\n${choiceLabelSel} { color: ${inputText} !important; }\n`;
                            }
                            return css;
                        }

                        function buildCssFromState(formId, feedId, data){
                            const scopes = [];
                            if (feedId) scopes.push(`.blfs-scope-${feedId}`);
                            scopes.push(`#gform_wrapper_${formId}`);

                            const scopeStr = scopes.join(', ');

                            const expand = (sub, attach = false) => {
                                return scopes.map(s => attach ? `${s}${sub}` : `${s} ${sub}`).join(',\n');
                            };

                            const tokens        = (data && data.tokens)         ? data.tokens         : {};
                            const typeOverrides = (data && data.type_overrides)  ? data.type_overrides  : {};
                            const fieldOverrides= (data && data.field_overrides) ? data.field_overrides : {};

                            function get(path, def){
                                const parts = path.split('.');
                                let cur = tokens;
                                for ( const p of parts ){
                                    if ( !cur || typeof cur !== 'object' || !(p in cur ) ) return def;
                                    cur = cur[p];
                                }
                                return (typeof cur === 'string' || typeof cur === 'number') ? String(cur) : def;
                            }

                            const vars = {
                                '--blfs-base-font-size':  get('typography.base_font_size',''),
                                '--blfs-label-font-size': get('typography.label_font_size',''),
                                '--blfs-input-font-size': get('typography.input_font_size',''),
                                '--blfs-text':            get('colors.text',''),
                                '--blfs-label':           get('colors.label',''),
                                '--blfs-choice-label':    get('colors.choice_label',''),
                                '--blfs-description':     get('colors.description',''),
                                '--blfs-input-bg':        get('colors.input_bg',''),
                                '--blfs-input-border':    get('colors.input_border',''),
                                '--blfs-focus':           get('colors.focus',''),
                                '--blfs-error':           get('colors.error',''),
                                '--blfs-button-bg':       get('colors.button_bg',''),
                                '--blfs-button-text':     get('colors.button_text',''),
                                '--blfs-field-mb':        get('spacing.field_margin_bottom',''),
                                '--blfs-input-padding':   get('spacing.input_padding',''),
                                '--blfs-radius':          get('borders.radius',''),
                                '--blfs-border-width':    get('borders.border_width',''),
                                '--blfs-button-radius':   get('buttons.radius',''),
                                '--blfs-button-padding':  get('buttons.padding',''),
                                '--blfs-focus-ring':      get('states.focus_ring',''),
                                '--blfs-error-border':    get('states.error_border',''),
                            };

                            const varLines = [];
                            for ( const k in vars ){
                                let v = String(vars[k]||'').trim();
                                if ( !v ) continue;
                                v = v.replace(/[;{}\\]/g, ''); // Basic sanitization
                                varLines.push(`  ${k}: ${v};`);
                            }
                            let css = varLines.length ? `${scopeStr} {\n${varLines.join('\n')}\n}\n\n` : '';

                            const inputTypes = ['text','email','number','tel','url','password'];
                            const inputSelList = inputTypes.map(t => expand(`input[type="${t}"]`)).concat(
                                [expand('textarea'), expand('select')]
                            ).join(',\n');

                            const focusSelList = inputTypes.map(t => expand(`input[type="${t}"]:focus`)).concat(
                                [expand('textarea:focus'), expand('select:focus')]
                            ).join(',\n');

                            const buttonSelList = expand('.gform_button') + ',\n' + expand('input[type="submit"].gform_button');

                            const errorSelList = [
                                expand('.gfield_error input'),
                                expand('.gfield_error textarea'),
                                expand('.gfield_error select')
                            ].join(',\n');

                            // Wrapper properties
                            const wrapperProps = [];
                            if (get('typography.base_font_size','')) wrapperProps.push('  font-size: var(--blfs-base-font-size) !important;');
                            if (get('colors.text',''))               wrapperProps.push('  color: var(--blfs-text) !important;');
                            if (wrapperProps.length) css += `${expand('.gform_wrapper', true)},\n${scopeStr} {\n${wrapperProps.join('\n')}\n}\n\n`;

                            // Field margin
                            if (get('spacing.field_margin_bottom','')) css += `${expand('.gfield')} {\n  margin-bottom: var(--blfs-field-mb) !important;\n}\n\n`;

                            // Label
                            const labelProps = [];
                            if (get('colors.label',''))               labelProps.push('  color: var(--blfs-label) !important;');
                            if (get('typography.label_font_size','')) labelProps.push('  font-size: var(--blfs-label-font-size) !important;');
                            if (labelProps.length) css += `${expand('.gfield_label')} {\n${labelProps.join('\n')}\n}\n\n`;

                            // Choice labels
                            if (get('colors.choice_label','')) css += `${expand('.gchoice label')} { color: var(--blfs-choice-label); }\n\n`;

                            // Description
                            if (get('colors.description','')) css += `${expand('.gfield_description')} { color: var(--blfs-description); }\n\n`;

                            // Inputs
                            const inputProps = [];
                            if (get('colors.input_bg',''))           inputProps.push('  background: var(--blfs-input-bg);');
                            if (get('colors.input_border',''))       inputProps.push('  border-color: var(--blfs-input-border);');
                            if (get('borders.border_width',''))      inputProps.push('  border-width: var(--blfs-border-width);');
                            if (get('borders.radius',''))            inputProps.push('  border-radius: var(--blfs-radius);');
                            if (get('spacing.input_padding',''))    inputProps.push('  padding: var(--blfs-input-padding);');
                            if (get('typography.input_font_size','')) inputProps.push('  font-size: var(--blfs-input-font-size);');
                            if (inputProps.length) css += `${inputSelList} {\n${inputProps.join('\n')}\n}\n\n`;

                            // Focus
                            const focusProps = [];
                            if (get('colors.focus',''))           focusProps.push('  border-color: var(--blfs-focus);');
                            if (get('states.focus_ring',''))      focusProps.push('  box-shadow: var(--blfs-focus-ring);');
                            if (focusProps.length) css += `${focusSelList} {\n  outline: none;\n${focusProps.join('\n')}\n}\n\n`;

                            // Button
                            const buttonProps = [];
                            if (get('colors.button_bg','')) {
                                buttonProps.push('  background: var(--blfs-button-bg);');
                                buttonProps.push('  border: 1px solid var(--blfs-button-bg);');
                            }
                            if (get('colors.button_text',''))     buttonProps.push('  color: var(--blfs-button-text);');
                            if (get('buttons.radius',''))          buttonProps.push('  border-radius: var(--blfs-button-radius);');
                            if (get('buttons.padding',''))         buttonProps.push('  padding: var(--blfs-button-padding);');
                            if (buttonProps.length) css += `${buttonSelList} {\n${buttonProps.join('\n')}\n}\n\n`;

                            // Error
                            if (get('states.error_border','')) css += `${errorSelList} {\n  border-color: var(--blfs-error-border);\n}\n\n`;

                            // Type overrides
                            for (const type in typeOverrides) {
                                const safeType = type.replace(/[^a-z0-9_\-]/gi, '');
                                if (!safeType) continue;
                                css += buildOverrideCss(scopes, expand, typeOverrides[type], `.gfield--type-${safeType}`);
                            }

                            // Field overrides
                            for (const fieldIdRaw in fieldOverrides) {
                                const safeId = String(fieldIdRaw).replace(/[^0-9.]/g, '');
                                if (!safeId) continue;
                                css += buildOverrideCss(scopes, expand, fieldOverrides[fieldIdRaw], `#field_${formId}_${safeId}`);
                            }

                            return css.trim();
                        }

                        /* -------------------------------------------------------------- */
                        /* Boot                                                            */
                        /* -------------------------------------------------------------- */

                        renderTypeSelect();
                        renderNav();
                        renderFieldList();
                        renderPanel();
                        syncJson();
                        updateCssPreview();

                        log('UI initialized.', { formId: BLFS_FORM_ID, feedId: BLFS_FEED_ID, fields: BLFS_FIELDS.length, types: BLFS_TYPES.length, hiddenFieldFound: !!hiddenField });
                    })();
				</script>
				<?php
			}

			/**
			 * Save feed settings.
			 *
			 * Decodes the base64 style payload to verify it before saving.
			 *
			 * @param int   $feed_id  The feed ID.
			 * @param int   $form_id  The form ID.
			 * @param array $settings The submitted settings.
			 *
			 * @return int The result of the save operation.
			 */
			public function save_feed_settings( $feed_id, $form_id, $settings ) {

				$payload_b64 = rgar( $settings, 'style_b64' );

				if ( ! empty( $payload_b64 ) && is_string( $payload_b64 ) ) {
					$decoded = base64_decode( $payload_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if ( false === $decoded ) {
						GFCommon::add_error_message( 'Form Styler: Payload decode failed (base64).' );
						return false;
					}

					$decoded_json = json_decode( $decoded, true );
					if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded_json ) ) {
						GFCommon::add_error_message( 'Form Styler: Invalid JSON payload.' );
						return false;
					}
				}
				// If empty, fall through — runtime will use defaults.

				$settings['updated_ts'] = time();

				$is_default = (bool) rgar( $settings, 'is_default' );
				if ( $is_default ) {
					$feeds = $this->get_feeds( $form_id );
					if ( is_array( $feeds ) ) {
						foreach ( $feeds as $f ) {
							$other_id = absint( rgar( $f, 'id' ) );
							if ( absint( $feed_id ) === $other_id ) {
								continue;
							}
							$meta = rgar( $f, 'meta' );
							if ( rgar( $meta, 'is_default' ) ) {
								$meta['is_default'] = 0;
								if ( method_exists( 'GFAPI', 'update_feed_meta' ) ) {
									/* @noinspection PhpUndefinedMethodInspection */
									GFAPI::update_feed_meta( $other_id, $meta );
								} else {
									$this->update_feed_meta( $other_id, $meta );
								}
							}
						}
					}
				}

				return parent::save_feed_settings( $feed_id, $form_id, $settings );
			}

			/**
			 * Get default style payload.
			 *
			 * @return array Default tokens and override structures.
			 */
			protected function default_style_payload() {
				// All token values are empty strings by default.
				// Only tokens the user explicitly fills in will emit CSS rules.
				// This ensures a fresh feed has zero impact on form appearance.
				return [
					'version'         => 1,
					'tokens'          => [
						'typography' => [
							'base_font_size'  => '',
							'label_font_size' => '',
							'input_font_size' => '',
						],
						'colors'     => [
							'text'         => '',
							'label'        => '',
							'choice_label' => '',
							'description'  => '',
							'input_bg'     => '',
							'input_border' => '',
							'focus'        => '',
							'error'        => '',
							'button_bg'    => '',
							'button_text'  => '',
						],
						'spacing'    => [
							'field_margin_bottom' => '',
							'input_padding'       => '',
							'section_padding'     => '',
						],
						'borders'    => [
							'radius'       => '',
							'border_width' => '',
						],
						'buttons'    => [
							'radius'  => '',
							'padding' => '',
						],
						'states'     => [
							'focus_ring'   => '',
							'error_border' => '',
						],
					],
					'type_overrides'  => (object) [],
					'field_overrides' => (object) [],
				];
			}

			/**
			 * Check for feed override in request and apply it to form object.
			 *
			 * @param array $form The form object.
			 *
			 * @return array Modified form object.
			 */
			public function maybe_set_feed_override_from_request( $form ) {
				if ( empty( $form ) || empty( $form['id'] ) ) {
					return $form;
				}
				if ( rgget( 'blfs_feed' ) ) {
					$form['_blfs_feed_override'] = absint( rgget( 'blfs_feed' ) );
				}
				return $form;
			}

			/**
			 * Identify applicable feed for a form.
			 *
			 * Priority: Request override > Default feed > First active feed.
			 *
			 * @param array $form The form object.
			 *
			 * @return array|null The selected feed or null.
			 */
			protected function pick_applicable_feed_for_form( $form ) {
				$form_id = (int) rgar( $form, 'id' );
				if ( ! $form_id ) {
					return null;
				}

				$override = (int) rgar( $form, '_blfs_feed_override' );
				if ( $override ) {
					$feed = GFAPI::get_feed( $override );
					if ( is_array( $feed )
					     && (int) rgar( $feed, 'form_id' ) === $form_id
					     && rgar( $feed, 'addon_slug' ) === $this->_slug
					) {
						return $feed;
					}
				}

				$feeds = $this->get_feeds( $form_id );
				if ( empty( $feeds ) || ! is_array( $feeds ) ) {
					return null;
				}

				foreach ( $feeds as $f ) {
					if ( ! rgar( $f, 'is_active' ) ) {
						continue;
					}
					if ( rgar( rgar( $f, 'meta' ), 'is_default' ) ) {
						return $f;
					}
				}
				foreach ( $feeds as $f ) {
					if ( rgar( $f, 'is_active' ) ) {
						return $f;
					}
				}
				return null;
			}

			/**
			 * Inject scope class into form tag.
			 *
			 * @param string $form_tag The opening <form> tag HTML.
			 * @param array  $form     The form object.
			 *
			 * @return string Modified form tag HTML.
			 */
			public function filter_form_tag_add_scope_class( $form_tag, $form ) {
				$feed = $this->pick_applicable_feed_for_form( $form );
				if ( ! is_array( $feed ) ) {
					return $form_tag;
				}

				$feed_id = (int) rgar( $feed, 'id' );
				if ( ! $feed_id ) {
					return $form_tag;
				}

				$scope_class = 'blfs-scope-' . $feed_id;

				if ( strpos( $form_tag, 'class=' ) !== false ) {
					$form_tag = preg_replace( '/class=(["\'])(.*?)\1/', 'class=$1$2 ' . esc_attr( $scope_class ) . '$1', $form_tag, 1 );
				} else {
					$form_tag = str_replace( '<form ', '<form class="' . esc_attr( $scope_class ) . '" ', $form_tag );
				}

				return $form_tag;
			}

			/**
			 * Injects custom styling and optional debug script into the form HTML.
			 *
			 * @param string $form_string The original form HTML string.
			 * @param array  $form The form object containing metadata and settings.
			 *
			 * @return string Modified form HTML with injected styles and scripts.
			 */
			public function inject_styling_into_form_html( $form_string, $form ) {

				$form_id = (int) rgar( $form, 'id' );
				if ( ! $form_id ) {
					return $form_string;
				}

				$feed = $this->pick_applicable_feed_for_form( $form );
				if ( ! is_array( $feed ) ) {
					return $form_string;
				}

				$feed_id = (int) rgar( $feed, 'id' );
				if ( ! $feed_id ) {
					return $form_string;
				}

				if ( in_array( $feed_id, self::$injected_feeds, true ) ) {
					return $form_string;
				}
				self::$injected_feeds[] = $feed_id;

				$meta = rgar( $feed, 'meta' );

				// Allow GF's own preview page through even without the checkbox,
				// but block other admin contexts unless the checkbox is ticked.
				if ( is_admin() && ! rgar( $meta, 'apply_admin_preview' ) && ! $this->is_gf_preview_context() ) {
					return $form_string;
				}

				$payload_b64 = rgar( $meta, 'style_b64' );
				$json        = '';

				if ( ! empty( $payload_b64 ) && is_string( $payload_b64 ) ) {
					$decoded = base64_decode( $payload_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if ( false !== $decoded ) {
						$json = $decoded;
					}
				}

				if ( empty( $json ) ) {
					$json = wp_json_encode( $this->default_style_payload() );
				}

				$css = $this->generate_css_from_json( $form_id, $feed_id, $json );
				if ( empty( $css ) ) {
					return $form_string;
				}

				$js = '';
				if ( rgar( $meta, 'debug_mode' ) ) {
					GFCommon::log_debug( __METHOD__ . "(): Injecting BLFS CSS. form_id=$form_id, feed_id=$feed_id, is_admin=" . ( is_admin() ? '1' : '0' ) );
					$is_admin_js = is_admin() ? 'true' : 'false';
					$js          = "<script>(function(){console.log('[BLFS] Applied feed $feed_id to form $form_id. is_admin=' + $is_admin_js);})();</script>";
				}

				$style_tag = "\n<style id=\"blfs-style-$feed_id\">\n$css\n</style>\n$js\n";
				return $style_tag . $form_string;
				// ↑ Function ends here. Do NOT add any code after this closing brace.
			}

			/**
			 * Detect GF's built-in form preview page.
			 */
			protected function is_gf_preview_context() {
				if ( rgget( 'gf_page' ) && 'preview' === rgget( 'gf_page' ) ) {
					return true;
				}
				$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				if ( strpos( $uri, 'gf_page=preview' ) !== false ) {
					return true;
				}
				if ( rgget( 'page' ) && strpos( rgget( 'page' ), 'gf_' ) === 0
				     && strpos( rgget( 'page' ), 'preview' ) !== false
				) {
					return true;
				}
				return false;
			}

			/*
			--------------------------------------------------------------------
			CSS generation (PHP — server-side render)

			Scope: ".blfs-scope-{feedId}, #gform_wrapper_{formId}"
			The dual selector gives front-end coverage via the class AND a
			fallback via the GF wrapper ID for edge cases where the class is
			not injected (e.g., cached markup, third-party renderers).
			--------------------------------------------------------------------
			*/

			/**
			 * Helper to generate CSS for a set of overrides.
			 *
			 * @param string $selector The base CSS selector for this set of overrides.
			 * @param array  $ov       The override configuration.
			 *
			 * @return string The generated CSS.
			 */
			private function generate_override_css( $selector, $ov ) {
				if ( ! is_array( $ov ) ) {
					return '';
				}
				$label_sel        = "$selector .gfield_label";
				$input_sel        = "$selector input:not([type=\"checkbox\"]):not([type=\"radio\"]), $selector textarea, $selector select";
				$choice_label_sel = "$selector .gchoice label";

				$label_color  = isset( $ov['label_color'] ) ? trim( (string) $ov['label_color'] ) : '';
				$label_size   = isset( $ov['label_font_size'] ) ? trim( (string) $ov['label_font_size'] ) : '';
				$input_bg     = isset( $ov['input_bg'] ) ? trim( (string) $ov['input_bg'] ) : '';
				$input_text   = isset( $ov['input_text'] ) ? trim( (string) $ov['input_text'] ) : '';
				$input_border = isset( $ov['input_border'] ) ? trim( (string) $ov['input_border'] ) : '';
				$input_radius = isset( $ov['input_radius'] ) ? trim( (string) $ov['input_radius'] ) : '';

				$css = '';
				if ( $label_color || $label_size ) {
					$css .= "\n$label_sel {";
					if ( $label_color ) {
						$css .= " color: $label_color !important;";
					}
					if ( $label_size ) {
						$css .= " font-size: $label_size !important;";
					}
					$css .= " }\n";
				}

				if ( $input_bg || $input_text || $input_border || $input_radius ) {
					$css .= "\n$input_sel {";
					if ( $input_bg ) {
						$css .= " background: $input_bg !important;";
					}
					if ( $input_text ) {
						$css .= " color: $input_text !important;";
					}
					if ( $input_border ) {
						$css .= " border-color: $input_border !important;";
					}
					if ( $input_radius ) {
						$css .= " border-radius: $input_radius !important;";
					}
					$css .= " }\n";
				}

				if ( $input_text ) {
					$css .= "\n$choice_label_sel { color: $input_text !important; }\n";
				}
				return $css;
			}

			/**
			 * Generate CSS from JSON payload.
			 *
			 * @param int    $form_id The form ID.
			 * @param int    $feed_id The feed ID.
			 * @param string $json    The JSON style payload.
			 *
			 * @return string Generated CSS rules.
			 */
			protected function generate_css_from_json( $form_id, $feed_id, $json ) {
				$data = json_decode( $json, true );
				if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
					return '';
				}

				$scopes    = [ ".blfs-scope-$feed_id", "#gform_wrapper_$form_id" ];
				$scope_str = implode( ', ', $scopes );

				$expand = function ( $sub, $attach = false ) use ( $scopes ) {
					$out = [];
					foreach ( $scopes as $s ) {
						$out[] = $attach ? "$s$sub" : "$s $sub";
					}
					return implode( ",\n", $out );
				};

				$tokens          = isset( $data['tokens'] ) && is_array( $data['tokens'] ) ? $data['tokens'] : [];
				$type_overrides  = isset( $data['type_overrides'] ) && is_array( $data['type_overrides'] ) ? $data['type_overrides'] : [];
				$field_overrides = isset( $data['field_overrides'] ) && is_array( $data['field_overrides'] ) ? $data['field_overrides'] : [];

				$get = function ( $path, $default_value = '' ) use ( $tokens ) {
					$parts = explode( '.', $path );
					$cur   = $tokens;
					foreach ( $parts as $p ) {
						if ( ! is_array( $cur ) || ! array_key_exists( $p, $cur ) ) {
							return $default_value;
						}
						$cur = $cur[ $p ];
					}
					return is_scalar( $cur ) ? (string) $cur : $default_value;
				};

				// No fallback defaults — empty string means "not set", and the var_lines loop
				// skips empty values, so only what the user has explicitly entered gets emitted.
				$vars = [
					'--blfs-base-font-size'  => $get( 'typography.base_font_size', '' ),
					'--blfs-label-font-size' => $get( 'typography.label_font_size', '' ),
					'--blfs-input-font-size' => $get( 'typography.input_font_size', '' ),
					'--blfs-text'            => $get( 'colors.text', '' ),
					'--blfs-label'           => $get( 'colors.label', '' ),
					'--blfs-choice-label'    => $get( 'colors.choice_label', '' ),
					'--blfs-description'     => $get( 'colors.description', '' ),
					'--blfs-input-bg'        => $get( 'colors.input_bg', '' ),
					'--blfs-input-border'    => $get( 'colors.input_border', '' ),
					'--blfs-focus'           => $get( 'colors.focus', '' ),
					'--blfs-error'           => $get( 'colors.error', '' ),
					'--blfs-button-bg'       => $get( 'colors.button_bg', '' ),
					'--blfs-button-text'     => $get( 'colors.button_text', '' ),
					'--blfs-field-mb'        => $get( 'spacing.field_margin_bottom', '' ),
					'--blfs-input-padding'   => $get( 'spacing.input_padding', '' ),
					'--blfs-radius'          => $get( 'borders.radius', '' ),
					'--blfs-border-width'    => $get( 'borders.border_width', '' ),
					'--blfs-button-radius'   => $get( 'buttons.radius', '' ),
					'--blfs-button-padding'  => $get( 'buttons.padding', '' ),
					'--blfs-focus-ring'      => $get( 'states.focus_ring', '' ),
					'--blfs-error-border'    => $get( 'states.error_border', '' ),
				];

				$var_lines = [];
				foreach ( $vars as $k => $v ) {
					$v = trim( (string) $v );
					if ( '' === $v ) {
						continue;
					}
					// Basic sanitization to prevent breaking out of CSS declaration.
					$v           = str_replace( [ ';', '{', '}', '\\' ], '', $v );
					$var_lines[] = "  $k: $v;";
				}

				// Only emit a :root-level custom-property block if there is at least one set value.
				if ( ! empty( $var_lines ) ) {
					$css = "$scope_str {\n" . implode( "\n", $var_lines ) . "\n}\n\n";
				} else {
					$css = '';
				}

				// Helper: build a selector string for common input fields.
				$input_types    = [ 'text', 'email', 'number', 'tel', 'url', 'password' ];
				$input_sel_list = [];
				foreach ( $input_types as $t ) {
					$input_sel_list[] = $expand( "input[type=\"$t\"]" );
				}
				$input_sel_list[] = $expand( 'textarea' );
				$input_sel_list[] = $expand( 'select' );
				$input_sel_list   = implode( ",\n", $input_sel_list );

				$focus_sel_list = [];
				foreach ( $input_types as $t ) {
					$focus_sel_list[] = $expand( "input[type=\"$t\"]:focus" );
				}
				$focus_sel_list[] = $expand( 'textarea:focus' );
				$focus_sel_list[] = $expand( 'select:focus' );
				$focus_sel_list   = implode( ",\n", $focus_sel_list );

				$button_sel_list = $expand( '.gform_button' ) . ",\n" . $expand( 'input[type="submit"].gform_button' );

				$error_input_sel_list =
					$expand( '.gfield_error input' ) . ",\n" .
					$expand( '.gfield_error textarea' ) . ",\n" .
					$expand( '.gfield_error select' );

				// ---- Wrapper: font-size + text color ----
				$wrapper_props = [];
				if ( $get( 'typography.base_font_size', '' ) !== '' ) {
					$wrapper_props[] = '  font-size: var(--blfs-base-font-size) !important;';
				}
				if ( $get( 'colors.text', '' ) !== '' ) {
					$wrapper_props[] = '  color: var(--blfs-text) !important;';
				}
				if ( ! empty( $wrapper_props ) ) {
					$css .= $expand( '.gform_wrapper', true ) . ",\n" . $scope_str . " {\n" . implode( "\n", $wrapper_props ) . "\n}\n\n";
				}

				// ---- Field margin ----
				if ( $get( 'spacing.field_margin_bottom', '' ) !== '' ) {
					$css .= $expand( '.gfield' ) . " {\n  margin-bottom: var(--blfs-field-mb) !important;\n}\n\n";
				}

				// ---- Label ----
				$label_props = [];
				if ( $get( 'colors.label', '' ) !== '' ) {
					$label_props[] = '  color: var(--blfs-label) !important;';
				}
				if ( $get( 'typography.label_font_size', '' ) !== '' ) {
					$label_props[] = '  font-size: var(--blfs-label-font-size) !important;';
				}
				if ( ! empty( $label_props ) ) {
					$css .= $expand( '.gfield_label' ) . " {\n" . implode( "\n", $label_props ) . "\n}\n\n";
				}

				// ---- Choice labels (radio/checkbox option text) — separate from field label ----
				if ( $get( 'colors.choice_label', '' ) !== '' ) {
					$css .= $expand( '.gchoice label' ) . " { color: var(--blfs-choice-label); }\n\n";
				}

				// ---- Description ----
				if ( $get( 'colors.description', '' ) !== '' ) {
					$css .= $expand( '.gfield_description' ) . " { color: var(--blfs-description); }\n\n";
				}

				// ---- Inputs (only emit properties that are actually set) ----
				$input_props = [];
				if ( $get( 'colors.input_bg', '' ) !== '' ) {
					$input_props[] = '  background: var(--blfs-input-bg);';
				}
				if ( $get( 'colors.input_border', '' ) !== '' ) {
					$input_props[] = '  border-color: var(--blfs-input-border);';
				}
				if ( $get( 'borders.border_width', '' ) !== '' ) {
					$input_props[] = '  border-width: var(--blfs-border-width);';
				}
				if ( $get( 'borders.radius', '' ) !== '' ) {
					$input_props[] = '  border-radius: var(--blfs-radius);';
				}
				if ( $get( 'spacing.input_padding', '' ) !== '' ) {
					$input_props[] = '  padding: var(--blfs-input-padding);';
				}
				if ( $get( 'typography.input_font_size', '' ) !== '' ) {
					$input_props[] = '  font-size: var(--blfs-input-font-size);';
				}
				if ( ! empty( $input_props ) ) {
					$css .= "$input_sel_list {\n" . implode( "\n", $input_props ) . "\n}\n\n";
				}

				// ---- Focus state ----
				$focus_props = [];
				if ( $get( 'colors.focus', '' ) !== '' ) {
					$focus_props[] = '  border-color: var(--blfs-focus);';
				}
				if ( $get( 'states.focus_ring', '' ) !== '' ) {
					$focus_props[] = '  box-shadow: var(--blfs-focus-ring);';
				}
				if ( ! empty( $focus_props ) ) {
					$css .= "$focus_sel_list {\n  outline: none;\n" . implode( "\n", $focus_props ) . "\n}\n\n";
				}

				// ---- Button ----
				$button_props = [];
				if ( $get( 'colors.button_bg', '' ) !== '' ) {
					$button_props[] = '  background: var(--blfs-button-bg);';
					$button_props[] = '  border: 1px solid var(--blfs-button-bg);';
				}
				if ( $get( 'colors.button_text', '' ) !== '' ) {
					$button_props[] = '  color: var(--blfs-button-text);';
				}
				if ( $get( 'buttons.radius', '' ) !== '' ) {
					$button_props[] = '  border-radius: var(--blfs-button-radius);';
				}
				if ( $get( 'buttons.padding', '' ) !== '' ) {
					$button_props[] = '  padding: var(--blfs-button-padding);';
				}
				if ( ! empty( $button_props ) ) {
					$css .= "$button_sel_list {\n" . implode( "\n", $button_props ) . "\n}\n\n";
				}

				// ---- Error state ----
				if ( $get( 'states.error_border', '' ) !== '' ) {
					$css .= "$error_input_sel_list {\n  border-color: var(--blfs-error-border);\n}\n\n";
				}

				// Type overrides
				foreach ( $type_overrides as $type => $ov ) {
					$type = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $type );
					if ( '' === $type ) {
						continue;
					}
					$css .= $this->generate_override_css( $expand( ".gfield--type-$type" ), $ov );
				}

				// Field overrides (highest specificity — these win over type overrides)
				foreach ( $field_overrides as $field_id => $ov ) {
					$field_id = preg_replace( '/[^0-9.]/', '', (string) $field_id );
					if ( '' === $field_id ) {
						continue;
					}
					$css .= $this->generate_override_css( $expand( "#field_{$form_id}_$field_id" ), $ov );
				}

				return $css;
			}
		}

		GFAddOn::register( 'GF_BrightLeaf_Form_Styler_AddOn' );
	},
	5
);
