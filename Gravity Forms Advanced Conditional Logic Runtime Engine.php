<?php
/**
 * Gravity Forms Advanced Conditional Logic Runtime Engine
 *
 * @gravityforms
 *
 * GOAL:
 * GOAL Provides the "engine" that makes advanced conditional logic work on your live forms. It
 * handles both real-time showing/hiding of fields in the browser and secure enforcement of those
 * rules when the form is submitted. CONFIGURATION REQUIRED - Must be active alongside the "Gravity
 * Forms Advanced Conditional Logic Editor UI" snippet. - Requires Gravity Forms to be installed
 * and active. USAGE 1. Ensure this snippet is active on your site. 2. There are no settings to
 * configure within this file. 3. Once active, it will automatically look for any "Advanced
 * Conditional Groups" you have configured on your form fields using the Editor UI snippet. HOW IT
 * WORKS - REAL-TIME SHOW/HIDE: Instantly evaluates advanced logic groups to show or hide fields as
 * users fill out the form. Uses a "pre-hide" technique to prevent fields from "flicker" on page
 * load. - SECURE ENFORCEMENT: Runs on the server during submission. If a field is hidden by
 * advanced logic, its value is cleared to prevent unnecessary data storage and bypass validation
 * for hidden fields. - NATIVE COMPATIBILITY: Respects Gravity Forms' built-in conditional logic.
 * NOTES - If advanced logic isn't working, verify BOTH this Engine snippet and the Editor UI
 * snippet are active.
 */
class BL_GF_AdvLogic {

	/**
     * Per-form configs accumulated during this request.
     *
     * @var array<int, array>
     */
	private static array $configs = [];

	/**
     * Form IDs that already have a gform_form_tag hook registered.
     *
     * @var array<int, true>
     */
	private static array $form_tag_hooks = [];

	/**
     * Whether footer output has been printed for this request.
     *
     * @var bool
     */
	private static bool $footer_printed = false;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

