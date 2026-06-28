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
 * Renders output file names from a naming-rule template.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Turns a template such as "{courseshortname}_{seq}_{originalname}" into a
 * safe, unique output filename for a matched image.
 */
class naming {
    /**
     * The placeholders a naming rule may use.
     *
     * @return string[]
     */
    public static function placeholders(): array {
        return [
            'originalname', 'originalbase', 'ext', 'fileid', 'contenthash',
            'contenthash8', 'component', 'filearea', 'itemid', 'courseid',
            'coursename', 'courseshortname', 'uploaderid', 'mimetype', 'seq',
            'date',
        ];
    }

    /**
     * Render a naming template for one file.
     *
     * The result is always cleaned to a safe filename and always carries the
     * original file extension if the template did not produce one.
     *
     * @param string $template The naming rule.
     * @param array $context Values keyed by placeholder name.
     * @return string A cleaned filename (without any directory part).
     */
    public static function render(string $template, array $context): string {
        $original = (string) ($context['originalname'] ?? '');
        $ext = '';
        if (($dotpos = strrpos($original, '.')) !== false) {
            $ext = substr($original, $dotpos + 1);
        }
        $base = $ext !== '' ? substr($original, 0, strrpos($original, '.')) : $original;

        $hash = (string) ($context['contenthash'] ?? '');
        $values = [
            'originalname'    => $original,
            'originalbase'    => $base,
            'ext'             => $ext,
            'fileid'          => (string) ($context['fileid'] ?? ''),
            'contenthash'     => $hash,
            'contenthash8'    => substr($hash, 0, 8),
            'component'       => (string) ($context['component'] ?? ''),
            'filearea'        => (string) ($context['filearea'] ?? ''),
            'itemid'          => (string) ($context['itemid'] ?? ''),
            'courseid'        => (string) ($context['courseid'] ?? ''),
            'coursename'      => (string) ($context['coursename'] ?? ''),
            'courseshortname' => (string) ($context['courseshortname'] ?? ''),
            'uploaderid'      => (string) ($context['uploaderid'] ?? ''),
            'mimetype'        => (string) ($context['mimetype'] ?? ''),
            'seq'             => (string) ($context['seq'] ?? ''),
            'date'            => (string) ($context['date'] ?? ''),
        ];

        $name = $template;
        foreach ($values as $key => $value) {
            // Clean each substituted value so a stray slash or control
            // character in course data cannot escape the filename.
            $name = str_replace('{' . $key . '}', clean_param($value, PARAM_FILE), $name);
        }

        // Drop any leftover unknown placeholders rather than leaving braces.
        $name = preg_replace('/\{[a-z0-9_]+\}/i', '', $name);
        $name = clean_param($name, PARAM_FILE);

        if ($name === '') {
            $name = 'image-' . ($values['fileid'] !== '' ? $values['fileid'] : $values['contenthash8']);
        }

        // Re-attach the original extension if the rendered name lost it.
        if ($ext !== '' && strtolower(substr($name, -(strlen($ext) + 1))) !== '.' . strtolower($ext)) {
            $name .= '.' . clean_param($ext, PARAM_FILE);
        }

        return $name;
    }

    /**
     * Guarantee the name is unique within a batch by appending a counter.
     *
     * @param string $name The candidate name.
     * @param array $seen Map of already-used lowercase names (modified in place).
     * @return string A name not present in $seen.
     */
    public static function ensure_unique(string $name, array &$seen): string {
        $key = \core_text::strtolower($name);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            return $name;
        }

        $ext = '';
        $base = $name;
        if (($dotpos = strrpos($name, '.')) !== false) {
            $ext = substr($name, $dotpos);
            $base = substr($name, 0, $dotpos);
        }

        $counter = 1;
        do {
            $candidate = $base . '_' . $counter . $ext;
            $key = \core_text::strtolower($candidate);
            $counter++;
        } while (isset($seen[$key]));

        $seen[$key] = true;
        return $candidate;
    }
}
