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
 * Locates the HTML field(s) that embed a matched image, and reads or rewrites
 * the image's alt text (its manually written description) inside them.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * A matched image's description ("alt text") does not live on the file - it
 * lives in the alt attribute of the <img> tag in whichever rich-text field the
 * image was embedded into (a page's content, a label's intro, a book chapter,
 * ...). That field is identified by the file's own component, filearea, itemid
 * and context, but the table and column vary per component, so this class holds
 * a curated map of the common core content areas and resolves the owning row.
 *
 * Everything here is best-effort: an image that is not embedded in a mapped
 * HTML field (a course file, an attachment, a user draft) simply resolves to no
 * locations, and reading its alt text yields nothing rather than an error.
 */
class htmllocator {
    /**
     * One resolved HTML field: the table, its text column, the row id and the
     * current stored HTML. Rewrites are written straight back to this row.
     *
     * @param \stdClass $item A matched item row.
     * @return \stdClass[] Zero or more objects {table, column, id, html}.
     */
    public static function locate(\stdClass $item): array {
        global $DB;

        $target = self::resolve_target($item);
        if ($target === null) {
            return [];
        }
        [$table, $column, $id] = $target;
        if ($id <= 0) {
            return [];
        }
        $html = $DB->get_field($table, $column, ['id' => $id], IGNORE_MISSING);
        if ($html === false || $html === null || $html === '') {
            return [];
        }
        return [(object) ['table' => $table, 'column' => $column, 'id' => (int) $id, 'html' => (string) $html]];
    }

    /**
     * Whether an image is embedded in mapped content via at least one <img>
     * tag whose alt attribute is empty or missing - i.e. it is displayed to
     * users without a description. An image that is not embedded in any mapped
     * field, or is embedded only with non-empty descriptions, is not flagged.
     *
     * @param \stdClass $item An object exposing component, filearea, contextid,
     *                        fileitemid and filename (a matched item, or a
     *                        matcher file row normalised to those names).
     * @return bool
     */
    public static function is_undescribed(\stdClass $item): bool {
        $filename = (string) $item->filename;
        foreach (self::locate($item) as $location) {
            // extract_alts returns the alt value of every <img> that references
            // this file (an empty string when the tag has no alt); a blank one
            // means the image is shown without a description.
            foreach (self::extract_alts($location->html, $filename) as $alt) {
                if (trim($alt) === '') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Resolve which table/column/row holds the HTML that embeds this file, or
     * null when the file's area is not a mapped rich-text field.
     *
     * @param \stdClass $item
     * @return array|null [string $table, string $column, int $id]
     */
    protected static function resolve_target(\stdClass $item): ?array {
        $component = (string) $item->component;
        $filearea = (string) $item->filearea;
        $itemid = (int) $item->fileitemid;
        $contextid = (int) $item->contextid;

        // Areas whose file itemid is the owning row's id directly.
        static $byitemid = [
            'mod_book:chapter'      => ['book_chapters', 'content'],
            'mod_lesson:page_contents' => ['lesson_pages', 'contents'],
            'mod_forum:post'        => ['forum_posts', 'message'],
            'mod_glossary:entry'    => ['glossary_entries', 'definition'],
            'question:questiontext' => ['question', 'questiontext'],
            'course:section'        => ['course_sections', 'summary'],
        ];
        $key = $component . ':' . $filearea;
        if (isset($byitemid[$key])) {
            [$table, $column] = $byitemid[$key];
            return [$table, $column, $itemid];
        }

        // Course summary: one row per course, found from the course context.
        if ($component === 'course' && $filearea === 'summary') {
            $courseid = self::course_id_from_context($contextid);
            return ['course', 'summary', $courseid];
        }

        // Module content and intro areas: one row per activity instance, found
        // from the module context. mod_page keeps its body in 'content'; every
        // module keeps its description in 'intro'.
        if (strpos($component, 'mod_') === 0) {
            $modname = substr($component, 4);
            if ($filearea === 'content' && $modname === 'page') {
                return ['page', 'content', self::module_instance_from_context($contextid, $modname)];
            }
            if ($filearea === 'intro') {
                return [$modname, 'intro', self::module_instance_from_context($contextid, $modname)];
            }
        }

        return null;
    }

    /**
     * The course module instance id for a module context, or 0.
     *
     * @param int $contextid
     * @param string $modname Expected module name (guards a mismatched map).
     * @return int
     */
    protected static function module_instance_from_context(int $contextid, string $modname): int {
        global $DB;
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel != CONTEXT_MODULE) {
            return 0;
        }
        $instance = $DB->get_field_sql(
            'SELECT cm.instance
               FROM {course_modules} cm
               JOIN {modules} md ON md.id = cm.module
              WHERE cm.id = :cmid AND md.name = :modname',
            ['cmid' => (int) $context->instanceid, 'modname' => $modname],
            IGNORE_MISSING
        );
        return $instance ? (int) $instance : 0;
    }

    /**
     * The course id for a course context, or 0.
     *
     * @param int $contextid
     * @return int
     */
    protected static function course_id_from_context(int $contextid): int {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel != CONTEXT_COURSE) {
            return 0;
        }
        return (int) $context->instanceid;
    }

    /**
     * Extract the alt texts of every <img> in an HTML string that references
     * the given file name. A file can be embedded more than once, so several
     * alt values may come back (deduplicated, blanks kept as empty strings).
     *
     * @param string $html
     * @param string $filename
     * @return string[] Alt values, in document order.
     */
    public static function extract_alts(string $html, string $filename): array {
        $alts = [];
        foreach (self::img_tags_for($html, $filename) as $tag) {
            $alts[] = self::tag_alt($tag);
        }
        return array_values(array_unique($alts));
    }

    /**
     * Rewrite the alt text of every <img> in an HTML string that references the
     * given file name, leaving the rest of the markup byte-for-byte unchanged
     * (only the matched tags' alt attributes are touched).
     *
     * @param string $html
     * @param string $filename
     * @param string $alt The new alt text (plain text; encoded for us).
     * @return array [string $html, int $changed] The new HTML and how many
     *               <img> tags were updated.
     */
    public static function set_alt(string $html, string $filename, string $alt): array {
        $changed = 0;
        $encoded = s($alt);
        $result = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($filename, $encoded, &$changed) {
            $tag = $m[0];
            if (!self::tag_references($tag, $filename)) {
                return $tag;
            }
            $newtag = self::tag_with_alt($tag, $encoded);
            if ($newtag !== $tag) {
                $changed++;
            }
            return $newtag;
        }, $html);
        // A null result means preg_replace_callback hit an internal error; keep
        // the original HTML rather than blanking the field if that ever happens.
        return [$result === null ? $html : $result, $changed];
    }