    /**
     * Initializes the required hooks for attaching custom behavior to Gravity Forms rendering and validation processes.
     *
     * @return void
     */
    public static function init(): void {
		add_filter( 'gform_pre_render', [ self::class, 'attach' ], 20 );
		add_filter( 'gform_pre_validation', [ self::class, 'attach_for_validation' ], 20 );
		add_action( 'gform_pre_submission', [ self::class, 'enforce_server_side' ], 20 );
		add_filter( 'gform_field_validation', [ self::class, 'skip_validation_if_hidden' ], 20, 4 );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Hooked to gform_pre_render.
	 * Auto-imports native CL, extracts config, and registers pre-hide CSS + config JSON (via
	 * gform_form_tag) and the runtime JS (via wp_footer).
	 *
	 * @param array $form GF form array.
	 * @return array Modified form array.
	 */
	public static function attach( array $form ): array {
		// Auto-import native conditionalLogic → blAdvLogic if not already set.
		// Imported configs have enabled=false so they pre-populate the editor for migration
		// but do not activate the advanced system until the user explicitly enables them.
		// This intentionally sets a property on each field object so that downstream hooks
		// at priority > 20 can inspect blAdvLogic if needed.
		foreach ( $form['fields'] as &$field ) {
			if (
				empty( self::get_field_prop( $field, 'blAdvLogic' ) ) &&
				! empty( self::get_field_prop( $field, 'conditionalLogic' ) )
			) {
				$native = self::get_field_prop( $field, 'conditionalLogic' );
				self::set_field_prop( $field, 'blAdvLogic', self::import_from_native( $native ) );
			}
		}
		unset( $field ); // release foreach-by-reference

		$form_id = intval( $form['id'] );
		$config  = self::extract_config( $form );

		self::$configs[ $form_id ] = $config;

		// Compute CSS and config JSON here so closures capture plain scalars, not a class reference.
		// Config JSON is injected via gform_form_tag so it is present for AJAX-rendered forms too
		// (wp_footer never fires in GF's AJAX response).
		$css  = self::build_prehide_css( $config );
		$json = ! empty( $config['fields'] )
			? wp_json_encode(
				$config,
				JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
				| JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
			: '';

		if ( ( $css || $json ) && ! isset( self::$form_tag_hooks[ $form_id ] ) ) {
			self::$form_tag_hooks[ $form_id ] = true;
			$config_script                    = $json
				? '<script>window.BL_GF_ADVLOGIC=window.BL_GF_ADVLOGIC||{};window.BL_GF_ADVLOGIC[' . absint( $form_id ) . ']=' . $json . ';</script>'
				: '';
			add_filter(
				'gform_form_tag',
				static function ( string $form_tag, array $f ) use ( $form_id, $css, $config_script ): string {
					if ( intval( $f['id'] ) !== $form_id ) {
						return $form_tag;
					}
					return ( $css ? '<style>' . $css . '</style>' : '' ) . $config_script . $form_tag;
				},
				20,
				2
			);
		}

		// WordPress deduplicates named static callbacks at the same priority, so calling
		// add_action here on every attach() invocation is safe — it only registers once.
		add_action( 'wp_footer', [ self::class, 'print_footer' ], 20 );

		return $form;
	}

	/**
	 * Hooked to gform_pre_validation.
	 * Lean variant of attach(): only auto-imports native CL → blAdvLogic so that
	 * skip_validation_if_hidden() has access to the config during field validation.
	 * Does not rebuild CSS/JSON or re-register hooks (already done via gform_pre_render).
	 *
	 * @param array $form GF form array.
	 * @return array Modified form array.
	 */
	public static function attach_for_validation( array $form ): array {
		foreach ( $form['fields'] as &$field ) {
			if (
				empty( self::get_field_prop( $field, 'blAdvLogic' ) ) &&
				! empty( self::get_field_prop( $field, 'conditionalLogic' ) )
			) {
				self::set_field_prop(
					$field,
					'blAdvLogic',
					self::import_from_native( self::get_field_prop( $field, 'conditionalLogic' ) )
				);
			}
		}
		unset( $field );
		return $form;
	}

	/**
	 * Outputs the runtime JS once in wp_footer.
	 * Per-form config JSON is injected inline via gform_form_tag so AJAX-rendered forms also receive it.
	 */
	public static function print_footer(): void {
		if ( self::$footer_printed || empty( self::$configs ) ) {
			return;
		}
		self::$footer_printed = true;

		?>
<script>
/* BL GF AdvLogic — runtime */
(function ($) {
    const BL_DEBUG = false;
    function log() { if (BL_DEBUG) { try { console.log.apply(console, arguments); } catch (e) {} } }

    // Match server-side numeric coercion:
    // - parse numeric prefix when present (e.g. "12abc" -> 12, "1e2x" -> 100)
    // - treat non-numeric values as 0.
    function toComparableNumber(v) {
        const s = String(v == null ? '' : v).trim();
        const m = s.match(/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/);
        if (!m) return 0;
        const n = parseFloat(m[0]);
        return Number.isNaN(n) ? 0 : n;
    }

    function getFormConfig(formId) {
        return (window.BL_GF_ADVLOGIC && window.BL_GF_ADVLOGIC[formId]) || null;
    }

    /** True if GF's own logic considers the field visible. */
    function nativeVisible(fieldWrap) {
        return !fieldWrap || fieldWrap.getAttribute('data-conditional-logic') !== 'hidden';
    }

    /**
     * Read the current value(s) for a GF field.
     * Returns a string for text/radio; an array for checkbox/multi-select.
     */
    function readFieldValue(formEl, fieldId) {
        const exactSel   = '[name="input_' + fieldId + '"]';
        const exactNodes = formEl.querySelectorAll(exactSel);

        if (exactNodes.length === 1) {
            const node = exactNodes[0];
            if (node.type === 'checkbox') return node.checked ? node.value : '';
            if (node.type === 'radio') {
                const radioNode = formEl.querySelector(exactSel + ':checked');
                return radioNode ? radioNode.value : '';
            }
            return node.value != null ? node.value : '';
        }

        if (exactNodes.length > 1) {
            if (exactNodes[0].type === 'radio') {
                const radioGroupNode = formEl.querySelector(exactSel + ':checked');
                return radioGroupNode ? radioGroupNode.value : '';
            }
            // Checkbox group — return array of checked values.
            const cbVals = [];
            exactNodes.forEach(function (n) { if (n.checked) cbVals.push(n.value); });
            return cbVals;
        }

        // GF choice field naming: input_X.Y
        const choiceSel   = '[name^="input_' + fieldId + '."]';
        const choiceNodes = formEl.querySelectorAll(choiceSel);
        if (choiceNodes.length) {
            if (choiceNodes[0].type === 'radio') {
                const choiceRadio = formEl.querySelector(choiceSel + ':checked');
                return choiceRadio ? choiceRadio.value : '';
            }
            if (choiceNodes[0].type === 'checkbox') {
                const choiceCbVals = [];
                choiceNodes.forEach(function (n) { if (n.checked) choiceCbVals.push(n.value); });
                return choiceCbVals;
            }
            return choiceNodes[0].value;
        }

        // Multi-select: input_X[]
        const multiNodes = formEl.querySelectorAll('[name="input_' + fieldId + '[]"]');
        if (multiNodes.length) {
            const multiVals = [];
            multiNodes.forEach(function (n) {
                if ((n.type === 'checkbox' || n.type === 'radio') && n.checked) multiVals.push(n.value);
                if (n.tagName === 'SELECT') Array.from(n.selectedOptions).forEach(function (o) { multiVals.push(o.value); });
            });
            return multiVals;
        }

        return null;
    }

    /** Evaluate a single rule against the field's actual value. */
    function evalRule(rule, actual) {
        const op       = rule.op;
        const expected = rule.value;
        const isArr    = Array.isArray(actual);

        if (actual === null || actual === undefined) actual = isArr ? [] : '';

        switch (op) {
            case 'is':
            case 'equals':
                return isArr ? actual.indexOf(expected) !== -1 : String(actual) === String(expected);
            case 'isnot':
            case 'not_equals':
                return isArr ? actual.indexOf(expected) === -1 : String(actual) !== String(expected);
            case 'contains':
                // Array: exact membership. String: substring search.
                if (isArr) return actual.indexOf(expected) !== -1;
                return String(actual || '').indexOf(String(expected || '')) !== -1;
            case 'starts_with':
                if (isArr) return actual.some(function(v) { return String(v).indexOf(String(expected || '')) === 0; });
                return String(actual || '').indexOf(String(expected || '')) === 0;
            case 'ends_with': {
                const e = String(expected || '');
                if (!e.length) return true;
                if (isArr) return actual.some(function(v) { const s = String(v); return s.slice(-e.length) === e; });
                return String(actual || '').slice(-e.length) === e;
            }
            case '>':
            case 'greater_than':
                if (isArr) return false;
                return toComparableNumber(actual) > toComparableNumber(expected);
            case '<':
            case 'less_than':
                if (isArr) return false;
                return toComparableNumber(actual) < toComparableNumber(expected);
            case 'isnotempty':
            case 'is_not_empty':
                return isArr ? actual.length > 0 : String(actual || '').trim() !== '';
            case 'isempty':
            case 'is_empty':
                return isArr ? actual.length === 0 : String(actual || '').trim() === '';
            default:
                log('[BL AdvLogic] unknown operator:', op, '— rule evaluates false');
                return false;
        }
    }

    /** Evaluate all rules in a group. Empty groups always pass. */
    function evalGroup(group, values) {
        const op    = (group.operator || 'AND').toUpperCase();
        const rules = group.rules || [];
        if (!rules.length) return true;
        return op === 'OR'
            ? rules.some(function (r) { return evalRule(r, values[r.fieldId]); })
            : rules.every(function (r) { return evalRule(r, values[r.fieldId]); });
    }

    /**
     * Evaluate the full advLogic config against a values snapshot.
     * Returns true if the field should be visible.
     */
    function evalAdvConfig(cfg, values) {
        if (!cfg || !cfg.enabled) return true;
        const groupsOp = (cfg.groups_operator || 'AND').toUpperCase();
        const groups   = cfg.groups || [];
        if (!groups.length) return true;

        const groupsResult = groupsOp === 'OR'
            ? groups.some(function (g) { return evalGroup(g, values); })
            : groups.every(function (g) { return evalGroup(g, values); });

        return (cfg.actionType || 'show').toLowerCase() === 'hide' ? !groupsResult : !!groupsResult;
    }

    function applyFieldVisibility(formId, formEl, fieldId, advCfg) {
        const wrap = document.getElementById('field_' + formId + '_' + fieldId);
        if (!wrap) { log('[BL AdvLogic] wrap not found: field_' + formId + '_' + fieldId); return; }

        // If GF's own logic hid this field (not us), skip our evaluation.
        if (wrap.dataset.blAdvHidden !== '1' && !nativeVisible(wrap)) {
            return;
        }

        const values = {};
        (advCfg.groups || []).forEach(function (g) {
            (g.rules || []).forEach(function (r) {
                if (r.fieldId != null && values[r.fieldId] === undefined) {
                    values[r.fieldId] = readFieldValue(formEl, r.fieldId);
                }
            });
        });

        log('[BL AdvLogic] field', fieldId, 'values', JSON.stringify(values));

        const advVis = evalAdvConfig(advCfg, values);
        log('[BL AdvLogic] field', fieldId, '-> visible=', advVis);

        if (!advVis) {
            // Capture the original display before the first hide so we can restore it
            // accurately. Pre-hidden fields (display:none from PHP CSS) fall back to
            // 'block', which is correct for standard .gfield wrappers.
            if (!wrap.dataset.blOrigDisplay) {
                const computed = window.getComputedStyle(wrap).display;
                wrap.dataset.blOrigDisplay = computed !== 'none' ? computed : 'block';
            }
            if (!wrap.dataset.blAdvHidden) {
                wrap.dataset.blNativeHiddenBeforeAdv = nativeVisible(wrap) ? '0' : '1';
            }
            wrap.style.setProperty('display', 'none', 'important');
            // Set GF's own attribute so it excludes this field from submission.
            wrap.setAttribute('data-conditional-logic', 'hidden');
            wrap.dataset.blAdvHidden = '1';
        } else {
            // Do not unhide when native logic is known to require hidden.
            if (wrap.dataset.blNativeHidden === '1' || wrap.dataset.blNativeHiddenBeforeAdv === '1') {
                return;
            }
            const restoreDisplay = wrap.dataset.blOrigDisplay || 'block';
            wrap.style.setProperty('display', restoreDisplay, 'important');
            wrap.removeAttribute('data-conditional-logic');
            delete wrap.dataset.blAdvHidden;
            delete wrap.dataset.blOrigDisplay;
            delete wrap.dataset.blNativeHiddenBeforeAdv;
        }
    }

    function evaluateForm(formId) {
        const cfg = getFormConfig(formId);
        if (!cfg) { log('[BL AdvLogic] no config for formId', formId); return; }

        const formEl = document.getElementById('gform_' + formId);
        if (!formEl) { log('[BL AdvLogic] form not found: gform_' + formId); return; }

        const fields = cfg.fields || {};
        log('[BL AdvLogic] evaluating formId', formId, '| fields:', Object.keys(fields).length);
        Object.keys(fields).forEach(function (fid) {
            applyFieldVisibility(formId, formEl, fid, fields[fid]);
        });
    }

    const BL_ATTACHED = new WeakSet();

    function attachListeners(formId) {
        const formEl = document.getElementById('gform_' + formId);
        if (!formEl) return;

        if (BL_ATTACHED.has(formEl)) return;
        BL_ATTACHED.add(formEl);

        formEl.addEventListener('change', function () { evaluateForm(formId); });
        formEl.addEventListener('input',  function () { evaluateForm(formId); });

        // Re-evaluate when GF's native logic changes visibility on a field we don't own.
        const mo = new MutationObserver(function (muts) {
            const hit = muts.some(function (m) {
                if (m.type !== 'attributes') return false;
                if (m.attributeName !== 'data-conditional-logic') return false;
                // Ignore mutations on elements we control (prevents evaluation loops).
                const wrap = (m.target.closest ? m.target.closest('.gfield') : null) || m.target;
                return !wrap.dataset.blAdvHidden;
            });
            if (hit) evaluateForm(formId);
        });

        mo.observe(formEl, {
            attributes:      true,
            subtree:         true,
            attributeFilter: ['data-conditional-logic'],
        });

        log('[BL AdvLogic] listeners attached for formId', formId);
    }

    $(document).on('gform_post_render', function (e, formId) {
        if (!formId) return;
        log('[BL AdvLogic] gform_post_render formId=', formId);
        evaluateForm(formId);
        attachListeners(formId);
    });

    $(document).on('gform_input_change', function (e, formId) {
        if (!formId) return;
        evaluateForm(formId);
    });

    $(document).on('gform_page_loaded', function (e, formId) {
        if (!formId) return;
        log('[BL AdvLogic] gform_page_loaded formId=', formId);
        evaluateForm(formId);
    });

    // Track native GF visibility intent explicitly so advanced logic does not
    // unhide fields native logic wants hidden.
    if (window.gform && typeof window.gform.addAction === 'function') {
        window.gform.addAction('gform_post_conditional_logic_field_action', function (formId, action, targetId) {
            if (!targetId || typeof targetId !== 'string' || targetId.indexOf('#field_') !== 0) return;
            const wrap = document.querySelector(targetId);
            if (!wrap) return;
            if (action === 'hide') {
                wrap.dataset.blNativeHidden = '1';
            } else if (action === 'show') {
                delete wrap.dataset.blNativeHidden;
            }
        });
    }

    // DOM-ready fallback for server-rendered forms (gform_post_render may have already
    // fired before this script was parsed, so we evaluate + attach here as a safety net).
    $(function () {
        if (!window.BL_GF_ADVLOGIC) return;
        Object.keys(window.BL_GF_ADVLOGIC).forEach(function (fid) {
            const formId = parseInt(fid, 10);
            log('[BL AdvLogic] DOM-ready fallback for formId', formId);
            evaluateForm(formId);
            attachListeners(formId);
        });
    });

})(jQuery);
</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build CSS that pre-hides fields with actionType=show and at least one rule,
	 * so they don't flash visible before JS runs.
	 * Called per-form during gform_form_tag (inline, synchronous, before paint).
	 *
	 * @param array $config Extracted form config.
	 * @return string CSS string, or empty string if nothing to hide.
	 */
	private static function build_prehide_css( array $config ): string {
		$form_id   = intval( $config['formId'] );
		$selectors = [];

		foreach ( $config['fields'] as $field_id => $field_cfg ) {
			if ( 'show' !== ( $field_cfg['actionType'] ?? 'show' ) ) {
				continue;
			}

			$has_rules = false;
			foreach ( $field_cfg['groups'] ?? [] as $group ) {
				if ( ! empty( $group['rules'] ) ) {
					$has_rules = true;
					break;
				}
			}

			if ( $has_rules ) {
				$selectors[] = '#field_' . $form_id . '_' . intval( $field_id );
			}
		}

		return $selectors
			? implode( ',', $selectors ) . '{display:none!important}'
			: '';
	}

	/**
	 * Converts native GF conditionalLogic into blAdvLogic group format.
	 * Mirrors editor import structure, with one intentional difference:
	 * auto-import defaults enabled=false until explicitly enabled by the user.
	 *
	 * @param array $native Native GF conditionalLogic array.
	 * @return array blAdvLogic config array.
	 */
	private static function import_from_native( array $native ): array {
		$action_type = $native['actionType'] ?? 'show';
		$logic_type  = $native['logicType'] ?? 'all';
		$rules       = $native['rules'] ?? [];

		$group_rules = array_map(
			static fn( array $r ): array => [
				'fieldId' => isset( $r['fieldId'] ) ? intval( $r['fieldId'] ) : null,
				'op'      => $r['operator'] ?? 'is',
				'value'   => $r['value'] ?? '',
			],
			$rules
		);

		return [
			'enabled'         => false,
			'actionType'      => $action_type,
			'groups_operator' => 'AND',
			'groups'          => [
				[
					'operator' => ( 'any' === $logic_type ) ? 'OR' : 'AND',
					'rules'    => $group_rules,
				],
			],
		];
	}

	/**
	 * Extracts blAdvLogic configs for all enabled fields in a form.
	 *
	 * @param array $form GF form array.
	 * @return array{ formId: int, fields: array<int, array> }
	 */
	private static function extract_config( array $form ): array {
		$out = [
			'formId' => intval( $form['id'] ),
			'fields' => [],
		];

		foreach ( $form['fields'] as $field ) {
			$id  = intval( self::get_field_prop( $field, 'id' ) );
			$adv = self::get_field_prop( $field, 'blAdvLogic' );

			// blAdvLogic may be stored as a JSON string by the editor.
			if ( is_string( $adv ) && '' !== $adv ) {
				$decoded = json_decode( $adv, true );
				$adv     = is_array( $decoded ) ? $decoded : null;
			}

			if ( ! empty( $adv['enabled'] ) ) {
				$out['fields'][ $id ] = $adv;
			}
		}

		return $out;
	}

	/**
	 * Returns the value of a named property from a GF field (object or legacy array).
	 *
	 * @param object|array $field GF field.
	 * @param string       $key   Property name.
	 * @return mixed|null
	 */
	private static function get_field_prop( $field, string $key ) {
		return is_object( $field )
			? ( property_exists( $field, $key ) ? $field->$key : null )
			: ( $field[ $key ] ?? null );
	}

	/**
	 * Sets a property on a GF field (object or legacy array), by reference.
	 *
	 * @param object|array &$field GF field.
	 * @param string       $key   Property name.
	 * @param mixed        $value New value.
	 */
	private static function set_field_prop( &$field, string $key, $value ): void {
		if ( is_object( $field ) ) {
			$field->$key = $value;
		} else {
			$field[ $key ] = $value;
		}
	}

	// -------------------------------------------------------------------------
	// Server-side rule evaluation — mirrors JS evalRule / evalGroup / evalAdvConfig
	// -------------------------------------------------------------------------

	/**
	 * Reads a submitted field value from $_POST.
	 * Returns string for single-value fields, string[] for checkbox/multi-select, null if absent.
	 *
	 * @param int $field_id GF field ID.
	 * @return string|string[]|null
	 */
	private static function get_submitted_value( int $field_id ) {
		$key = 'input_' . $field_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified upstream by GF.
		if ( isset( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized next line
			$v = wp_unslash( $_POST[ $key ] );
			return is_array( $v ) ? array_map( 'strval', $v ) : (string) $v;
		}
		// Checkbox sub-inputs: input_X_Y where Y is the choice index.
		$prefix = 'input_' . $field_id . '_';
		$vals   = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( $_POST as $k => $v ) {
			if ( '' !== $v && str_starts_with( (string) $k, $prefix ) ) {
				$vals[] = (string) wp_unslash( $v );
			}
		}
		return $vals ?: null;
	}

	/**
	 * Returns a values map [ fieldId => submitted value ] for every field referenced in rules.
	 *
	 * @param array $adv_cfg blAdvLogic config array.
	 * @return array<int, string|string[]|null>
	 */
	private static function collect_rule_values( array $adv_cfg ): array {
		$values = [];
		foreach ( $adv_cfg['groups'] ?? [] as $group ) {
			foreach ( $group['rules'] ?? [] as $rule ) {
				$ref_id = isset( $rule['fieldId'] ) ? intval( $rule['fieldId'] ) : 0;
				if ( $ref_id && ! array_key_exists( $ref_id, $values ) ) {
					$values[ $ref_id ] = self::get_submitted_value( $ref_id );
				}
			}
		}
		return $values;
	}

	/**
	 * Converts a value to float with JS parseFloat-like prefix parsing,
	 * but returns 0 for non-numeric values to mirror frontend behavior.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function to_comparable_float( $value ): float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}

		$str = trim( (string) $value );
		if ( '' === $str ) {
			return 0.0;
		}

		if ( preg_match( '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $str, $m ) ) {
			return (float) $m[0];
		}

		return 0.0;
	}

	/**
	 * Evaluates a single rule against an actual submitted value.
	 *
	 * @param array                $rule   Rule array with 'op', 'value', 'fieldId'.
	 * @param string|string[]|null $actual Submitted field value.
	 * @return bool
	 */
	private static function eval_rule( array $rule, $actual ): bool {
		$op       = (string) ( $rule['op'] ?? 'is' );
		$expected = (string) ( $rule['value'] ?? '' );
		$is_arr   = is_array( $actual );

		if ( null === $actual ) {
			$actual = $is_arr ? [] : '';
		}

		switch ( $op ) {
			case 'is':
			case 'equals':
				return $is_arr ? in_array( $expected, $actual, true ) : (string) $actual === $expected;
			case 'isnot':
			case 'not_equals':
				return $is_arr ? ! in_array( $expected, $actual, true ) : (string) $actual !== $expected;
			case 'contains':
				return $is_arr
				? in_array( $expected, $actual, true )
				: ( '' === $expected || str_contains( (string) $actual, $expected ) );
			case 'starts_with':
				if ( '' === $expected ) {
					return true;
                }
				if ( $is_arr ) {
					foreach ( $actual as $v ) {
						if ( str_starts_with( (string) $v, $expected ) ) {
							return true;
                        }
					}
					return false;
				}
				return str_starts_with( (string) $actual, $expected );
			case 'ends_with':
				if ( '' === $expected ) {
					return true;
                }
				$elen = strlen( $expected );
				if ( $is_arr ) {
					foreach ( $actual as $v ) {
						$s = (string) $v;
						if ( strlen( $s ) >= $elen && substr( $s, -$elen ) === $expected ) {
							return true;
                        }
					}
					return false;
				}
				$a = (string) $actual;
				return strlen( $a ) >= $elen && substr( $a, -$elen ) === $expected;
			case '>':
			case 'greater_than':
				return ! $is_arr && self::to_comparable_float( $actual ) > self::to_comparable_float( $expected );
			case '<':
			case 'less_than':
				return ! $is_arr && self::to_comparable_float( $actual ) < self::to_comparable_float( $expected );
			case 'isnotempty':
			case 'is_not_empty':
				return $is_arr ? ! empty( $actual ) : '' !== trim( (string) $actual );
			case 'isempty':
			case 'is_empty':
				return $is_arr ? empty( $actual ) : '' === trim( (string) $actual );
			default:
				return false;
		}
	}

	/**
	 * Evaluates all rules in a group. Empty groups always pass.
	 *
	 * @param array $group  Group array with 'operator' and 'rules'.
	 * @param array $values Values map [ fieldId => actual ].
	 * @return bool
	 */
	private static function eval_group( array $group, array $values ): bool {
		$op    = strtoupper( $group['operator'] ?? 'AND' );
		$rules = $group['rules'] ?? [];
		if ( empty( $rules ) ) {
			return true;
        }

		foreach ( $rules as $rule ) {
			$ref_id = isset( $rule['fieldId'] ) ? intval( $rule['fieldId'] ) : 0;
			$result = self::eval_rule( $rule, $values[ $ref_id ] ?? null );
			if ( 'OR' === $op && $result ) {
				return true;
            }
			if ( 'AND' === $op && ! $result ) {
				return false;
            }
		}
		return 'AND' === $op;
	}

	/**
	 * Evaluates a full advLogic config. Returns true if the field should be visible.
	 *
	 * @param array $cfg    blAdvLogic config array.
	 * @param array $values Values map from collect_rule_values().
	 * @return bool
	 */
	private static function eval_adv_config( array $cfg, array $values ): bool {
		if ( empty( $cfg['enabled'] ) ) {
			return true;
        }
		$groups_op = strtoupper( $cfg['groups_operator'] ?? 'AND' );
		$groups    = $cfg['groups'] ?? [];
		if ( empty( $groups ) ) {
			return true;
        }

		$groups_result = 'OR' !== $groups_op;
		foreach ( $groups as $group ) {
			$g = self::eval_group( $group, $values );
			if ( 'OR' === $groups_op ) {
				if ( $g ) {
					$groups_result = true;
					break; }
			} elseif ( ! $g ) {
				$groups_result = false;
				break;
			}
		}

		return 'hide' === strtolower( $cfg['actionType'] ?? 'show' ) ? ! $groups_result : $groups_result;
	}

	/**
	 * Clears a field's POST value so GF neither validates nor stores it.
	 *
	 * @param int $field_id GF field ID.
	 */
	private static function clear_post_value( int $field_id ): void {
		$key = 'input_' . $field_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$_POST[ $key ] = is_array( $_POST[ $key ] ) ? [] : '';
		}
		$prefix = 'input_' . $field_id . '_';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( array_keys( $_POST ) as $k ) {
			if ( str_starts_with( $k, $prefix ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$_POST[ $k ] = '';
			}
		}
	}

	// -------------------------------------------------------------------------
	// Server-side enforcement hooks
	// -------------------------------------------------------------------------

	/**
	 * Hooked to gform_pre_submission (action).
	 * Clears POST values for fields hidden by advanced conditional logic so GF
	 * neither validates nor stores those values. Mirrors JS evalAdvConfig.
	 *
	 * @param array $form GF form array passed by GF's action.
	 */
	public static function enforce_server_side( array $form ): void {
		// Auto-import native CL if not yet done — gform_pre_validation (where attach() runs)
		// fires after gform_pre_submission, so we replicate the import here.
		foreach ( $form['fields'] as &$field ) {
			if (
				empty( self::get_field_prop( $field, 'blAdvLogic' ) ) &&
				! empty( self::get_field_prop( $field, 'conditionalLogic' ) )
			) {
				self::set_field_prop(
					$field,
					'blAdvLogic',
					self::import_from_native( self::get_field_prop( $field, 'conditionalLogic' ) )
				);
			}
		}
		unset( $field );

		$config = self::extract_config( $form );
		foreach ( $config['fields'] as $field_id => $adv_cfg ) {
			$values = self::collect_rule_values( $adv_cfg );
			if ( ! self::eval_adv_config( $adv_cfg, $values ) ) {
				self::clear_post_value( intval( $field_id ) );
			}
		}
	}

    /**
     * Hooked to gform_field_validation (filter).
     * Passes validation for fields that advanced conditional logic hides, preventing
     * required-field errors on fields the user cannot see.
     *
     * @param array        $result Validation result: [ 'is_valid' => bool, 'message' => string ].
     * @param mixed        $_value unused.
     * @param array        $_form unused.
     * @param object|array $field GF field.
     * @return array
     */
	public static function skip_validation_if_hidden( array $result, $_value, array $_form, $field ): array {
		$adv = self::get_field_prop( $field, 'blAdvLogic' );
		if ( is_string( $adv ) && '' !== $adv ) {
			$decoded = json_decode( $adv, true );
			$adv     = is_array( $decoded ) ? $decoded : null;
		}
		if ( empty( $adv['enabled'] ) ) {
			return $result;
		}

		$values = self::collect_rule_values( $adv );
		if ( ! self::eval_adv_config( $adv, $values ) ) {
			return [
				'is_valid' => true,
				'message'  => '',
			];
		}
		return $result;
	}
}

BL_GF_AdvLogic::init();
