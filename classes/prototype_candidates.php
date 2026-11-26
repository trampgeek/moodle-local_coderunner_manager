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
 * Two groups are offered for the question's own course, and they are a
 * partition rather than two overlapping lists. They are drawn by **scope**,
 * which is the line the author's own library follows: a child prototype is
 * made in the same course as its parent, so a group is a whole subtree rather
 * than a slice through one. An empty group is not offered at all, so a site
 * using only the built-ins shows one group and no heading to choose between.
 *
 * 1. **This course** — prototypes belonging to the question's own course, in
 *    tree order.
 * 2. **Site** — the site library, in tree order, which every course sees.
 *
 * Another course's prototypes are offered the same way, through
 * {@see for_other_course()}: a scope is one course's library, so that list
 * needs no groups and no course in its labels. Which courses a scope may be
 * chosen from is {@see borrowable_courses()}.
 */
class prototype_candidates {
    /** @var string Prototypes belonging to the question's own course. */
    const THIS_COURSE = 'thiscourse';

    /** @var string Prototypes belonging to the site, which every course sees. */
    const SITE = 'site';

    /** @var string The prototypes of one other course, chosen as the scope. */
    const OTHER_COURSE = 'othercourse';

    /** @var string The capability that governs using a course's prototypes. */
    const CAPABILITY = 'qtype/coderunner:management';

    /** @var prototype_repository prototype storage. */
    private prototype_repository $repository;

    /**
     * @param prototype_repository|null $repository storage, or a new one.
     */
    public function __construct(?prototype_repository $repository = null) {
        $this->repository = $repository ?? new prototype_repository();
    }

    /**
     * The groups a question in this course is offered, in the order shown.
     *
     * The question's own course only. A prototype belonging to another course
     * is offered in that course's scope, through {@see for_other_course()},
     * which is where the picker goes for one — including the prototype a
     * question already uses, so that nothing here has to make room for it.
     *
     * @param int $courseid the course the question belongs to.
     * @return array a list of ['key' => string, 'options' => array], each
     *      option being id => ['label', 'depth', 'courseid', 'summary'].
     */
    public function groups_for_course(int $courseid): array {
        $local = [];
        foreach ($this->repository->list_for_course($courseid) as $prototype) {
            $local[$prototype->id] = $prototype;
        }

        // Partitioned by scope, not by use. An earlier version put the
        // prototypes the course already uses first, on the grounds that the
        // group would stay small however large the library grew. Measured on
        // the development site it is the larger of the two on a mature course
        // — a course uses nearly everything it owns — and it had two faults
        // that scope does not. A prototype just created is by definition
        // unused, so the menu filed an author's newest work among the
        // built-ins at the moment they came to use it. And use is not
        // hereditary: a parent can be used while its child is not, so no
        // grouping by use can keep a tree whole, which defeats the
        // indentation. Children are made in the same course as their parent,
        // so scope keeps them together.
        //
        // The comparison is explicit: SITEID holds whatever the database
        // returned for the site course's id, which is a *string* under MySQL,
        // so `===` against an integer course id would always be false.
        $issite = fn($prototype) => (int) $prototype->courseid === (int) SITEID;
        $groups = [
            self::THIS_COURSE => array_filter($local, fn($p) => !$issite($p)),
            self::SITE => array_filter($local, $issite),
        ];

        $result = [];
        foreach ($groups as $key => $prototypes) {
            if ($prototypes) {
                $result[] = ['key' => $key, 'options' => $this->as_options($prototypes)];
            }
        }
        return $result;
    }

