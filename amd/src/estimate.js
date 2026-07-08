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
 * Live match-count estimate for the extract job form.
 *
 * Watches the criteria fields and, shortly after each change, asks the server
 * for an approximate match count and total size, updating an inline region
 * without submitting the form.
 *
 * @module     tool_imageextractor/estimate
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';

/** @type {string} Selector for the region the estimate is written into. */
const REGION = '[data-region="tool_imageextractor-estimate"]';

/** @type {number} Milliseconds to wait after the last change before asking. */
const DELAY = 600;

/**
 * Read the current criteria from the form as web-service arguments.
 *
 * @param {HTMLFormElement} form The job form.
 * @return {Object} Arguments for tool_imageextractor_estimate_matches.
 */
const collect = (form) => {
    const text = (name) => {
        const el = form.elements[name];
        return el ? String(el.value).trim() : '';
    };
    const checked = (name) => {
        const el = form.elements[name];
        return el ? Boolean(el.checked) : false;
    };
    const number = (name) => {
        const parsed = parseInt(text(name), 10);
        return Number.isNaN(parsed) ? 0 : parsed;
    };
    const ids = (name) => {
        const el = form.elements[name];
        if (!el || !el.selectedOptions) {
            return [];
        }
        return Array.from(el.selectedOptions)
            .map((option) => parseInt(option.value, 10))
            .filter((value) => value > 0);
    };
    const epoch = (name) => {
        if (!checked(name + '[enabled]')) {
            return 0;
        }
        const year = number(name + '[year]');
        const month = number(name + '[month]');
        const day = number(name + '[day]');
        if (!year || !month || !day) {
            return 0;
        }
        return Math.floor(Date.UTC(year, month - 1, day) / 1000);
    };

    return {
        imageonly: checked('imageonly'),
        dedupe: checked('dedupe'),
        component: text('component'),
        filearea: text('filearea'),
        filenamepattern: text('filenamepattern'),
        mimetypes: text('mimetypes'),
        minsizekb: number('minsizekb'),
        maxsizekb: number('maxsizekb'),
        datefrom: epoch('datefrom'),
        dateto: epoch('dateto'),
        courseids: ids('courseids'),
        categoryids: ids('categoryids'),
    };
};

/**
 * Write a string into the region, but only if it is still the latest request.
 *
 * @param {HTMLElement} region The output region.
 * @param {Object} state Shared request state.
 * @param {number} seq The sequence number of the request being rendered.
 * @param {string} key Language string identifier.
 * @param {Object|null} data Placeholder data for the string.
 * @return {Promise} Resolves once the region is (or is not) updated.
 */
const render = (region, state, seq, key, data) => {
    return getString(key, 'tool_imageextractor', data).then((message) => {
        if (seq === state.seq) {
            region.textContent = message;
        }
        return message;
    }).catch(() => {
        if (seq === state.seq) {
            region.textContent = '';
        }
        return '';
    });
};

/**
 * Request and render an estimate for the current criteria.
 *
 * @param {HTMLFormElement} form The job form.
 * @param {HTMLElement} region The output region.
 * @param {Object} state Shared request state (holds the sequence counter).
 * @return {Promise} Resolves once the estimate has been rendered.
 */
const update = (form, region, state) => {
    state.seq += 1;
    const seq = state.seq;

    render(region, state, seq, 'estimatelivecomputing', null);

    return Ajax.call([{
        methodname: 'tool_imageextractor_estimate_matches',
        args: collect(form),
    }])[0].then((result) => {
        return render(region, state, seq, 'estimatelivevalue', {
            count: result.count,
            size: result.formattedsize,
        });
    }).catch(() => {
        return render(region, state, seq, 'estimateliveerror', null);
    });
};

/**
 * Initialise the live estimate on the extract form.
 *
 * @return {void}
 */
export const init = () => {
    const region = document.querySelector(REGION);
    if (!region) {
        return;
    }
    const form = region.closest('form');
    if (!form) {
        return;
    }

    const state = {seq: 0, timer: null};
    const schedule = () => {
        if (state.timer) {
            window.clearTimeout(state.timer);
        }
        state.timer = window.setTimeout(() => update(form, region, state), DELAY);
    };

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);

    // Show an estimate for the criteria the form loaded with.
    update(form, region, state);
};
