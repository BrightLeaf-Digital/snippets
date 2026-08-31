<?php
/**
 * Field Dependency Heatmap
 *
 * @gravityforms
 *
 * GOAL:
 * - Provide a visual representation of field dependencies in the Gravity Forms editor.
 * - Make it easy to see where a field is used in conditional logic, calculations, and
 *   notifications.
 * - Give form editors a way to quickly audit references without opening every field or setting.
 *
 * REQUIREMENTS:
 * - User must have 'gform_full_access' capability.
 *
 * FEATURES:
 * - Color-coded badges for different reference types (conditional logic, calculations,
 *   notifications).
 * - Interactive modal showing exactly which rules, formulas, or notifications refer to a field.
 * - Automatic updates after AJAX saves (no page reload needed) using Gravity Forms action hooks.
 * - Integration with Gravity Wiz's inline field ID pills, with a clean fallback for standard
 *   labels.
 * - Real-time badge rendering as fields are added or modified in the editor via MutationObserver.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_enqueue_scripts',
	function () {

		if ( ! is_admin() ) {
			return; }

		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// GF Form Editor only.
		if ( 'gf_edit_forms' !== $page || ! $form_id ) {
			return;
		}

		if ( ! current_user_can( 'gform_full_access' ) ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		// Pass formId safely.
		$form_id_js = wp_json_encode( $form_id );

		// Inject notification data server-side -- window.form.notifications is
		// stripped by GF from the JS object for security, so we pass it separately.
		$form_data        = GFAPI::get_form( $form_id );
		$notifications    = $form_data['notifications'] ?? [];
		$notifications_js = wp_json_encode( array_values( $notifications ) );
		?>
		<script id="gf-dependency-heatmap-js">
			<?php
			ob_start();
			?>
            (function($){
                'use strict';

                function injectCSS() {
                    if (document.getElementById('bl-gfdep-styles')) return;
                    const style = document.createElement('style');
                    style.id = 'bl-gfdep-styles';
                    style.textContent = [
                        '.bl-gfdep-badges{',
                        '  display:inline-flex !important;',
                        '  flex-direction:row !important;',
                        '  align-items:center !important;',
                        '  gap:4px !important;',
                        '  vertical-align:middle !important;',
                        '  margin-left:4px !important;',
                        '  position:static !important;',
                        '  height:auto !important;',
                        '  width:auto !important;',
                        '  overflow:visible !important;',
                        '  z-index:99999 !important;',
                        '  pointer-events:auto;',
                        '}',
                        '.bl-gfdep-badges--fallback{',
                        '  position:absolute !important;',
                        '  top:8px !important;',
                        '  right:48px !important;',
                        '  margin-left:0 !important;',
                        '}',
                        '.bl-gfdep-badge{',
                        '  width:14px !important;',
                        '  height:14px !important;',
                        '  border-radius:999px !important;',
                        '  display:inline-block !important;',
                        '  border:1px solid rgba(0,0,0,.18);',
                        '  box-shadow:0 1px 2px rgba(0,0,0,.10);',
                        '  cursor:pointer;',
                        '  opacity:.85;',
                        '  flex-shrink:0;',
                        '}',
                        '.bl-gfdep-badge:hover{ opacity:1 !important; }',
                        '.bl-gfdep-badge[data-kind="cond_source"]{ background:rgba(60,120,216,.85) !important; }',
                        '.bl-gfdep-badge[data-kind="cond_target"]{ background:rgba(136,84,208,.85) !important; }',
                        '.bl-gfdep-badge[data-kind="calc_ref"]   { background:rgba(46,160,67,.85) !important; }',
                        '.bl-gfdep-badge[data-kind="notif_ref"]  { background:rgba(255,140,0,.85) !important; }',
                        '#bl-gfdep-modal{ display:none; }',
                        '#bl-gfdep-modal .bl-gfdep-meta{ margin:0 0 10px; font-size:13px; opacity:.85; }',
                        '#bl-gfdep-modal ul{ margin:8px 0 0 18px; }',
                        '#bl-gfdep-modal li{ margin:4px 0; }',
                        '.bl-gfdep-ref-type{ font-weight:600; }',
                        '.bl-gfdep-small{ font-size:12px; opacity:.85; }'
                    ].join('\n');
                    (document.head || document.documentElement).appendChild(style);
                    log('injectCSS: style tag appended');
                }

                const CFG = {
                    formId: <?php echo $form_id_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>,
                    // Notifications injected server-side -- not available in window.form
                    notifications: <?php echo $notifications_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>,
                    debug: true,
                    badgeKinds: [
                        { key: 'cond_source', label: n => `Used in ${n} conditional rules` },
                        { key: 'calc_ref',    label: n => `Referenced in ${n} calculations` },
                        { key: 'notif_ref',   label: n => `Used in ${n} notifications` },
                        { key: 'cond_target', label: n => `Target of ${n} logic conditions` }
                    ]
                };

                const state = {
                    lastForm: null,
                    index: null,
                    refs: null,
                    fieldLabels: null,
                    observer: null
                };

                function log() {
                    if (!CFG.debug || !window.console) return;
                    const args = Array.prototype.slice.call(arguments);
                    args.unshift('[BL GFDEP]');
                    console.log.apply(console, args);
                }

                function ensureModal() {
                    if (document.getElementById('bl-gfdep-modal')) return;
                    const modal = document.createElement('div');
                    modal.id = 'bl-gfdep-modal';
                    modal.innerHTML = '<div class="bl-gfdep-meta"></div><div class="bl-gfdep-body"></div>';
                    document.body.appendChild(modal);
                }

                function escapeHtml(s) {
                    s = String(s);
                    return s.replace(/[&<>"']/g, function (m) {
                        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
                        map["'"] = '&#039;';
                        return map[m];
                    });
                }

                function openModal(fieldId, kind) {
                    ensureModal();
                    const label = (state.fieldLabels && state.fieldLabels[fieldId]) ? state.fieldLabels[fieldId] : (`Field ${fieldId}`);
                    const count = (state.index && state.index[fieldId] && state.index[fieldId][kind]) ? (state.index[fieldId][kind].count || 0) : 0;
                    const refs = (state.refs && state.refs[fieldId] && state.refs[fieldId][kind]) ? state.refs[fieldId][kind] : [];

                    let kindDef = null;
                    for (let i = 0; i < CFG.badgeKinds.length; i++) {
                        if (CFG.badgeKinds[i].key === kind) { kindDef = CFG.badgeKinds[i]; break; }
                    }
                    const titleLine = kindDef ? kindDef.label(count) : kind;

                    const metaEl = document.querySelector('#bl-gfdep-modal .bl-gfdep-meta');
                    const bodyEl = document.querySelector('#bl-gfdep-modal .bl-gfdep-body');
                    metaEl.textContent = `${label} (ID: ${fieldId}) - ${titleLine}`;

                    if (!refs || !refs.length) {
                        bodyEl.innerHTML = '<div class="bl-gfdep-small">No references found.</div>';
                    } else {
                        let items = '';
                        for (let r = 0; r < refs.length; r++) {
                            const ref = refs[r] || {};
                            const type = ref.type ? `<span class="bl-gfdep-ref-type">${escapeHtml(ref.type)}</span>` : '';
                            const where = ref.where ? ` - ${escapeHtml(ref.where)}` : '';
                            const detail = ref.detail ? `<div class="bl-gfdep-small">${escapeHtml(ref.detail)}</div>` : '';
                            items += `<li>${type}${where}${detail}</li>`;
                        }
                        bodyEl.innerHTML = `<ul>${items}</ul>`;
                    }

                    $('#bl-gfdep-modal').dialog({
                        title: 'Field Dependency References',
                        modal: true,
                        width: Math.min(window.innerWidth * 0.9, 720),
                        maxHeight: window.innerHeight * 0.8,
                        position: { my: "center", at: "center", of: window }
                    });
                }

                function collectRulesFromConditionalLogic(cl) {
                    if (!cl || !cl.rules || !Array.isArray(cl.rules)) return [];
                    const out = [];
                    for (let i = 0; i < cl.rules.length; i++) {
                        const r = cl.rules[i] || {};
                        const fid = (r.fieldId !== undefined && r.fieldId !== null) ? String(r.fieldId) : '';
                        if (!fid) continue;
                        out.push({
                            fieldId: fid,
                            operator: (r.operator !== undefined && r.operator !== null) ? String(r.operator) : '',
                            value: (r.value !== undefined && r.value !== null) ? String(r.value) : ''
                        });
                    }
                    return out;
                }

                function extractFieldIdsFromText(text) {
                    if (!text || typeof text !== 'string') return [];
                    const ids = {};
                    const re = /\{[^{}]*:([0-9]+)(?:\.[0-9]+)?[^{}]*\}/g;
                    let m;
                    while ((m = re.exec(text)) !== null) {
                        if (m[1]) ids[String(parseInt(m[1], 10))] = true;
                    }
                    return Object.keys(ids);
                }

                function initEmptySlot(index, fieldId) {
                    if (index[fieldId]) return;
                    index[fieldId] = {
                        cond_source: { count: 0 },
                        cond_target: { count: 0 },
                        calc_ref: { count: 0 },
                        notif_ref: { count: 0 }
                    };
                }

                function addRef(fieldId, kind, ref) {
                    if (!state.refs[fieldId]) state.refs[fieldId] = {};
                    if (!state.refs[fieldId][kind]) state.refs[fieldId][kind] = [];
                    state.refs[fieldId][kind].push(ref);
                }

                function buildFieldLabelMap(form) {
                    const map = {};
                    const fields = (form && form.fields) ? form.fields : [];
                    for (let i = 0; i < fields.length; i++) {
                        const f = fields[i] || {};
                        const id = (f.id !== undefined && f.id !== null) ? String(f.id) : '';
                        if (!id) continue;
                        const label = (f.label || f.adminLabel || '').trim();
                        map[id] = label ? label : (`Field ${id}`);
                    }
                    return map;
                }

                function buildIndexFromForm(form) {
                    const index = {};
                    state.refs = {};
                    state.fieldLabels = buildFieldLabelMap(form);
                    const fields = (form && form.fields) ? form.fields : [];

                    // --- cond_target: field has conditional logic controlling its OWN visibility.
                    // The rules inside name SOURCE fields.
                    for (let i = 0; i < fields.length; i++) {
                        const f = fields[i] || {};
                        const fid = (f.id !== undefined && f.id !== null) ? String(f.id) : '';
                        if (!fid) continue;
                        initEmptySlot(index, fid);

                        const cl = f.conditionalLogic;
                        if (cl && cl.rules && Array.isArray(cl.rules) && cl.rules.length) {
                            index[fid].cond_target.count = cl.rules.length;
                            for (let k = 0; k < cl.rules.length; k++) {
                                const r = cl.rules[k] || {};
                                const rid = (r.fieldId !== undefined && r.fieldId !== null) ? String(r.fieldId) : '';
                                addRef(fid, 'cond_target', {
                                    type: 'field.conditionalLogic',
                                    where: state.fieldLabels[fid] || (`Field ${fid}`),
                                    detail: `if field ${rid} (${state.fieldLabels[rid] || '?'}) ${r.operator || ''} "${r.value || ''}"`
                                });
                            }
                        }
                    }

                    // --- cond_source: fields referenced by OTHER fields' conditional logic rules
                    for (let j = 0; j < fields.length; j++) {
                        const tf = fields[j] || {};
                        const targetId = (tf.id !== undefined && tf.id !== null) ? String(tf.id) : '';
                        const rules = collectRulesFromConditionalLogic(tf.conditionalLogic);
                        if (!rules.length) continue;
                        for (let k = 0; k < rules.length; k++) {
                            const r = rules[k];
                            const sourceId = r.fieldId;
                            initEmptySlot(index, sourceId);
                            index[sourceId].cond_source.count += 1;
                            addRef(sourceId, 'cond_source', {
                                type: 'field.conditionalLogic',
                                where: (state.fieldLabels[targetId] || (`Field ${targetId}`)) + ` (ID: ${targetId})`,
                                detail: `if field ${sourceId} ${r.operator} "${r.value}"`
                            });
                        }
                    }

                    // --- confirmations conditional logic (source fields)
                    const confirmations = (form && form.confirmations) ? form.confirmations : {};
                    for (const cid in confirmations) {
                        if (!confirmations.hasOwnProperty(cid)) continue;
                        const conf = confirmations[cid] || {};
                        const crules = collectRulesFromConditionalLogic(conf.conditionalLogic);
                        if (!crules.length) continue;
                        const confName = conf.name || conf.type || (`Confirmation ${cid}`);
                        for (let c = 0; c < crules.length; c++) {
                            const rr = crules[c];
                            const sid = rr.fieldId;
                            initEmptySlot(index, sid);
                            index[sid].cond_source.count += 1;
                            addRef(sid, 'cond_source', {
                                type: 'confirmation.conditionalLogic',
                                where: confName,
                                detail: `if field ${sid} ${rr.operator} "${rr.value}"`
                            });
                        }
                    }

                    // --- calculations
                    for (let x = 0; x < fields.length; x++) {
                        const cf = fields[x] || {};
                        if (!cf.calculationFormula || typeof cf.calculationFormula !== 'string') continue;
                        const formulaFieldId = (cf.id !== undefined && cf.id !== null) ? String(cf.id) : '';
                        const formula = cf.calculationFormula;
                        const hits = extractFieldIdsFromText(formula);
                        for (let h = 0; h < hits.length; h++) {
                            const refId = hits[h];
                            initEmptySlot(index, refId);
                            index[refId].calc_ref.count += 1;
                            addRef(refId, 'calc_ref', {
                                type: 'field.calculationFormula',
                                where: (state.fieldLabels[formulaFieldId] || (`Field ${formulaFieldId}`)) + ` (ID: ${formulaFieldId})`,
                                detail: `Formula: ${formula}`
                            });
                        }
                    }

                    // --- notifications
                    const notifList = (CFG.notifications && CFG.notifications.length) ? CFG.notifications : [];
                    for (let nIdx = 0; nIdx < notifList.length; nIdx++) {
                        const n = notifList[nIdx] || {};
                        const nName = n.name || (`Notification ${nIdx + 1}`);

                        if (n.toType === 'field' && n.to !== undefined && n.to !== null && String(n.to).match(/^\d+$/)) {
                            const toFid = String(n.to);
                            initEmptySlot(index, toFid);
                            index[toFid].notif_ref.count += 1;
                            addRef(toFid, 'notif_ref', {
                                type: 'notification.to (field)',
                                where: nName,
                                detail: `Email sent to value of field ID ${toFid}`
                            });
                        }

                        if (n.routing && Array.isArray(n.routing)) {
                            for (let ri2 = 0; ri2 < n.routing.length; ri2++) {
                                const rule = n.routing[ri2] || {};
                                const rfid = (rule.fieldId !== undefined && rule.fieldId !== null) ? String(rule.fieldId) : '';
                                if (rfid) {
                                    initEmptySlot(index, rfid);
                                    index[rfid].notif_ref.count += 1;
                                    addRef(rfid, 'notif_ref', {
                                        type: 'notification.routing',
                                        where: `${nName} (rule #${ri2 + 1})`,
                                        detail: `if field ${rfid} ${rule.operator || ''} "${rule.value || ''}" -> ${rule.email || ''}`
                                    });
                                }
                                if (rule.email && typeof rule.email === 'string') {
                                    const emailHits = extractFieldIdsFromText(rule.email);
                                    for (let ei = 0; ei < emailHits.length; ei++) {
                                        const eid = emailHits[ei];
                                        initEmptySlot(index, eid);
                                        index[eid].notif_ref.count += 1;
                                        addRef(eid, 'notif_ref', {
                                            type: 'notification.routing.email',
                                            where: `${nName} (rule #${ri2 + 1} email)`,
                                            detail: `email: ${rule.email}`
                                        });
                                    }
                                }
                            }
                        }

                        const ncl = collectRulesFromConditionalLogic(n.conditionalLogic);
                        for (let ni = 0; ni < ncl.length; ni++) {
                            const nrr = ncl[ni];
                            const nsid = nrr.fieldId;
                            initEmptySlot(index, nsid);
                            index[nsid].cond_source.count += 1;
                            addRef(nsid, 'cond_source', {
                                type: 'notification.conditionalLogic',
                                where: nName,
                                detail: `if field ${nsid} ${nrr.operator} "${nrr.value}"`
                            });
                        }

                        const props = ['to', 'bcc', 'subject', 'message', 'from', 'fromName', 'replyTo'];
                        for (let p = 0; p < props.length; p++) {
                            const key = props[p];
                            const val = n[key];
                            if (!val || typeof val !== 'string') continue;
                            const mhits = extractFieldIdsFromText(val);
                            for (let mi = 0; mi < mhits.length; mi++) {
                                const mid = mhits[mi];
                                initEmptySlot(index, mid);
                                index[mid].notif_ref.count += 1;
                                addRef(mid, 'notif_ref', {
                                    type: 'notification.mergeTags',
                                    where: `${nName} (${key})`,
                                    detail: `${key}: ${val}`
                                });
                            }
                        }
                    }

                    return index;
                }

                function findFieldElement(formId, fieldId) {
                    const sels = [
                        `#field_${formId}_${fieldId}`,
                        `#field_${fieldId}`,
                        `[data-field-id="${fieldId}"]`,
                        `.gfield[data-id="${fieldId}"]`
                    ];
                    for (let i = 0; i < sels.length; i++) {
                        const el = document.querySelector(sels[i]);
                        if (el) return el;
                    }
                    return null;
                }

                // Find the Gravity Wiz inline field ID span (.gw-inline-field-id).
                // This snippet injects "ID: X" after the label/legend close tag,
                // so we append our badge host immediately after that span to sit
                // inline in the same label row.
                function findIdPill(fieldEl) {
                    return fieldEl.querySelector('.gw-inline-field-id');
                }

                function applyHostStyles(host) {
                    const isFallback = host.classList.contains('bl-gfdep-badges--fallback');
                    const base = [
                        'display:inline-flex',
                        'flex-direction:row',
                        'align-items:center',
                        'gap:4px',
                        'vertical-align:middle',
                        'height:auto',
                        'width:auto',
                        'overflow:visible',
                        'visibility:visible',
                        'opacity:1',
                        'z-index:99999',
                        'pointer-events:auto'
                    ];
                    if (isFallback) {
                        base.push('position:absolute');
                        base.push('top:8px');
                        base.push('right:48px');
                        base.push('margin-left:0');
                    } else {
                        base.push('position:static');
                        base.push('margin-left:4px');
                    }
                    host.style.cssText = base.join(' !important;') + ' !important;';
                }

                const BADGE_COLORS = {
                    cond_source: 'rgba(60,120,216,.9)',
                    cond_target: 'rgba(136,84,208,.9)',
                    calc_ref: 'rgba(46,160,67,.9)',
                    notif_ref: 'rgba(255,140,0,.9)'
                };

                function applyBadgeStyles(badge, kind) {
                    const bg = BADGE_COLORS[kind] || 'rgba(128,128,128,.9)';
                    badge.style.cssText = [
                        'display:inline-block',
                        'width:14px',
                        'height:14px',
                        'border-radius:999px',
                        'background:' + bg,
                        'border:1px solid rgba(0,0,0,.25)',
                        'box-shadow:0 1px 3px rgba(0,0,0,.2)',
                        'cursor:pointer',
                        'opacity:.9',
                        'flex-shrink:0',
                        'position:relative',
                        'visibility:visible',
                        'overflow:visible'
                    ].join(' !important;') + ' !important;';
                }

                function ensureBadgeHost(el) {
                    if (!el) return null;

                    const pill = findIdPill(el);

                    if (pill) {
                        let host = pill.nextElementSibling;
                        if (host && host.classList.contains('bl-gfdep-badges')) {
                            applyHostStyles(host);
                            return host;
                        }
                        host = document.createElement('span');
                        host.className = 'bl-gfdep-badges';
                        pill.parentNode.insertBefore(host, pill.nextSibling);
                        applyHostStyles(host);
                        return host;
                    }

                    let host = el.querySelector(':scope > .bl-gfdep-badges');
                    if (!host) {
                        host = document.createElement('span');
                        host.className = 'bl-gfdep-badges bl-gfdep-badges--fallback';
                        el.appendChild(host);
                    }
                    applyHostStyles(host);
                    return host;
                }

                function renderBadges() {
                    if (!state.index) return;
                    let rendered = 0;
                    for (const fieldId in state.index) {
                        if (!state.index.hasOwnProperty(fieldId)) continue;
                        const el = findFieldElement(CFG.formId, fieldId);
                        if (!el) continue;
                        const host = ensureBadgeHost(el);
                        if (!host) continue;
                        host.innerHTML = '';
                        for (let i = 0; i < CFG.badgeKinds.length; i++) {
                            const kind = CFG.badgeKinds[i];
                            const n = (state.index[fieldId] && state.index[fieldId][kind.key]) ? (state.index[fieldId][kind.key].count || 0) : 0;
                            if (!n) continue;
                            const badge = document.createElement('span');
                            badge.className = 'bl-gfdep-badge';
                            badge.setAttribute('data-kind', kind.key);
                            badge.title = kind.label(n);
                            applyBadgeStyles(badge, kind.key);
                            (function (fid, kkey) {
                                badge.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    openModal(fid, kkey);
                                });
                            })(fieldId, kind.key);
                            host.appendChild(badge);
                        }
                        rendered++;
                    }
                    log('renderBadges done. Fields rendered:', rendered);
                }

                function updateFromForm(form, reason) {
                    state.lastForm = form;
                    state.index = buildIndexFromForm(form);
                    log('updateFromForm:', reason, 'index keys:', Object.keys(state.index || {}).length);
                    renderBadges();
                }

                function tryInitFromWindowForm() {
                    if (window.form && String(window.form.id || '') === String(CFG.formId)) {
                        updateFromForm(window.form, 'init.window.form');
                        return true;
                    }
                    return false;
                }

                function startFieldObserver() {
                    if (state.observer) return;
                    const target = document.getElementById('gform_fields') || document.body;
                    state.observer = new MutationObserver(function (mutations) {
                        if (!state.index) return;
                        let relevant = false;
                        for (let i = 0; i < mutations.length; i++) {
                            const mut = mutations[i];
                            for (let j = 0; j < mut.addedNodes.length; j++) {
                                const node = mut.addedNodes[j];
                                if (node.nodeType === 1 && (
                                    node.classList.contains('gfield') ||
                                    (node.querySelector && node.querySelector('.gfield'))
                                )) {
                                    relevant = true;
                                    break;
                                }
                            }
                            if (relevant) break;
                        }
                        if (relevant) {
                            log('MutationObserver: field nodes added, re-rendering badges');
                            renderBadges();
                        }
                    });
                    state.observer.observe(target, { childList: true, subtree: true });
                    log('MutationObserver started on', target.id || 'body');
                }

                function formFingerprint(form) {
                    if (!form || !form.fields) return '';
                    const parts = [form.fields.length];
                    for (let i = 0; i < form.fields.length; i++) {
                        const f = form.fields[i];
                        if (!f) continue;
                        // Track ID, label (for modal title updates), conditional logic, and calculations
                        parts.push(
                            String(f.id) + ':' +
                            (f.label || '') + ':' +
                            JSON.stringify(f.conditionalLogic || null) + ':' +
                            (f.calculationFormula || '')
                        );
                    }
                    return parts.join('|');
                }

                function hookSaveEvents() {
                    if (!window.gform || typeof window.gform.addAction !== 'function') return false;

                    let lastFingerprint = formFingerprint(window.form);
                    // Use a debounced-style check instead of heavy JSON.stringify in interval
                    function checkChange() {
                        if (!window.form || String(window.form.id || '') !== String(CFG.formId)) return;
                        const fp = formFingerprint(window.form);
                        if (fp !== lastFingerprint) {
                            lastFingerprint = fp;
                            log('window.form change detected, re-indexing');
                            updateFromForm(window.form, 'poll.change');
                        }
                    }

                    setInterval(checkChange, 1000); // 1s is plenty for background polling

                    try {
                        window.gform.addAction('gform_form_saving_action_save_succeeded', function (form) {
                            log('save_succeeded fired');
                            if (form && form.fields) updateFromForm(form, 'save_succeeded');
                            else tryInitFromWindowForm();
                        }, 10, 1);
                        window.gform.addAction('gform_form_saving_action_element_after_reload', function () {
                            log('element_after_reload fired');
                            if (window.form && String(window.form.id || '') === String(CFG.formId)) {
                                updateFromForm(window.form, 'element_after_reload');
                            }
                        }, 10, 1);
                    } catch (e) { log('hook registration error:', e); }

                    return true;
                }

                function boot() {
                    injectCSS();
                    ensureModal();
                    log('boot start. formId=', CFG.formId);
                    startFieldObserver();
                    const initOk = tryInitFromWindowForm();
                    if (!initOk) log('window.form not ready yet');
                    let tries = 0;
                    const t = setInterval(function () {
                        tries++;
                        const hooksOk = hookSaveEvents();
                        const init2 = !state.index && tryInitFromWindowForm();
                        if (hooksOk && (state.index || init2)) {
                            clearInterval(t);
                            log('Initialized (hooks + initial render).');
                        }
                        if (tries > 60) {
                            clearInterval(t);
                            log('Init timed out.');
                        }
                    }, 200);
                }

                // The script runs in the footer, after DOMContentLoaded has already
                // fired, so call boot() directly rather than waiting for the event.
                if (document.readyState === 'loading'){
                    document.addEventListener('DOMContentLoaded', boot);
                } else {
                    boot();
                }

            })(jQuery);
			<?php
			$script_content = ob_get_clean();
			?>
		</script>
		<?php
		wp_add_inline_script( 'jquery-ui-dialog', $script_content );
	},
	20
);
