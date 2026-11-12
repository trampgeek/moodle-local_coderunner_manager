<?php
// This file is part of CodeRunner - http://coderunner.org.nz
//
// CodeRunner is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// CodeRunner is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with CodeRunner.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Converting CodeRunner 5 question options to CodeRunner 6 form.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use core_question\local\bank\question_version_status;
use qtype_coderunner\v6\legacy_converter;

/**
 * Gives every CodeRunner question a prototype id and an overrides document.
 *
 * Each question's prototype is found once, by CodeRunner 5's name rules, and
 * mapped to the prototype the snapshot created from it. The old option columns
 * are left exactly as they are, so the result can be verified against them and
 * the migration re-run if a fault is found later.
 *
 * Resolution is done from indexes built once rather than by calling
 * get_prototype() per question, which costs several queries each. One site has
 * reported 410,000 questions, and every version of every question has an
 * options row, so per-question queries are not an option.
 */
class question_migrator {
    /** @var string The question type's options table. */
    const OPTIONS = 'question_coderunner_options';

    /** @var int How many rows to convert before writing a batch. */
    const BATCH_SIZE = 500;

    /** @var array Prototype questions, keyed by name then context id. */
    private array $prototypesbyname = [];

    /** @var array Manager prototype ids, keyed by name then course id. */
    private array $managerbyname = [];

    /** @var array The prototype context ids to search, keyed by course id. */
    private array $contextsbycourse = [];

    /** @var array The course id of each question context, keyed by context id. */
    private array $coursebycontext = [];

    /** @var array A tally of what the last run did. */
    private array $counts = [];

    /**
     * What the last run did.
     *
     * @return array counts keyed by outcome.
     */
    public function counts(): array {
        return $this->counts;
    }

    /**
     * Convert every question that has not been converted yet.
     *
     * Re-running is safe: a question whose overrides document is already
     * present is skipped unless $force is set, so a partial run can be resumed
     * and a corrected converter can be re-applied deliberately.
     *
     * @param bool $force true to reconvert questions that already have an
     *      overrides document, which is how a corrected migration is re-run.
     * @param callable|null $progress called with (done, total) between batches.
     * @return array the counts for this run.
     */
    public function migrate(bool $force = false, ?callable $progress = null): array {
        global $DB;

        $this->counts = ['converted' => 0, 'broken' => 0, 'skipped' => 0, 'reasons' => []];
        $this->build_indexes();

        // Two kinds of row are wanted. One has no overrides document and has
        // never been converted. The other has a document but no prototype id,
        // which is a question whose name could not be resolved when it was
        // written -- and the case that matters is a course restored from a
        // pre-6.0 backup, where the questions are converted as they arrive but
        // the course's own prototype questions have not yet been snapshotted
        // into the manager, so there is nothing of that name to resolve
        // against. Once the snapshot has run, the reference can be made.
        //
        // A question that is simply broken -- no prototype of its name
        // anywhere -- is in the second set too, and is re-examined on every
        // run. That is cheap and it is the honest reading: nothing else
        // distinguishes a question waiting for its prototype from one that
        // will never have it.
        $pending = 'overrides IS NULL OR prototypeid IS NULL';
        $total = $DB->count_records_select(
            self::OPTIONS,
            'prototypetype = 0' . ($force ? '' : " AND ($pending)")
        );
        if ($total === 0) {
            return $this->counts;
        }

        $select = 'qco.prototypetype = 0' . ($force ? '' : " AND (qco.$pending)");
        $rs = $DB->get_recordset_sql("
            SELECT qco.*, qc.contextid
              FROM {" . self::OPTIONS . "} qco
              JOIN {question} q ON qco.questionid = q.id
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qv.questionbankentryid = qbe.id
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE $select
          ORDER BY qco.id");

        $batch = [];
        $done = 0;
        foreach ($rs as $row) {
            $batch[] = $this->convert_row($row, $force);
            $done++;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->write_batch($batch);
                $batch = [];
                if ($progress) {
                    $progress($done, $total);
                }
            }
        }
        $rs->close();

        if ($batch) {
            $this->write_batch($batch);
        }
        if ($progress) {
            $progress($done, $total);
        }

        return $this->counts;
    }

    /**
     * Convert one options row, without writing it.
     *
     * A row that already has an overrides document keeps it, and only its
     * prototype reference is resolved. That is what the restore case needs,
     * and it is also the one place the two converters are not inverses: an
     * author who clears a pinned field's override leaves columns that describe
     * the same question without recording that the value is inherited, so
     * re-converting such a row would pin the field again. Forcing re-converts
     * regardless, which is what forcing is for.
     *
     * @param \stdClass $row the options row, with its question's context id.
     * @param bool $force re-convert the overrides document as well.
     * @return \stdClass an update with id, prototypeid and overrides.
     */
    protected function convert_row(\stdClass $row, bool $force = false): \stdClass {
        $prototypeid = $this->resolve_prototype($row);
        if ($prototypeid === null) {
            $this->counts['broken']++;
            $this->counts['reasons'][$row->coderunnertype] =
                ($this->counts['reasons'][$row->coderunnertype] ?? 0) + 1;
        } else {
            $this->counts['converted']++;
        }

        $hasdocument = ($row->overrides ?? '') !== '';
        $overrides = $hasdocument && !$force
            ? $row->overrides
            : json_encode(legacy_converter::question_overrides((array) $row)->to_array());

        return (object) [
            'id' => $row->id,
            'prototypeid' => $prototypeid,
            'overrides' => $overrides,
        ];
    }