    /**
     * The prototypes of another course: one scope of the picker.
     *
     * **A prototype the question already uses is offered even where the author
     * may not browse its course.** Otherwise a question built on another
     * course's prototype could not be edited at all by an author with rights
     * only in its own course: the menu would not hold the question's own
     * value, so saving the form would either re-point the question or fail its
     * own validation. The same rule governs the save
     * ({@see may_choose()}): the capability is on *changing* the reference,
     * not on keeping it. Nothing else of that course is offered with it.
     *
     * @param int $courseid the course to list.
     * @param int|null $selectedid the prototype the question currently uses.
     * @return array a group, or an empty array if there is nothing to offer.
     */
    public function for_other_course(int $courseid, ?int $selectedid = null): array {
        $prototypes = [];
        if ($this->may_use_course($courseid)) {
            foreach ($this->repository->list_for_course($courseid) as $prototype) {
                if ((int) $prototype->courseid === $courseid) {
                    // The site prototypes come back with them and are already
                    // offered in the question's own scope.
                    $prototypes[$prototype->id] = $prototype;
                }
            }
        } else if ($selectedid && ($selected = $this->repository->get($selectedid))) {
            if ((int) $selected->courseid === $courseid) {
                $prototypes[$selected->id] = $selected;
            }
        }

        return $prototypes
            ? ['key' => self::OTHER_COURSE, 'options' => $this->as_options($prototypes)]
            : [];
    }

    /**
     * The courses the user may borrow prototypes from, other than this one.
     *
     * **The cheap filter runs first, and that is what makes this affordable on
     * every load of the question form.** The courses that own prototypes are
     * one `SELECT DISTINCT courseid` over the manager's own table
     * ({@see course_ids_with_prototypes()}) and there are as many of them as
     * there are courses that have written a prototype — a handful on a real
     * site, not one per course. The capability is then resolved against just
     * those, each a context fetch Moodle caches. Asked the other way round,
     * through `get_user_capability_course()`, the cost is one row per course
     * the user is enrolled in or can see, which for an administrator is the
     * whole site; that is the shape the picker's scope menu cannot pay for,
     * since it is drawn whenever the author has somewhere to borrow from.
     *
     * @param int $excludecourseid the question's own course, left out.
     * @return array course id => course name, by name.
     */
    public function borrowable_courses(int $excludecourseid): array {
        global $DB;

        $withprototypes = $this->course_ids_with_prototypes();
        unset($withprototypes[$excludecourseid], $withprototypes[(int) SITEID]);
        if (!$withprototypes) {
            return [];
        }

        $allowed = array_filter(array_keys($withprototypes), fn($id) => $this->may_use_course($id));
        if (!$allowed) {
            return [];
        }

        $names = [];
        $courses = $DB->get_records_list('course', 'id', $allowed, 'shortname ASC', 'id, shortname');
        foreach ($courses as $course) {
            $names[(int) $course->id] = format_string($course->shortname);
        }
        return $names;
    }

    /**
     * The course a prototype belongs to, which is the scope it is offered in.
     *
     * @param int|null $prototypeid the prototype.
     * @return int|null its course id, or null if there is no such prototype.
     */
    public function course_of(?int $prototypeid): ?int {
        if (!$prototypeid) {
            return null;
        }
        $prototype = $this->repository->get($prototypeid);
        return $prototype === null ? null : (int) $prototype->courseid;
    }

    /**
     * One course's name, for the entry the course menu gives it.
     *
     * Asked one course at a time, and only for the course that owns the
     * question's current prototype: {@see borrowable_courses()} names the rest
     * in a single query, and this is the one course that may not be among them.
     *
     * @param int $courseid the course.
     * @return string its short name, or an empty string if it has gone.
     */
    public function course_name(int $courseid): string {
        global $DB;

        $shortname = $DB->get_field('course', 'shortname', ['id' => $courseid]);
        return $shortname === false ? '' : format_string($shortname);
    }

