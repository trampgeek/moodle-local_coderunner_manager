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
 * Unit tests for the prototype editor's form.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use local_coderunner_manager\form\prototype_form;
use qtype_coderunner\v6\override_set;
use qtype_coderunner\v6\prototype_chain;
use qtype_coderunner\v6\settings_form;

defined('MOODLE_INTERNAL') || die();

/**
 * Building the editor's form is where a missing language string, an element
 * type MoodleQuickForm does not have, or a help button pointing at nothing
 * shows up — all of them at page-view time rather than at save time, and none
 * of them visible to a test of the storage layer. So the form is built here.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class prototype_form_test extends \advanced_testcase {
    /** @var prototype_repository the repository. */
    private prototype_repository $repo;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
    }

    /**
     * Build the form the way edit.php does.
     *
     * @param prototype|null $prototype the prototype being edited.
     * @return prototype_form the form.
     */
    private function quickform(prototype_form $form): \MoodleQuickForm {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        return $property->getValue($form);
    }

    /**
     * Build the form the way edit.php does.
     *
     * @param prototype|null $prototype the prototype being edited.
     * @return prototype_form the form.
     */
    private function build_form(?prototype $prototype): prototype_form {
        $inherited = $prototype && $prototype->parentid()
            ? $this->repo->chain_for($prototype->parentid())->resolve()
            : (new prototype_chain([]))->resolve();

        return new prototype_form(null, [
            'prototype' => $prototype,
            'inherited' => $inherited,
            'own' => $prototype && $prototype->currentversion
                ? $prototype->currentversion->overrides
                : new override_set([], ''),
            'parents' => [0 => 'None'],
            'courseid' => SITEID,
        ]);
    }

    /**
     * The form for a new prototype builds, and offers every settings section
     * the editor claims to show.
     */
    public function test_the_new_prototype_form_builds(): void {
        $form = $this->build_form(null);
        $mform = $this->quickform($form);

        $this->assertTrue($mform->elementExists('name'));
        $this->assertTrue($mform->elementExists('parentid'));
        $this->assertTrue($mform->elementExists('documentation'));
        $this->assertTrue($mform->elementExists('commitmessage'));

        foreach (prototype_form::sections() as $section) {
            $this->assertTrue(
                $mform->elementExists('section_' . $section->value),
                "The editor claims to show section {$section->value} but rendered no header for it."
            );
        }
    }

    /**
     * Every field of every section the editor shows gets a control and an
     * override checkbox.
     */
    public function test_every_shown_field_gets_a_control(): void {
        $mform = $this->quickform($this->build_form(null));

        $checked = 0;
        foreach (prototype_form::sections() as $section) {
            foreach (settings_form::fields_of($section) as $name => $field) {
                $this->assertTrue($mform->elementExists($name . '_group'), "No control for $name.");
                $checked++;
            }
        }

        $this->assertGreaterThan(25, $checked, 'Far fewer fields were offered than the registry holds.');
    }

    /**
     * The form for an existing prototype builds and shows its stored identity.
     */
    public function test_the_edit_form_builds_for_an_existing_prototype(): void {
        $parent = $this->repo->create('a_parent', SITEID, new override_set(['template' => 'P'], 'a_parent'));
        $child = $this->repo->create(
            'a_child',
            SITEID,
            new override_set(['grader' => 'TemplateGrader'], 'a_child'),
            $parent->id,
            'A child of a_parent'
        );

        $mform = $this->quickform($this->build_form($child));

        $this->assertTrue($mform->elementExists('name'));
        $this->assertTrue($mform->elementExists('grader_group'));
    }

    /**
     * The parent picker must not offer a prototype's own descendants, since
     * choosing one would make a cycle and be refused on save.
     */
    public function test_descendants_are_found_to_any_depth(): void {
        $root = $this->repo->create('root', SITEID, new override_set([], 'root'));
        $mid = $this->repo->create('mid', SITEID, new override_set([], 'mid'), $root->id);
        $leaf = $this->repo->create('leaf', SITEID, new override_set([], 'leaf'), $mid->id);
        $other = $this->repo->create('other', SITEID, new override_set([], 'other'));

        $descendants = $this->repo->descendant_ids($root->id);

        sort($descendants);
        $expected = [$mid->id, $leaf->id];
        sort($expected);
        $this->assertSame($expected, $descendants);
        $this->assertNotContains($other->id, $descendants);
        $this->assertNotContains($root->id, $descendants, 'A prototype is not its own descendant.');
    }

    /**
     * A prototype with nothing below it has no descendants, which is what lets
     * the picker offer everything else.
     */
    public function test_a_leaf_has_no_descendants(): void {
        $leaf = $this->repo->create('alone', SITEID, new override_set([], 'alone'));

        $this->assertSame([], $this->repo->descendant_ids($leaf->id));
    }
}