    /**
     * The manager prototype a question should point at, or null if the question
     * has no resolvable prototype and so stays broken.
     *
     * CodeRunner 5's rules are applied first: the prototype question of that
     * name visible from the question's course, and exactly one of them. The
     * prototype the snapshot created from it is then found by name and scope.
     *
     * @param \stdClass $row the options row, with its question's context id.
     * @return int|null the prototype id, or null if it cannot be resolved.
     */
    protected function resolve_prototype(\stdClass $row): ?int {
        $courseid = $this->course_of_context((int) $row->contextid);
        if ($courseid === null) {
            return null;
        }

        $candidates = $this->prototypesbyname[$row->coderunnertype] ?? [];
        $visible = [];
        foreach ($this->prototype_contexts($courseid) as $contextid) {
            foreach ($candidates[$contextid] ?? [] as $candidate) {
                $visible[] = $candidate;
            }
        }
        if (count($visible) !== 1) {
            // None, or more than one: exactly what breaks the question today.
            return null;
        }

        $prototypescope = $this->course_of_context((int) $visible[0]->contextid);
        if ($prototypescope === null) {
            return null;
        }
        return $this->managerbyname[$row->coderunnertype][$prototypescope] ?? null;
    }

    /**
     * The prototype contexts to search for a course, computed once per course.
     *
     * @param int $courseid the course.
     * @return int[] the context ids.
     */
    protected function prototype_contexts(int $courseid): array {
        global $CFG;

        if (!isset($this->contextsbycourse[$courseid])) {
            // qtype_coderunner lives in the question type's questiontype.php,
            // not in its classes/ directory, so Moodle's class loader cannot
            // find it: it is loaded only by whatever required that file. A web
            // request has, by the time it reaches a question; the restore does
            // it explicitly; the test files each do it themselves. Cron does
            // not, and so the sweep died on its first question with "Class
            // qtype_coderunner not found" every time the task ran.
            require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');

            $this->contextsbycourse[$courseid] = \qtype_coderunner::prototype_contexts(
                \context_course::instance($courseid)
            );
        }
        return $this->contextsbycourse[$courseid];
    }

    /**
     * The course a context belongs to, computed once per context.
     *
     * @param int $contextid the context.
     * @return int|null the course id, or null if the context has no course.
     */
    protected function course_of_context(int $contextid): ?int {
        if (!array_key_exists($contextid, $this->coursebycontext)) {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            $coursecontext = $context ? $context->get_course_context(false) : false;
            $this->coursebycontext[$contextid] = $coursecontext ? (int) $coursecontext->instanceid : null;
        }
        return $this->coursebycontext[$contextid];
    }

    /**
     * Load every prototype question and every manager prototype once, so that
     * resolving a question is a lookup rather than a query.
     */
    protected function build_indexes(): void {
        global $DB;

        $params = [
            'hidden' => question_version_status::QUESTION_STATUS_HIDDEN,
            'hidden2' => question_version_status::QUESTION_STATUS_HIDDEN,
        ];
        $rows = $DB->get_records_sql("
            SELECT q.id, qco.coderunnertype, qc.contextid
              FROM {" . self::OPTIONS . "} qco
              JOIN {question} q ON qco.questionid = q.id
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qv.questionbankentryid = qbe.id
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE (qv.version = (SELECT MAX(v.version)
                                    FROM {question_versions} v
                                    JOIN {question_bank_entries} be ON be.id = v.questionbankentryid
                                   WHERE be.id = qbe.id
                                     AND v.status <> :hidden)
                                 )
               AND qv.status <> :hidden2
               AND qco.prototypetype <> 0", $params);

        $this->prototypesbyname = [];
        foreach ($rows as $row) {
            $this->prototypesbyname[$row->coderunnertype][(int) $row->contextid][] = $row;
        }

        $this->managerbyname = [];
        foreach ($DB->get_records(prototype_repository::PROTOTYPES) as $prototype) {
            $this->managerbyname[$prototype->name][(int) $prototype->courseid] = (int) $prototype->id;
        }
    }

    /**
     * Apply a batch of conversions in one statement.
     *
     * A row at a time costs a millisecond or more, which is minutes at several
     * hundred thousand rows. A single UPDATE with CASE expressions does the
     * whole batch at once and, unlike `UPDATE ... JOIN` or a temporary table,
     * needs no per-database-family SQL.
     *
     * @param \stdClass[] $batch the updates to apply.
     */
    protected function write_batch(array $batch): void {
        global $DB;

        if (!$batch) {
            return;
        }

        $ids = array_column($batch, 'id');
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'id');

        $protocases = '';
        $overridecases = '';
        foreach ($batch as $i => $update) {
            $params["k$i"] = $update->id;
            $params["p$i"] = $update->prototypeid;
            $params["o$i"] = $update->overrides;
            $protocases .= " WHEN :k$i THEN :p$i";
            // Reuse of a named parameter is not portable, so the key appears
            // once per CASE with its own name.
            $params["j$i"] = $update->id;
            $overridecases .= " WHEN :j$i THEN :o$i";
        }

        $DB->execute(
            'UPDATE {' . self::OPTIONS . '}'
                . " SET prototypeid = CASE id$protocases ELSE prototypeid END,"
                . " overrides = CASE id$overridecases ELSE overrides END"
                . " WHERE id $insql",
            $params
        );
    }
}
