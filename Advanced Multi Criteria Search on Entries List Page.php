<?php
/**
 * Advanced multi-criteria search on Entries list
 *
 * Plugin Name: BL GF Advanced Filters
 * Description: Advanced multi-criteria search on Gravity Forms Entries list (wp-admin)
 * Version: 2.5
 *
 * @package BL_GF_Advanced_Filters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BL_GF_Advanced_Filters' ) ) {

	/**
	 * Class BL_GF_Advanced_Filters
	 *
	 * Handles the UI and backend logic for advanced multi-criteria entry filtering.
	 */
	class BL_GF_Advanced_Filters {

		/**
		 * The URL parameter used to store the filter payload.
		 */
		const PARAM = 'bl_adv';

		/**
		 * The maximum number of filters allowed.
		 */
		const MAX_FILTERS = 25;

		/**
		 * The maximum length of the payload in the URL.
		 */
		const MAX_PAYLOAD_LEN = 6000;

		/**
		 * Initializer for the plugin.
		 */
		public static function init() {
			$instance = new self();
			add_action( 'admin_enqueue_scripts', [ $instance, 'enqueue_scripts' ], 20 );
			add_action( 'admin_footer', [ $instance, 'maybe_render_ui' ] );
			add_filter( 'gform_get_entries_args_entry_list', [ $instance, 'apply_filters' ] );
		}

		/**
		 * Checks if the current user has permission and is on the correct page.
		 *
		 * @return bool
		 */
		private function current_user_can_access() {
			if ( ! is_admin() || ! class_exists( 'GFAPI' ) ) {
				return false;
			}

			if ( ! current_user_can( 'gform_full_access' ) ) {
				return false;
			}

			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'gf_entries' !== $page ) {
				return false;
			}

			return true;
		}

		/**
		 * Enqueues scripts and styles, and prepares the payload for the JS UI.
		 */
		public function enqueue_scripts() {
			if ( ! $this->current_user_can_access() ) {
				return;
			}

			$form_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 0 === $form_id ) {
				$all_forms = GFAPI::get_forms();
				if ( ! is_array( $all_forms ) || empty( $all_forms ) ) {
					return;
				}
				usort(
					$all_forms,
					function ( $a, $b ) {
						return strcmp(
							strtolower( $a['title'] ?? '' ),
							strtolower( $b['title'] ?? '' )
						);
					}
				);
				$form_id = isset( $all_forms[0]['id'] ) ? absint( $all_forms[0]['id'] ) : 0;
				if ( 0 === $form_id ) {
					return;
				}
			}

			$form = GFAPI::get_form( $form_id );
			if ( ! is_array( $form ) || empty( $form['fields'] ) ) {
				return;
			}

			$ui_fields = [];

			foreach ( $form['fields'] as $field ) {
				if ( ! is_object( $field ) || empty( $field->id ) ) {
					continue;
				}

				$choices = [];
				if ( ! empty( $field->choices ) && is_array( $field->choices ) ) {
					foreach ( $field->choices as $c ) {
						if ( is_array( $c ) && isset( $c['text'] ) ) {
							$choices[] = [
								'text'  => (string) $c['text'],
								'value' => isset( $c['value'] ) ? (string) $c['value'] : (string) $c['text'],
							];
						}
					}
				}

				$ui_fields[] = [
					'id'      => (string) $field->id,
					'label'   => isset( $field->label ) ? (string) $field->label : ( 'Field ' . $field->id ),
					'type'    => isset( $field->type ) ? (string) $field->type : '',
					'choices' => $choices,
				];
			}

			wp_enqueue_script( 'jquery' );

			$css = $this->get_inline_css();
			wp_register_style( 'bl-gf-adv-inline', false, [], '2.5' );
			wp_enqueue_style( 'bl-gf-adv-inline' );
			wp_add_inline_style( 'bl-gf-adv-inline', $css );

			$existing = '';
			if ( isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$existing = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			$payload = [
				'formId'   => $form_id,
				'fields'   => $ui_fields,
				'param'    => self::PARAM,
				'existing' => $existing,
				'maxRows'  => self::MAX_FILTERS,
			];

			$GLOBALS['bl_gf_adv_payload'] = $payload;
		}

		/**
		 * Renders the inline JS UI if the payload is present.
		 */
		public function maybe_render_ui() {
			if ( empty( $GLOBALS['bl_gf_adv_payload'] ) || ! $this->current_user_can_access() ) {
				return;
			}

			$payload = $GLOBALS['bl_gf_adv_payload'];
			$json    = wp_json_encode( $payload );
			if ( ! $json ) {
				$json = '{}';
			}

			printf(
				'<script id="bl-gf-adv-js">%s</script>',
				$this->get_inline_js( $json ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		/**
		 * Returns the inline CSS for the UI.
		 *
		 * @return string
		 */
		private function get_inline_css() {
			return <<<'CSS'
			#bl-gf-adv-wrap { margin: 14px 0 10px; padding: 12px; border: 1px solid #dcdcde; background: #fff; border-radius: 6px; }
			#bl-gf-adv-wrap h2 { margin: 0 0 8px; font-size: 14px; line-height: 1.2; }
			#bl-gf-adv-controls { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
			#bl-gf-adv-controls label { display: flex; gap: 6px; align-items: center; }
			#bl-gf-adv-rows { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
			.bl-gf-adv-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
			.bl-gf-adv-row select, .bl-gf-adv-row input[type=text] { min-height: 30px; }
			.bl-gf-adv-row .bl-w-field { min-width: 240px; }
			.bl-gf-adv-row .bl-w-op { min-width: 140px; }
			.bl-gf-adv-row .bl-w-val { min-width: 260px; }
			.bl-gf-adv-row .bl-w-date { min-width: 150px; }
			.bl-gf-adv-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
			.bl-gf-adv-note { opacity: .8; font-size: 12px; }
			.bl-gf-adv-danger { color: #b32d2e; }
CSS;
		}

		/**
		 * Returns the inline JS for the UI.
		 *
		 * @param string $json_config The JSON configuration for the UI.
		 * @return string
		 */
		private function get_inline_js( $json_config ) {
			$js = <<<'JS'
(function($){
	"use strict";
	const CFG = {{CONFIG}};

	function log(){ try { console.log.apply(console, arguments); } catch(e) {} }

	function b64urlEncode(str){
		const b64 = btoa(unescape(encodeURIComponent(str)));
		return b64.replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
	}
	function b64urlDecode(b64u){
		const b64 = (b64u || '').replace(/-/g,'+').replace(/_/g,'/');
		const pad = b64.length % 4 ? '='.repeat(4 - (b64.length % 4)) : '';
		const s = atob(b64 + pad);
		return decodeURIComponent(escape(s));
	}

	function getUrl(){ return new URL(window.location.href); }

	function setParam(key, val){
		const url = getUrl();
		if(!val){ url.searchParams.delete(key); }
		else{ url.searchParams.set(key, val); }
		window.location.href = url.toString();
	}

	function safeParseExisting(){
		if(!CFG.existing) return null;
		try{
			const decoded = b64urlDecode(CFG.existing);
			return JSON.parse(decoded);
		}catch(e){
			log('[BL GF Adv] Failed to parse existing payload', e);
			return null;
		}
	}

	function escapeHtml(s){
		return String(s).replace(/[&<>"']/g, function(m){
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
		});
	}

	const FIELD_MAP = {};
	(CFG.fields || []).forEach(function(f){ FIELD_MAP[String(f.id)] = f; });

	const ENTRY_KEYS = [
		{ key: '__date_created', label: 'Entry: Date Created (range)' },
		{ key: 'created_by',     label: 'Entry: Created By (user ID)' },
		{ key: 'is_read',        label: 'Entry: Is Read (1 or 0)' },
		{ key: 'currency',       label: 'Entry: Currency (e.g. USD)' }
	];

	const OPS = [
		{ v: 'is',           t: 'is (=)' },
		{ v: 'isnot',        t: 'is not (!=)' },
		{ v: 'contains',     t: 'contains' },
		{ v: 'in',           t: 'in (multi-select)' },
		{ v: 'not in',       t: 'not in (multi-select)' },
		{ v: 'is_empty',     t: 'is empty' },
		{ v: 'is_not_empty', t: 'is not empty' }
	];

	const NO_VALUE_OPS = { 'is_empty': true, 'is_not_empty': true };

	function buildFieldOptions(){
		const entry_opts = ENTRY_KEYS.map(function(o){
			return `<option value="${escapeHtml(o.key)}">${escapeHtml(o.label)}</option>`;
		}).join('');
		const field_opts = (CFG.fields || []).map(function(f){
			const label = `${f.label ? f.label : (`Field ${f.id}`)} (ID ${f.id})`;
			return `<option value="${escapeHtml(String(f.id))}">${escapeHtml(label)}</option>`;
		}).join('');
		return `<optgroup label="Entry Properties">${entry_opts}</optgroup><optgroup label="Form Fields">${field_opts}</optgroup>`;
	}

	function buildOpOptions(){
		return OPS.map(function(o){
			return `<option value="${escapeHtml(o.v)}">${escapeHtml(o.t)}</option>`;
		}).join('');
	}

	let row_counter = 0;

	function rowTemplate(){
		row_counter++;
		const field_options = buildFieldOptions();
		const op_options = buildOpOptions();
		return `
			<div class="bl-gf-adv-row" data-row="r${row_counter}">
				<select class="bl-w-field bl-gf-adv-field">${field_options}</select>
				<select class="bl-w-op bl-gf-adv-op">${op_options}</select>
				<input class="bl-w-val bl-gf-adv-val-text" type="text" placeholder="Value" />
				<select class="bl-w-val bl-gf-adv-val-select" style="display:none"></select>
				<select class="bl-w-val bl-gf-adv-val-multi" multiple style="display:none;min-height:60px"></select>
				<span style="display:none" class="bl-gf-adv-date-wrap">
					From: <input class="bl-w-date bl-gf-adv-date-start" type="text" placeholder="YYYY-MM-DD" />
				</span>
				<span style="display:none" class="bl-gf-adv-date-wrap2">
					To: <input class="bl-w-date bl-gf-adv-date-end" type="text" placeholder="YYYY-MM-DD" />
				</span>
				<button type="button" class="button bl-gf-adv-remove">Remove</button>
			</div>
		`;
	}

	function setChoiceOptions(el, choices){
		const opts = '<option value=""></option>' + (choices || []).map(function(c){
			const val = (c.value !== undefined && c.value !== null) ? String(c.value) : String(c.text || '');
			const txt = (c.text !== undefined && c.text !== null) ? String(c.text) : val;
			return `<option value="${escapeHtml(val)}">${escapeHtml(txt)}</option>`;
		}).join('');
		jQuery(el).html(opts);
	}

	function updateRowUI(row){
		const jrow   = jQuery(row);
		const key    = jrow.find('.bl-gf-adv-field').val();
		const op     = jrow.find('.bl-gf-adv-op').val();
		const jop    = jrow.find('.bl-gf-adv-op');
		const jtext  = jrow.find('.bl-gf-adv-val-text');
		const jsel   = jrow.find('.bl-gf-adv-val-select');
		const jmulti = jrow.find('.bl-gf-adv-val-multi');
		const jdw1   = jrow.find('.bl-gf-adv-date-wrap');
		const jdw2   = jrow.find('.bl-gf-adv-date-wrap2');

		jtext.hide(); jsel.hide(); jmulti.hide(); jdw1.hide(); jdw2.hide();

		if(key === '__date_created'){
			jop.hide();
			jdw1.show();
			jdw2.show();
			return;
		}

		jop.show();

		if(NO_VALUE_OPS[op]){
			return;
		}

		const f = FIELD_MAP[String(key)];
		const choices = (f && Array.isArray(f.choices) && f.choices.length) ? f.choices : null;

		if(choices){
			setChoiceOptions(jsel[0], choices);
			setChoiceOptions(jmulti[0], choices);
			if(op === 'in' || op === 'not in'){
				jmulti.show();
			}else{
				jsel.show();
			}
		}else{
			jtext.attr('placeholder', (op === 'in' || op === 'not in') ? 'Comma-separated values' : 'Value');
			jtext.show();
		}
	}

	function restoreRowValues(row, initial){
		if(!initial) return;
		const jrow  = jQuery(row);
		const key   = jrow.find('.bl-gf-adv-field').val();
		const op    = jrow.find('.bl-gf-adv-op').val();

		if(key === '__date_created'){
			if(initial.value)  jrow.find('.bl-gf-adv-date-start').val(String(initial.value));
			if(initial.value2) jrow.find('.bl-gf-adv-date-end').val(String(initial.value2));
			return;
		}

		if(NO_VALUE_OPS[op]){ return; }

		const jtext  = jrow.find('.bl-gf-adv-val-text');
		const jsel   = jrow.find('.bl-gf-adv-val-select');
		const jmulti = jrow.find('.bl-gf-adv-val-multi');

		if(jmulti.is(':visible')){
			const vals = Array.isArray(initial.value)
				? initial.value.map(String)
				: (initial.value !== undefined ? [String(initial.value)] : []);
			jmulti.val(vals);
		}else if(jsel.is(':visible')){
			const v = (initial.value !== undefined && initial.value !== null) ? String(initial.value) : '';
			jsel.val(v);
		}else{
			const tv = Array.isArray(initial.value)
				? initial.value.join(', ')
				: (initial.value !== undefined && initial.value !== null ? String(initial.value) : '');
			jtext.val(tv);
		}
	}

	function addRow(initial){
		if(jQuery('#bl-gf-adv-rows .bl-gf-adv-row').length >= (CFG.maxRows || 25)){
			showErr('Max filters reached ('+(CFG.maxRows||25)+').');
			return;
		}

		const jrow = jQuery(rowTemplate());
		jQuery('#bl-gf-adv-rows').append(jrow);

		if(initial && initial.key){
			jrow.find('.bl-gf-adv-field').val(String(initial.key));
		}
		if(initial && initial.operator){
			jrow.find('.bl-gf-adv-op').val(String(initial.operator));
		}

		updateRowUI(jrow[0]);

		if(initial){
			restoreRowValues(jrow[0], initial);
		}

		jrow.on('change', '.bl-gf-adv-field, .bl-gf-adv-op', function(){
			updateRowUI(jrow[0]);
		});
		jrow.on('click', '.bl-gf-adv-remove', function(){ jrow.remove(); });
	}

	function collect(){
		const mode = jQuery('input[name="bl-gf-adv-mode"]:checked').val() || 'all';
		const filters = [];

		jQuery('#bl-gf-adv-rows .bl-gf-adv-row').each(function(){
			const jrow = jQuery(this);
			const key  = jrow.find('.bl-gf-adv-field').val();
			if(!key) return;

			if(key === '__date_created'){
				const start = jrow.find('.bl-gf-adv-date-start').val() || '';
				const end   = jrow.find('.bl-gf-adv-date-end').val() || '';
				if(!start && !end) return;
				filters.push({ key:key, operator:'', value:start, value2:end });
				return;
			}

			const op = jrow.find('.bl-gf-adv-op').val() || 'contains';

			if(NO_VALUE_OPS[op]){
				filters.push({ key:key, operator:op, value:'', value2:'' });
				return;
			}

			const jmulti = jrow.find('.bl-gf-adv-val-multi');
			const jsel   = jrow.find('.bl-gf-adv-val-select');
			const jtext  = jrow.find('.bl-gf-adv-val-text');
			let value;

			if(jmulti.is(':visible')){
				value = (jmulti.val() || []).map(String).filter(Boolean);
				if(!value.length) return;
			}else if(jsel.is(':visible')){
				value = String(jsel.val() || '').trim();
				if(!value) return;
			}else{
				const raw = String(jtext.val() || '').trim();
				if(!raw) return;
				if(op === 'in' || op === 'not in'){
					value = raw.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
					if(!value.length) return;
				}else{
					value = raw;
				}
			}

			filters.push({ key:key, operator:op, value:value, value2:'' });
		});

		return { mode: mode, filters: filters };
	}

	function showErr(msg){ jQuery('#bl-gf-adv-err').text(msg).show(); }
	function clearErr(){ jQuery('#bl-gf-adv-err').hide().text(''); }

	function injectUI(){
		let jtarget = jQuery('#gform-form-toolbar');
		if(!jtarget.length){
			jtarget = jQuery('.gform-form-toolbar').first();
		}
		if(!jtarget.length){
			log('[BL GF Adv] Could not find injection target.');
			return;
		}

		const html = `
			<div id="bl-gf-adv-wrap">
				<h2>Advanced Filters</h2>
				<div id="bl-gf-adv-controls">
					<label><input type="radio" name="bl-gf-adv-mode" value="all" checked> Match ALL (AND)</label>
					<label><input type="radio" name="bl-gf-adv-mode" value="any"> Match ANY (OR)</label>
					<span class="bl-gf-adv-note">Stored in URL param <code>${escapeHtml(CFG.param)}</code>.</span>
				</div>
				<div id="bl-gf-adv-rows"></div>
				<div class="bl-gf-adv-actions">
					<button type="button" class="button" id="bl-gf-adv-add">Add filter</button>
					<button type="button" class="button button-primary" id="bl-gf-adv-apply">Apply</button>
					<button type="button" class="button" id="bl-gf-adv-clear">Clear</button>
					<span class="bl-gf-adv-note bl-gf-adv-danger" id="bl-gf-adv-err" style="display:none"></span>
				</div>
			</div>
		`;

		jtarget.after(html);

		const existing = safeParseExisting();
		if(existing && Array.isArray(existing.filters) && existing.filters.length){
			if(existing.mode === 'any'){
				jQuery('input[name="bl-gf-adv-mode"][value="any"]').prop('checked', true);
			}
			existing.filters.slice(0, CFG.maxRows || 25).forEach(function(f){ addRow(f); });
		}else{
			addRow();
		}

		log('[BL GF Adv] Injected for form', CFG.formId, '| existing:', existing);
	}

	jQuery(document).on('click', '#bl-gf-adv-add',   function(){ clearErr(); addRow(); });
	jQuery(document).on('click', '#bl-gf-adv-clear',  function(){ clearErr(); setParam(CFG.param, ''); });

	jQuery(document).on('click', '#bl-gf-adv-apply', function(){
		clearErr();
		const obj = collect();
		if(!obj.filters.length){ setParam(CFG.param, ''); return; }
		try{
			const json = JSON.stringify(obj);
			if(json.length > 3000){
				showErr('Filters payload too large. Reduce rows/values.');
				return;
			}
			const encoded = b64urlEncode(json);
			log('[BL GF Adv] Applying:', obj, '| encoded len:', encoded.length);
			setParam(CFG.param, encoded);
		}catch(e){
			showErr('Failed to encode filters.');
			log('[BL GF Adv] encode error', e);
		}
	});

	if(document.readyState === 'loading'){
		jQuery(function(){ injectUI(); });
	}else{
		injectUI();
	}

})(jQuery);
JS;
			return str_replace( '{{CONFIG}}', $json_config, $js );
		}

		/**
		 * Applies the filters from the URL parameter to the entry list query.
		 *
		 * @param array $args    The entry list query arguments.
		 *
		 * @return array
		 */
		public function apply_filters( $args ) {
			if ( ! is_admin() || ! current_user_can( 'gform_full_access' ) ) {
				return $args;
			}

			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'gf_entries' !== $page ) {
				return $args;
			}

			if ( empty( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return $args;
			}

			$raw = (string) wp_unslash( $_GET[ self::PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( strlen( $raw ) > self::MAX_PAYLOAD_LEN ) {
				return $args;
			}

			$decoded = $this->b64url_json_decode( $raw );
			if ( ! is_array( $decoded ) ) {
				return $args;
			}

			$mode    = ( isset( $decoded['mode'] ) && 'any' === $decoded['mode'] ) ? 'any' : 'all';
			$filters = ( isset( $decoded['filters'] ) && is_array( $decoded['filters'] ) ) ? $decoded['filters'] : [];
			if ( empty( $filters ) ) {
				return $args;
			}

			$filters = array_slice( $filters, 0, self::MAX_FILTERS );

			$allowed_entry_keys = [
				'created_by' => true,
				'is_read'    => true,
				'currency'   => true,
			];

			$allowed_ops = [
				'is'           => 'scalar',
				'isnot'        => 'scalar',
				'contains'     => 'scalar',
				'in'           => 'array',
				'not in'       => 'array',
				'is_empty'     => 'none',
				'is_not_empty' => 'none',
			];

			if ( ! isset( $args['search_criteria'] ) || ! is_array( $args['search_criteria'] ) ) {
				$args['search_criteria'] = [];
			}
			if ( ! isset( $args['search_criteria']['field_filters'] ) || ! is_array( $args['search_criteria']['field_filters'] ) ) {
				$args['search_criteria']['field_filters'] = [];
			}

			$args['search_criteria']['field_filters']['mode'] = $mode;

			unset( $args['search_criteria']['start_date'], $args['search_criteria']['end_date'] );

			foreach ( $filters as $row ) {

				if ( ! is_array( $row ) ) {
					continue;
				}

				$key = isset( $row['key'] ) ? (string) $row['key'] : '';
				if ( ! $key ) {
					continue;
				}

				if ( '__date_created' === $key ) {
					$start = isset( $row['value'] ) ? (string) $row['value'] : '';
					$end   = isset( $row['value2'] ) ? (string) $row['value2'] : '';
					if ( $start && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
						$args['search_criteria']['start_date'] = $start;
					}
					if ( $end && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
						$args['search_criteria']['end_date'] = $end;
					}
					continue;
				}

				$op = isset( $row['operator'] ) ? (string) $row['operator'] : 'contains';
				if ( ! isset( $allowed_ops[ $op ] ) ) {
					continue;
				}

				$is_entry_key  = isset( $allowed_entry_keys[ $key ] );
				$is_form_field = ! $is_entry_key && is_numeric( $key );
				if ( ! $is_form_field && ! $is_entry_key ) {
					continue;
				}

				$val_type = $allowed_ops[ $op ];

				if ( 'none' === $val_type ) {
					$gf_op                                      = ( 'is_empty' === $op ) ? 'is' : 'isnot';
					$args['search_criteria']['field_filters'][] = [
						'key'      => $key,
						'operator' => $gf_op,
						'value'    => '',
					];
					continue;
				}

				$value = null;

				if ( 'array' === $val_type ) {
					$raw_val = $row['value'] ?? [];
					if ( is_string( $raw_val ) ) {
						$raw_val = array_filter( array_map( 'trim', explode( ',', $raw_val ) ) );
					}
					if ( ! is_array( $raw_val ) ) {
						continue;
					}
					$value = array_values( array_filter( array_map( [ $this, 'sanitize_scalar' ], $raw_val ), 'strlen' ) );
					if ( empty( $value ) ) {
						continue;
					}
				} else {
					$value = $this->sanitize_scalar( $row['value'] ?? '' );
					if ( '' === $value && 'is' !== $op ) {
						continue;
					}
				}

				$args['search_criteria']['field_filters'][] = [
					'key'      => $key,
					'operator' => $op,
					'value'    => $value,
				];
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[BL GF Adv] search_criteria: ' . wp_json_encode( $args['search_criteria'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return $args;
		}

		/**
		 * Decodes a base64url encoded JSON string.
		 *
		 * @param string $b64url The encoded string.
		 * @return array|null
		 */
		private function b64url_json_decode( $b64url ) {
			if ( ! is_string( $b64url ) || '' === $b64url ) {
				return null;
			}
			$b64     = strtr( $b64url, '-_', '+/' );
			$pad_len = strlen( $b64 ) % 4;
			if ( $pad_len ) {
				$b64 .= str_repeat( '=', 4 - $pad_len );
			}
			$raw = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw ) {
				return null;
			}
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * Sanitizes a scalar value for entry filtering.
		 *
		 * @param mixed $v The value to sanitize.
		 * @return string
		 */
		private function sanitize_scalar( $v ) {
			if ( is_bool( $v ) ) {
				return $v ? '1' : '0';
			}
			if ( is_numeric( $v ) ) {
				return (string) $v;
			}
			$s = is_string( $v ) ? $v : '';
			$s = wp_strip_all_tags( $s );
			$s = trim( $s );
			if ( strlen( $s ) > 500 ) {
				$s = substr( $s, 0, 500 );
			}
			return $s;
		}
	}

	BL_GF_Advanced_Filters::init();
}
