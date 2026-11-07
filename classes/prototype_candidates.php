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
 * Which prototypes a question author may choose from, and in what order.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

/**
 * The prototypes a question in a given course can be built on, grouped and
 * ordered as the picker shows them.
 *
 * The scope rules and the queries live here, in the manager, because they are
 * about prototypes and about which courses a user may borrow from; the question
 * type's picker only renders what this produces (design section 5).
 *
 * Three groups are offered by default, and they are a partition rather than
 * three overlapping lists:
 *
 * 1. **Used in this course** — prototypes of this course or the site that the
 *    course's own CodeRunner questions already use. This is the group that
 *    stays small however large the library grows, which is why it comes first.
 * 2. **This course and the site** — the rest of them.
 * 3. **Already borrowed from another course** — prototypes belonging to another
 *    course that this course's questions already use, each labelled with its
 *    course.
 *
 * Anything else from another course is reached through a deliberate second
 * step, which is what {@see borrowable_courses()} and
 * {@see for_other_course()} serve. Keeping it behind that step keeps the list
 * short for an author with rights in many courses, and keeps the expensive
 * capability query off every page load of the question form.
 */
class prototype_candidates {
    /** @var string Prototypes of this course or the site that it already uses. */
    const USED_HERE = 'usedhere';

    /** @var string Everything else of this course and the site. */
    const COURSE_AND_SITE = 'courseandsite';

    /** @var string Other courses' prototypes that this course already uses. */
    const BORROWED = 'borrowed';

    /** @var string The prototypes of one other course, chosen in the second step. */
    const OTHER_COURSE = 'othercourse';

    /** @var string The capability that governs using a course's prototypes. */
    const CAPABILITY = 'qtype/coderunner:management';

    /** @var prototype_repository prototype storage. */
    private prototype_repository $repository;

    /** @var array Prototype ids used by each course asked about, keyed by course id. */
    private static array $usedcache = [];

    /**
     * @param prototype_repository|null $repository storage, or a new one.
     */
    public function __construct(?prototype_repository $repository = null) {
        $this->repository = $repository ?? new prototype_repository();
    }

    /**
     * The groups a question in this course is offered, in the order shown.
     *
     * The currently selected prototype is always included, whatever its scope
     * and whatever the author's rights in its course, because a picker that
     * omits the question's own prototype would silently re-point the question
     * on the next save. Where it belongs to a course not otherwise listed it
     * forms a group of its own.
     *
     * @param int $courseid the course the question belongs to.
     * @param int|null $selectedid the prototype the question currently uses.
     * @return array a list of ['key' => string, 'options' => array], each
     *      option being id => ['label', 'depth', 'courseid', 'summary'].
     */
    public function groups_for_course(int $courseid, ?int $selectedid = null): array {
        $usedhere = $this->ids_used_in_course($courseid);

        $local = [];
        $borrowed = [];
        foreach ($this->repository->list_for_course($courseid) as $prototype) {
            $local[$prototype->id] = $prototype;
        }
        foreach ($usedhere as $id) {
            if (!isset($local[$id]) && ($prototype = $this->repository->get($id))) {
                // Used here but belonging to another course.
                $borrowed[$id] = $prototype;
            }
        }

        if ($selectedid && !isset($local[$selectedid]) && !isset($borrowed[$selectedid])) {
            if ($selected = $this->repository->get($selectedid)) {
                $borrowed[$selectedid] = $selected;
            }
        }

        $used = array_flip($usedhere);
        $groups = [
            self::USED_HERE => array_filter($local, fn($p) => isset($used[$p->id])),
            self::COURSE_AND_SITE => array_filter($local, fn($p) => !isset($used[$p->id])),
            self::BORROWED => $borrowed,
        ];

        $result = [];
        foreach ($groups as $key => $prototypes) {
            if ($prototypes) {
                $result[] = ['key' => $key, 'options' => $this->as_options($prototypes, $key === self::BORROWED)];
            }
        }
        return $result;
    }

    /**
     * The prototypes of one other course, for the picker's second step.
     *
     * @param int $courseid the course to list.
     * @return array a group, or an empty array if the user may not use it.
     */
    public function for_other_course(int $courseid): array {
        if (!$this->may_use_course($courseid)) {
            return [];
        }

        $prototypes = [];
        foreach ($this->repository->list_for_course($courseid) as $prototype) {
            if ($prototype->courseid === $courseid) {
                // The site prototypes come back with them and are already
                // offered in the course's own group.
                $prototypes[$prototype->id] = $prototype;
            }
        }

        return $prototypes
            ? ['key' => self::OTHER_COURSE, 'options' => $this->as_options($prototypes, true)]
            : [];
    }

