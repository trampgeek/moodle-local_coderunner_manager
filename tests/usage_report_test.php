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
 * Unit tests for what a viewer is told about what depends on a prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the usage report's two halves: which courses a viewer may be told
 * about, and the questions of one course it drills down to.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class usage_report_test extends \advanced_testcase {
    /** @var prototype_repository prototype storage. */
    private prototype_repository $repo;

    /** @var prototype a site prototype two courses depend on. */
    private prototype $prototype;

    /** @var prototype a child of it, so the report has to reach through one. */
    private prototype $child;

    /** @var \stdClass one course the viewer will be able to manage. */
    private \stdClass $mine;

    /** @var \stdClass another course, which they will not. */
    private \stdClass $theirs;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->repo = new prototype_repository();
        $this->prototype = $this->repo->create('python3', SITEID, new override_set([], 'python3'));
        $this->child = $this->repo->create(
            'python3_child',
            SITEID,
            new override_set([], 'python3_child'),
            $this->prototype->id
        );

        $this->mine = $this->getDataGenerator()->create_course();
        $this->theirs = $this->getDataGenerator()->create_course();
    }

    /**
     * A question in a course, built on a prototype.
     *
     * @param int $courseid the course to put it in.
     * @param int $prototypeid the prototype it depends on.
     * @param string $name the question's name.
     * @return \stdClass the question.
     */
    private function make_question(int $courseid, int $prototypeid, string $name): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(
            ['contextid' => \context_course::instance($courseid)->id]
        );
        $question = $generator->create_question('coderunner', null, [
            'category' => $category->id,
            'name' => $name,
        ]);
        $DB->set_field('question_coderunner_options', 'prototypeid', $prototypeid, ['questionid' => $question->id]);

        return $question;
    }

    /**
     * A teacher of one course enrolled as such, and made the current user.
     *
     * @param \stdClass $course the course they teach.
     */
    private function teach(\stdClass $course): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * An administrator holds the capability everywhere, so every course is
     * named and nothing is lumped.
     */
    public function test_an_administrator_sees_every_course_named(): void {
        $this->make_question((int) $this->mine->id, $this->prototype->id, 'Mine');
        $this->make_question((int) $this->theirs->id, $this->prototype->id, 'Theirs');

        $report = usage_report::by_course_for_viewer($this->prototype->id, $this->repo);

        $this->assertCount(2, $report['courses']);
        $this->assertSame(0, $report['other']['courses']);
        $this->assertSame(0, $report['other']['questions']);
        $this->assertSame(2, $report['total']);
    }

    /**
     * A teacher sees their own course by name and everything else in one
     * anonymous bin — and the two still add up to the total, which is the
     * figure the report exists to give.
     */
    public function test_a_teacher_sees_their_own_course_and_a_lumped_rest(): void {
        $this->make_question((int) $this->mine->id, $this->prototype->id, 'Mine');
        $this->make_question((int) $this->theirs->id, $this->prototype->id, 'Theirs');
        $this->make_question((int) $this->theirs->id, $this->prototype->id, 'Theirs too');

        $this->teach($this->mine);
        $report = usage_report::by_course_for_viewer($this->prototype->id, $this->repo);

        $this->assertCount(1, $report['courses']);
        $this->assertSame((int) $this->mine->id, $report['courses'][0]['courseid']);
        $this->assertSame($this->mine->shortname, $report['courses'][0]['name']);
        $this->assertSame(1, $report['courses'][0]['questions']);

        $this->assertSame(1, $report['other']['courses'], 'The other course is one row, not two.');
        $this->assertSame(2, $report['other']['questions']);

        $this->assertSame(
            $report['total'],
            $report['courses'][0]['questions'] + $report['other']['questions'],
            'The named and the lumped have to account for every question.'
        );
    }

    /**
     * The report reaches through a child prototype, and a viewer who manages
     * neither course is told the total and no more.
     */
    public function test_a_viewer_who_manages_nothing_sees_only_the_bin(): void {
        $this->make_question((int) $this->mine->id, $this->child->id, 'Through the child');

        $this->teach($this->theirs);
        $report = usage_report::by_course_for_viewer($this->prototype->id, $this->repo);

        $this->assertSame([], $report['courses']);
        $this->assertSame(1, $report['other']['courses']);
        $this->assertSame(1, $report['other']['questions']);
        $this->assertSame(1, $report['total']);
    }

    /**
     * The drill-down lists the questions of the course asked about, and no
     * others, naming the prototype each one actually depends on.
     */
    public function test_the_drill_down_lists_one_course(): void {
        $this->make_question((int) $this->mine->id, $this->prototype->id, 'Mine');
        $this->make_question((int) $this->mine->id, $this->child->id, 'Mine through the child');
        $this->make_question((int) $this->theirs->id, $this->prototype->id, 'Theirs');

        $questions = $this->repo->dependant_questions($this->prototype->id, (int) $this->mine->id);

        $this->assertCount(2, $questions);
        $this->assertSame(['Mine', 'Mine through the child'], array_column($questions, 'name'));
        $this->assertEquals(
            [$this->prototype->id, $this->child->id],
            array_map('intval', array_column($questions, 'prototypeid'))
        );
    }

    /**
     * Each question is listed with the category it is filed in, which on a
     * course with one question bank is the only thing that tells two rows
     * apart.
     */
    public function test_the_drill_down_names_each_category(): void {
        global $DB;

        $this->make_question((int) $this->mine->id, $this->prototype->id, 'Mine');
        $questions = $this->repo->dependant_questions($this->prototype->id, (int) $this->mine->id);

        $this->assertCount(1, $questions);
        $this->assertSame(
            $DB->get_field('question_categories', 'name', ['id' => $questions[0]->categoryid]),
            $questions[0]->categoryname
        );
        $this->assertNotEmpty($questions[0]->categoryname);
    }

    /**
     * A question with several versions on the prototype is one question, which
     * is how the by-course count counts it and so how the list must list it.
     */
    public function test_a_question_is_listed_once_however_many_versions(): void {
        global $DB;

        $question = $this->make_question((int) $this->mine->id, $this->prototype->id, 'Twice over');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $newversion = $generator->update_question($question, null, ['name' => 'Twice over']);
        $DB->set_field(
            'question_coderunner_options',
            'prototypeid',
            $this->prototype->id,
            ['questionid' => $newversion->id]
        );

        $questions = $this->repo->dependant_questions($this->prototype->id, (int) $this->mine->id);

        $this->assertCount(1, $questions);
        $this->assertSame((int) $newversion->id, (int) $questions[0]->questionid, 'The latest version should stand for it.');
        $this->assertSame(
            $this->repo->dependants_by_course($this->prototype->id)[0]['questions'],
            count($questions),
            'The list and the count have to agree.'
        );
    }

    /**
     * A course with nothing in it drills down to nothing, rather than failing.
     */
    public function test_a_course_with_no_dependants_lists_nothing(): void {
        $this->assertSame([], $this->repo->dependant_questions($this->prototype->id, (int) $this->theirs->id));
    }
}
