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
 * Shared test fixture: create a replace job record and its replacement source.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Builds a replace job row (and stores a single replacement image) for the
 * engine and task tests, so they do not each repeat the same fixture.
 */
trait replace_job_maker {
    /**
     * Insert a replace job with sensible defaults and store a single-mode
     * replacement image for it.
     *
     * @param array $overrides Fields to override on the default job record.
     * @param string $replacementcontent Content of the stored replacement image.
     * @return \stdClass The reloaded job record.
     */
    protected function make_replace_job_record(array $overrides, string $replacementcontent): \stdClass {
        global $DB, $USER;

        $now = time();
        $defaults = [
            'name'         => 'Test replace',
            'jobtype'      => 'replace',
            'status'       => manager::STATUS_QUEUED,
            'criteria'     => json_encode(['imageonly' => true, 'component' => 'mod_label', 'filearea' => 'intro']),
            'csvmode'      => 'none',
            'namingrule'   => '{originalname}',
            'replacemode'  => 'single',
            'backup'       => 1,
            'missingonly'  => 0,
            'dedupe'       => 0,
            'volumesize'   => 1048576,
            'usermodified' => $USER->id,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $job = (object) array_merge($defaults, $overrides);
        $job->id = $DB->insert_record('tool_imageextractor_job', $job);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => manager::COMPONENT,
            'filearea'  => 'replacement',
            'itemid'    => $job->id,
            'filepath'  => '/',
            'filename'  => 'new.png',
        ], $replacementcontent);

        return $DB->get_record('tool_imageextractor_job', ['id' => $job->id]);
    }
}