    /**
     * The <img> tags in an HTML string that reference the given file name.
     *
     * @param string $html
     * @param string $filename
     * @return string[] Raw tag strings.
     */
    protected static function img_tags_for(string $html, string $filename): array {
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
            return [];
        }
        $tags = [];
        foreach ($matches[0] as $tag) {
            if (self::tag_references($tag, $filename)) {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /**
     * Whether an <img> tag's src points at the given file name. The stored src
     * is a pluginfile placeholder such as "@@PLUGINFILE@@/My%20Image.jpg", so
     * the value is URL-decoded and compared on its final path segment.
     *
     * @param string $tag
     * @param string $filename
     * @return bool
     */
    protected static function tag_references(string $tag, string $filename): bool {
        if (!preg_match('/\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m)) {
            return false;
        }
        $src = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
        // Drop any query string or fragment, then decode percent-escapes.
        $src = preg_replace('/[?#].*$/', '', $src);
        $src = rawurldecode(html_entity_decode($src, ENT_QUOTES));
        $needle = '/' . $filename;
        return $src === $filename || substr($src, -strlen($needle)) === $needle;
    }

    /**
     * The decoded alt text of a single <img> tag ('' when it has none).
     *
     * @param string $tag
     * @return string
     */
    protected static function tag_alt(string $tag): string {
        if (!preg_match('/\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m)) {
            return '';
        }
        $value = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
        return html_entity_decode($value, ENT_QUOTES);
    }

    /**
     * Return a copy of an <img> tag with its alt attribute set to the given
     * (already HTML-encoded) value, replacing an existing alt or inserting one.
     *
     * @param string $tag
     * @param string $encodedalt Alt value, already passed through s().
     * @return string
     */
    protected static function tag_with_alt(string $tag, string $encodedalt): string {
        $replacement = 'alt="' . $encodedalt . '"';
        if (preg_match('/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag)) {
            return preg_replace('/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', $replacement, $tag, 1);
        }
        // No alt attribute yet: insert one just before the tag's closing >
        // (handling a self-closing "/>" too), preserving everything else.
        if (substr(rtrim($tag), -2) === '/>') {
            return preg_replace('/\s*\/>\s*$/', ' ' . $replacement . ' />', $tag, 1);
        }
        return preg_replace('/\s*>\s*$/', ' ' . $replacement . '>', $tag, 1);
    }
}
