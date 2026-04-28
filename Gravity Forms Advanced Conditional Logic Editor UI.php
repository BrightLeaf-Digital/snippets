<?php
/**
 * Gravity Forms Advanced Conditional Logic Editor UI
 *
 * GOAL
 * Adds a powerful "Advanced Conditional Groups" interface to the Gravity Forms editor.
 * Allows for complex, nested "AND" and "OR" logic that isn't possible with standard settings.
 *
 * CONFIGURATION REQUIRED
 * - Must be active alongside the "Gravity Forms Advanced Conditional Logic Runtime Engine" snippet.
 * - Requires Gravity Forms to be installed and active.
 *
 * USAGE
 * 1. Enable this snippet and the "Runtime Engine" snippet.
 * 2. Edit a Gravity Form and click on a field to open its settings.
 * 3. In the sidebar, look for "Conditional Logic" -> "Advanced Conditional Groups".
 * 4. Toggle "Enable Advanced Groups" to ON.
 * 5. Use "Add group" and "+" buttons to build your complex logic.
 * 6. Save the form.
 *
 * FEATURES
 * - NESTED GROUPS: Create multiple groups and choose between AND/OR logic between them.
 * - INTERNAL LOGIC: Choose between AND/OR logic for rules within each group.
 * - NATIVE IMPORT: "Initialize from existing Conditional Logic" button to quickly migrate setups.
 * - SECURE STORAGE: Saves directly to field settings.
 *
 * TIPS
 * - Enabling Advanced Groups overrides the standard Gravity Forms "Conditional Logic" for that field.
 * - You can choose whether the logic should "Show" or "Hide" the field when conditions are met.
 *
 * --- TECHNICAL NOTES ---
 * 1. STORAGE: Config is stored on the field as field.blAdvLogic via SetFieldProperty.
 *
 * 2. CACHING: Per-field cache (BL_ADVLOGIC_CACHE) survives the toggle cycle where disabling
 *    then re-enabling advanced groups caused groups to be wiped: the cache stores the
 *    last known good config (with actual rules) per field ID, and restores it when
 *    re-enabling if the current in-memory config has empty groups.
 */