    /**
     * Whether this user holds the capability in a prototype's own scope.
     *
     * The test the editor makes, and made in the same way: it takes its context
     * from the prototype's **own** course, so a site prototype is governed by
     * the capability at system level and a teacher holding
     * `qtype/coderunner:management` in one course does not hold it there. The
     * library lists prototypes of other scopes beside the ones they can edit,
     * so every such row offered a link that answered "you don't have
     * permission"; asked here so that the page and the link cannot disagree.
     *
     * The **usage report** no longer asks this, and `may_edit()` is the only
     * thing that does. A report of what depends on a prototype is readable from
     * the scope the viewer is browsing in, whatever scope the prototype belongs
     * to, because that is as much a teacher's question about a site prototype
     * as an administrator's; {@see usage_report} decides how much of the answer
     * each viewer is shown.
     *
     * Not the same question as {@see may_use_course()}, which is about
     * building a question on a prototype: a site prototype is offered to every
     * author, while its editor is not.
     *
     * @param prototype $prototype the prototype.
     * @return bool true if the capability is held in its scope.
     */
    public static function may_manage(prototype $prototype): bool {
        $context = $prototype->is_site_prototype()
            ? \context_system::instance()
            : \context_course::instance($prototype->courseid, IGNORE_MISSING);
        return $context && has_capability(self::CAPABILITY, $context);
    }

    /**
     * Whether this user may open a prototype in the editor.
     *
     * Stricter than {@see may_manage()}: a site prototype is every course's
     * business, so `edit.php` reserves it to administrators whatever the
     * capability says (design section 8). The usage report of a site prototype
     * is readable by any viewer who can see the row; the editor is not.
     *
     * @param prototype $prototype the prototype.
     * @return bool true if the editor is reachable.
     */
    public static function may_edit(prototype $prototype): bool {
        return self::may_manage($prototype)
            && (!$prototype->is_site_prototype() || is_siteadmin());
    }

    /**
     * Whether the user may create a prototype in the given course.
     *
     * Not the same question as {@see may_use_course()}, which asks whether a
     * course's prototypes may be *built on*: every author may build on a site
     * prototype, and none but an administrator may add one, exactly as
     * {@see may_edit()} reserves editing them. A course prototype needs the
     * capability in that course.
     *
     * @param int $courseid the course the prototype would belong to.
     * @return bool true if they may.
     */
    public function may_create_in_course(int $courseid): bool {
        if ($courseid === (int) SITEID) {
            return is_siteadmin();
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        return $context && has_capability(self::CAPABILITY, $context);
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
     * One option's menu label, indented to show its depth in the tree.
     *
     * The single place the indentation is spelled, because both menus that
     * show the library use it. Non-breaking spaces rather than padding: an
     * option's text is the only thing a `select` lets us style, and core drops
     * a class from an option (see `export_for_template()` in
     * `lib/form/select.php`). The name is escaped here, because an option's
     * text is written into the select unescaped and a prototype's name is
     * author-supplied.
     *
     * @param array $option one entry of {@see as_options()}.
     * @return string the label to show.
     */
    public static function indented_label(array $option): string {
        return str_repeat('&nbsp;', 4 * (int) ($option['depth'] ?? 0)) . s($option['label']);
    }

    /**
     * Prototypes as picker options, in tree order and indented by depth.
     *
     * Depth is counted within the group rather than over the whole library, and
     * a prototype whose parent is not in the group is a root here: indenting
     * an item three levels because of ancestors that are not on screen says
     * nothing to the author reading it. With the groups drawn by scope this
     * arises where a course prototype descends from a site one, which is the
     * common shape — a course's own type built on a built-in.
     *
     * Public because two menus are built from it: the question form's
     * prototype picker, through {@see groups_for_course()}, and the prototype
     * editor's *Inherits from*. They showed the library differently until the
     * second was given this one too -- a flat list of bare names against a
     * tree -- which is the sort of difference an author notices and no test
     * does.
     *
     * @param prototype[] $prototypes the prototypes, keyed by id.
     * @param bool $withcourse true to label each with its course, which is what
     *      distinguishes one course's `python3_mine` from another's.
     * @return array id => ['label', 'depth', 'courseid', 'summary'].
     */
    public function as_options(array $prototypes, bool $withcourse = false): array {
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
