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
 * Tests for the resumable chunked-upload assembler.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Tests for the resumable chunked-upload assembler.
 *
 * @covers \tool_imageextractor\chunk_uploader
 */
final class chunk_uploader_test extends \advanced_testcase {
    /**
     * Insert a bare replace job and return its id.
     *
     * @return int
     */
    protected function make_job(): int {
        global $DB, $USER;
        return (int) $DB->insert_record('tool_imageextractor_job', (object) [
            'name' => 'Chunk', 'jobtype' => 'replace', 'status' => manager::STATUS_REVIEW,
            'csvmode' => 'none', 'replacemode' => 'zip', 'usermodified' => $USER->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Split a string into $n roughly equal chunks.
     *
     * @param string $data
     * @param int $n
     * @return string[]
     */
    protected function split(string $data, int $n): array {
        $size = (int) ceil(strlen($data) / $n);
        return str_split($data, max(1, $size));
    }

    /**
     * A ZIP uploaded in chunks is reassembled and unpacked into the job's
     * replacement area, matched by basename.
     */
    public function test_chunked_zip_is_assembled_and_unpacked(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Build a real ZIP holding one image, then upload it in three chunks.
        $tempdir = make_request_directory();
        $zippath = $tempdir . '/set.zip';
        get_file_packer('application/zip')->archive_to_pathname(['logo.png' => ['PNGDATA']], $zippath);
        $zipbytes = file_get_contents($zippath);

        $jobid = $this->make_job();
        $upload = chunk_uploader::start($jobid, 'set.zip', strlen($zipbytes));
        $index = 0;
        foreach ($this->split($zipbytes, 3) as $chunk) {
            $state = chunk_uploader::store_chunk($upload->token, $index++, $chunk);
        }
        $this->assertSame(strlen($zipbytes), (int) $state->uploadedbytes);
        $this->assertSame(3, (int) $state->chunks);

        chunk_uploader::finish($upload->token);

        // The image is now in the replacement area (under an isolating folder),
        // and the upload session and its chunks are gone.
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            manager::COMPONENT,
            'replacement',
            $jobid,
            'filename',
            false
        );
        $names = array_map(static fn($f) => $f->get_filename(), $files);
        $this->assertContains('logo.png', $names);
        global $DB;
        $this->assertSame(0, $DB->count_records('tool_imageextractor_upload'));
        $this->assertEmpty($fs->get_area_files(
            \context_system::instance()->id,
            manager::COMPONENT,
            chunk_uploader::CHUNK_AREA,
            $upload->id,
            'id',
            false
        ));
    }

    /**
     * A non-ZIP upload becomes the single replacement image.
     */
    public function test_chunked_single_image(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $jobid = $this->make_job();
        $upload = chunk_uploader::start($jobid, 'brand.png', 6);
        chunk_uploader::store_chunk($upload->token, 0, 'PNG');
        chunk_uploader::store_chunk($upload->token, 1, 'DAT');
        chunk_uploader::finish($upload->token);

        $stored = get_file_storage()->get_file(
            \context_system::instance()->id,
            manager::COMPONENT,
            'replacement',
            $jobid,
            '/',
            'brand.png'
        );
        $this->assertNotFalse($stored);
        $this->assertSame('PNGDAT', $stored->get_content());
    }

    /**
     * Re-sending a chunk at the same index is idempotent (no double counting).
     */
    public function test_chunk_retry_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $jobid = $this->make_job();
        $upload = chunk_uploader::start($jobid, 'x.zip', 6);
        chunk_uploader::store_chunk($upload->token, 0, 'ABC');
        $state = chunk_uploader::store_chunk($upload->token, 0, 'ABC');
        $this->assertSame(3, (int) $state->uploadedbytes);
        $this->assertSame(1, (int) $state->chunks);
    }

    /**
     * An upload session cannot be driven by a different user.
     */
    public function test_session_ownership_enforced(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $jobid = $this->make_job();
        $upload = chunk_uploader::start($jobid, 'x.zip', 3, (int) $owner->id);

        $this->expectException(\moodle_exception::class);
        chunk_uploader::store_chunk($upload->token, 0, 'ABC', (int) $other->id);
    }

    /**
     * Stale sessions are purged with their chunks.
     */
    public function test_purge_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $jobid = $this->make_job();
        $upload = chunk_uploader::start($jobid, 'x.zip', 3);
        chunk_uploader::store_chunk($upload->token, 0, 'ABC');
        // Backdate the session so it counts as abandoned.
        $DB->set_field('tool_imageextractor_upload', 'timemodified', time() - DAYSECS - 10, ['id' => $upload->id]);

        $this->assertSame(1, chunk_uploader::purge_stale(time() - DAYSECS));
        $this->assertSame(0, $DB->count_records('tool_imageextractor_upload'));
    }
}
