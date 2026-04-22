<?php
/**
 * Plugin Name: BL GF Editor Bulk Field Edit (Sidebar-native)
 * Description: Bulk-edit core field properties from inside the existing Gravity Forms editor sidebar UI.
 * Version: 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── 1. Register the sidebar panel ──────────────────────────────────────────

add_filter(
        'gform_editor_sidebar_panels',
        function ( $panels ) {
            $panels[] = [
                'id'           => 'bl_bulkedit_panel',
                'title'        => 'Bulk Field Edit',
                'nav_classes'  => [ 'bl-bulkedit-nav' ],
                'body_classes' => [ 'bl-bulkedit-body' ],
            ];
            return $panels;
        }
);

// ─── 2. Output the panel content ────────────────────────────────────────────

add_action(
        'gform_editor_sidebar_panel_content',
        function ( $panel ) {
            if ( ! is_array( $panel ) || ( $panel['id'] ?? '' ) !== 'bl_bulkedit_panel' ) {
                return;
            }
            ?>
            <div id="bl_bulkedit_setting">
                <div class="bl-bulkedit-setting__head">
                    <span class="section_label">Bulk Field Edit</span>
                    <span class="bl-bulkedit-setting__meta"><span class="bl-bulkedit-count">0</span> selected</span>
                </div>

                <p class="bl-bulkedit-empty">Click the select icon on any field to start selecting fields for bulk editing.</p>

                <div class="bl-bulkedit-setting__body" style="display:none;">
                    <div class="bl-row">
                        <label class="section_label">Required</label>
                        <select data-key="required">
                            <option value="">— No change —</option>
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="bl-row">
                        <label class="section_label">Visibility</label>
                        <select data-key="visibility">
                            <option value="">— No change —</option>
                            <option value="visible">Visible</option>
                            <option value="hidden">Hidden</option>
                            <option value="administrative">Administrative</option>
                        </select>
                    </div>
                    <div class="bl-row">
                        <label class="section_label">Label Visibility</label>
                        <select data-key="labelVisibility">
                            <option value="">— No change —</option>
                            <option value="top_label">Visible</option>
                            <option value="hidden_label">Hidden</option>
                        </select>
                    </div>
                    <div class="bl-row">
                        <label class="section_label">No Duplicates</label>
                        <select data-key="noDuplicates">
                            <option value="">— No change —</option>
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="bl-row">
                        <label class="section_label">Size</label>
                        <select data-key="size">
                            <option value="">— No change —</option>
                            <option value="small">Small</option>
                            <option value="medium">Medium</option>
                            <option value="large">Large</option>
                        </select>
                    </div>
                    <div class="bl-row">
                        <label class="section_label">CSS Classes</label>
                        <div style="display:flex; gap:4px; margin-bottom:4px;">
                            <select data-key="cssClassMode" style="flex:1;">
                                <option value="add">Add</option>
                                <option value="remove">Remove</option>
                                <option value="replace">Replace</option>
                            </select>
                        </div>
                        <input type="text" data-key="cssClass" autocomplete="off" placeholder="e.g. custom-class" />
                    </div>
                    <div class="bl-actions">
                        <button type="button" class="button bl-bulkedit-selectall">Select all</button>
                        <button type="button" class="button bl-bulkedit-selectnone">Select none</button>
                        <button type="button" class="button bl-bulkedit-exit">Exit Bulk Mode</button>
                    </div>
                    <div class="bl-status" style="display:none; margin-top:12px; padding:8px; border-radius:4px; font-size:12px; line-height:1.5;"></div>
                </div>
            </div>
            <?php
        }
);

// ─── 3. JS + CSS via gform_editor_js ────────────────────────────────────────

add_action(
        'gform_editor_js',
        function () {
            ?>
            <script>
                (function(){
                    'use strict';

                    const BL      = window.BL_GFBulkEdit = window.BL_GFBulkEdit || {};
                    BL.state      = BL.state || { bulkMode: false, selected: new Set() };
                    // Nonce for the same endpoint GF uses to re-render field previews
                    const BL_NONCE = '<?php echo esc_js( wp_create_nonce( 'rg_refresh_field_preview' ) ); ?>';

                    function qs(sel, root){ return (root || document).querySelector(sel); }
                    function qsa(sel, root){ return Array.from((root || document).querySelectorAll(sel)); }
                    function getEditorForm(){ return (window.form && window.form.fields) ? window.form : null; }

                    function getFieldIdFromElement(el){
                        if (!el) return null;
                        const dfid = el.getAttribute('data-field-id') || (el.dataset ? el.dataset.fieldId : null);
                        if (dfid && String(dfid).match(/^\d+$/)) return parseInt(dfid, 10);
                        const id = el.id || '';
                        let m = id.match(/^field_(\d+)_(\d+)$/);
                        if (m) return parseInt(m[2], 10);
                        m = id.match(/^field_(\d+)$/);
                        if (m) return parseInt(m[1], 10);
                        const anc = el.closest('[id^="field_"], [data-field-id]');
                        if (anc && anc !== el) return getFieldIdFromElement(anc);
                        return null;
                    }

                    // ── Dynamic field support detection ───────────────────────────────────
                    // GF exposes window.fieldSettings: { fieldType: '.required_setting, .size_setting, ...' }
                    // Built server-side from every registered field's get_form_editor_field_settings(),
                    // so custom fields and third-party types are included automatically.
                    // Maps our keys to the CSS class names used in GF's get_form_editor_field_settings() arrays.
                    // Required lives inside rules_setting; no-duplicates has its own duplicate_setting.
                    const SETTING_CLASS = {
                        required:        'rules_setting',
                        visibility:      'visibility_setting',
                        noDuplicates:    'duplicate_setting',
                        size:            'size_setting',
                        labelVisibility: 'label_placement_setting',
                        cssClass:        'css_class_setting',
                    };

                    function fieldSupports(field, prop) {
                        const cls = SETTING_CLASS[prop];
                        if (!cls || !window.fieldSettings) return true;
                        const type = field.type || 'text';
                        let str = window.fieldSettings[type] || '';
                        // Some field types inherit settings from inputType (mirrors GF's getAllFieldSettings)
                        if (field.inputType && type !== 'post_category') {
                            str += ',' + (window.fieldSettings[field.inputType] || '');
                        }
                        if (!str) return true; // unrecognised type — assume supported
                        return str.includes(cls);
                    }

                    // ── Field badges ──────────────────────────────────────────────────────
                    function ensureBulkBadge(fieldEl){
                        if (!fieldEl) return;
                        let badge = fieldEl.querySelector('.bl-bulkedit-badge');
                        if (!badge) {
                            badge = document.createElement('div');
                            badge.className = 'bl-bulkedit-badge';
                            badge.innerHTML = `
                                <button type="button" class="bl-bulkedit-toggle" title="Select for bulk edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <line x1="9" y1="6" x2="20" y2="6"/>
                                        <line x1="9" y1="12" x2="20" y2="12"/>
                                        <line x1="9" y1="18" x2="20" y2="18"/>
                                        <circle cx="3.5" cy="6" r="1.5" fill="currentColor" stroke="none"/>
                                        <circle cx="3.5" cy="12" r="1.5" fill="currentColor" stroke="none"/>
                                        <circle cx="3.5" cy="18" r="1.5" fill="currentColor" stroke="none"/>
                                    </svg>
                                </button>
                                <label class="bl-bulkedit-checkboxwrap" title="Select for bulk edit">
                                    <input type="checkbox" class="bl-bulkedit-checkbox" />
                                </label>
                            `;
                            fieldEl.style.position = fieldEl.style.position || 'relative';
                            fieldEl.appendChild(badge);

                            badge.querySelector('.bl-bulkedit-toggle').addEventListener('click', function(e){
                                e.preventDefault(); e.stopPropagation();
                                enterBulkMode();
                                const id = getFieldIdFromElement(fieldEl);
                                if (id) {
                                    BL.state.selected.add(id);
                                    fieldEl.classList.add('bl-bulkedit-selected');
                                    const cb = badge.querySelector('.bl-bulkedit-checkbox');
                                    if (cb) cb.checked = true;
                                    updatePanelState();
                                }
                            });

                            const cb = badge.querySelector('.bl-bulkedit-checkbox');
                            cb.addEventListener('click', e => e.stopPropagation());
                            cb.addEventListener('change', function(){
                                const id = getFieldIdFromElement(fieldEl);
                                if (!id) return;
                                if (this.checked) {
                                    BL.state.selected.add(id);
                                    fieldEl.classList.add('bl-bulkedit-selected');
                                } else {
                                    BL.state.selected.delete(id);
                                    fieldEl.classList.remove('bl-bulkedit-selected');
                                }
                                updatePanelState();
                            });
                        }

                        const toggle = badge.querySelector('.bl-bulkedit-toggle');
                        const wrap   = badge.querySelector('.bl-bulkedit-checkboxwrap');
                        toggle.style.display = BL.state.bulkMode ? 'none'        : 'inline-flex';
                        wrap.style.display   = BL.state.bulkMode ? 'inline-flex' : 'none';

                        const id = getFieldIdFromElement(fieldEl);
                        const cb = badge.querySelector('.bl-bulkedit-checkbox');
                        if (id != null) cb.checked = BL.state.selected.has(id);
                    }

                    function scanAndInjectBadges(){
                        qsa('.gfield').forEach(el => {
                            if (getFieldIdFromElement(el)) ensureBulkBadge(el);
                        });
                    }

                    // ── Panel Controls ────────────────────────────────────────────────────
                    function bindPanelControls(){
                        const panel = qs('#bl_bulkedit_setting');
                        if (!panel || panel.dataset.blBound) return;
                        panel.dataset.blBound = '1';

                        panel.querySelector('.bl-bulkedit-selectall').addEventListener('click', function(){
                            qsa('.gfield').forEach(el => {
                                const fid = getFieldIdFromElement(el);
                                if (!fid) return;
                                BL.state.selected.add(fid);
                                el.classList.add('bl-bulkedit-selected');
                                const cb = el.querySelector('.bl-bulkedit-checkbox');
                                if (cb) cb.checked = true;
                            });
                            updatePanelState();
                        });

                        panel.querySelector('.bl-bulkedit-selectnone').addEventListener('click', function(){
                            BL.state.selected.clear();
                            qsa('.gfield.bl-bulkedit-selected').forEach(el => el.classList.remove('bl-bulkedit-selected'));
                            qsa('.bl-bulkedit-checkbox').forEach(cb => cb.checked = false);
                            updatePanelState();
                        });

                        panel.querySelector('.bl-bulkedit-exit').addEventListener('click', exitBulkMode);

                        qsa('select[data-key], input[data-key]', panel).forEach(el => {
                            el.addEventListener('change', () => applyBulkChanges());
                        });
                    }

                    function updatePanelState(){
                        const panel = qs('#bl_bulkedit_setting');
                        if (!panel) return;

                        const count = BL.state.selected.size;
                        const countEl = panel.querySelector('.bl-bulkedit-count');
                        if (countEl) countEl.textContent = String(count);

                        const body  = panel.querySelector('.bl-bulkedit-setting__body');
                        const empty = panel.querySelector('.bl-bulkedit-empty');
                        const rows  = panel.querySelectorAll('.bl-row');

                        if (BL.state.bulkMode) {
                            body.style.display = '';
                            empty.style.display = 'none';
                            rows.forEach(r => r.style.display = count > 0 ? '' : 'none');
                        } else {
                            body.style.display = 'none';
                            empty.style.display = '';
                        }
                    }

                    // ── Mode Management ──────────────────────────────────────────────────
                    function enterBulkMode(){
                        if (BL.state.bulkMode) return;
                        BL.state.bulkMode = true;
                        document.body.classList.add('bl-bulkedit-mode');

                        setTimeout(() => {
                            const tab = qs('.bl-bulkedit-nav a');
                            if (tab) tab.click();
                            if (window.gform_sidebar_navigation && typeof window.gform_sidebar_navigation.setPanel === 'function') {
                                window.gform_sidebar_navigation.setPanel('bl_bulkedit_panel');
                            }
                        }, 50);

                        scanAndInjectBadges();
                        bindPanelControls();
                        updatePanelState();
                    }

                    function exitBulkMode(){
                        clearStatus();
                        BL.state.bulkMode = false;
                        BL.state.selected.clear();
                        document.body.classList.remove('bl-bulkedit-mode');
                        qsa('.gfield.bl-bulkedit-selected').forEach(el => el.classList.remove('bl-bulkedit-selected'));
                        qsa('.bl-bulkedit-checkbox').forEach(cb => cb.checked = false);

                        if (window.gform_sidebar_navigation) {
                            window.gform_sidebar_navigation.setPanel('add_fields');
                        }

                        scanAndInjectBadges();
                        updatePanelState();
                    }

                    let _statusTimer = null;
                    function clearStatus(){
                        const panel = qs('#bl_bulkedit_setting');
                        if (!panel) return;
                        const statusEl = panel.querySelector('.bl-status');
                        if (statusEl) statusEl.style.display = 'none';
                        clearTimeout(_statusTimer);
                    }

                    function showStatus(msg, type){
                        const panel = qs('#bl_bulkedit_setting');
                        if (!panel) return;
                        const statusEl = panel.querySelector('.bl-status');
                        if (!statusEl) return;
                        clearTimeout(_statusTimer);
                        statusEl.innerHTML = msg;
                        statusEl.style.display = 'block';
                        const colors = {
                            success: { bg: '#edfaef', color: '#008a20' },
                            warn:    { bg: '#fff8e5', color: '#996800' },
                            info:    { bg: '#f0f6fc', color: '#2271b1' },
                        };
                        const c = colors[type] || colors.info;
                        statusEl.style.backgroundColor = c.bg;
                        statusEl.style.color = c.color;
                        _statusTimer = setTimeout(clearStatus, 6000);
                    }

                    // ── Apply Logic ──────────────────────────────────────────────────────
                    function collectChanges(){
                        const panel = qs('#bl_bulkedit_setting');
                        if (!panel) return {};
                        const changes = {};
                        qsa('select[data-key], input[data-key]', panel).forEach(el => {
                            const key = el.getAttribute('data-key');
                            const val = (el.value || '').trim();
                            if (val !== '' || key === 'cssClass') changes[key] = val;
                        });
                        return changes;
                    }

                    function restoreBulkModeAfterRefresh(){
                        if (!BL.state.bulkMode) return;
                        scanAndInjectBadges();
                        const panel = qs('#bl_bulkedit_setting');
                        if (panel) {
                            delete panel.dataset.blBound;
                            bindPanelControls();
                        }
                        updatePanelState();
                        if (window.gform_sidebar_navigation) {
                            window.gform_sidebar_navigation.setPanel('bl_bulkedit_panel');
                        } else {
                            const tab = qs('.bl-bulkedit-nav a');
                            if (tab) tab.click();
                        }
                    }

                    // GF's RefreshSelectedFieldPreview() re-reads window.selectedField inside its
                    // AJAX callback, which breaks parallel calls. We hit the same endpoint directly
                    // so each request carries the correct field data and replaceWith() always fires.
                    function refreshFieldPreviews(fields, onComplete){
                        if (window.SetDirty) window.SetDirty(true);

                        if (!fields.length) { if (onComplete) onComplete(); return; }

                        if (!window.jQuery || !window.ajaxurl) {
                            try { if (typeof window.InitializeForm === 'function') window.InitializeForm(window.form, false); } catch(e) {}
                            setTimeout(onComplete, 250);
                            return;
                        }

                        let pending = fields.length;
                        function done(){ if (--pending === 0 && onComplete) onComplete(); }

                        fields.forEach(function(field){
                            jQuery.post(
                                window.ajaxurl,
                                {
                                    action:                    'rg_refresh_field_preview',
                                    rg_refresh_field_preview:  BL_NONCE,
                                    field:                     JSON.stringify(field),
                                    formId:                    window.form.id,
                                },
                                function(resp){
                                    if (resp && resp.fieldId && resp.fieldString) {
                                        jQuery('#field_' + resp.fieldId).replaceWith(resp.fieldString);
                                    }
                                    done();
                                },
                                'json'
                            );
                        });
                    }

                    function applyBulkChanges(){
                        clearStatus();
                        const fieldIds = Array.from(BL.state.selected.values());
                        if (!fieldIds.length) return;
                        const changes = collectChanges();
                        if (!Object.keys(changes).length) return;

                        const f = getEditorForm();
                        if (!f) return;

                        let modified = false;
                        const skipped = []; // { label, settings[] }

                        fieldIds.forEach(id => {
                            const field = (f.fields || []).find(fl => String(fl.id) === String(id));
                            if (!field) return;

                            const fieldSkipped = [];

                            if ('required' in changes && changes.required !== '') {
                                if (fieldSupports(field, 'required')) {
                                    field.isRequired = (changes.required === '1');
                                    modified = true;
                                } else {
                                    fieldSkipped.push('Required');
                                }
                            }
                            if ('visibility' in changes && changes.visibility !== '') {
                                if (fieldSupports(field, 'visibility')) {
                                    field.visibility = changes.visibility;
                                    modified = true;
                                } else {
                                    fieldSkipped.push('Visibility');
                                }
                            }
                            if ('noDuplicates' in changes && changes.noDuplicates !== '') {
                                if (fieldSupports(field, 'noDuplicates')) {
                                    field.noDuplicates = (changes.noDuplicates === '1');
                                    modified = true;
                                } else {
                                    fieldSkipped.push('No Duplicates');
                                }
                            }
                            if ('size' in changes && changes.size !== '') {
                                if (fieldSupports(field, 'size')) {
                                    field.size = changes.size;
                                    modified = true;
                                } else {
                                    fieldSkipped.push('Size');
                                }
                            }
                            if ('labelVisibility' in changes && changes.labelVisibility !== '') {
                                if (fieldSupports(field, 'labelVisibility')) {
                                    field.labelPlacement = changes.labelVisibility;
                                    modified = true;
                                } else {
                                    fieldSkipped.push('Label Visibility');
                                }
                            }
                            if (changes.cssClassMode) {
                                if (fieldSupports(field, 'cssClass')) {
                                    const current = (field.cssClass || '');
                                    const target  = (changes.cssClass || '').trim();
                                    const mode    = changes.cssClassMode;
                                    let classes   = current.split(' ').filter(c => c !== '');
                                    if (mode === 'add' && target && !classes.includes(target)) {
                                        classes.push(target);
                                        field.cssClass = classes.join(' ');
                                        modified = true;
                                    } else if (mode === 'remove' && target) {
                                        field.cssClass = classes.filter(c => c !== target).join(' ');
                                        modified = true;
                                    } else if (mode === 'replace' && field.cssClass !== target) {
                                        field.cssClass = target;
                                        modified = true;
                                    }
                                } else {
                                    fieldSkipped.push('CSS Classes');
                                }
                            }

                            if (fieldSkipped.length) {
                                skipped.push({ label: field.label || `Field ${id}`, settings: fieldSkipped });
                            }
                        });

                        if (modified) {
                            const modifiedFields = fieldIds
                                .map(id => (f.fields || []).find(fl => String(fl.id) === String(id)))
                                .filter(Boolean);
                            refreshFieldPreviews(modifiedFields, restoreBulkModeAfterRefresh);
                        }

                        // Build status
                        const parts = [];
                        if (modified) parts.push('Changes applied. Remember to Save.');
                        if (skipped.length) {
                            const lines = skipped.map(s => `<b>${s.label}</b>: ${s.settings.join(', ')} not supported`);
                            parts.push(lines.join('<br>'));
                        }
                        if (parts.length) {
                            const type = skipped.length ? (modified ? 'warn' : 'info') : 'success';
                            showStatus(parts.join('<br>'), type);
                        }
                    }

                    // ── Boot ──────────────────────────────────────────────────────────────
                    function boot(){
                        if (qs('#bl-bulkedit-css')) return;
                        const css = document.createElement('style');
                        css.id = 'bl-bulkedit-css';
                        css.textContent = `
                            .bl-bulkedit-badge { position: absolute; top: 8px; right: 8px; display: flex; gap: 4px; z-index: 999; }
                            .bl-bulkedit-toggle {
                                display: inline-flex; align-items: center; justify-content: center;
                                width: 24px; height: 24px; border: 1px solid #c3c4c7; border-radius: 4px;
                                background: #fff; cursor: pointer; color: #2271b1; padding: 0; box-shadow: 0 1px 2px rgba(0,0,0,.07);
                            }
                            .bl-bulkedit-toggle:hover { background: #f0f6fc; border-color: #2271b1; }
                            .bl-bulkedit-toggle svg { pointer-events: none; display: block; }
                            .bl-bulkedit-checkboxwrap { display: none; align-items: center; justify-content: center; width: 24px; height: 24px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; }
                            .gfield.bl-bulkedit-selected { outline: 2px solid #2271b1 !important; outline-offset: 2px; }
                            #bl_bulkedit_setting .bl-row { margin-bottom: 12px; }
                            #bl_bulkedit_setting select, #bl_bulkedit_setting input { width: 100%; border-radius: 4px; border: 1px solid #8c8f94; padding: 5px; }
                            #bl_bulkedit_setting .bl-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 15px; border-top: 1px solid #dcdcde; padding-top: 15px; }
                        `;
                        document.head.appendChild(css);
                        scanAndInjectBadges();
                        bindPanelControls();
                        updatePanelState();

                        new MutationObserver(() => scanAndInjectBadges()).observe(qs('.gform-fields, #gform_fields') || document.body, { childList: true, subtree: true });
                    }

                    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
                })();
            </script>
            <?php
        }
);
