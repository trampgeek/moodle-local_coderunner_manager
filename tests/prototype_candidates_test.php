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
     * The groups are a partition by scope: the course's own prototypes in the
     * first group, the site's in the second, and nothing listed twice.
     *
     * Scope is the only line drawn. A prototype just created is unused by
     * definition, and an earlier grouping by use filed an author's newest work
     * among the site's built-ins at the moment they came to use it.
     */
    public function test_the_default_groups_are_a_partition_by_scope(): void {
        $sitewide = $this->make_prototype('python3', SITEID);
        $mine = $this->make_prototype('cosc131_python3', (int) $this->course->id);

        $groups = $this->candidates->groups_for_course((int) $this->course->id);
        $thiscourse = $this->group($groups, prototype_candidates::THIS_COURSE);
        $site = $this->group($groups, prototype_candidates::SITE);

        $this->assertArrayHasKey($mine->id, $thiscourse);
        $this->assertArrayNotHasKey($mine->id, $site);
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
     * A child made in the course of its parent stays with it.
     *
     * This is what grouping by scope buys and grouping by use could not: use
     * is not hereditary, so a used parent and its unused child landed in
     * different groups, and the child appeared as a root of the group it fell
     * into — which is how two new children of python3_scratchpad came to look
     * like they had not been created.
     */
    public function test_a_new_child_stays_with_its_parent(): void {
        $parent = $this->make_prototype('scratchpad', (int) $this->course->id);
        $child = $this->make_prototype('scratchpad_files', (int) $this->course->id, $parent->id);

        $options = $this->group(
            $this->candidates->groups_for_course((int) $this->course->id),
            prototype_candidates::THIS_COURSE
        );

        $this->assertSame([$parent->id, $child->id], array_keys($options));
        $this->assertSame(1, $options[$child->id]['depth'], 'The child should sit under its parent.');
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
     * A scope lists that course's own prototypes, and not the site's, which
     * the question's own scope already offers.
     */
    public function test_a_scope_lists_only_that_courses_prototypes(): void {
        $other = $this->getDataGenerator()->create_course();
        $theirs = $this->make_prototype('theirs', (int) $other->id);
        $sitewide = $this->make_prototype('python3', SITEID);

        $group = $this->candidates->for_other_course((int) $other->id);

        $this->assertArrayHasKey($theirs->id, $group['options']);
        $this->assertArrayNotHasKey($sitewide->id, $group['options']);
    }

    /**
     * One rule indents both menus that show the library.
     *
     * The question form's prototype picker and the prototype editor's
     * *Parent* showed the same tree differently until they were given
     * this method -- indented against a flat list of bare names -- which is
     * the sort of difference an author notices and no test did.
     *
     * The escaping is asserted with it, because a prototype's name is
     * author-supplied text and an option's text is written into a select
     * unescaped, so this method is where that is dealt with for both menus.
     */
    public function test_one_rule_indents_both_library_menus(): void {
        $this->assertSame(
            'python3',
            prototype_candidates::indented_label(['label' => 'python3', 'depth' => 0]),
            'A root is not indented.'
        );
        $this->assertSame(
            str_repeat('&nbsp;', 4) . 'python3_mine',
            prototype_candidates::indented_label(['label' => 'python3_mine', 'depth' => 1]),
            'Four non-breaking spaces to a level.'
        );
        $this->assertSame(
            str_repeat('&nbsp;', 8) . 'deeper',
            prototype_candidates::indented_label(['label' => 'deeper', 'depth' => 2])
        );
        $this->assertStringNotContainsString(
            '<script>',
            prototype_candidates::indented_label(['label' => '<script>bad()</script>', 'depth' => 0]),
            'A name reaches the select unescaped, so it is escaped here.'
        );
    }

    /**
     * A course the author may not manage yields nothing at all, so the
     * capability is enforced where the list is built and not only where it is
     * shown.
     */
    public function test_a_scope_is_refused_without_the_capability(): void {
        $other = $this->getDataGenerator()->create_course();
        $this->make_prototype('theirs', (int) $other->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertSame([], $this->candidates->for_other_course((int) $other->id));
    }

    /**
     * Except for the prototype the question already uses, which that scope
     * offers on its own.
     *
     * The rule {@see prototype_candidates::may_choose()} applies on the save
     * has to hold in the picker too: a menu that could not show the question's
     * own value would leave an author with rights only in their own course
     * unable to edit such a question at all.
     */
    public function test_a_prototype_in_use_is_offered_without_the_capability(): void {
        $other = $this->getDataGenerator()->create_course();
        $inuse = $this->make_prototype('theirs', (int) $other->id);
        $alsotheirs = $this->make_prototype('their_other_one', (int) $other->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $group = $this->candidates->for_other_course((int) $other->id, $inuse->id);

        $this->assertSame([$inuse->id], array_keys($group['options']));
        $this->assertArrayNotHasKey(
            $alsotheirs->id,
            $group['options'],
            'Nothing else of that course comes with it.'
        );
    }

    /**
     * And a prototype of some third course is not smuggled in by naming it:
     * the scope asked for is the scope answered.
     */
    public function test_a_prototype_in_use_is_offered_only_in_its_own_scope(): void {
        $other = $this->getDataGenerator()->create_course();
        $third = $this->getDataGenerator()->create_course();
        $elsewhere = $this->make_prototype('third_courses', (int) $third->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertSame([], $this->candidates->for_other_course((int) $other->id, $elsewhere->id));
    }

    /**
     * A prototype whose parent is not in the set being shown is a root of it,
     * so a walk from the roots down cannot lose it.
     *
     * This is the library page's bug of 26 November, found by the walkthrough:
     * a prototype made in CourseA on CourseB's prototype saved, said it had
     * saved, and did not appear in CourseA's table. It was bucketed under a
     * parent that was not in the course's list, and the walk never visited
     * that bucket. Nothing complained, which is why this is asserted here
     * rather than left to the rendering.
     */
    public function test_a_prototype_whose_parent_is_absent_is_a_root(): void {
        $other = $this->getDataGenerator()->create_course();
        $elsewhere = $this->make_prototype('theirs', (int) $other->id);
        $mine = $this->make_prototype('mine', (int) $this->course->id, $elsewhere->id);
        $root = $this->make_prototype('my_root', (int) $this->course->id);
        $child = $this->make_prototype('my_child', (int) $this->course->id, $root->id);

        // The set the library page shows for this course, which does not hold
        // the other course's prototype.
        $shown = [];
        foreach ([$mine, $root, $child] as $prototype) {
            $shown[$prototype->id] = $prototype;
        }

        $buckets = prototype_candidates::children_by_parent($shown);
        $rootids = array_map(fn($p) => $p->id, $buckets[0] ?? []);

        $this->assertContains($mine->id, $rootids, 'A prototype with an absent parent must be a root here.');
        $this->assertContains($root->id, $rootids);
        $this->assertNotContains($child->id, $rootids, 'A child whose parent is here stays under it.');
        $this->assertSame([$child->id], array_map(fn($p) => $p->id, $buckets[$root->id]));

        // And every prototype shown is reachable from the roots, which is the
        // property the page needs and the one that was broken.
        $seen = [];
        $walk = function (array $children) use (&$walk, &$seen, $buckets) {
            foreach ($children as $prototype) {
                $seen[] = $prototype->id;
                $walk($buckets[$prototype->id] ?? []);
            }
        };
        $walk($buckets[0] ?? []);
        $this->assertEqualsCanonicalizing(array_keys($shown), $seen);
    }

    /**
     * A prototype's own course, which is the scope the picker opens on, and
     * that course's name for the menu.
     */
    public function test_a_prototype_reports_its_own_course(): void {
        $other = $this->getDataGenerator()->create_course(['shortname' => 'COSC122-26S1']);
        $theirs = $this->make_prototype('theirs', (int) $other->id);

        $this->assertSame((int) $other->id, $this->candidates->course_of($theirs->id));
        $this->assertSame('COSC122-26S1', $this->candidates->course_name((int) $other->id));
        $this->assertNull($this->candidates->course_of(null));
        $this->assertNull($this->candidates->course_of(999999));
    }

    /**
     * The course menu is filtered by capability as well as by what each course
     * owns, so an author is never offered a library they may not build on.
     */
    public function test_only_courses_the_author_may_use_are_offered(): void {
        $allowed = $this->getDataGenerator()->create_course(['shortname' => 'ALLOWED']);
        $forbidden = $this->getDataGenerator()->create_course(['shortname' => 'FORBIDDEN']);
        $this->make_prototype('theirs', (int) $allowed->id);
        $this->make_prototype('not_for_me', (int) $forbidden->id);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $allowed->id, 'editingteacher');
        $this->setUser($teacher);

        $courses = $this->candidates->borrowable_courses((int) $this->course->id);

        $this->assertSame([(int) $allowed->id => 'ALLOWED'], $courses);
    }
}
