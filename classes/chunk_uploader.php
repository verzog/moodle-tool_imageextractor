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
 * Server side of the resumable chunked upload for large replacement files.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Assembles a large replacement file from small chunks uploaded one at a time,
 * so a file bigger than the site's per-request upload limit can still be sent.
 *
 * The browser slices the file and posts each chunk to the upload endpoint,
 * which calls store_chunk(); the chunks are held in the file storage (keyed by
 * their index) so the upload survives across requests and load-balanced app
 * servers. finish() streams them back together in order and unpacks (a ZIP) or
 * stores (a single image) the result into the job's replacement area - the same
 * place the ordinary replacement upload lands.
 */
class chunk_uploader {
    /** @var string File area holding the in-progress chunks. */
    const CHUNK_AREA = 'chunk';

    /**
     * Begin an upload session for a job and return its record (including the
     * token the browser uses for every subsequent chunk).
     *
     * @param int $jobid
     * @param string $filename The name of the file being uploaded.
     * @param int $filesize The full size the assembled file should reach.
     * @param int $userid The owner of the session (defaults to the current user).
     * @return \stdClass The upload session record.
     */
    public static function start(int $jobid, string $filename, int $filesize, int $userid = 0): \stdClass {
        global $DB, $USER;

        $now = time();
        $record = new \stdClass();
        $record->token = random_string(40);
        $record->userid = $userid > 0 ? $userid : (int) $USER->id;
        $record->jobid = $jobid;
        $record->filename = clean_param($filename, PARAM_FILE);
        $record->filesize = max(0, $filesize);
        $record->uploadedbytes = 0;
        $record->chunks = 0;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('tool_imageextractor_upload', $record);
        return $record;
    }

    /**
     * Load an upload session by token, checking it belongs to the given user.
     *
     * @param string $token
     * @param int $userid Owner to check against (0 for the current user).
     * @return \stdClass
     */
    public static function session(string $token, int $userid = 0): \stdClass {
        global $DB, $USER;
        $userid = $userid > 0 ? $userid : (int) $USER->id;
        $upload = $DB->get_record('tool_imageextractor_upload', ['token' => $token], '*', MUST_EXIST);
        if ((int) $upload->userid !== $userid) {
            throw new \moodle_exception('uploadnotyours', 'tool_imageextractor');
        }
        return $upload;
    }

    /**
     * Store one chunk at its index (overwriting a retried chunk), then recount
     * the received bytes and chunks from what is actually stored so the totals
     * stay correct across retries and resumes.
     *
     * @param string $token
     * @param int $index Zero-based chunk index.
     * @param string $data Raw chunk bytes.
     * @param int $userid Owner (0 for the current user).
     * @return \stdClass The updated session record.
     */
    public static function store_chunk(string $token, int $index, string $data, int $userid = 0): \stdClass {
        global $DB;

        $upload = self::session($token, $userid);
        $fs = get_file_storage();
        $context = manager::context();
        $filename = sprintf('%08d', max(0, $index));

        // Replace any existing chunk at this index (an idempotent retry).
        $existing = $fs->get_file($context->id, manager::COMPONENT, self::CHUNK_AREA, (int) $upload->id, '/', $filename);
        if ($existing) {
            $existing->delete();
        }
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => manager::COMPONENT,
            'filearea'  => self::CHUNK_AREA,
            'itemid'    => (int) $upload->id,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $data);

        // Recount from the stored chunks (never from a running client total).
        $bytes = 0;
        $count = 0;
        foreach ($fs->get_area_files($context->id, manager::COMPONENT, self::CHUNK_AREA, $upload->id, 'id', false) as $chunk) {
            $bytes += (int) $chunk->get_filesize();
            $count++;
        }
        $upload->uploadedbytes = $bytes;
        $upload->chunks = $count;
        $upload->timemodified = time();
        $DB->update_record('tool_imageextractor_upload', $upload);
        return $upload;
    }

    /**
     * Assemble the stored chunks into the finished file and hand it to the
     * job's replacement area: a ZIP is unpacked (its entries matched by name at
     * apply time), any other file is stored as the single replacement image.
     * The session and its chunks are removed once done.
     *
     * @param string $token
     * @param int $userid Owner (0 for the current user).
     * @return void
     */
    public static function finish(string $token, int $userid = 0): void {
        $upload = self::session($token, $userid);
        $fs = get_file_storage();
        $context = manager::context();

        $chunks = $fs->get_area_files($context->id, manager::COMPONENT, self::CHUNK_AREA, $upload->id, 'filename', false);
        if (!$chunks) {
            throw new \moodle_exception('uploadnochunks', 'tool_imageextractor');
        }

        // Stream the chunks back together (in filename/index order) into a
        // temporary file, without ever holding the whole upload in memory.
        $tempdir = make_request_directory();
        $assembled = $tempdir . '/' . $upload->filename;
        $out = fopen($assembled, 'wb');
        foreach ($chunks as $chunk) {
            $in = $chunk->get_content_file_handle();
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        $isolate = '/chunkupload-' . (int) $upload->id . '/';
        if (strtolower(pathinfo($upload->filename, PATHINFO_EXTENSION)) === 'zip') {
            // Unpack into its own folder so entries cannot collide with another
            // upload's; replacement matching is by basename and ignores folders.
            get_file_packer('application/zip')->extract_to_storage(
                $assembled,
                $context->id,
                manager::COMPONENT,
                'replacement',
                (int) $upload->jobid,
                $isolate
            );
        } else {
            // A single image replaces whatever single source is stored.
            $fs->delete_area_files($context->id, manager::COMPONENT, 'replacement', (int) $upload->jobid);
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => manager::COMPONENT,
                'filearea'  => 'replacement',
                'itemid'    => (int) $upload->jobid,
                'filepath'  => '/',
                'filename'  => $upload->filename,
            ], $assembled);
        }

        self::discard($upload->id);
    }

    /**
     * Remove an upload session and its stored chunks.
     *
     * @param int $uploadid
     * @return void
     */
    public static function discard(int $uploadid): void {
        global $DB;
        get_file_storage()->delete_area_files(
            manager::context()->id,
            manager::COMPONENT,
            self::CHUNK_AREA,
            $uploadid
        );
        $DB->delete_records('tool_imageextractor_upload', ['id' => $uploadid]);
    }

    /**
     * Remove upload sessions that were abandoned before the given cutoff, so
     * half-finished uploads do not accumulate. Called from the cleanup task.
     *
     * @param int $before Delete sessions last touched before this timestamp.
     * @return int How many sessions were removed.
     */
    public static function purge_stale(int $before): int {
        global $DB;
        $stale = $DB->get_records_select(
            'tool_imageextractor_upload',
            'timemodified < :before',
            ['before' => $before],
            '',
            'id'
        );
        foreach ($stale as $upload) {
            self::discard((int) $upload->id);
        }
        return count($stale);
    }
}
