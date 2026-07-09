// Copyright (c) Skin Cancer College Australasia.
// All rights reserved.
//
// This file is part of a proprietary plugin developed by Skin Cancer
// College Australasia for use with Moodle. It is NOT free software and is
// NOT released under the GNU General Public License.
//
// Unauthorised copying, distribution, modification, or use of this file,
// in whole or in part, via any medium, is strictly prohibited without the
// prior written permission of Skin Cancer College Australasia. The software
// is provided "as is", without warranty of any kind, express or implied.

/**
 * Show only the job-form sections relevant to the selected job type.
 *
 * The form's hideIf rules hide the individual fields, but Moodle's form
 * dependencies cannot hide section headers, which would linger as empty
 * fieldsets. This module toggles the whole type-specific sections: the
 * output section is extract-only, the replacement section replace-only.
 *
 * @module     tool_imageextractor/jobtype
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

/** @type {Object} Fieldset ids that only apply to one job type. */
const SECTIONS = {
    extract: ['id_outputheader'],
    replace: ['id_replacementheader'],
};

/**
 * Show or hide one fieldset by id.
 *
 * @param {string} id The fieldset element id.
 * @param {boolean} show Whether the fieldset should be visible.
 */
const toggle = (id, show) => {
    const fieldset = document.getElementById(id);
    if (fieldset) {
        fieldset.classList.toggle('d-none', !show);
    }
};

/**
 * Apply the visibility rules for the current job type.
 *
 * @param {HTMLElement} field The jobtype select (or hidden input on edit).
 */
const apply = (field) => {
    const type = field.value === 'replace' ? 'replace' : 'extract';
    SECTIONS.extract.forEach((id) => toggle(id, type === 'extract'));
    SECTIONS.replace.forEach((id) => toggle(id, type === 'replace'));
};

/**
 * Initialise the job-type section toggling on the job form.
 *
 * @return {void}
 */
export const init = () => {
    const field = document.querySelector('form [name="jobtype"]');
    if (!field) {
        return;
    }
    // On edit (or when replace is not permitted) the type is a hidden input
    // and never changes; the initial pass still hides the wrong section.
    field.addEventListener('change', () => apply(field));
    apply(field);
};
