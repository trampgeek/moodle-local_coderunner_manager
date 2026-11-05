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
 * Unit tests for prototype storage.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

/**
 * Tests that prototype storage keeps its invariants: names unique across a
 * course and the site, no cycles in the parent links, every save a new version,
 * and chains that resolve against each ancestor's current version.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prototype::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_version::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class prototype_repository_test extends \advanced_testcase {
    /** @var prototype_repository the repository under test. */
    private prototype_repository $repo;

    /** @var int a course to scope prototypes to. */
    private int $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
        $this->courseid = (int) $this->getDataGenerator()->create_course()->id;
    }

    /**
     * Creating a prototype gives it a first version, a stable identifier, and
     * the values it was created with.
     */
    public function test_create_makes_a_first_version(): void {
        $prototype = $this->repo->create(
            'python3',
            SITEID,
            new override_set(['template' => 'PY3', 'language' => 'python3']),
            summary: 'Runs Python 3.'
        );

        $this->assertNotEquals(0, $prototype->id);
        $this->assertSame('python3', $prototype->name);
        $this->assertTrue($prototype->is_site_prototype());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $prototype->uuid);

        $version = $prototype->currentversion;
        $this->assertNotNull($version);
        $this->assertSame(1, $version->version);
        $this->assertTrue($version->is_root());
        $this->assertSame('PY3', $version->overrides->get('template'));
        $this->assertSame('Runs Python 3.', $version->summary);
    }

    /**
     * An empty string in the overrides survives the round trip through JSON as
     * an override, not as an absent field. This is the rule the whole design
     * rests on, so it is worth checking at the storage boundary too.
     */
    public function test_an_empty_override_survives_storage(): void {
        $created = $this->repo->create(
            'blanker',
            $this->courseid,
            new override_set(['testsplitterre' => '', 'template' => 'x'])
        );

        $reloaded = $this->repo->get_or_fail($created->id)->currentversion->overrides;
        $this->assertTrue($reloaded->has('testsplitterre'));
        $this->assertSame('', $reloaded->get('testsplitterre'));
        $this->assertFalse($reloaded->has('grader'), 'An unmentioned field must stay absent.');
    }

    /**
     * Every save is a new version and nothing is overwritten, so earlier states
     * remain readable.
     */
    public function test_each_save_adds_a_version(): void {
        $prototype = $this->repo->create('p', $this->courseid, new override_set(['template' => 'first']));

        $prototype = $this->repo->save_new_version(
            $prototype,
            new override_set(['template' => 'second']),
            commitmessage: 'Changed the template.'
        );

        $this->assertSame(2, $prototype->currentversion->version);
        $this->assertSame('second', $prototype->currentversion->overrides->get('template'));
        $this->assertSame('Changed the template.', $prototype->currentversion->commitmessage);

        $history = $this->repo->history($prototype->id);
        $this->assertSame([2, 1], array_keys($history));
        $this->assertSame('first', $history[1]->overrides->get('template'));
    }

    /**
     * A name may not be reused within a course.
     */
    public function test_a_name_cannot_repeat_within_a_course(): void {
        $this->repo->create('dup', $this->courseid, new override_set());

        $this->expectException(\moodle_exception::class);
        $this->repo->create('dup', $this->courseid, new override_set());
    }

    /**
     * A course prototype may not take the name of a site prototype, so a course
     * can never shadow a site one.
     */
    public function test_a_course_prototype_cannot_shadow_a_site_prototype(): void {
        $this->repo->create('python3', SITEID, new override_set());

        $this->assertSame('nameinuseatsite', $this->repo->name_clash('python3', $this->courseid));
        $this->expectException(\moodle_exception::class);
        $this->repo->create('python3', $this->courseid, new override_set());
    }

    /**
     * And a new site prototype may not take a name a course already uses, since
     * names must be unique across the two taken together.
     */
    public function test_a_site_prototype_cannot_take_a_name_a_course_uses(): void {
        $this->repo->create('mytype', $this->courseid, new override_set());

        $this->assertSame('nameinuseincourse', $this->repo->name_clash('mytype', SITEID));
        $this->expectException(\moodle_exception::class);
        $this->repo->create('mytype', SITEID, new override_set());
    }

    /**
     * Two different courses may use the same name, since the picker lists
     * another course's prototypes under that course's name.
     */
    public function test_two_courses_may_use_the_same_name(): void {
        $other = (int) $this->getDataGenerator()->create_course()->id;

        $first = $this->repo->create('shared', $this->courseid, new override_set());
        $second = $this->repo->create('shared', $other, new override_set());

        $this->assertNotEquals($first->id, $second->id);
        $this->assertNull($this->repo->name_clash('shared', $other, $second->id));
    }

    /**
     * Renaming is not a content change, so it does not create a version.
     */
    public function test_renaming_does_not_create_a_version(): void {
        $prototype = $this->repo->create('oldname', $this->courseid, new override_set());

        $renamed = $this->repo->rename($prototype, 'newname');

        $this->assertSame('newname', $renamed->name);
        $this->assertSame(1, $renamed->currentversion->version);
        $this->assertCount(1, $this->repo->history($renamed->id));
    }

    /**
     * A rename onto an existing name is refused.
     */
    public function test_renaming_onto_an_existing_name_is_refused(): void {
        $this->repo->create('taken', $this->courseid, new override_set());
        $other = $this->repo->create('free', $this->courseid, new override_set());

        $this->expectException(\moodle_exception::class);
        $this->repo->rename($other, 'taken');
    }

    /**
     * A prototype cannot be its own parent.
     */
    public function test_a_prototype_cannot_be_its_own_parent(): void {
        $prototype = $this->repo->create('self', $this->courseid, new override_set());

        $this->expectException(\moodle_exception::class);
        $this->repo->save_new_version($prototype, new override_set(), $prototype->id);
    }

    /**
     * Nor can it take a descendant as its parent, which is the cycle that is
     * actually easy to create by accident.
     */
    public function test_a_descendant_cannot_become_the_parent(): void {
        $root = $this->repo->create('root', $this->courseid, new override_set());
        $child = $this->repo->create('child', $this->courseid, new override_set(), $root->id);
        $grandchild = $this->repo->create('grandchild', $this->courseid, new override_set(), $child->id);

        $this->expectException(\moodle_exception::class);
        $this->repo->save_new_version($root, new override_set(), $grandchild->id);
    }

    /**
     * Changing a parent affects only the new version; earlier versions keep the
     * parent they were saved with.
     */
    public function test_an_earlier_version_keeps_its_own_parent(): void {
        $first = $this->repo->create('first', $this->courseid, new override_set());
        $second = $this->repo->create('second', $this->courseid, new override_set());
        $child = $this->repo->create('child', $this->courseid, new override_set(), $first->id);

        $child = $this->repo->save_new_version($child, new override_set(), $second->id);

        $history = $this->repo->history($child->id);
        $this->assertSame($second->id, $history[2]->parentid);
        $this->assertSame($first->id, $history[1]->parentid);
    }

    /**
     * A chain resolves root first, through each ancestor's current version.
     */
    public function test_a_chain_resolves_root_first(): void {
        $root = $this->repo->create(
            'python3',
            SITEID,
            new override_set(['template' => 'PY3', 'language' => 'python3', 'cputimelimitsecs' => 5])
        );
        $middle = $this->repo->create(
            'scratchpad',
            SITEID,
            new override_set(['template' => 'SCRATCH', 'uiplugin' => 'scratchpad']),
            $root->id
        );
        $leaf = $this->repo->create(
            'cosc131',
            $this->courseid,
            new override_set(['cputimelimitsecs' => 2]),
            $middle->id
        );

        $chain = $this->repo->chain_for($leaf->id, new override_set(['globalextra' => 'data'], 'the question'));
        $settings = $chain->resolve();

        $this->assertSame(4, $chain->depth());
        $this->assertSame('SCRATCH', $settings->get('template'));
        $this->assertSame('python3', $settings->get('language'));
        $this->assertSame(2, $settings->get('cputimelimitsecs'));
        $this->assertSame('data', $settings->get('globalextra'));
        $this->assertSame('the question', $settings->source_of('globalextra'));
        $this->assertStringStartsWith('scratchpad@', $settings->source_of('template'));
    }

    /**
     * A chain follows each ancestor to its *current* version, so editing an
     * ancestor changes what its descendants resolve to.
     */
    public function test_a_chain_follows_the_parents_current_version(): void {
        $root = $this->repo->create('root', $this->courseid, new override_set(['template' => 'old']));
        $child = $this->repo->create('child', $this->courseid, new override_set(), $root->id);

        $this->assertSame('old', $this->repo->chain_for($child->id)->resolve()->get('template'));

        $this->repo->save_new_version($root, new override_set(['template' => 'new']));

        $this->assertSame('new', $this->repo->chain_for($child->id)->resolve()->get('template'));
    }

    /**
     * Cycles can arrive in the tables through a restore rather than through this
     * API, so building a chain detects them rather than looping forever.
     */
    public function test_a_stored_cycle_is_detected_when_building_a_chain(): void {
        global $DB;

        $first = $this->repo->create('a', $this->courseid, new override_set());
        $second = $this->repo->create('b', $this->courseid, new override_set(), $first->id);
        // Write a cycle directly, as a bad restore could.
        $DB->set_field(
            prototype_repository::VERSIONS,
            'parentid',
            $second->id,
            ['id' => $first->currentversion->id]
        );

        $this->expectException(\coding_exception::class);
        $this->repo->chain_for($second->id);
    }

    /**
     * Children are found through each candidate's current version.
     */
    public function test_children_are_listed(): void {
        $root = $this->repo->create('root', $this->courseid, new override_set());
        $childa = $this->repo->create('childa', $this->courseid, new override_set(), $root->id);
        $childb = $this->repo->create('childb', $this->courseid, new override_set(), $root->id);
        $this->repo->create('unrelated', $this->courseid, new override_set());

        $children = $this->repo->children_of($root->id);

        $this->assertSame([$childa->id, $childb->id], array_keys($children));
    }

    /**
     * A prototype other prototypes derive from cannot be deleted.
     */
    public function test_a_prototype_in_use_cannot_be_deleted(): void {
        $root = $this->repo->create('root', $this->courseid, new override_set());
        $this->repo->create('child', $this->courseid, new override_set(), $root->id);

        $this->expectException(\moodle_exception::class);
        $this->repo->delete($root);
    }

    /**
     * Deleting an unused prototype removes its whole history.
     */
    public function test_deleting_removes_the_history(): void {
        global $DB;

        $prototype = $this->repo->create('doomed', $this->courseid, new override_set());
        $this->repo->save_new_version($prototype, new override_set(['template' => 'x']));

        $this->repo->delete($this->repo->get_or_fail($prototype->id));

        $this->assertNull($this->repo->get($prototype->id));
        $this->assertSame(0, $DB->count_records(prototype_repository::VERSIONS, ['prototypeid' => $prototype->id]));
    }

    /**
     * A course author is offered their own course's prototypes and the site
     * prototypes, and not another course's.
     */
    public function test_listing_for_a_course_includes_site_prototypes_only(): void {
        $other = (int) $this->getDataGenerator()->create_course()->id;

        $site = $this->repo->create('sitewide', SITEID, new override_set());
        $mine = $this->repo->create('mine', $this->courseid, new override_set());
        $theirs = $this->repo->create('theirs', $other, new override_set());

        $listed = array_keys($this->repo->list_for_course($this->courseid));

        $this->assertContains($site->id, $listed);
        $this->assertContains($mine->id, $listed);
        $this->assertNotContains($theirs->id, $listed);
    }

    /**
     * The stable identifier is not unique on a site, so lookups by it return
     * every match for the caller to choose between by scope.
     */
    public function test_finding_by_uuid_returns_every_copy(): void {
        $other = (int) $this->getDataGenerator()->create_course()->id;

        $original = $this->repo->create('orig', $this->courseid, new override_set());
        $copy = $this->repo->create('copy', $other, new override_set(), uuid: $original->uuid);

        $found = array_keys($this->repo->find_by_uuid($original->uuid));

        $this->assertCount(2, $found);
        $this->assertContains($original->id, $found);
        $this->assertContains($copy->id, $found);
    }

    /**
     * Asking for a prototype that does not exist is an error with a message,
     * not a null that propagates.
     */
    public function test_a_missing_prototype_fails_loudly(): void {
        $this->assertNull($this->repo->get(-1));
        $this->expectException(\moodle_exception::class);
        $this->repo->get_or_fail(-1);
    }
}
