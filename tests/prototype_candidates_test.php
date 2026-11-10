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
 * Unit tests for the prototypes a question author may choose from.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');

/**
 * Tests the picker's scope rules: which prototypes a question in a given course
 * is offered, in which group, and in what order.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_candidates::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class prototype_candidates_test extends \advanced_testcase {
    /** @var prototype_repository prototype storage. */
    private prototype_repository $repo;

    /** @var prototype_candidates the candidate list under test. */
    private prototype_candidates $candidates;

    /** @var \stdClass the course the question belongs to. */
    private \stdClass $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
        $this->candidates = new prototype_candidates($this->repo);
        $this->course = $this->getDataGenerator()->create_course();
        prototype_candidates::reset_caches();
    }

    /**
     * Create a prototype.
     *
     * @param string $name its name.
     * @param int $courseid its scope.
     * @param int|null $parentid its parent, if any.
     * @return prototype the prototype.
     */
    private function make_prototype(string $name, int $courseid, ?int $parentid = null): prototype {
        return $this->repo->create(
            $name,
            $courseid,
            new override_set(['template' => "TEMPLATE FOR $name"], $name),
            $parentid
        );
    }

    /**
     * Create a CodeRunner question in a course's own question bank, which is
     * what makes a prototype count as used in that course.
     *
     * @param int $courseid the course.
     * @param string $prototypename the prototype the question is built on.
     */
    private function make_question_in_course(int $courseid, string $prototypename): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => \context_course::instance($courseid)->id,
        ]);
        $generator->create_question('coderunner', 'sqr', [
            'category' => $category->id,
            'coderunnertype' => $prototypename,
        ]);
        prototype_candidates::reset_caches();
    }

    /**
     * The options of one group, by key, or null if the group is not offered.
     *
     * @param array $groups the groups.
     * @param string $key the group wanted.
     * @return array|null the group's options.
     */
    private function group(array $groups, string $key): ?array {
        foreach ($groups as $group) {
            if ($group['key'] === $key) {
                return $group['options'];
            }
        }
        return null;
    }

    /**
     * A prototype a question in the course uses is reported as used there.
     */
    public function test_a_prototype_a_question_uses_is_used_in_the_course(): void {
        $prototype = $this->make_prototype('python3', SITEID);
        $this->make_question_in_course((int) $this->course->id, 'python3');

        $this->assertSame(
            [$prototype->id],
            $this->candidates->ids_used_in_course((int) $this->course->id)
        );
    }

    /**
     * Another course's questions do not make a prototype used in this one,
     * which is the whole point of the first group.
     */
    public function test_another_courses_questions_do_not_count(): void {
        $this->make_prototype('python3', SITEID);
        $other = $this->getDataGenerator()->create_course();
        $this->make_question_in_course((int) $other->id, 'python3');

        $this->assertSame([], $this->candidates->ids_used_in_course((int) $this->course->id));
        $this->assertNotEmpty($this->candidates->ids_used_in_course((int) $other->id));
    }

    /**
     * A question in an activity of the course counts too, because a bank can
     * live in any module context, not only in the course's own.
     */
    public function test_a_question_in_an_activity_of_the_course_counts(): void {
        $prototype = $this->make_prototype('python3', SITEID);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => \context_module::instance($quiz->cmid)->id,
        ]);
        $generator->create_question('coderunner', 'sqr', [
            'category' => $category->id,
            'coderunnertype' => 'python3',
        ]);
        prototype_candidates::reset_caches();

        $this->assertSame(
            [$prototype->id],
            $this->candidates->ids_used_in_course((int) $this->course->id)
        );
    }

    /**
     * The groups are a partition by scope: the course's own prototypes in the
     * first group, the site's in the second, and nothing listed twice.
     *
     * Use decides nothing here. A prototype just created is unused by
     * definition, and filing it with the site's built-ins put an author's
     * newest work where they would not look for it.
     */
    public function test_the_default_groups_are_a_partition_by_scope(): void {
        $sitewide = $this->make_prototype('python3', SITEID);
        $mine = $this->make_prototype('cosc131_python3', (int) $this->course->id);
        $this->make_question_in_course((int) $this->course->id, 'python3');

        $groups = $this->candidates->groups_for_course((int) $this->course->id);
        $thiscourse = $this->group($groups, prototype_candidates::THIS_COURSE);
        $site = $this->group($groups, prototype_candidates::SITE);

        $this->assertArrayHasKey($mine->id, $thiscourse);
        $this->assertArrayNotHasKey($mine->id, $site);
        // In use by a question of this course, and still in the site group,
        // because that is where it belongs.
        $this->assertArrayHasKey($sitewide->id, $site);
        $this->assertArrayNotHasKey($sitewide->id, $thiscourse);

        $seen = [];
        foreach ($groups as $group) {
            foreach (array_keys($group['options']) as $id) {
                $this->assertArrayNotHasKey($id, $seen, "Prototype $id is offered in two groups.");
                $seen[$id] = true;
            }
        }
    }

    /**
     * A course with no prototypes of its own is offered no empty group, which
     * is the common case on a site that uses only the built-ins.
     */
    public function test_a_course_with_no_prototypes_of_its_own_gets_no_group(): void {
        $this->make_prototype('python3', SITEID);

        $groups = $this->candidates->groups_for_course((int) $this->course->id);

        $this->assertSame(
            [prototype_candidates::SITE],
            array_column($groups, 'key'),
            'An empty group should not be offered at all.'
        );
    }

    /**
     * A child made in the course of its parent stays with it, whether or not
     * anything uses it yet.
     *
     * This is what grouping by scope buys and grouping by use cannot: use is
     * not hereditary, so a used parent and its unused child landed in
     * different groups, and the child appeared as a root of the group it fell
     * into — which is how two new children of python3_scratchpad came to look
     * like they had not been created.
     */
    public function test_a_new_child_stays_with_its_parent(): void {
        $parent = $this->make_prototype('scratchpad', (int) $this->course->id);
        $child = $this->make_prototype('scratchpad_files', (int) $this->course->id, $parent->id);
        $this->make_question_in_course((int) $this->course->id, 'scratchpad');

        $options = $this->group(
            $this->candidates->groups_for_course((int) $this->course->id),
            prototype_candidates::THIS_COURSE
        );

        $this->assertSame([$parent->id, $child->id], array_keys($options));
        $this->assertSame(1, $options[$child->id]['depth'], 'The child should sit under its parent.');
    }

    /**
     * Another course's prototype that this course's questions already use is
     * offered in its own group, labelled with the course it comes from.
     */
    public function test_a_borrowed_prototype_is_grouped_and_labelled(): void {
        $other = $this->getDataGenerator()->create_course(['shortname' => 'COSC122-26S1']);
        $borrowed = $this->make_prototype('cosc122_scratchpad', (int) $other->id);

        // A question of this course built on it, which is what "already uses"
        // means. The name does not resolve from this course, so the reference
        // is made directly.
        $this->make_question_in_course((int) $this->course->id, 'python3');
        $this->point_every_question_at($borrowed->id);

        $groups = $this->candidates->groups_for_course((int) $this->course->id);
        $options = $this->group($groups, prototype_candidates::BORROWED);

        $this->assertNotNull($options);
        $this->assertSame('cosc122_scratchpad (COSC122-26S1)', $options[$borrowed->id]['label']);
    }

    /**
     * The prototype a question already uses is always offered, even when it is
     * out of scope, because a picker that omitted it would silently re-point
     * the question the next time the form was saved.
     */
    public function test_the_current_choice_is_always_offered(): void {
        $other = $this->getDataGenerator()->create_course();
        $elsewhere = $this->make_prototype('someone_elses', (int) $other->id);

        $groups = $this->candidates->groups_for_course((int) $this->course->id, $elsewhere->id);

        $offered = [];
        foreach ($groups as $group) {
            $offered += $group['options'];
        }
        $this->assertArrayHasKey($elsewhere->id, $offered);
    }

    /**
     * Within a group, a child sits under its parent and is indented by its
     * depth.
     */
    public function test_a_group_is_in_tree_order_and_indented_by_depth(): void {
        $parent = $this->make_prototype('python3', SITEID);
        $child = $this->make_prototype('python3_scratchpad', SITEID, $parent->id);
        $grandchild = $this->make_prototype('cosc131_scratchpad', (int) $this->course->id, $child->id);

        $site = $this->group(
            $this->candidates->groups_for_course((int) $this->course->id),
            prototype_candidates::SITE
        );
        $own = $this->group(
            $this->candidates->groups_for_course((int) $this->course->id),
            prototype_candidates::THIS_COURSE
        );

        $this->assertSame(
            [$parent->id, $child->id],
            array_keys($site),
            'The group should be in tree order, parent before child.'
        );
        $this->assertSame(0, $site[$parent->id]['depth']);
        $this->assertSame(1, $site[$child->id]['depth']);
        // The grandchild belongs to the course, so it is in the course's group
        // — and a root there, since its parent is in the site's.
        $this->assertSame([$grandchild->id], array_keys($own));
        $this->assertSame(0, $own[$grandchild->id]['depth']);
    }

    /**
     * Depth is counted within the group, so a prototype whose parent is not in
     * the group is a root there.
     *
     * Indenting an item two levels because of ancestors that are not on screen
     * tells the author nothing. With the groups drawn by scope this happens
     * only where a course prototype descends from a site one, which is the
     * common shape: a course's own type built on a built-in.
     */
    public function test_depth_is_counted_within_the_group(): void {
        $parent = $this->make_prototype('python3', SITEID);
        $child = $this->make_prototype('cosc131_python3', (int) $this->course->id, $parent->id);

        $options = $this->group(
            $this->candidates->groups_for_course((int) $this->course->id),
            prototype_candidates::THIS_COURSE
        );

        $this->assertSame([$child->id], array_keys($options));
        $this->assertSame(0, $options[$child->id]['depth']);
    }

    /**
     * A prototype's own pages are reachable only where the capability reaches
     * them, which for a site prototype means at system level.
     *
     * The page and the pages it links to have to agree about this. Both
     * `edit.php` and `usage.php` take their context from the prototype's own
     * course, so a teacher holding the capability in one course does not hold
     * it over a site prototype — and the course prototypes page lists the site
     * prototypes beside the course's own. Every built-in row linked to a
     * refusal until the list asked this question first.
     */
    public function test_only_reachable_pages_are_offered(): void {
        $sitewide = $this->make_prototype('python3', SITEID);
        $mine = $this->make_prototype('cosc131_python3', (int) $this->course->id);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        // Their own course's prototype: both pages.
        $this->assertTrue(prototype_candidates::may_manage($mine));
        $this->assertTrue(prototype_candidates::may_edit($mine));

        // A site prototype: neither, because the capability is theirs at
        // course level only.
        $this->assertFalse(prototype_candidates::may_manage($sitewide));
        $this->assertFalse(prototype_candidates::may_edit($sitewide));

        // An administrator gets both, and the editor is the stricter test:
        // only an administrator passes it for a site prototype.
        $this->setAdminUser();
        $this->assertTrue(prototype_candidates::may_manage($sitewide));
        $this->assertTrue(prototype_candidates::may_edit($sitewide));
    }

    /**
     * An author may use the prototypes of a course they can manage, and the
     * site's, and not those of a course they cannot.
     */
    public function test_a_course_may_be_used_only_with_the_capability(): void {
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertTrue($this->candidates->may_use_course((int) $this->course->id));
        $this->assertTrue($this->candidates->may_use_course(SITEID));
        $this->assertFalse($this->candidates->may_use_course((int) $other->id));
    }

    /**
     * A question keeps a prototype it already has, whatever the author's rights
     * in its course: the check is on changing the reference, not on keeping it.
     *
     * Otherwise an author with rights only in their own course could never edit
     * a question that borrows from another, which would protect nothing — the
     * reference was authorised when it was made — and would make the question
     * uneditable.
     */
    public function test_an_existing_reference_may_always_be_kept(): void {
        $other = $this->getDataGenerator()->create_course();
        $elsewhere = $this->make_prototype('someone_elses', (int) $other->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertTrue($this->candidates->may_choose($elsewhere->id, $elsewhere->id));
        $this->assertFalse($this->candidates->may_choose($elsewhere->id, null));
    }

    /**
     * Only courses with prototypes of their own are offered in the second step,
     * since choosing one with none would show an empty list.
     */
    public function test_only_courses_with_prototypes_are_offered(): void {
        $withsome = $this->getDataGenerator()->create_course(['shortname' => 'HAS-PROTOTYPES']);
        $withnone = $this->getDataGenerator()->create_course(['shortname' => 'HAS-NONE']);
        $this->make_prototype('theirs', (int) $withsome->id);

        $courses = $this->candidates->borrowable_courses((int) $this->course->id);

        $this->assertArrayHasKey((int) $withsome->id, $courses);
        $this->assertArrayNotHasKey((int) $withnone->id, $courses);
        $this->assertArrayNotHasKey((int) $this->course->id, $courses);
    }

    /**
     * The second step lists that course's own prototypes, and not the site's,
     * which the first two groups already offer.
     */
    public function test_the_second_step_lists_only_that_courses_prototypes(): void {
        $other = $this->getDataGenerator()->create_course();
        $theirs = $this->make_prototype('theirs', (int) $other->id);
        $sitewide = $this->make_prototype('python3', SITEID);

        $group = $this->candidates->for_other_course((int) $other->id);

        $this->assertArrayHasKey($theirs->id, $group['options']);
        $this->assertArrayNotHasKey($sitewide->id, $group['options']);
    }

    /**
     * A course the author may not manage yields nothing at all, so the
     * capability is enforced where the list is built and not only where it is
     * shown.
     */
    public function test_the_second_step_refuses_a_course_without_the_capability(): void {
        $other = $this->getDataGenerator()->create_course();
        $this->make_prototype('theirs', (int) $other->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertSame([], $this->candidates->for_other_course((int) $other->id));
    }

    /**
     * Point every CodeRunner question at one prototype, standing in for a
     * question saved against another course's prototype through the picker.
     *
     * @param int $prototypeid the prototype to point them at.
     */
    private function point_every_question_at(int $prototypeid): void {
        global $DB;

        $DB->set_field('question_coderunner_options', 'prototypeid', $prototypeid, []);
        prototype_candidates::reset_caches();
    }
}
