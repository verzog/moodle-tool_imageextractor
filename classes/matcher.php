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
 * Builds and runs the file-storage query for an extraction job.
 *
 * @package    tool_imageextractor
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace tool_imageextractor;

/**
 * Translates a job's decoded criteria into a query over the {files} table.
 *
 * The matcher never deletes or mutates anything - it only selects file rows
 * to be copied into the export. Directories (filename '.') and zero-byte
 * placeholder rows are always excluded.
 */
class matcher {
    /** @var array Decoded criteria for the job. */
    protected $criteria;

    /** @var bool Whether to collapse duplicate content to one file. */
    protected $dedupe;

    /** @var int Running counter giving each bound param a unique name. */
    protected $paramseq = 0;

    /**
     * Constructor.
     *
     * @param array $criteria Decoded criteria (see manager::default_criteria()).
     * @param bool $dedupe If true, only one file per content hash is matched.
     */
    public function __construct(array $criteria, bool $dedupe = true) {
        $this->criteria = $criteria;
        $this->dedupe = $dedupe;
    }

    /**
     * Produce a fresh unique placeholder name.
     *
     * @param string $prefix
     * @return string
     */
    protected function param(string $prefix): string {
        $this->paramseq++;
        return $prefix . $this->paramseq;
    }

    /**
     * Build the WHERE clause and bound parameters for the whole job.
     *
     * @return array [string $where, array $params]
     */
    public function get_where(): array {
        global $DB;

        $clauses = [];
        $params = [];

        // Never export directory rows or zero-byte placeholders, and require
        // a real component so transient internal rows are skipped.
        $clauses[] = "f.filename <> '.'";
        $clauses[] = 'f.filesize > 0';
        $clauses[] = "f.component <> ''";

        // Base criteria always apply.
        [$basewhere, $baseparams] = $this->build_group($this->criteria, 'b');
        if ($basewhere !== '') {
            $clauses[] = $basewhere;
            $params += $baseparams;
        }

        // Per-row criteria groups (CSV "per-row criteria" mode) are OR'd
        // together and AND'd onto the base criteria.
        if (!empty($this->criteria['rows']) && is_array($this->criteria['rows'])) {
            $orclauses = [];
            foreach (array_values($this->criteria['rows']) as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                [$rowwhere, $rowparams] = $this->build_group($row, 'r' . $i . '_');
                if ($rowwhere !== '') {
                    $orclauses[] = '(' . $rowwhere . ')';
                    $params += $rowparams;
                }
            }
            if ($orclauses) {
                $clauses[] = '(' . implode(' OR ', $orclauses) . ')';
            }
        }

        unset($DB);
        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Build the WHERE fragment for one criteria group (base or a CSV row).
     *
     * @param array $c Criteria for this group.
     * @param string $prefix Param-name prefix keeping this group's params distinct.
     * @return array [string $where, array $params]
     */
    protected function build_group(array $c, string $prefix): array {
        global $DB;

        $clauses = [];
        $params = [];

        // Restrict to images unless the job explicitly opts out.
        if (!array_key_exists('imageonly', $c) || !empty($c['imageonly'])) {
            $p = $this->param($prefix . 'img');
            $clauses[] = $DB->sql_like('f.mimetype', ':' . $p, false);
            $params[$p] = 'image/%';
        }

        // Explicit mime-type list (each entry matched as a prefix).
        if (!empty($c['mimetypes']) && is_array($c['mimetypes'])) {
            $mimeors = [];
            foreach ($c['mimetypes'] as $mime) {
                $mime = trim((string) $mime);
                if ($mime === '') {
                    continue;
                }
                $p = $this->param($prefix . 'mime');
                $mimeors[] = $DB->sql_like('f.mimetype', ':' . $p, false);
                $params[$p] = $DB->sql_like_escape($mime) . '%';
            }
            if ($mimeors) {
                $clauses[] = '(' . implode(' OR ', $mimeors) . ')';
            }
        }

        if (!empty($c['component'])) {
            $p = $this->param($prefix . 'comp');
            $clauses[] = 'f.component = :' . $p;
            $params[$p] = (string) $c['component'];
        }

        if (!empty($c['filearea'])) {
            $p = $this->param($prefix . 'area');
            $clauses[] = 'f.filearea = :' . $p;
            $params[$p] = (string) $c['filearea'];
        }

        if (!empty($c['filenamepattern'])) {
            // Escape the value so any real % or _ are literal, then turn the
            // user-supplied '*' (which sql_like_escape leaves untouched) into
            // the SQL wildcard.
            $pattern = str_replace('*', '%', $DB->sql_like_escape((string) $c['filenamepattern']));
            $p = $this->param($prefix . 'fn');
            $clauses[] = $DB->sql_like('f.filename', ':' . $p, false);
            $params[$p] = $pattern;
        }

        if (isset($c['minsize']) && $c['minsize'] !== '' && (int) $c['minsize'] > 0) {
            $p = $this->param($prefix . 'min');
            $clauses[] = 'f.filesize >= :' . $p;
            $params[$p] = (int) $c['minsize'];
        }

        if (isset($c['maxsize']) && $c['maxsize'] !== '' && (int) $c['maxsize'] > 0) {
            $p = $this->param($prefix . 'max');
            $clauses[] = 'f.filesize <= :' . $p;
            $params[$p] = (int) $c['maxsize'];
        }

        if (!empty($c['datefrom'])) {
            $p = $this->param($prefix . 'df');
            $clauses[] = 'f.timecreated >= :' . $p;
            $params[$p] = (int) $c['datefrom'];
        }

        if (!empty($c['dateto'])) {
            $p = $this->param($prefix . 'dt');
            $clauses[] = 'f.timecreated <= :' . $p;
            $params[$p] = (int) $c['dateto'];
        }

        // Uploader scope (CSV scope mode, user identifiers).
        if (!empty($c['userids']) && is_array($c['userids'])) {
            $ids = array_values(array_unique(array_map('intval', $c['userids'])));
            if ($ids) {
                [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, $prefix . 'uid');
                $clauses[] = 'f.userid ' . $insql;
                $params += $inparams;
            }
        }

        // Exact filename and/or content-hash match lists (CSV match mode). A
        // match list pulls files whose filename OR content hash is listed, so
        // the two predicates are combined with OR, not AND.
        $matchors = [];
        if (!empty($c['filenames']) && is_array($c['filenames'])) {
            $names = array_values(array_unique(array_filter(array_map('strval', $c['filenames']), 'strlen')));
            if ($names) {
                [$insql, $inparams] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED, $prefix . 'name');
                $matchors[] = 'f.filename ' . $insql;
                $params += $inparams;
            }
        }
        if (!empty($c['contenthashes']) && is_array($c['contenthashes'])) {
            $hashes = array_values(array_unique(array_filter(array_map('strval', $c['contenthashes']), 'strlen')));
            if ($hashes) {
                [$insql, $inparams] = $DB->get_in_or_equal($hashes, SQL_PARAMS_NAMED, $prefix . 'hash');
                $matchors[] = 'f.contenthash ' . $insql;
                $params += $inparams;
            }
        }
        if ($matchors) {
            $clauses[] = '(' . implode(' OR ', $matchors) . ')';
        }

        // Location scope: files may be limited to given courses and/or course
        // categories (from the form, or a CSV scope list of course ids). A file
        // belongs to a course/category when its context is that context or any
        // context nested beneath it - subcategories, courses, activities,
        // blocks. Path containment captures all of them in one set-based test.
        // Course and category scope are OR'd together, so picking both widens
        // (union) the result rather than narrowing it.
        $locationors = [];
        $courseexists = $this->context_scope_exists($c['courseids'] ?? null, CONTEXT_COURSE, $prefix . 'cid', $params);
        if ($courseexists !== '') {
            $locationors[] = $courseexists;
        }
        $catexists = $this->context_scope_exists($c['categoryids'] ?? null, CONTEXT_COURSECAT, $prefix . 'cat', $params);
        if ($catexists !== '') {
            $locationors[] = $catexists;
        }
        if ($locationors) {
            $clauses[] = '(' . implode(' OR ', $locationors) . ')';
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Build an EXISTS test matching files whose context is one of the given
     * instances at a context level, or nested beneath it via path containment.
     *
     * @param mixed $rawids Array of instance ids (course or category), or null.
     * @param int $contextlevel CONTEXT_COURSE or CONTEXT_COURSECAT.
     * @param string $paramprefix Unique prefix for this group's bound params.
     * @param array $params Bound-param accumulator, appended to by reference.
     * @return string SQL EXISTS fragment, or '' when there are no usable ids.
     */
    protected function context_scope_exists($rawids, int $contextlevel, string $paramprefix, array &$params): string {
        global $DB;

        if (empty($rawids) || !is_array($rawids)) {
            return '';
        }
        // Drop 0/negative ids: course 0 is the site and a 0 category is "none";
        // neither is a meaningful scope and would otherwise match nothing.
        $ids = array_values(array_unique(array_filter(array_map('intval', $rawids), fn($id) => $id > 0)));
        if (!$ids) {
            return '';
        }

        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, $paramprefix);
        $pathlike = $DB->sql_concat('cc.path', "'/%'");
        $params += $inparams;

        return "EXISTS (
            SELECT 1
              FROM {context} cc
              JOIN {context} fc ON fc.id = f.contextid
             WHERE cc.contextlevel = $contextlevel
               AND cc.instanceid $insql
               AND (fc.id = cc.id OR fc.path LIKE $pathlike)
        )";
    }

