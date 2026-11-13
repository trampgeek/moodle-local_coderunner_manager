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
 * What a particular viewer may be told about what depends on a prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

/**
 * The usage report, as the viewer in front of it may see it.
 *
 * A prototype's usage report is readable by anyone who can reach the library it
 * is listed in, whatever scope the prototype itself belongs to: the question a
 * maintainer asks — *what will I break if I change this?* — is the same question
 * a teacher asks about a site prototype their own course depends on. What
 * differs is how much of the answer each may be shown.
 *
 * So the report is split here rather than in the page. A course the viewer may
 * manage CodeRunner questions in is named, and its count links to the questions
 * themselves. Every other course is added into one anonymous bin, which keeps
 * the totals adding up without saying which courses those are. The number of
 * such courses stays derivable from the totals, which is deliberate: the figure
 * a maintainer needs is how much depends on the prototype, and that is not
 * sensitive. Which course, and which question, is.
 */
class usage_report {
    /**
     * What depends on a prototype, by course, as this viewer may see it.
     *
     * @param int $prototypeid the prototype asked about.
     * @param prototype_repository|null $repo the repository to ask.
     * @return array ['courses' => list of ['courseid' => int, 'name' => string,
     *      'questions' => int], 'other' => ['courses' => int, 'questions' =>
     *      int], 'total' => int]. Named courses come busiest first.
     */
    public static function by_course_for_viewer(int $prototypeid, ?prototype_repository $repo = null): array {
        global $DB;

        $repo ??= new prototype_repository();
        $bycourse = $repo->dependants_by_course($prototypeid);

        $courseids = array_filter(array_column($bycourse, 'courseid'));
        $shortnames = $courseids
            ? $DB->get_records_list('course', 'id', $courseids, '', 'id, shortname')
            : [];

        $named = [];
        $other = ['courses' => 0, 'questions' => 0];

        foreach ($bycourse as $row) {
            $courseid = (int) ($row['courseid'] ?? 0);
            if (!self::may_see_course($courseid)) {
                $other['courses']++;
                $other['questions'] += $row['questions'];
                continue;
            }
            $named[] = [
                'courseid' => $courseid,
                'name' => $courseid
                    ? format_string($shortnames[$courseid]->shortname ?? $courseid)
                    : get_string('usagesitebanks', 'local_coderunner_manager'),
                'questions' => $row['questions'],
            ];
        }

        return [
            'courses' => $named,
            'other' => $other,
            'total' => array_sum(array_column($bycourse, 'questions')),
        ];
    }

    /**
     * Whether this viewer may be told which course some questions are in, and
     * may see the questions themselves.
     *
     * @param int $courseid the course, or zero for the banks outside any course.
     * @return bool true if the course may be named.
     */
    public static function may_see_course(int $courseid): bool {
        $context = $courseid
            ? \context_course::instance($courseid, IGNORE_MISSING)
            : \context_system::instance();
        return $context && has_capability(prototype_candidates::CAPABILITY, $context);
    }

    /**
     * Whether this viewer may see the names of the questions in one bank.
     *
     * Managing a course's CodeRunner prototypes is not by itself permission to
     * read its question bank, so the drill-down asks this per bank and says how
     * many questions it left out rather than listing them.
     *
     * @param int $contextid the bank's context.
     * @return bool true if its questions may be listed.
     */
    public static function may_see_bank(int $contextid): bool {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        return $context && has_any_capability(
            ['moodle/question:viewall', 'moodle/question:editall'],
            $context
        );
    }

    /**
     * A link that opens one question where its author keeps it.
     *
     * The recipe is the question type's own, from
     * `qtype_coderunner_util::make_question_bank_url_params()`, and is repeated
     * rather than called: the question type depends on this plugin and not the
     * other way about, so calling into it would make a cycle of a dependency
     * that is deliberately one-directional.
     *
     * @param \stdClass $question a row from
     *      {@see prototype_repository::dependant_questions()}.
     * @return \moodle_url the question bank, showing that question's category.
     */
    public static function question_bank_url(\stdClass $question): \moodle_url {
        $context = \context::instance_by_id((int) $question->contextid);

        if ($context->contextlevel == CONTEXT_MODULE) {
            $params = ['cmid' => $context->instanceid];
        } else if ($context->contextlevel == CONTEXT_COURSE) {
            $params = ['courseid' => $context->instanceid];
        } else {
            $params = ['courseid' => SITEID];
        }

        $params['category'] = $question->categoryid . ',' . $question->contextid;
        $params['lastchanged'] = $question->questionid;
        $params['showhidden'] = 1;

        return new \moodle_url('/question/edit.php', $params);
    }
}
