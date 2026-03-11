<?php
/**
 * Plugin Name: GF Export Layout
 * Description: Adds an "Export Layout" button to the Gravity Forms editor that copies a full HTML visual layout of the form to clipboard, including GPPA, conditional logic, calculations, and all field metadata.
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────
// AJAX: Return all fields (including sub-fields) for a given form
// ─────────────────────────────────────────────
add_action(
	'wp_ajax_get_gppa_form_fields',
	function () {

		// Security: verify nonce
		check_ajax_referer( 'gf_export_nonce', 'nonce' );

		if ( ! current_user_can( 'gravityforms_edit_forms' ) ) {
			wp_send_json_error( 'Permission denied', 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		if ( ! $form_id ) {
			wp_send_json_error( 'Missing form ID', 400 );
		}

		$form = GFAPI::get_form( $form_id );
		if ( ! $form || empty( $form['fields'] ) ) {
			wp_send_json_error( 'Form not found', 404 );
		}

		$field_map = [];

		foreach ( $form['fields'] as $field ) {
			// Top-level field
			if ( isset( $field->id, $field->label ) ) {
				$field_map[ (string) $field->id ] = $field->label;
			}

			// Sub-fields (address parts, name parts, etc.)
			if ( isset( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					if ( ! empty( $input['id'] ) ) {
						$sub_label                          = ! empty( $input['label'] )
							? ( $field->label . ' — ' . $input['label'] )
							: $field->label;
						$field_map[ (string) $input['id'] ] = $sub_label;
					}
				}
			}
		}

		wp_send_json_success(
			[
				'title'  => rgar( $form, 'title' ),
				'fields' => $field_map,
			]
		);
	}
);


// ─────────────────────────────────────────────
// Inject the Export Layout button + script into the GF editor
// ─────────────────────────────────────────────
add_action(
	'gform_editor_js',
	function () {
		$nonce = wp_create_nonce( 'gf_export_nonce' );
		?>
		<script>
            jQuery(document).ready(function ($) {
                console.log('✅ GF Export Layout initialized.');

                // ── Inject button ──────────────────────────────────────────────────
                const $btn = $('<button class="gform-button gform-button--white gform-button--icon-leading" id="export-form-visual">📋 Export Layout</button>');
                $('#gf_toolbar_buttons_container').append($btn);

                // ── Nonce (injected server-side) ───────────────────────────────────
                const GF_EXPORT_NONCE = <?php echo wp_json_encode( $nonce ); ?>;

                // ── Width class → colspan (out of 12 columns) ─────────────────────
                const WIDTH_MAP = {
                    'quarter'            : 3,
                    'third'              : 4,
                    'half'               : 6,
                    'two-thirds'         : 8,
                    'three-quarter'      : 9,
                    'three-quarters'     : 9,
                    'full'               : 12,
                    'five-twelfths'      : 5,
                    'seven-twelfths'     : 7,
                    'five-sixths'        : 10,
                    'eleven-twelfths'    : 11,
                };

                // ── Escape HTML helper ───────────────────────────────────────────
                function escHtml (str) {
                    if (!str) return '';
                    return String(str)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                // ── Visibility badge ───────────────────────────────────────────────
                function getVisibility ($f) {
                    if ($f.hasClass('gfield_visibility_hidden'))       return '🔒 Hidden';
                    if ($f.hasClass('gfield_visibility_administrative')) return '🛠 Admin Only';
                    return '';
                }

                // ── Clean label (strip required asterisk etc.) ─────────────────────
                function cleanLabel ($f) {
                    const $clone = $f.find('.gfield_label').clone();
                    $clone.find('.gfield_required, .field_required').remove();
                    return $clone.text().trim() || '(No Label)';
                }

                // ── Resolve a GPPA field key like "gf_field_3" → human label ───────
                function makeResolver (fieldLabels) {
                    return function resolve (key) {
                        if (!key) return '';
                        // handles "gf_field_3" and "gf_field_1.3"
                        const match = key.match(/gf_field_([\d.]+)/);
                        if (!match) return escHtml(key);
                        const fid = match[1];
                        return fieldLabels[fid] ? `${escHtml(fieldLabels[fid])} <small>(${escHtml(key)})</small>` : escHtml(key);
                    };
                }

                // ── Fetch field labels for a source form (with nonce) ──────────────
                const formCache = {};
                async function fetchFormFields (formId) {
                    if (formCache[formId]) return formCache[formId];

                    const result = await $.post(ajaxurl, {
                        action  : 'get_gppa_form_fields',
                        nonce   : GF_EXPORT_NONCE,
                        form_id : parseInt(formId),
                    });
                    if (result.success) {
                        formCache[formId] = result.data;
                        return result.data;
                    }
                    return null;
                }

                // ── Build GPPA HTML block ──────────────────────────────────────────
                async function buildGppaHTML (fieldObj) {
                    if (!fieldObj['gppa-choices-enabled']) return '';

                    const source   = fieldObj['gppa-choices-object-type']         || '';
                    const primary  = fieldObj['gppa-choices-primary-property']    || '';
                    const ordering = fieldObj['gppa-choices-ordering-property']   || '';
                    const method   = fieldObj['gppa-choices-ordering-method']     || '';
                    const templates = fieldObj['gppa-choices-templates']          || {};
                    const filters   = fieldObj['gppa-choices-filter-groups']      || [];

                    let html = `<div class="gfe-meta gfe-gppa">🔄 <strong>Populate Anything</strong><br>`;
                    html += `Source Type: <em>${escHtml(source) || '(unknown)'}</em><br>`;
                    html += `Primary Property: <code>${escHtml(primary)}</code><br>`;

                    // If source is a GF form, resolve field keys to labels
                    let resolve = k => escHtml(k); // default: pass-through
                    if (source === 'gf_entry' && primary) {
                        try {
                            const data = await fetchFormFields(primary);
                            if (data) {
                                html += `📄 Source Form: <strong>${escHtml(data.title)}</strong><br>`;
                                resolve = makeResolver(data.fields);
                            }
                        } catch (err) {
                            console.warn('❌ GPPA AJAX error:', err);
                        }
                    }

                    if (ordering) html += `Order By: ${resolve(ordering)} (${escHtml(method)})<br>`;

                    if (templates.value || templates.label) {
                        html += `<u>Templates:</u><br>`;
                        if (templates.value) html += `&nbsp;Value → ${resolve(templates.value)}<br>`;
                        if (templates.label) html += `&nbsp;Label → ${resolve(templates.label)}<br>`;
                    }

                    // Filters — guard against malformed groups
                    if (Array.isArray(filters) && filters.length) {
                        html += `<u>Filters:</u><br>`;
                        filters.forEach(group => {
                            if (!Array.isArray(group)) return;
                            group.forEach(filter => {
                                const key   = resolve(filter.key   || '');
                                const op    = escHtml(filter.operator || '');
                                const val   = escHtml(filter.value    || '');
                                html += `&nbsp;${key} <em>${op}</em> "<strong>${val}</strong>"<br>`;
                            });
                        });
                    }

                    html += `</div>`;
                    return html;
                }

                // ── Build static choices HTML ──────────────────────────────────────
                function buildChoicesHTML (fieldObj) {
                    if (!Array.isArray(fieldObj.choices) || !fieldObj.choices.length) return '';

                    const rows = fieldObj.choices.map(c => {
                        const isDefault = c.isSelected ? ' ✔' : '';
                        const price     = c.price ? ` ($${escHtml(c.price)})` : '';
                        const val       = (c.value && c.value !== c.text) ? ` <small>[val: ${escHtml(c.value)}]</small>` : '';
                        return `${escHtml(c.text) || '(empty)'}${isDefault}${price}${val}`;
                    });

                    const shown = rows.slice(0, 15);
                    const more  = rows.length > 15 ? `<br><small>…and ${rows.length - 15} more</small>` : '';
                    return `<div class="gfe-meta">🔘 <strong>Choices:</strong><br>${shown.join('<br>')}${more}</div>`;
                }

                // ── Build conditional logic HTML ───────────────────────────────────
                function buildLogicHTML (fieldObj, allFields) {
                    if (!fieldObj.conditionalLogic) return '';
                    const logic     = fieldObj.conditionalLogic;
                    const action    = logic.actionType === 'show' ? '👁 Show' : '🙈 Hide';
                    const logicType = logic.logicType  === 'all'  ? 'ALL of'  : 'ANY of';

                    const rules = (logic.rules || []).map(r => {
                        const target      = allFields.find((f) => String(f.id) === String(r.fieldId));
                        const targetLabel = target ? target.label : `Field ${r.fieldId}`;
                        return `&nbsp;${escHtml(targetLabel)} <em>${escHtml(r.operator)}</em> "<strong>${escHtml(r.value)}</strong>"`;
                    }).join('<br>');

                    return `<div class="gfe-meta gfe-logic">⚙️ <strong>Conditional Logic:</strong><br>${action} if ${logicType}:<br>${rules}</div>`;
                }

                // ── Build calculation HTML ─────────────────────────────────────────
                function buildCalcHTML (fieldObj) {
                    if (!fieldObj.enableCalculation || !fieldObj.calculationFormula) return '';
                    const rounding = fieldObj.calculationRounding != null ? ` (round to ${parseInt(fieldObj.calculationRounding)} decimals)` : '';
                    return `<div class="gfe-meta gfe-calc">🧮 <strong>Formula:</strong> <code>${escHtml(fieldObj.calculationFormula)}</code>${rounding}</div>`;
                }

                // ── Build sub-fields (inputs) HTML ─────────────────────────────────
                function buildInputsHTML (fieldObj) {
                    if (!Array.isArray(fieldObj.inputs) || !fieldObj.inputs.length) return '';
                    const lines = fieldObj.inputs.map(inp => {
                        const hidden = inp.isHidden ? ' <em>(hidden)</em>' : '';
                        return `&nbsp;[${escHtml(inp.id)}] ${escHtml(inp.label) || '(unlabeled)'}${hidden}`;
                    });
                    return `<div class="gfe-meta">📐 <strong>Sub-fields:</strong><br>${lines.join('<br>')}</div>`;
                }

                // ── Build full cell HTML for one field ─────────────────────────────
                async function buildCellHTML ($f, colspan, allFields) {
                    const idAttr  = $f.attr('id') || '';
                    const idMatch = idAttr.match(/_(\d+)$/);
                    const id      = idMatch ? parseInt(idMatch[1]) : null;
                    const label   = cleanLabel($f);
                    const type    = ($f.attr('class').match(/gfield--type-([^\s]+)/) || [])[1] || 'unknown';
                    const desc    = $f.find('.gfield_description').text().trim();
                    const vis     = getVisibility($f);

                    // ── Locate field object in window.form ──────────────────────────
                    const fieldObj = (window.form && Array.isArray(window.form.fields))
                        ? window.form.fields.find((f) => String(f.id) === String(id))
                        : null;

                    // ── Assemble metadata blocks ────────────────────────────────────
                    let gppaHTML    = '';
                    let choicesHTML = '';
                    let logicHTML   = '';
                    let calcHTML    = '';
                    let inputsHTML  = '';
                    let metaHTML    = '';

                    if (fieldObj) {
                        gppaHTML    = await buildGppaHTML(fieldObj);
                        if (!gppaHTML) choicesHTML = buildChoicesHTML(fieldObj);
                        logicHTML   = buildLogicHTML(fieldObj, allFields);
                        calcHTML    = buildCalcHTML(fieldObj);
                        inputsHTML  = buildInputsHTML(fieldObj);

                        // ── Scalar metadata ──────────────────────────────────────────
                        const meta = [];
                        if (fieldObj.adminLabel)          meta.push(`🏷 Admin Label: <em>${escHtml(fieldObj.adminLabel)}</em>`);
                        if (fieldObj.placeholder)          meta.push(`💬 Placeholder: "${escHtml(fieldObj.placeholder)}"`);
                        if (fieldObj.defaultValue)         meta.push(`📌 Default: "${escHtml(fieldObj.defaultValue)}"`);
                        if (fieldObj.cssClass)             meta.push(`🎨 CSS: <code>${escHtml(fieldObj.cssClass)}</code>`);
                        if (fieldObj.isRequired)           meta.push(`✅ Required`);
                        if (fieldObj.noDuplicates)         meta.push(`⚠️ No Duplicates`);
                        if (fieldObj.maxLength)            meta.push(`📏 Max Length: ${parseInt(fieldObj.maxLength)}`);
                        if (fieldObj.rangeMin != null || fieldObj.rangeMax != null)
                            meta.push(`📊 Range: ${escHtml(fieldObj.rangeMin) ?? '—'} → ${escHtml(fieldObj.rangeMax) ?? '—'}`);
                        if (fieldObj.allowsPrepopulate && fieldObj.inputName)
                            meta.push(`🔗 Pre-pop param: <code>?${escHtml(fieldObj.inputName)}</code>`);
                        if (fieldObj.enableAutocomplete && fieldObj.autocompleteAttribute)
                            meta.push(`🤖 Autocomplete: ${escHtml(fieldObj.autocompleteAttribute)}`);
                        // Date/time specific
                        if (fieldObj.dateFormat)           meta.push(`📅 Date Format: ${escHtml(fieldObj.dateFormat)}`);
                        if (fieldObj.timeFormat)           meta.push(`🕐 Time Format: ${escHtml(fieldObj.timeFormat)}`);
                        // Address specific
                        if (fieldObj.addressType)          meta.push(`🌍 Address Type: ${escHtml(fieldObj.addressType)}`);
                        // Page break specific
                        if (type === 'page') {
                            if (fieldObj.nextButton?.text)  meta.push(`▶ Next: "${escHtml(fieldObj.nextButton.text)}"`);
                            if (fieldObj.previousButton?.text) meta.push(`◀ Prev: "${escHtml(fieldObj.previousButton.text)}"`);
                        }

                        if (meta.length) {
                            metaHTML = `<div class="gfe-meta">${meta.join('<br>')}</div>`;
                        }
                    }

                    // ── Assemble cell ───────────────────────────────────────────────
                    let cell = `<td colspan="${parseInt(colspan)}" style="border:1px solid #ccc;padding:10px;vertical-align:top;background:#fff;">`;
                    cell += `<strong style="font-size:1em;">${escHtml(label)}</strong>`;
                    cell += `<br><em style="color:#888;">${escHtml(type)}</em>`;
                    cell += `<br><small style="color:#aaa;">Field ID: ${id ?? 'n/a'}</small>`;
                    if (vis)  cell += `<br><span style="font-size:0.85em;">${escHtml(vis)}</span>`;
                    if (desc) cell += `<div style="margin-top:4px;font-size:0.85em;color:#666;font-style:italic;">${escHtml(desc)}</div>`;

                    cell += metaHTML;
                    cell += calcHTML;
                    cell += gppaHTML || choicesHTML;
                    cell += inputsHTML;
                    cell += logicHTML;
                    cell += `</td>`;
                    return cell;
                }

                // ── Main export handler ────────────────────────────────────────────
                $btn.on('click', async function (e) {
                    e.preventDefault();

                    if (!window.form) {
                        alert('Form data not available. Please reload the editor and try again.');
                        return;
                    }

                    $btn.text('⏳ Exporting…').prop('disabled', true);

                    try {
                        const allFields = (window.form && Array.isArray(window.form.fields)) ? window.form.fields : [];
                        const $fields   = $('#gform_fields .gfield');
                        const rows      = [];
                        let currentRow  = [], currentWidth = 0;

                        $fields.each(function (i, el) {
                            const $f        = $(el);
                            const idAttr    = $f.attr('id') || '';
                            const idMatch   = idAttr.match(/_(\d+)$/);
                            const id        = idMatch ? parseInt(idMatch[1]) : null;

                            // Try to get precise span from window.form first
                            let colspan = 12;
                            const fieldObj = (window.form && Array.isArray(window.form.fields))
                                ? window.form.fields.find((f) => String(f.id) === String(id))
                                : null;

                            if (fieldObj && fieldObj.layoutGridColumnSpan) {
                                colspan = parseInt(fieldObj.layoutGridColumnSpan);
                            } else {
                                // Fallback to CSS classes
                                const wClass = ($f.attr('class') || '').match(/gfield--width-(\S+)/);
                                colspan = wClass ? (WIDTH_MAP[wClass[1]] || 12) : 12;
                            }

                            if (currentWidth + colspan > 12) {
                                rows.push(currentRow);
                                currentRow  = [];
                                currentWidth = 0;
                            }
                            currentRow.push({ $f, colspan });
                            currentWidth += colspan;
                        });
                        if (currentRow.length) rows.push(currentRow);

                        // Build all rows (async — GPPA AJAX calls happen here)
                        const htmlRows = await Promise.all(rows.map(async (row) => {
                            const cellPromises = row.map(({ $f, colspan }) => buildCellHTML($f, colspan, allFields));
                            const cells = await Promise.all(cellPromises);

                            // Fill in the rest with empty cells if row doesn't sum to 12
                            const rowWidth = row.reduce((sum, item) => sum + item.colspan, 0);
                            if (rowWidth < 12) {
                                const remaining = 12 - rowWidth;
                                cells.push(`<td colspan="${remaining}" style="border:1px solid #ccc;padding:10px;background:#f9f9f9;">&nbsp;</td>`);
                            }

                            return `<tr>${cells.join('')}</tr>`;
                        }));

                        // ── Wrapper with embedded styles (self-contained for paste) ──
                        const style = `
                    <style>
                        .gfe-table { border-collapse:collapse; width:100%; font-family:Arial,sans-serif; font-size:13px; }
                        .gfe-meta  { margin-top:6px; font-size:0.85em; color:#444; line-height:1.6; border-top:1px dashed #ddd; padding-top:4px; }
                        .gfe-gppa  { color:#2a6496; }
                        .gfe-logic { color:#6a3d9a; }
                        .gfe-calc  { color:#b05f00; }
                        code       { background:#f4f4f4; padding:1px 3px; border-radius:2px; font-size:0.9em; }
                    </style>`;

                        const formTitle  = escHtml(window.form.title || 'Gravity Form');
                        const timestamp  = escHtml(new Date().toLocaleString());
                        const tableHTML  = `
                    ${style}
                    <h2 style="font-family:Arial,sans-serif;">${formTitle} — Layout Export</h2>
                    <p style="font-family:Arial,sans-serif;color:#888;font-size:0.85em;">Exported: ${timestamp}</p>
                    <table class="gfe-table">${htmlRows.join('')}</table>`;

                        // ── Copy to clipboard ──────────────────────────────────────
                        try {
                            await navigator.clipboard.write([
                                new ClipboardItem({ 'text/html': new Blob([tableHTML], { type: 'text/html' }) })
                            ]);
                            window.open('https://docs.google.com/document/create', '_blank');
                            alert('✅ Copied! Paste (Ctrl+V / Cmd+V) into the new Google Doc.');
                        } catch (clipErr) {
                            console.warn('⚠️ Clipboard API failed, falling back to new window:', clipErr);
                            // Fallback: open raw HTML in a new tab for manual copy
                            const fallback = window.open('', '_blank');
                            if (!fallback) {
                                alert('Clipboard access was denied, and the browser blocked the fallback window.');
                                return;
                            }
                            fallback.document.open();
                            fallback.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${formTitle} Export</title></head><body>${tableHTML}</body></html>`);
                            fallback.document.close();
                            alert('Clipboard access was denied.\n\nThe export has been opened in a new tab — select all (Ctrl+A) and copy from there.');
                        }

                    } catch (err) {
                        console.error('❌ Export failed:', err);
                        alert('Export failed. Check the browser console for details.');
                    } finally {
                        $btn.text('📋 Export Layout').prop('disabled', false);
                    }
                });
            });
		</script>
		<?php
	}
);