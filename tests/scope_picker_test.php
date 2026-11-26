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
 * Tests of the shared pair of menus.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');

/**
 * Tests of what both forms get from {@see scope_picker}, concentrating on what
 * the prototype editor asks of it and the question form does not: the
 * exclusions that stop a prototype inheriting from itself, and a leading
 * option that is a real answer rather than an unanswered field.
 *
 * The scope rules themselves are tested in `prototype_candidates_test`, and
 * the question form's configuration of this control in the question type's
 * `prototype_picker_test`.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scope_picker::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class scope_picker_test extends \advanced_testcase {
    /** @var prototype_repository prototype storage. */
    private prototype_repository $repo;

    /** @var \stdClass the course the thing being edited belongs to. */
    private \stdClass $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
        $this->course = $this->getDataGenerator()->create_course(['shortname' => 'OWN']);
    }

    /**
     * Create a prototype.
     *
     * @param string $name its name.
     * @param int|null $courseid its scope, defaulting to this course.
     * @param int|null $parentid its parent, if any.
     * @return prototype the prototype.
     */
    private function make_prototype(string $name, ?int $courseid = null, ?int $parentid = null): prototype {
        return $this->repo->create(
            $name,
            $courseid ?? (int) $this->course->id,
            new override_set(['template' => 'T'], $name),
            $parentid
        );
    }

    /**
     * Build a form carrying one picker, configured as the caller asks.
     *
     * @param scope_picker $picker the picker under test.
     * @param int|null $selectedid the prototype currently chosen.
     * @return \MoodleQuickForm the built form.
     */
    private function build(scope_picker $picker, ?int $selectedid = null): \MoodleQuickForm {
        $form = new class (null, [
            'picker' => $picker,
            'courseid' => (int) $this->course->id,
            'selectedid' => $selectedid,
        ]) extends \moodleform {
            /**
             * One group holding the pair, as both real forms do.
             */
            protected function definition() {
                [$elements, $separators] = $this->_customdata['picker']->elements(
                    $this->_form,
                    $this->_customdata['courseid'],
                    $this->_customdata['selectedid']
                );
                $this->_form->addGroup($elements, 'g', 'Label', $separators, false);
            }
        };

        $mform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
        $picker->settle($mform, 'g', (int) $this->course->id, $selectedid);
        return $mform;
    }

    /**
     * One element of the group, by name.
     *
     * @param \MoodleQuickForm $mform the built form.
     * @param string $name the element wanted.
     * @return object|null the element, or null if the group has no such one.
     */
    private function element(\MoodleQuickForm $mform, string $name) {
        foreach ($mform->getElement('g')->getElements() as $element) {
            if ($element->getName() === $name) {
                return $element;
            }
        }
        return null;
    }

    /**
     * The values the prototype menu offers, whichever element it is.
     *
     * @param \MoodleQuickForm $mform the built form.
     * @return string[] the option values, in order.
     */
    private function values(\MoodleQuickForm $mform): array {
        $menu = $this->element($mform, 'thing');
        $values = [];
        if ($menu->getType() === 'selectgroups') {
            foreach ($menu->_optGroups as $group) {
                foreach ($group['options'] as $option) {
                    $values[] = (string) $option['attr']['value'];
                }
            }
            return $values;
        }
        foreach ($menu->_options as $option) {
            $values[] = (string) $option['attr']['value'];
        }
        return $values;
    }

    /**
     * What a menu is showing as its selection.
     *
     * @param object $element the menu.
     * @return string the selected value, or '' for none.
     */
    private function selection($element): string {
        $values = method_exists($element, 'getSelected') ? $element->getSelected() : $element->getValue();
        $values = array_values((array) $values);
        return $values === [] ? '' : (string) $values[0];
    }

    /**
     * A picker configured the way the prototype editor configures it.
     *
     * @param int[] $exclude prototypes it may not offer.
     * @return scope_picker the picker.
     */
    private function parent_picker(array $exclude = []): scope_picker {
        return new scope_picker('thing', 'thingcourse', $exclude, 'No parent', 0);
    }

    /**
     * The leading option is the caller's: a real answer for the editor, where
     * a root prototype is a normal thing to be, and an unanswered required
     * field for the question form, where a question must be built on
     * something.
     */
    public function test_the_leading_option_is_the_callers(): void {
        $this->make_prototype('python3', SITEID);

        $editor = $this->build($this->parent_picker());
        $this->assertSame('0', $this->values($editor)[0], 'No parent carries a real value.');
        $this->assertSame('0', $this->selection($this->element($editor, 'thing')));

        $question = $this->build(new scope_picker('thing', 'thingcourse'));
        $this->assertSame('', $this->values($question)[0], 'Choose... carries no value.');
        $this->assertSame('', $this->selection($this->element($question, 'thing')));
    }

    /**
     * A prototype is not offered as its own parent, nor are its descendants,
     * which would make a cycle.
     */
    public function test_exclusions_are_left_out(): void {
        $root = $this->make_prototype('python3', SITEID);
        $mine = $this->make_prototype('mine', (int) $this->course->id, $root->id);
        $child = $this->make_prototype('mine_child', (int) $this->course->id, $mine->id);
        $sibling = $this->make_prototype('other_of_mine', (int) $this->course->id, $root->id);

        $values = $this->values($this->build($this->parent_picker([$mine->id, $child->id])));

        $this->assertNotContains((string) $mine->id, $values, 'A prototype cannot inherit from itself.');
        $this->assertNotContains((string) $child->id, $values, 'Nor from its own descendant.');
        $this->assertContains((string) $sibling->id, $values);
        $this->assertContains((string) $root->id, $values, 'The site library is still on offer.');
    }

    /**
     * A group left empty by the exclusions is not offered, so the menu is
     * plain where only one group survives.
     *
     * The count of groups decides whether the menu is grouped at all, so the
     * exclusions have to be applied before that count is taken -- which is why
     * they are this component's business and not the caller's.
     */
    public function test_a_group_emptied_by_the_exclusions_is_dropped(): void {
        $this->make_prototype('python3', SITEID);
        $onlyone = $this->make_prototype('mine', (int) $this->course->id);

        $grouped = $this->build($this->parent_picker());
        $this->assertSame('selectgroups', $this->element($grouped, 'thing')->getType());

        // With this course's only prototype excluded, the course group is
        // empty and the site's is all that is left.
        $plain = $this->build($this->parent_picker([$onlyone->id]));
        $this->assertSame('select', $this->element($plain, 'thing')->getType());
    }

    /**
     * A prototype may inherit from another course's prototype, which is the
     * same reach the question form has: the course menu is drawn, and choosing
     * a course replaces what the prototype menu holds.
     */
    public function test_another_courses_library_can_be_reached(): void {
        $other = $this->getDataGenerator()->create_course(['shortname' => 'LENDER']);
        $theirs = $this->make_prototype('theirs', (int) $other->id);
        $mine = $this->make_prototype('mine', (int) $this->course->id);

        $coursemenu = $this->element($this->build($this->parent_picker()), 'thingcourse');
        $this->assertNotNull($coursemenu, 'A course that owns prototypes makes the course menu worth drawing.');

        $_POST['thingcourse'] = (string) $other->id;
        $values = $this->values($this->build($this->parent_picker()));

        $this->assertContains((string) $theirs->id, $values);
        $this->assertNotContains((string) $mine->id, $values, 'The chosen course replaces the list.');
    }

    /**
     * An existing child opens on the scope that holds its parent, with the
     * parent selected, even where the author may not browse that course.
     */
    public function test_a_parent_in_another_course_stays_reachable(): void {
        $other = $this->getDataGenerator()->create_course(['shortname' => 'LENDER']);
        $theirs = $this->make_prototype('theirs', (int) $other->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $mform = $this->build($this->parent_picker(), $theirs->id);

        $this->assertSame((string) $other->id, $this->selection($this->element($mform, 'thingcourse')));
        $this->assertSame((string) $theirs->id, $this->selection($this->element($mform, 'thing')));
    }

    /**
     * A submission from a render of another scope is recognised as one.
     *
     * The caller needs this where its leading option is a real answer: *No
     * parent* submitted because the author changed course has to be told from
     * *No parent* submitted because the author means it, and the two POSTs are
     * otherwise identical.
     */
    public function test_a_submission_from_another_scope_is_recognised(): void {
        $picker = $this->parent_picker();

        $this->assertFalse($picker->scope_changed(), 'No render recorded, so nothing is stale.');

        $_POST['thingcourse_rendered'] = (string) $this->course->id;
        $_POST['thingcourse'] = (string) $this->course->id;
        $this->assertFalse($picker->scope_changed(), 'The same scope is not a change.');

        $other = $this->getDataGenerator()->create_course();
        $_POST['thingcourse'] = (string) $other->id;
        $this->assertTrue($picker->scope_changed());
    }

    /**
     * The row reads as a sentence: *From* ‹course› *use* ‹prototype›.
     *
     * The leading word is an element rather than a separator, because a
     * group's separators go between its elements and there is nothing to the
     * left of the first one.
     */
    public function test_the_row_reads_as_a_sentence(): void {
        $other = $this->getDataGenerator()->create_course(['shortname' => 'LENDER']);
        $this->make_prototype('theirs', (int) $other->id);

        $mform = $this->build($this->parent_picker());
        $names = [];
        foreach ($mform->getElement('g')->getElements() as $element) {
            $names[] = $element->getName();
        }

        $this->assertSame(
            ['thingcourse_from', 'thingcourse', 'thingcourse_rendered', 'thing'],
            $names
        );
        $this->assertSame(
            get_string('scopefrom', 'local_coderunner_manager'),
            $this->element($mform, 'thingcourse_from')->toHtml()
        );
        // The hidden field records the scope this render is showing, which is
        // what makes a stale submission recognisable; see scope_changed().
        $this->assertSame(
            (string) $this->course->id,
            (string) $this->element($mform, 'thingcourse_rendered')->getValue()
        );
    }
}
