/* eslint-env browser, jquery */
/* global jQuery, GFFORMID */
/**
 * Disable Choices Already Picked in a Dropdown on Nested Form by Another Child Entry in the Batch
 *
 * @gravityperks
 * @gravityforms
 *
 * GOAL:
 * This JavaScript snippet disables dropdown choices in a Gravity Forms nested form to prevent
 * duplicate selections across child entries within the same batch. When a user selects an option
 * in one child entry, it becomes unavailable in subsequent child entries, ensuring unique
 * selections. JS snippet to be installed on child form. Make sure to replace the parent form id,
 * the field id of the dropdown and the field id of the nested form on the parent form.
 *
 * CONFIGURATION:
 * - `parentFormId`: ID of the parent form that contains the Nested Form field
 * - `nestedFieldId`: Field ID of the Nested Form field on the parent form
 * - `childDropdownFieldId`: Field ID of the dropdown on the child form
 * - `compareBy`: 'value' (recommended) or 'label' to compare against saved values or visible
 *   labels
 *
 * FEATURES:
 * - **Prevents duplicate selections** in a dropdown field within nested forms.
 * - **Dynamically disables options** that have already been chosen by other child entries.
 * - **Works within the same batch of child entries** in a Gravity Forms parent form.
 * - **Ensures data integrity** by restricting users from selecting the same option multiple times.
 * - **Customizable field IDs** to match your specific form setup.
 * - **Lightweight and efficient** JavaScript solution that runs client-side.
 *
 * NOTES:
 * - Adds class 'bld-disabled-choice' to disabled and sets a helpful title.
 */
(function ($) {
    'use strict';

    // === Configuration (edit these) ============================================
    const cfg = {
        parentFormId: 23,
        nestedFieldId: 1,
        childDropdownFieldId: 1,
        compareBy: 'value', // 'value' | 'label'
    };
    // ==========================================================================

    function ns() {
        const key = `GPNestedForms_${cfg.parentFormId}_${cfg.nestedFieldId}`;
        return window[key];
    }

    function buildUsedSet(namespace) {
        const used = new Set();
        if (!namespace || !Array.isArray(namespace.entries)) {
            return used;
        }
        namespace.entries.forEach(function (entry) {
            const field = entry[cfg.childDropdownFieldId] || {};
            const str =
                cfg.compareBy === 'value'
                    ? field.value || field.label
                    : field.label || field.value;
            if (str) {
                used.add(String(str).trim());
            }
        });
        return used;
    }

    function updateDisabledOptions() {
        // Ensure child select exists on current page.
        const select = document.querySelector(
            `#input_${window.GFFORMID}_${cfg.childDropdownFieldId}`
        );
        if (!select) {
            return;
        }
        const namespace = ns();
        const used = buildUsedSet(namespace);
        Array.prototype.forEach.call(select.options, function (opt) {
            const toCheck =
                cfg.compareBy === 'value'
                    ? opt.value
                    : (opt.textContent || '').trim();
            const disabled = used.has(String(toCheck));
            opt.disabled = disabled;
            opt.classList.toggle('bld-disabled-choice', disabled);
            opt.title = disabled ? 'Already selected in this batch' : '';
        });
    }

    $(document).on(
        'gpnf_after_entry_added gpnf_after_entry_removed gpnf_after_edit_entry gform_post_render',
        updateDisabledOptions
    );
    $(updateDisabledOptions);
})(jQuery);