add_action(
	'gform_editor_js',
	function () {
		?>
<script>
(function ($) {

    const BL_DEBUG = false;
    function log() { if (BL_DEBUG) { try { console.log.apply(console, arguments); } catch (e) {} } }

    function safeParseJSON(s) { try { return JSON.parse(s); } catch (e) { return null; } }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
        });
    }

    // ---- ID counter (collision-free, unlike Math.random) ----
    let BL_TOGGLE_ID_COUNTER = 0;
    function nextToggleId() { return 'bl_advlogic_toggle_' + (++BL_TOGGLE_ID_COUNTER); }

    // ---- Per-field cache: keyed by field ID, stores last known good config ----
    const BL_ADVLOGIC_CACHE = {};

    // ---- Module-level handle for the native-CL reinject timer ----
    let nativeCLReinjectTimer = null;

    // -------------------------------------------------------------------------
    // Cache helpers
    // -------------------------------------------------------------------------

    function hasRealRules(cfg) {
        if (!cfg || !Array.isArray(cfg.groups)) return false;
        return cfg.groups.some(function (g) { return g.rules && g.rules.length > 0; });
    }

    function cacheIfGood(fieldId, cfg) {
        if (hasRealRules(cfg)) {
            const keys = Object.keys(BL_ADVLOGIC_CACHE);
            if (keys.length >= 200) { delete BL_ADVLOGIC_CACHE[keys[0]]; }
            BL_ADVLOGIC_CACHE[fieldId] = JSON.parse(JSON.stringify(cfg));
            log('[BL AdvLogic UI] cached config for field', fieldId, '| groups:', cfg.groups.length);
        }
    }

    function restoreFromCache(fieldId, cfg) {
        const cached = BL_ADVLOGIC_CACHE[fieldId];
        if (cached && hasRealRules(cached) && !hasRealRules(cfg)) {
            log('[BL AdvLogic UI] restoring from cache for field', fieldId);
            const restored        = JSON.parse(JSON.stringify(cached));
            restored.enabled    = cfg.enabled; // preserve current enabled state
            // Use null-check (not ||) so an explicit empty string from cfg is respected.
            if (cfg.actionType      != null) restored.actionType      = cfg.actionType;
            if (cfg.groups_operator != null) restored.groups_operator = cfg.groups_operator;
            return restored;
        }
        return cfg;
    }

    // -------------------------------------------------------------------------
    // DOM shortcuts
    // -------------------------------------------------------------------------

    function getFlyout() {
        return $('aside.conditional_logic_flyout').first();
    }

    function getBlock($flyout) {
        return $flyout.find('#bl-advlogic-flyout-block').first();
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    function normalizeCfg(cfg) {
        if (!cfg || typeof cfg !== 'object') cfg = {};
        if (typeof cfg.enabled !== 'boolean') cfg.enabled = false;
        if (!cfg.actionType)      cfg.actionType      = 'show';
        if (!cfg.groups_operator) cfg.groups_operator = 'AND';
        if (!Array.isArray(cfg.groups)) cfg.groups = [];
        if (!cfg.groups.length) cfg.groups.push({ operator: 'AND', rules: [] });
        cfg.groups.forEach(function (g) {
            if (!g.operator) g.operator = 'AND';
            if (!Array.isArray(g.rules)) g.rules = [];
        });
        return cfg;
    }

    function getSelectedField() {
        return (typeof GetSelectedField === 'function') ? GetSelectedField() : null;
    }

    function readFromField(field) {
        const v = field && field.blAdvLogic ? field.blAdvLogic : null;
        if (!v) return null;
        if (typeof v === 'string') {
            const parsed = safeParseJSON(v);
            return (parsed && typeof parsed === 'object') ? parsed : null;
        }
        if (typeof v === 'object') return v;
        return null;
    }

    function writeToField(cfg) {
        cfg = normalizeCfg(cfg);
        if (typeof SetFieldProperty === 'function') {
            SetFieldProperty('blAdvLogic', cfg);
        } else {
            log('[BL AdvLogic UI] WARNING: SetFieldProperty not available');
        }
        const field = getSelectedField();
        if (field) cacheIfGood(field.id, cfg);
        return cfg;
    }

    function getFormFields() {
        return (window.form && Array.isArray(window.form.fields)) ? window.form.fields : [];
    }

    function getFieldById(id) {
        id = parseInt(id, 10);
        const fields = getFormFields();
        for (let i = 0; i < fields.length; i++) {
            if (parseInt(fields[i].id, 10) === id) return fields[i];
        }
        return null;
    }

    function getAllFieldChoices() {
        const out = [];
        getFormFields().forEach(function (f) {
            if (!f || !f.id) return;
            const label = (f.label && f.label.length) ? f.label : ('Field ' + f.id);
            out.push({ id: f.id, label: label });
        });
        return out;
    }

    function getOperatorChoices() {
        return [
            { v: 'is',          t: 'is' },
            { v: 'isnot',       t: 'is not' },
            { v: '>',           t: 'greater than' },
            { v: '<',           t: 'less than' },
            { v: 'contains',    t: 'contains' },
            { v: 'starts_with', t: 'starts with' },
            { v: 'ends_with',   t: 'ends with' },
        ];
    }

    // Mirrors import_from_native() structure in runtime PHP.
    // Note: this editor action enables imported logic when rules exist, while
    // PHP auto-import keeps enabled=false until user opt-in.
    function importFromNative(field) {
        const native = field && field.conditionalLogic ? field.conditionalLogic : null;

        if (!native || !native.rules || !native.rules.length) {
            return normalizeCfg({
                enabled:         false,
                actionType:      'show',
                groups_operator: 'AND',
                groups:          [{ operator: 'AND', rules: [] }],
            });
        }

        const actionType = native.actionType || 'show';
        const logicType  = native.logicType  || 'all';
        const groupOp    = (logicType === 'any') ? 'OR' : 'AND';

        const rules = native.rules.map(function (r) {
            return {
                fieldId: parseInt(r.fieldId, 10),
                op:      r.operator || 'is',
                value:   r.value !== undefined ? r.value : '',
            };
        });

        return normalizeCfg({
            enabled:         true,
            actionType:      actionType,
            groups_operator: 'AND',
            groups:          [{ operator: groupOp, rules: rules }],
        });
    }

    // -------------------------------------------------------------------------
    // Native-disable / mode note
    // -------------------------------------------------------------------------

    function ensureNativeDisabledStyles() {
        if ($('#bl-native-disabled-styles').length) return;
        $('<style id="bl-native-disabled-styles">' +
            '.bl-native-disabled{opacity:.45;pointer-events:none;}' +
            '.bl-advlogic-mode-note{margin:10px 0 0 0;padding:10px;border:1px solid rgba(0,0,0,.10);border-radius:10px;background:rgba(0,0,0,.02);font-size:12px;color:#334;}' +
            '.bl-advlogic-mode-note strong{font-weight:600;}' +
        '</style>').appendTo('head');
    }

    function setNativeDisabled($flyout, disabled) {
        ensureNativeDisabledStyles();
        const $native = $flyout.find('.conditional_logic_flyout__main fieldset.conditional-flyout__main-fields').first();
        if (!$native.length) return;
        $native.toggleClass('bl-native-disabled', !!disabled);
        $native.find('select, input, button, textarea').prop('disabled', !!disabled);
        log('[BL AdvLogic UI] native UI disabled=', !!disabled);
    }

    function updateModeNote($flyout, enabled) {
        const $block = getBlock($flyout);
        if (!$block.length) return;
        $block.find('.bl-advlogic-mode-note').remove();
        if (enabled) {
            $block.find('.bl-advlogic-body').prepend(
                '<div class="bl-advlogic-mode-note">' +
                    '<strong>Mode:</strong> Advanced Groups enabled. Native Conditional Logic below is left untouched but treated as inactive.' +
                '</div>'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Styles
    // -------------------------------------------------------------------------

    function ensureStyles() {
        if ($('#bl-advlogic-flyout-styles').length) return;
        $('<style id="bl-advlogic-flyout-styles">' +
            '.bl-advlogic-block{margin-top:16px;padding-top:16px;border-top:1px solid rgba(0,0,0,.08);}' +
            '.bl-advlogic-title{font-weight:600;margin-bottom:10px;}' +
            '.bl-advlogic-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0;}' +
            '.bl-advlogic-inline{display:flex;gap:6px;align-items:center;}' +
            '.bl-advlogic-groups{margin-top:10px;}' +
            '.bl-advlogic-group{border:1px solid rgba(0,0,0,.10);border-radius:10px;padding:10px;background:#fff;margin-bottom:10px;}' +
            '.bl-advlogic-rule{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.85fr) minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px;border-top:1px solid rgba(0,0,0,.08);}' +
            '.bl-advlogic-rule select,.bl-advlogic-rule input{min-width:0 !important;width:100%;}' +
            '.bl-advlogic-muted{color:#667;font-size:12px;margin-top:10px;}' +
            '.bl-advlogic-hidden{display:none !important;}' +
            '.bl-advlogic-rule .button{min-width:34px;padding:0 10px;}' +
            '.bl-advlogic-empty{padding:8px 0;}' +
        '</style>').appendTo('head');
    }

    // -------------------------------------------------------------------------
    // Render helpers
    // -------------------------------------------------------------------------

    function renderValueControl(fieldObj, rule) {
        const val     = (rule.value !== undefined && rule.value !== null) ? String(rule.value) : '';
        const choices = fieldObj && Array.isArray(fieldObj.choices) ? fieldObj.choices : null;

        if (choices && choices.length) {
            const opts = ['<option value="" ' + (val === '' ? 'selected' : '') + '>Empty (no choices selected)</option>'];
            choices.forEach(function (c) {
                if (!c) return;
                const v   = (c.value !== undefined && c.value !== null && String(c.value).length) ? String(c.value) : String(c.text || '');
                const t   = (c.text  !== undefined && c.text  !== null) ? String(c.text) : v;
                const sel = (val === v) ? 'selected' : '';
                opts.push('<option value="' + esc(v) + '" ' + sel + '>' + esc(t) + '</option>');
            });
            return '<select data-valtype="choice" class="gfield_rule_select bl-rule-val">' + opts.join('') + '</select>';
        }

        // esc() already encodes " as &quot;, so no further replace needed.
        return '<input type="text" data-valtype="text" class="gfield_rule_select bl-rule-val" value="' + esc(val) + '" placeholder="Enter a value">';
    }

    function renderRuleRow(rule) {
        rule = rule || {};
        const fields = getAllFieldChoices();
        const ops    = getOperatorChoices();

        const r = { ...rule };
        if (!r.fieldId && fields[0]) r.fieldId = fields[0].id;

        const fieldOptions = fields.map(function (f) {
            const sel = (parseInt(r.fieldId, 10) === parseInt(f.id, 10)) ? 'selected' : '';
            return '<option value="' + esc(String(f.id)) + '" ' + sel + '>' + esc(f.label) + '</option>';
        }).join('');

        const opOptions = ops.map(function (o) {
            const sel = (r.op === o.v) ? 'selected' : '';
            return '<option value="' + esc(o.v) + '" ' + sel + '>' + esc(o.t) + '</option>';
        }).join('');

        const fieldObj     = getFieldById(r.fieldId);
        const valueControl = renderValueControl(fieldObj, r);

        return '<div class="bl-advlogic-rule">' +
            '<select class="gfield_rule_select bl-rule-field">' + fieldOptions + '</select>' +
            '<select class="gfield_rule_select bl-rule-op">' + opOptions + '</select>' +
            valueControl +
            '<button type="button" class="button bl-remove-rule" aria-label="Remove rule" title="Remove rule">&minus;</button>' +
            '</div>';
    }

    function renderGroups(cfg, $block) {
        const $groups = $block.find('.bl-advlogic-groups');
        $groups.empty();

        cfg.groups.forEach(function (g, gi) {
            const $g = $(
                '<div class="bl-advlogic-group" data-gi="' + gi + '">' +
                    '<div class="bl-advlogic-row">' +
                        '<strong>Group ' + (gi + 1) + '</strong>' +
                        '<span class="bl-advlogic-inline">Within group:' +
                            '<select class="gfield_rule_select bl-group-op">' +
                                '<option value="AND">AND</option>' +
                                '<option value="OR">OR</option>' +
                            '</select>' +
                        '</span>' +
                        '<button type="button" class="button bl-add-rule" title="Add rule">+</button>' +
                        '<button type="button" class="button bl-remove-group" ' + (cfg.groups.length === 1 ? 'disabled' : '') + '>Remove</button>' +
                    '</div>' +
                    '<div class="bl-rules"></div>' +
                '</div>'
            );

            $g.find('.bl-group-op').val((g.operator || 'AND').toUpperCase());

            const $rules = $g.find('.bl-rules');
            if (!g.rules.length) {
                $rules.append('<div class="bl-advlogic-empty bl-advlogic-muted">No rules yet.</div>');
            } else {
                g.rules.forEach(function (r) { $rules.append(renderRuleRow(r)); });
            }

            $groups.append($g);
        });
    }

    function buildToggleHTML(enabled) {
        const id = nextToggleId();
        return '<div class="conditional_logic_flyout__toggle">' +
            '<span class="conditional_logic_flyout__toggle_label">Enable Advanced Groups</span>' +
            '<div class="conditional_logic_flyout__toggle_input gform-field__toggle">' +
                '<span class="gform-settings-input__container">' +
                    '<input type="checkbox" class="gform-field__toggle-input bl-advlogic-enabled" id="' + id + '" ' + (enabled ? 'checked' : '') + '>' +
                    '<label class="gform-field__toggle-container" for="' + id + '">' +
                        '<span class="gform-field__toggle-switch-text screen-reader-text">' + (enabled ? 'Enabled' : 'Disabled') + '</span>' +
                        '<span class="gform-field__toggle-switch"></span>' +
                    '</label>' +
                '</span>' +
            '</div>' +
            '</div>';
    }

    function renderFlyoutUI(field, cfg, $flyout) {
        cfg = normalizeCfg(cfg);
        ensureStyles();
        ensureNativeDisabledStyles();

        if (field) cacheIfGood(field.id, cfg);

        $flyout.find('#bl-advlogic-flyout-block').remove();

        const enabled = !!cfg.enabled;

        const html =
            '<div id="bl-advlogic-flyout-block" class="bl-advlogic-block">' +
                '<div class="bl-advlogic-title">Advanced Conditional Groups</div>' +
                buildToggleHTML(enabled) +
                '<div class="bl-advlogic-body ' + (enabled ? '' : 'bl-advlogic-hidden') + '">' +
                    '<div class="bl-advlogic-row">' +
                        '<button type="button" class="button bl-init-native">Initialize from existing Conditional Logic</button>' +
                        '<span class="bl-advlogic-inline">Between groups:' +
                            '<select class="gfield_rule_select bl-groups-op">' +
                                '<option value="AND">AND</option>' +
                                '<option value="OR">OR</option>' +
                            '</select>' +
                        '</span>' +
                        '<span class="bl-advlogic-inline">Action:' +
                            '<select class="gfield_rule_select bl-action">' +
                                '<option value="show">Show</option>' +
                                '<option value="hide">Hide</option>' +
                            '</select>' +
                        '</span>' +
                    '</div>' +
                    '<div class="bl-advlogic-groups"></div>' +
                    '<div class="bl-advlogic-row">' +
                        '<button type="button" class="button button-primary bl-add-group">Add group</button>' +
                        '<button type="button" class="button bl-console-log">Console log</button>' +
                    '</div>' +
                    '<div class="bl-advlogic-muted">Stores to <code>field.blAdvLogic</code> only. Native Conditional Logic is unchanged.</div>' +
                '</div>' +
            '</div>';

        const $main = $flyout.find('.conditional_logic_flyout__main').first();
        if (!$main.length) {
            log('[BL AdvLogic UI] ERROR: .conditional_logic_flyout__main not found');
            return;
        }

        const $nativeFieldset = $main.find('fieldset.conditional-flyout__main-fields').first();
        if ($nativeFieldset.length) $nativeFieldset.before(html);
        else $main.prepend(html);

        const $block = getBlock($flyout);
        $block.find('.bl-groups-op').val((cfg.groups_operator || 'AND').toUpperCase());
        $block.find('.bl-action').val((cfg.actionType || 'show').toLowerCase());

        if (enabled) renderGroups(cfg, $block);

        updateModeNote($flyout, enabled);
        setNativeDisabled($flyout, enabled);

        log('[BL AdvLogic UI] injected into flyout for field', field && field.id, 'enabled=', enabled);
    }

    function cfgFromFlyout($flyout) {
        const $block = getBlock($flyout);

        // Start from a blank so we only pull from the DOM — avoids
        // carrying over stale properties from a previous field selection.
        const cfg = {
            enabled:         $block.find('.bl-advlogic-enabled').is(':checked'),
            groups_operator: ($block.find('.bl-groups-op').val() || 'AND').toUpperCase(),
            actionType:      ($block.find('.bl-action').val() || 'show').toLowerCase(),
            groups:          [],
        };

        $block.find('.bl-advlogic-group').each(function () {
            const $g = $(this);
            const g  = { operator: ($g.find('.bl-group-op').val() || 'AND').toUpperCase(), rules: [] };

            $g.find('.bl-advlogic-rule').each(function () {
                const $r = $(this);
                g.rules.push({
                    fieldId: parseInt($r.find('.bl-rule-field').val(), 10),
                    op:      $r.find('.bl-rule-op').val(),
                    value:   $r.find('.bl-rule-val').val(),
                });
            });

            cfg.groups.push(g);
        });

        return normalizeCfg(cfg);
    }

    // -------------------------------------------------------------------------
    // Flyout injection
    // -------------------------------------------------------------------------

    function maybeInject($flyout) {
        if (!$flyout || !$flyout.length) return;

        const field = getSelectedField();
        if (!field) {
            log('[BL AdvLogic UI] flyout present but no selected field');
            return;
        }

        const raw = readFromField(field) || {
            enabled: false, actionType: 'show', groups_operator: 'AND',
            groups:  [{ operator: 'AND', rules: [] }],
        };
        const cfg = restoreFromCache(field.id, raw);
        renderFlyoutUI(field, cfg, $flyout);
    }

    function startObserver() {
        const mo = new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                (m.addedNodes || []).forEach(function (node) {
                    if (!(node instanceof HTMLElement)) return;
                    const $n = $(node);

                    if ($n.is('aside.conditional_logic_flyout')) {
                        log('[BL AdvLogic UI] flyout detected (direct node)');
                        setTimeout(function () { maybeInject($n); }, 0);
                        return;
                    }

                    const $fly = $n.find('aside.conditional_logic_flyout').first();
                    if ($fly.length) {
                        log('[BL AdvLogic UI] flyout detected (descendant)');
                        setTimeout(function () { maybeInject($fly); }, 0);
                    }
                });
            });
        });

        mo.observe(document.body, { childList: true, subtree: true });
    }

    // Reinject when a different field is selected.
    $(document).on('gform_load_field_settings', function () {
        const $flyout = getFlyout();
        if ($flyout) {
            log('[BL AdvLogic UI] gform_load_field_settings -> reinject');
            setTimeout(function () { maybeInject($flyout); }, 0);
        }
    });

    // Reinject after GF's native CL toggle causes flyout contents to re-render.
    // "conditonal" is GF's own attribute name (typo in GF source — keep as-is).
    $(document).on('change', 'aside.conditional_logic_flyout [data-js-conditonal-toggle]', function () {
        log('[BL AdvLogic UI] native CL toggle changed — scheduling reinject...');

        if (nativeCLReinjectTimer) {
            clearTimeout(nativeCLReinjectTimer);
            nativeCLReinjectTimer = null;
        }

        let tries = 0;
        const poll = function () {
            if (++tries > 15) {
                nativeCLReinjectTimer = null;
                return;
            }

            const $flyout = getFlyout();
            if (!$flyout) {
                nativeCLReinjectTimer = setTimeout(poll, 120);
                return;
            }

            const $block = getBlock($flyout);
            if (!$block.length) {
                log('[BL AdvLogic UI] reinject attempt', tries, '(block missing)');
                maybeInject($flyout);
                nativeCLReinjectTimer = setTimeout(poll, 120);
            } else {
                const field = getSelectedField();
                const cfg   = normalizeCfg(field ? (readFromField(field) || {}) : {});
                setNativeDisabled($flyout, !!cfg.enabled);
                updateModeNote($flyout, !!cfg.enabled);
                nativeCLReinjectTimer = null;
            }
        };
        nativeCLReinjectTimer = setTimeout(poll, 120);
    });

    // -------------------------------------------------------------------------
    // Events inside the injected UI
    // -------------------------------------------------------------------------

    $(document).on('change', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-advlogic-enabled', function () {
        const $flyout = getFlyout();
        if (!$flyout.length) return;
        const field = getSelectedField();
        const cfg   = cfgFromFlyout($flyout);

        // If re-enabling and groups are empty, restore from cache.
        if (cfg.enabled && !hasRealRules(cfg) && field) {
            const restored = restoreFromCache(field.id, cfg);
            if (hasRealRules(restored)) {
                restored.enabled = true;
                renderFlyoutUI(field, restored, $flyout);
                writeToField(restored);
                return; // renderFlyoutUI handles mode note + native disabled state
            }
        }

        getBlock($flyout).find('.bl-advlogic-body').toggleClass('bl-advlogic-hidden', !cfg.enabled);
        writeToField(cfg);
        updateModeNote($flyout, cfg.enabled);
        setNativeDisabled($flyout, cfg.enabled);
        log('[BL AdvLogic UI] advanced toggle changed. enabled=', cfg.enabled);
    });

    $(document).on('click', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-init-native', function () {
        const field = getSelectedField();
        if (!field) return;

        const cfg = importFromNative(field);
        // enabled is set by importFromNative: true when rules exist, false when there is nothing to import.

        const $flyout = getFlyout();
        if (!$flyout.length) return;
        renderFlyoutUI(field, cfg, $flyout);
        writeToField(cfg);
        log('[BL AdvLogic UI] initialized from native for field', field.id);
    });

    $(document).on('click', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-add-group', function () {
        const $flyout = getFlyout();
        if (!$flyout.length) return;
        const cfg = cfgFromFlyout($flyout);

        cfg.groups.push({ operator: 'AND', rules: [] });
        writeToField(cfg);
        renderGroups(cfg, getBlock($flyout));
        log('[BL AdvLogic UI] group added. groups=', cfg.groups.length);
    });

    $(document).on('click', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-remove-group', function () {
        const $flyout = getFlyout();
        if (!$flyout.length) return;
        const cfg = cfgFromFlyout($flyout);
        const gi  = parseInt($(this).closest('.bl-advlogic-group').attr('data-gi'), 10);

        if (cfg.groups.length <= 1) return;

        cfg.groups.splice(gi, 1);
        writeToField(cfg);
        renderGroups(cfg, getBlock($flyout));
        log('[BL AdvLogic UI] group removed. groups=', cfg.groups.length);
    });

    $(document).on('click', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-add-rule', function () {
        const $flyout = getFlyout();
        if (!$flyout.length) return;
        const cfg        = cfgFromFlyout($flyout);
        const gi         = parseInt($(this).closest('.bl-advlogic-group').attr('data-gi'), 10);
        const fieldChoices = getAllFieldChoices();
        const firstFieldId = fieldChoices.length ? fieldChoices[0].id : null;

        cfg.groups[gi].rules.push({ fieldId: firstFieldId, op: 'is', value: '' });
        writeToField(cfg);
        renderGroups(cfg, getBlock($flyout));
        log('[BL AdvLogic UI] rule added to group', gi);
    });

    $(document).on('click', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-remove-rule', function () {
        const $flyout = getFlyout();
        if (!$flyout.length) return;
        const cfg   = cfgFromFlyout($flyout);
        const $rule = $(this).closest('.bl-advlogic-rule');
        const gi    = parseInt($rule.closest('.bl-advlogic-group').attr('data-gi'), 10);
        const ri    = $rule.parent().children('.bl-advlogic-rule').index($rule);

        if (gi >= 0 && ri >= 0) {
            cfg.groups[gi].rules.splice(ri, 1);
            writeToField(cfg);
            renderGroups(cfg, getBlock($flyout));
            log('[BL AdvLogic UI] rule removed from group', gi, 'ruleIndex', ri);
        } else {
            log('[BL AdvLogic UI] remove rule failed — gi/ri', gi, ri);
        }
    });

    $(document).on('click', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-console-log', function () {
        const $flyout = getFlyout();
        if (!$flyout.length) return;
        const cfg = cfgFromFlyout($flyout);
        console.log('[BL AdvLogic UI] Current Config:', cfg);
    });

    $(document).on('change', 'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-rule-field', function () {
        const $flyout = getFlyout();
        if (!$flyout.length) return;
        const cfg = cfgFromFlyout($flyout);
        writeToField(cfg);
        renderGroups(cfg, getBlock($flyout));
    });

    // Persist on any other control change (no re-render needed).
    $(document).on(
        'change input',
        'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-groups-op, ' +
        'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-action, '    +
        'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-group-op, '  +
        'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-rule-op, '   +
        'aside.conditional_logic_flyout #bl-advlogic-flyout-block .bl-rule-val',
        function () {
            const $flyout = getFlyout();
            if ($flyout.length) writeToField(cfgFromFlyout($flyout));
        }
    );

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    $(function () {
        startObserver();
        log('[BL AdvLogic UI] loaded');
    });

})(jQuery);
</script>
		<?php
	}
);
