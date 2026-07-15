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
 * Browser side of the resumable chunked upload: slice a large file and post it
 * one small chunk at a time to upload.php, then finalise it into the job's
 * replacement area. Plain (non-AMD) script, self-initialising on load.
 */
(function() {
    "use strict";

    /**
     * POST a request to the upload endpoint and parse the JSON reply.
     *
     * @param {String} endpoint Base endpoint URL.
     * @param {String} sesskey Session key.
     * @param {Object} params Query parameters (action, token, ...).
     * @param {Blob} body Optional raw chunk body.
     * @return {Promise<Object>} The decoded JSON response.
     */
    function request(endpoint, sesskey, params, body) {
        var url = endpoint + '?sesskey=' + encodeURIComponent(sesskey);
        Object.keys(params).forEach(function(key) {
            url += '&' + key + '=' + encodeURIComponent(params[key]);
        });
        var options = {method: 'POST'};
        if (body) {
            options.body = body;
            options.headers = {'Content-Type': 'application/octet-stream'};
        }
        return fetch(url, options).then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok) {
                    throw new Error(data.error || 'Upload failed');
                }
                return data;
            });
        });
    }

    /**
     * Send one chunk, retrying a few times on transient failure.
     *
     * @param {Function} post Bound request function.
     * @param {String} token Upload token.
     * @param {Number} index Chunk index.
     * @param {Blob} blob Chunk data.
     * @param {Number} tries Remaining attempts.
     * @return {Promise<Object>}
     */
    function sendChunk(post, token, index, blob, tries) {
        return post({action: 'chunk', token: token, index: index}, blob).catch(function(error) {
            if (tries > 1) {
                return sendChunk(post, token, index, blob, tries - 1);
            }
            throw error;
        });
    }

    /**
     * Wire up one chunked-upload widget.
     *
     * @param {Element} root The widget container.
     */
    function init(root) {
        var endpoint = root.getAttribute('data-endpoint');
        var sesskey = root.getAttribute('data-sesskey');
        var jobid = root.getAttribute('data-jobid');
        var chunkSize = parseInt(root.getAttribute('data-chunksize'), 10) || (5 * 1024 * 1024);
        var doneField = document.getElementById(root.getAttribute('data-donefield'));
        var fileInput = root.querySelector('input[type=file]');
        var button = root.querySelector('[data-action=upload]');
        var progress = root.querySelector('progress');
        var status = root.querySelector('[data-region=status]');

        var post = function(params, body) {
            return request(endpoint, sesskey, params, body);
        };

        var sendAll = function(file, token, startIndex) {
            var total = Math.ceil(file.size / chunkSize) || 1;
            var index = startIndex;
            var step = function() {
                if (index >= total) {
                    return Promise.resolve();
                }
                var start = index * chunkSize;
                var blob = file.slice(start, Math.min(start + chunkSize, file.size));
                return sendChunk(post, token, index, blob, 3).then(function(res) {
                    progress.value = file.size ? Math.round((res.uploadedbytes / file.size) * 100) : 100;
                    status.textContent = 'Uploaded ' + res.chunks + ' / ' + total + ' chunks';
                    index++;
                    return step();
                });
            };
            return step();
        };

        button.addEventListener('click', function() {
            var file = fileInput.files[0];
            if (!file) {
                status.textContent = root.getAttribute('data-str-nofile');
                return;
            }
            button.disabled = true;
            fileInput.disabled = true;
            progress.hidden = false;
            var token;
            post({action: 'start', jobid: jobid, filename: file.name, filesize: file.size}).then(function(res) {
                token = res.token;
                return sendAll(file, token, res.chunks || 0);
            }).then(function() {
                return post({action: 'finish', token: token});
            }).then(function() {
                progress.value = 100;
                status.textContent = root.getAttribute('data-str-complete');
                if (doneField) {
                    doneField.value = '1';
                }
            }).catch(function(error) {
                status.textContent = root.getAttribute('data-str-error') + ' ' + error.message;
                button.disabled = false;
                fileInput.disabled = false;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var widgets = document.querySelectorAll('.tool-imageextractor-chunkupload');
        Array.prototype.forEach.call(widgets, init);
    });
})();
