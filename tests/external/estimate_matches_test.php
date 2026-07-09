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
 * Tests for the estimate_matches web service.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor\external;

/**
 * Tests for the estimate_matches external function.
 *
 * @covers \tool_imageextractor\external\estimate_matches
 */
final class estimate_matches_test extends \core_external\tests\externallib_testcase {
    /**
     * Default (empty) arguments for the web service, overridden per test.
     *
     * @param array $overrides
     * @return array
     */
    protected function args(array $overrides = []): array {
        return $overrides + [
            'imageonly'       => true,
            'dedupe'          => false,
            'component'       => '',
            'filearea'        => '',
            'filenamepattern' => '',
            'mimetypes'       => '',
            'minsizekb'       => 0,
            'maxsizekb'       => 0,
            'datefrom'        => 0,
            'dateto'          => 0,
            'courseids'       => [],
            'categoryids'     => [],
        ];
    }

    /**
     * Call the service with the given arguments and return the cleaned result.
     *
     * @param array $args
     * @return array
     */
    protected function call(array $args): array {
        $result = estimate_matches::execute(
            $args['imageonly'],
            $args['dedupe'],
            $args['component'],
            $args['filearea'],
            $args['filenamepattern'],
            $args['mimetypes'],
            $args['minsizekb'],
            $args['maxsizekb'],
            $args['datefrom'],
            $args['dateto'],
            $args['courseids'],
            $args['categoryids']
        );
        return \core_external\external_api::clean_returnvalue(estimate_matches::execute_returns(), $result);
    }

    /**
     * Create a stored image file in a context.
     *
     * @param int $contextid
     * @param string $filename
     * @param string $content
     * @return void
     */
    protected function make_file(int $contextid, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $content);
    }

    /**
     * The service counts matching files and reports their total size, honouring
     * course scope.
     */
    public function test_estimate_counts_and_scopes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->make_file(\context_course::instance($course->id)->id, 'in.png', 'AAAA');
        $this->make_file(\context_system::instance()->id, 'out.png', 'BBBB');

        // Both image files, scoped to our component/area.
        $all = $this->call($this->args(['component' => 'mod_label', 'filearea' => 'intro']));
        $this->assertSame(2, $all['count']);
        $this->assertSame(8, $all['bytes']);
        $this->assertIsString($all['formattedsize']);

        // Scoped to the course, only the in-course file is counted.
        $scoped = $this->call($this->args([
            'component' => 'mod_label',
            'filearea'  => 'intro',
            'courseids' => [$course->id],
        ]));
        $this->assertSame(1, $scoped['count']);
        $this->assertSame(4, $scoped['bytes']);
    }

    /**
     * Without the manage capability the service is refused.
     */
    public function test_estimate_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        $this->call($this->args());
    }
}