    /**
     * The courses the user may borrow prototypes from, other than this one.
     *
     * Deliberately not called while the picker is merely being shown: resolving
     * a capability across every course is the one expensive thing here, and an
     * author with rights in hundreds of courses would pay for it on every load
     * of the question form. The picker asks for it only when the author has
     * said they want another course.
     *
     * @param int $excludecourseid the question's own course, left out.
     * @return array course id => course name.
     */
    public function borrowable_courses(int $excludecourseid): array {
        $courses = get_user_capability_course(self::CAPABILITY, null, true, 'shortname', 'shortname ASC');
        if (!$courses) {
            return [];
        }

        $withprototypes = $this->course_ids_with_prototypes();
        $names = [];
        foreach ($courses as $course) {
            $id = (int) $course->id;
            if ($id === $excludecourseid || $id === (int) SITEID || !isset($withprototypes[$id])) {
                continue;
            }
            $names[$id] = format_string($course->shortname);
        }
        return $names;
    }

    /**
     * Whether the user may build a question on the given course's prototypes.
     *
     * @param int $courseid the course the prototype belongs to.
     * @return bool true if they may.
     */
    public function may_use_course(int $courseid): bool {
        if ($courseid === (int) SITEID) {
            // Site prototypes are offered to every author; only editing them
            // is reserved to administrators. The cast is not decoration:
            // SITEID is `define('SITEID', $SITE->id)`, so it holds whatever the
            // database returned for the site course's id — a *string* under
            // MySQL — and a strict comparison against an int course id would
            // always be false.
            return true;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        return $context && has_capability(self::CAPABILITY, $context);
    }

    /**
     * Whether a question may be pointed at the given prototype.
     *
     * The check is on *changing* the reference, not on keeping it. A question
     * that already borrows from another course must stay editable by an author
     * with rights only in its own course: refusing the save would make the
     * question uneditable rather than protecting anything, since the reference
     * it already has was authorised when it was made.
     *
     * @param int|null $prototypeid the prototype chosen.
     * @param int|null $previousid the prototype the question used before.
     * @return bool true if the choice is allowed.
     */
    public function may_choose(?int $prototypeid, ?int $previousid): bool {
        if ($prototypeid === null || $prototypeid === $previousid) {
            return true;
        }
        $prototype = $this->repository->get($prototypeid);
        return $prototype !== null && $this->may_use_course($prototype->courseid);
    }

    /**
     * The prototype ids used by the CodeRunner questions of one course.
     *
     * Every bank of the course counts: its own question categories, the banks
     * of its `mod_qbank` instances, and the categories of activities that still
     * hold their own. They are reached through the context tree rather than by
     * listing modules, so a bank in any kind of activity is included.
     *
     * **Two queries, and the shape is what makes it affordable.** Asked as one
     * join with the context test in it, MySQL drives from
     * `question_coderunner_options` and filters by context last, so the cost is
     * flat in the course and linear in the *site*: measured warm on the
     * development site's 13,213 rows, a course of 19 questions and one of 9,497
     * both took about 41 ms, which at 400,000 rows would be something like a
     * second, paid by every course including an empty one.
     *
     * Finding the course's bank contexts first and giving the join an explicit
     * list of them makes the optimiser start at
     * `question_categories.contextid`, and the cost becomes the *course's* own
     * questions: 0.3 ms for that 19-question course, 17 ms for one of 3,562 and
     * 31 ms for one of 9,497, or roughly 3 to 5 µs a question plus a little per
     * bank. A site reaching 400,000 questions does so by having more courses,
     * not a bigger one, so a course keeps its own cost however large the site
     * around it grows. What is left to watch is a single course holding a very
     * large bank, which would be seconds rather than milliseconds.
     *
     * Collecting the contexts that actually hold categories, rather than every
     * context under the course, also keeps the list proportional to the number
     * of banks — 176 rather than 511 for the course measured — instead of to the
     * number of activities.
     *
     * That is fast enough to run on each load of the question form, so the
     * notes' suggestion of a session cache is not taken: a cache here would
     * have to be invalidated whenever any question in the course was saved, and
     * until it was, a prototype just used would be missing from the group named
     * for the prototypes the course uses. The per-request memo below is enough,
     * because a form builds its picker once.
     *
     * @param int $courseid the course.
     * @return int[] the prototype ids, each once.
     */
    public function ids_used_in_course(int $courseid): array {
        global $DB;

        if (isset(self::$usedcache[$courseid])) {
            return self::$usedcache[$courseid];
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return self::$usedcache[$courseid] = [];
        }

        $like = $DB->sql_like('ctx.path', ':path');
        $bankcontexts = $DB->get_fieldset_sql("
            SELECT DISTINCT qc.contextid
              FROM {question_categories} qc
              JOIN {context} ctx ON ctx.id = qc.contextid
             WHERE ctx.id = :contextid OR $like", [
            'contextid' => $context->id,
            'path' => $DB->sql_like_escape($context->path) . '/%',
        ]);

        if (!$bankcontexts) {
            return self::$usedcache[$courseid] = [];
        }

        [$insql, $params] = $DB->get_in_or_equal($bankcontexts, SQL_PARAMS_NAMED, 'ctx');
        $ids = $DB->get_fieldset_sql("
            SELECT DISTINCT o.prototypeid
              FROM {question_categories} qc
              JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
              JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              JOIN {question_coderunner_options} o ON o.questionid = qv.questionid
             WHERE qc.contextid $insql
               AND o.prototypeid IS NOT NULL", $params);

        return self::$usedcache[$courseid] = array_map('intval', $ids);
    }

    /**
     * Forget the cached usage, so that a test or a long-running script sees a
     * question saved since it last asked.
     */
    public static function reset_caches(): void {
        self::$usedcache = [];
    }

    /**
     * The courses that have prototypes of their own.
     *
     * @return array course id => true.
     */
    private function course_ids_with_prototypes(): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            prototype_repository::PROTOTYPES,
            'DISTINCT courseid',
            'courseid <> :siteid',
            ['siteid' => SITEID]
        );
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Prototypes as picker options, in tree order and indented by depth.
     *
     * Depth is counted within the group rather than over the whole library, and
     * a prototype whose parent is not in the group is a root here. A group is
     * usually a subset — *Used in this course* especially — and indenting an
     * item three levels because of ancestors that are not on screen says
     * nothing to the author reading it.
     *
     * @param prototype[] $prototypes the prototypes, keyed by id.
     * @param bool $withcourse true to label each with its course, which is what
     *      distinguishes one course's `python3_mine` from another's.
     * @return array id => ['label', 'depth', 'courseid', 'summary'].
     */
    private function as_options(array $prototypes, bool $withcourse = false): array {
        $childrenof = [];
        foreach ($prototypes as $prototype) {
            $parentid = $prototype->parentid();
            $key = ($parentid !== null && isset($prototypes[$parentid])) ? $parentid : 0;
            $childrenof[$key][] = $prototype;
        }
        foreach ($childrenof as &$children) {
            usort($children, fn($a, $b) => strnatcasecmp($a->name, $b->name));
        }
        unset($children);

        $options = [];
        $walk = function (array $children, int $depth) use (&$walk, &$options, $childrenof, $withcourse) {
            foreach ($children as $prototype) {
                $options[$prototype->id] = [
                    'label' => $this->label($prototype, $withcourse),
                    'depth' => $depth,
                    'courseid' => $prototype->courseid,
                    'summary' => $prototype->currentversion->summary ?? '',
                ];
                $walk($childrenof[$prototype->id] ?? [], $depth + 1);
            }
        };
        $walk($childrenof[0] ?? [], 0);

        return $options;
    }

    /**
     * One prototype's label, with its course when that is what tells two of
     * the same name apart.
     *
     * @param prototype $prototype the prototype.
     * @param bool $withcourse true to append the course's short name.
     * @return string the label.
     */
    private function label(prototype $prototype, bool $withcourse): string {
        global $DB;

        if (!$withcourse || $prototype->courseid === (int) SITEID) {
            return $prototype->name;
        }

        $shortname = $DB->get_field('course', 'shortname', ['id' => $prototype->courseid]);
        return $shortname
            ? $prototype->name . ' (' . format_string($shortname) . ')'
            : $prototype->name;
    }
}