    /**
     * Estimate how many files (and how many bytes) the job will export.
     *
     * @return array ['count' => int, 'bytes' => int]
     */
    public function estimate(): array {
        global $DB;
        [$where, $params] = $this->get_where();

        if ($this->dedupe) {
            // One row per content hash; filesize is constant for a hash, so
            // grouping by both is safe on PostgreSQL too.
            $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(sub.filesize), 0) AS bytes
                      FROM (
                            SELECT f.filesize
                              FROM {files} f
                             WHERE $where
                          GROUP BY f.contenthash, f.filesize
                           ) sub";
        } else {
            $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(f.filesize), 0) AS bytes
                      FROM {files} f
                     WHERE $where";
        }
        $rec = $DB->get_record_sql($sql, $params);
        return [
            'count' => (int) ($rec->cnt ?? 0),
            'bytes' => (int) ($rec->bytes ?? 0),
        ];
    }

    /**
     * Stream the matched file rows.
     *
     * When $ordered, rows are sorted by content hash then id so the caller can
     * collapse duplicates (extract's dedupe) by skipping repeats of a hash.
     * That sort forces the database to materialise and order the ENTIRE
     * matched set before the first row is returned - a heavy, disk-spilling
     * operation on large sites - so callers that do not need it (the replace
     * paths) must pass false to stream rows as the database finds them.
     *
     * @param bool $ordered Sort by content hash for duplicate collapsing.
     * @return \moodle_recordset
     */
    public function get_recordset(bool $ordered = true): \moodle_recordset {
        global $DB;
        [$where, $params] = $this->get_where();
        $orderby = $ordered ? ' ORDER BY f.contenthash, f.id' : '';
        $sql = "SELECT f.id, f.contenthash, f.filename, f.filepath, f.filesize, f.mimetype,
                       f.contextid, f.component, f.filearea, f.itemid, f.userid,
                       f.timecreated, f.author, f.license
                  FROM {files} f
                 WHERE $where
{$orderby}";
        return $DB->get_recordset_sql($sql, $params);
    }

    /**
     * Fetch one keyset page of matched file rows after a cursor, so a large
     * match can be processed a bounded batch per cron run instead of scanning
     * the whole file table in a single pass.
     *
     * The cursor is the last row returned by the previous page. When $ordered
     * (extract's duplicate collapsing) the keyset is (contenthash, id) so the
     * hash grouping the caller relies on is preserved across pages; otherwise
     * it is just id. A short page (fewer than $limit rows) means the scan is
     * exhausted.
     *
     * @param int $afterid Cursor file id (0 to start).
     * @param string $afterhash Cursor content hash ('' to start); used only when $ordered.
     * @param int $limit Maximum rows to return.
     * @param bool $ordered Order by (contenthash, id) for duplicate collapsing.
     * @return array Rows keyed by file id, in scan order.
     */
    public function get_page(int $afterid, string $afterhash, int $limit, bool $ordered): array {
        global $DB;
        [$where, $params] = $this->get_where();

        if ($ordered) {
            // Resume strictly after the (contenthash, id) cursor.
            $where .= ' AND (f.contenthash > :cah1 OR (f.contenthash = :cah2 AND f.id > :caid))';
            $params['cah1'] = $afterhash;
            $params['cah2'] = $afterhash;
            $params['caid'] = $afterid;
            $orderby = 'ORDER BY f.contenthash, f.id';
        } else {
            $where .= ' AND f.id > :caid';
            $params['caid'] = $afterid;
            $orderby = 'ORDER BY f.id';
        }

        $sql = "SELECT f.id, f.contenthash, f.filename, f.filepath, f.filesize, f.mimetype,
                       f.contextid, f.component, f.filearea, f.itemid, f.userid,
                       f.timecreated, f.author, f.license
                  FROM {files} f
                 WHERE $where
                 $orderby";
        return $DB->get_records_sql($sql, $params, 0, $limit);
    }
}
