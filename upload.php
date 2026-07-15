<?php
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
 * AJAX endpoint for the resumable chunked upload of a large replacement file.
 *
 * Each request is small (one chunk), so the whole upload can exceed the site's
 * per-request upload limit. Every action is gated on the same rules as running
 * a replace: the manage capability, site administrator, the replace opt-in and
 * a valid session key.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

use tool_imageextractor\chunk_uploader;
use tool_imageextractor\manager;

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('tool/imageextractor:manage', $context);
if (!manager::is_enabled() || !manager::is_replace_allowed() || !is_siteadmin()) {
    throw new moodle_exception('replaceadminonly', 'tool_imageextractor');
}

$action = required_param('action', PARAM_ALPHA);

\core\session\manager::write_close();
header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {
        case 'start':
            $jobid = required_param('jobid', PARAM_INT);
            $filename = required_param('filename', PARAM_FILE);
            $filesize = required_param('filesize', PARAM_INT);
            // The job must exist (get_job throws otherwise).
            manager::get_job($jobid);
            $upload = chunk_uploader::start($jobid, $filename, $filesize);
            echo json_encode(['token' => $upload->token, 'uploadedbytes' => 0, 'chunks' => 0]);
            break;

        case 'status':
            $token = required_param('token', PARAM_ALPHANUMEXT);
            $upload = chunk_uploader::session($token);
            echo json_encode(['uploadedbytes' => (int) $upload->uploadedbytes, 'chunks' => (int) $upload->chunks]);
            break;

        case 'chunk':
            $token = required_param('token', PARAM_ALPHANUMEXT);
            $index = required_param('index', PARAM_INT);
            $data = file_get_contents('php://input');
            if ($data === false) {
                $data = '';
            }
            $upload = chunk_uploader::store_chunk($token, $index, $data);
            echo json_encode(['uploadedbytes' => (int) $upload->uploadedbytes, 'chunks' => (int) $upload->chunks]);
            break;

        case 'finish':
            $token = required_param('token', PARAM_ALPHANUMEXT);
            chunk_uploader::finish($token);
            echo json_encode(['finished' => true]);
            break;

        case 'abort':
            $token = required_param('token', PARAM_ALPHANUMEXT);
            $upload = chunk_uploader::session($token);
            chunk_uploader::discard((int) $upload->id);
            echo json_encode(['aborted' => true]);
            break;

        default:
            throw new moodle_exception('invalidaction', 'error');
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
