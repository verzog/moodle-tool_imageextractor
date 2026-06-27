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
 * Parses an uploaded CSV into criteria fragments.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Reads an uploaded CSV and converts it, according to the chosen mode, into
 * criteria the matcher understands.
 *
 * Three modes are supported:
 *  - scope    : a list of course and/or user identifiers to limit the search;
 *  - match    : a list of exact filenames and/or content hashes to pull;
 *  - criteria : one search specification per row (OR'd by the matcher), each
 *               row optionally carrying its own output-name template.
 */
class csv_importer {
    /**
     * Parse raw CSV text into rows of header => value maps.
     *
     * The first non-empty line is treated as the header. Header names are
     * lower-cased and stripped of spaces and underscores so "Course ID",
     * "courseid" and "course_id" all match.
     *
     * @param string $content Raw CSV text.
     * @return array List of associative rows.
     */
    public static function parse_rows(string $content): array {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = array_values(array_filter(explode("\n", $content), static function ($line) {
            return trim($line) !== '';
        }));
        if (!$lines) {
            return [];
        }

        $header = array_map(static function ($h) {
            return preg_replace('/[\s_]+/', '', \core_text::strtolower(trim($h)));
        }, str_getcsv(array_shift($lines)));

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $row[$key] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
            }
            $row['__first'] = isset($cells[0]) ? trim((string) $cells[0]) : '';
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Convert parsed CSV rows into criteria fragments for the given mode.
     *
     * @param array $rows Output of parse_rows().
     * @param string $mode One of 'scope', 'match' or 'criteria'.
     * @return array ['criteria' => array, 'warnings' => string[]]
     */
    public static function to_criteria(array $rows, string $mode): array {
        switch ($mode) {
            case 'scope':
                return self::scope_criteria($rows);
            case 'match':
                return self::match_criteria($rows);
            case 'criteria':
                return self::row_criteria($rows);
            default:
                return ['criteria' => [], 'warnings' => []];
        }
    }

    /**
     * Build scope criteria (course and/or user id lists) from rows.
     *
     * @param array $rows
     * @return array
     */
    protected static function scope_criteria(array $rows): array {
        $courseids = [];
        $userids = [];
        $warnings = [];

        foreach ($rows as $row) {
            $coursekeys = ['courseid', 'course', 'courseshortname', 'shortname', 'courseidnumber', 'idnumber'];
            $courseref = self::first_value($row, $coursekeys);
            $userref = self::first_value($row, ['userid', 'user', 'username', 'useremail', 'email']);

            // Fall back to the first column when no recognised header is present.
            if ($courseref === '' && $userref === '') {
                $courseref = (string) ($row['__first'] ?? '');
            }

            if ($courseref !== '') {
                $id = self::resolve_course($courseref);
                if ($id) {
                    $courseids[] = $id;
                } else {
                    $warnings[] = get_string('csvunknowncourse', 'tool_imageextractor', s($courseref));
                }
            }
            if ($userref !== '') {
                $id = self::resolve_user($userref);
                if ($id) {
                    $userids[] = $id;
                } else {
                    $warnings[] = get_string('csvunknownuser', 'tool_imageextractor', s($userref));
                }
            }
        }

        $criteria = [];
        if ($courseids) {
            $criteria['courseids'] = array_values(array_unique($courseids));
        }
        if ($userids) {
            $criteria['userids'] = array_values(array_unique($userids));
        }
        return ['criteria' => $criteria, 'warnings' => $warnings];
    }

    /**
     * Build match criteria (filename and/or content-hash lists) from rows.
     *
     * @param array $rows
     * @return array
     */
    protected static function match_criteria(array $rows): array {
        $filenames = [];
        $hashes = [];

        foreach ($rows as $row) {
            $name = self::first_value($row, ['filename', 'name', 'file']);
            $hash = self::first_value($row, ['contenthash', 'hash', 'sha1']);

            if ($name === '' && $hash === '') {
                // A single bare column: a 40-char hex string is a content
                // hash, anything else is treated as a filename.
                $first = (string) ($row['__first'] ?? '');
                if (preg_match('/^[0-9a-f]{40}$/i', $first)) {
                    $hash = $first;
                } else if ($first !== '') {
                    $name = $first;
                }
            }

            if ($name !== '') {
                $filenames[] = $name;
            }
            if ($hash !== '') {
                $hashes[] = \core_text::strtolower($hash);
            }
        }

        $criteria = [];
        if ($filenames) {
            $criteria['filenames'] = array_values(array_unique($filenames));
        }
        if ($hashes) {
            $criteria['contenthashes'] = array_values(array_unique($hashes));
        }
        return ['criteria' => $criteria, 'warnings' => []];
    }

    /**
     * Build per-row criteria groups from rows.
     *
     * @param array $rows
     * @return array
     */
    protected static function row_criteria(array $rows): array {
        $groups = [];
        $warnings = [];

        foreach ($rows as $row) {
            $group = [];
            if (($v = self::first_value($row, ['component'])) !== '') {
                $group['component'] = $v;
            }
            if (($v = self::first_value($row, ['filearea', 'area'])) !== '') {
                $group['filearea'] = $v;
            }
            if (($v = self::first_value($row, ['filename', 'filenamepattern', 'name', 'pattern'])) !== '') {
                $group['filenamepattern'] = $v;
            }
            if (($v = self::first_value($row, ['mimetype', 'mime'])) !== '') {
                $group['mimetypes'] = [$v];
            }
            if (($v = self::first_value($row, ['minsize', 'min'])) !== '') {
                $group['minsize'] = (int) $v;
            }
            if (($v = self::first_value($row, ['maxsize', 'max'])) !== '') {
                $group['maxsize'] = (int) $v;
            }
            if (($v = self::first_value($row, ['courseid', 'course'])) !== '') {
                $id = self::resolve_course($v);
                if ($id) {
                    $group['courseids'] = [$id];
                } else {
                    $warnings[] = get_string('csvunknowncourse', 'tool_imageextractor', s($v));
                }
            }

            if ($group) {
                $groups[] = $group;
            }
        }

        $criteria = $groups ? ['rows' => $groups] : [];
        return ['criteria' => $criteria, 'warnings' => $warnings];
    }

    /**
     * Return the first non-empty value among the given header keys.
     *
     * @param array $row
     * @param string[] $keys
     * @return string
     */
    protected static function first_value(array $row, array $keys): string {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim($row[$key]) !== '') {
                return trim($row[$key]);
            }
        }
        return '';
    }

    /**
     * Resolve a course identifier (id, shortname or idnumber) to a course id.
     *
     * @param string $ref
     * @return int Course id, or 0 if it could not be resolved.
     */
    protected static function resolve_course(string $ref): int {
        global $DB;
        $ref = trim($ref);
        if ($ref === '') {
            return 0;
        }
        if (ctype_digit($ref)) {
            if ($DB->record_exists('course', ['id' => (int) $ref])) {
                return (int) $ref;
            }
        }
        if ($id = $DB->get_field('course', 'id', ['shortname' => $ref])) {
            return (int) $id;
        }
        if ($id = $DB->get_field('course', 'id', ['idnumber' => $ref])) {
            return (int) $id;
        }
        return 0;
    }

    /**
     * Resolve a user identifier (id, username or email) to a user id.
     *
     * @param string $ref
     * @return int User id, or 0 if it could not be resolved.
     */
    protected static function resolve_user(string $ref): int {
        global $DB;
        $ref = trim($ref);
        if ($ref === '') {
            return 0;
        }
        if (ctype_digit($ref)) {
            if ($DB->record_exists('user', ['id' => (int) $ref])) {
                return (int) $ref;
            }
        }
        if ($id = $DB->get_field('user', 'id', ['username' => \core_text::strtolower($ref)])) {
            return (int) $id;
        }
        if ($id = $DB->get_field('user', 'id', ['email' => $ref])) {
            return (int) $id;
        }
        return 0;
    }
}
