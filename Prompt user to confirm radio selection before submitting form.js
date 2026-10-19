/**
 * Prompt user to confirm radio selection before submitting form
 *
 * @gravityforms
 *
 * GOAL:
 * This JavaScript snippet prompts users with a confirmation message before submitting a **Gravity
 * Form** when they select a specific radio button choice. If the user selects a designated choice,
 * a confirmation dialog appears, requiring them to confirm their selection before the form is
 * submitted. This ensures that users intentionally proceed with their chosen option. Remember to
 * update the choices and messages on the top of the snippet. And update the name of the radio
 * button a few lines down. If you are unsure what the name is right click on the radio field,
 * click inspect and you will be able to see the html element name.
 *
 * CONFIGURATION:
 * - Edit the config block below. Installed through GOCSM, these values are fields and survive
 *   snippet updates.
 * - `bldRadioFieldId`: numeric field ID of the radio field (e.g., 3)
 * - `bldRadioMessages`: map of radio option value to confirmation message
 * - `bldRadioFormIds`: optional array of form IDs; empty means apply to any form on the page
 *
 * FEATURES:
 * - **Prompts users with a confirmation message** before submitting the form.
 * - **Works specifically for radio button fields**, allowing customized messages for each choice.
 * - **Prevents accidental submissions** by requiring user confirmation.
 * - **Customizable messages** for different radio button options.
 * - **Lightweight and runs client-side** for a seamless user experience.
 */

// === BEGIN CONFIG ===
/** @config int Numeric field ID of the radio field */
const bldRadioFieldId = 3;
/** @config object Map of radio option value to confirmation message */
const bldRadioMessages = {'First Choice': 'Are you sure you want to submit First Choice?', 'Second Choice': 'Are you sure you want to submit Second Choice?'};
/** @config array Form IDs to apply to. Empty array applies to every form on the page */
const bldRadioFormIds = [];
// === END CONFIG ===

(function ($) {
    'use strict';

    const cfg = {
        fieldId: bldRadioFieldId,
        messages: bldRadioMessages,
        applyToFormIds: bldRadioFormIds,
    };

    function shouldApply(formId) {
        if (
            !Array.isArray(cfg.applyToFormIds) ||
            0 === cfg.applyToFormIds.length
        ) {
            return true;
        }
        return cfg.applyToFormIds.indexOf(Number(formId)) !== -1;
    }

    function bindForForm(formId) {
        if (!formId || !shouldApply(formId)) {
            return;
        }
        const form = document.getElementById('gform_' + String(formId));
        if (!form || form.dataset.bldConfirmBound === '1') {
            return;
        }
        form.dataset.bldConfirmBound = '1';

        form.addEventListener(
            'submit',
            function (e) {
                const selector =
                    'input[name="input_' + String(cfg.fieldId) + '"]:checked';
                const input = form.querySelector(selector);
                const val = input ? input.value : '';
                const msg = Object.prototype.hasOwnProperty.call(
                    cfg.messages,
                    val
                )
                    ? cfg.messages[val]
                    : '';
                if (msg) {
                    const ok = window.confirm(msg);
                    if (!ok) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }
            },
            true
        );
    }

    $(document).on(
        'gform_post_render gform_page_loaded',
        function (ev, formId) {
            const id = typeof formId === 'undefined' ? window.GFFORMID : formId;
            if (typeof id !== 'undefined' && id !== null) {
                bindForForm(parseInt(id, 10));
            }
        }
    );

    $(function () {
        if (
            typeof window.GFFORMID !== 'undefined' &&
            window.GFFORMID !== null
        ) {
            bindForForm(parseInt(window.GFFORMID, 10));
        }
    });
})(jQuery);
