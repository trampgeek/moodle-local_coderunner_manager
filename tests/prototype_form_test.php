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
use qtype_coderunner\v6\form_section;
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
    private function build_form(?prototype $prototype, bool $readonly = false): prototype_form {
        $inherited = $prototype && $prototype->parentid()
            ? $this->repo->chain_for($prototype->parentid())->resolve()
            : (new prototype_chain([]))->resolve();

        return new prototype_form(null, [
            'prototype' => $prototype,
            'inherited' => $inherited,
            'own' => $prototype && $prototype->currentversion
                ? $prototype->currentversion->overrides
                : new override_set([], ''),
            'parentid' => $prototype?->parentid() ?? 0,
            'parents' => [0 => 'None'],
            'courseid' => SITEID,
            'readonly' => $readonly,
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
     * Every field of every section the editor shows gets a control, whether
     * on a row of its own or inside the group of the field it belongs to.
     *
     * The template parameters' language and per-try switch are components of
     * the parameters rather than settings of their own, so they sit in that
     * field's group; everything else has a row.
     */
    public function test_every_shown_field_gets_a_control(): void {
        $mform = $this->quickform($this->build_form(null));

        $checked = 0;
        foreach (prototype_form::sections() as $section) {
            $fields = settings_form::fields_of($section);
            $components = settings_form::components_of($fields);
            foreach ($fields as $name => $field) {
                if (isset($components[$name])) {
                    $this->assertTrue(
                        $this->is_group_member($mform, $name),
                        "No control for $name beside the field it belongs to."
                    );
                    $checked++;
                    continue;
                }
                $this->assertTrue(
                    $mform->elementExists($field->iscode ? $name : $name . '_group'),
                    "No control for $name."
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(25, $checked, 'Far fewer fields were offered than the registry holds.');
    }

    /**
     * Whether a component field is offered inside the group of the field that
     * names it.
     *
     * @param \MoodleQuickForm $mform the built form.
     * @param string $name the component's name.
     * @return bool true if it is there.
     */
    private function is_group_member(\MoodleQuickForm $mform, string $name): bool {
        foreach (\qtype_coderunner\v6\field_registry::all() as $principal) {
            if (!in_array($name, $principal->perlayerwith, true)) {
                continue;
            }
            if (!$mform->elementExists($principal->name . '_group')) {
                return false;
            }
            foreach ($mform->getElement($principal->name . '_group')->getElements() as $element) {
                if ($element->getName() === $name) {
                    return true;
                }
            }
        }
        return false;
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

    /**
     * A built-in is shown frozen rather than refused: its settings have to be
     * readable by anyone choosing it, but every release rewrites it, so an
     * edit would be lost.
     */
    public function test_a_read_only_form_offers_no_save_button(): void {
        $builtin = $this->repo->create(
            'python3_builtin',
            SITEID,
            new override_set(['template' => 'STOCK'], 'python3_builtin'),
            null,
            '',
            '',
            true
        );

        $mform = $this->quickform($this->build_form($builtin, true));

        $this->assertFalse($mform->elementExists('buttonar'), 'A frozen form still offered a save button.');
        $this->assertTrue($mform->elementExists('template'), 'A frozen form hid the settings instead of showing them.');
        $this->assertFalse(
            $mform->elementExists(prototype_form::RELOAD_BUTTON),
            'A form nobody can change offered to rebuild itself.'
        );
    }

    /**
     * Changing the parent rebuilds the form, through a hidden no-submit button
     * the menu presses.
     *
     * Everything the form says about a field but the author's own value comes
     * from the parent chain: what the field inherits, which ancestor supplies
     * it, and which test case fields the prototype offers. None of that can be
     * worked out in the browser, so the server builds it again. Without this
     * the form went on answering for the old parent until the prototype was
     * saved and the page reloaded, which is how a child of python3_scratchpad
     * came to show an empty template and *Parent: not set*.
     */
    public function test_a_change_of_parent_can_rebuild_the_form(): void {
        $mform = $this->quickform($this->build_form(null));

        $this->assertTrue(
            $mform->elementExists(prototype_form::RELOAD_BUTTON),
            'The parent menu had no way to rebuild the form.'
        );
        $this->assertTrue(
            $mform->isNoSubmitButton(prototype_form::RELOAD_BUTTON),
            'The reload button would save the prototype instead of rebuilding the form.'
        );

        // And it marks itself as skipping the form's client-side validation,
        // which it can only do if it was registered before it was created:
        // MoodleQuickForm_submit asks as it is built. Registered the other way
        // round, pressing it runs every client rule on the form.
        $button = $mform->getElement(prototype_form::RELOAD_BUTTON);
        $this->assertSame(1, $button->getAttribute('data-no-submit'));
        $this->assertStringContainsString(
            'skipClientValidation',
            (string) $button->getAttribute('onclick')
        );
    }

    /**
     * The submitted parent is what the form answers for, so the rebuilt form
     * shows what the newly chosen parent supplies.
     */
    public function test_the_submitted_parent_wins(): void {
        $parent = $this->repo->create('the_parent', SITEID, new override_set([], 'the_parent'));
        $child = $this->repo->create('the_child', SITEID, new override_set([], 'the_child'), $parent->id);
        $other = $this->repo->create('another', SITEID, new override_set([], 'another'));

        $this->assertSame(
            $other->id,
            prototype_form::effective_parentid($child, $other->id, null),
            'A change of parent was ignored in favour of the stored one.'
        );
        $this->assertNull(
            prototype_form::effective_parentid($child, 0, null),
            '*No parent* was read as "nothing submitted".'
        );
        $this->assertSame(
            $parent->id,
            prototype_form::effective_parentid($child, null, null),
            'A form that was not submitted should answer for the stored parent.'
        );
    }

    /**
     * A prototype being created inherits from Site defaults unless the author
     * says otherwise, which is where design section 6 puts the root of every
     * chain — and the menu says so, rather than reading *No parent* beside a
     * form showing Site defaults' values as inherited.
     */
    public function test_a_new_prototype_starts_from_site_defaults(): void {
        $defaults = $this->repo->create(
            'Site defaults',
            SITEID,
            new override_set([], 'Site defaults'),
            null,
            '',
            '',
            true,
            '',
            true
        );

        $this->assertSame(
            $defaults->id,
            prototype_form::effective_parentid(null, null, $defaults->id)
        );

        $form = new prototype_form(null, [
            'prototype' => null,
            'inherited' => (new prototype_chain([]))->resolve(),
            'own' => new override_set([], ''),
            'parentid' => $defaults->id,
            'parents' => [0 => 'None', $defaults->id => 'Site defaults'],
            'courseid' => SITEID,
            'readonly' => false,
        ]);

        $this->assertSame(
            [$defaults->id],
            $this->quickform($form)->getElement(prototype_form::PARENT_FIELD)->getSelected(),
            'The menu did not show the parent the form was built against.'
        );
    }

    /**
     * An author continuing a draft is shown the draft, not the published
     * version it was drafted from.
     *
     * The overrides already came from the draft; the summary, the
     * documentation and the parent did not, so three of a draft's fields read
     * as the published version's, and saving again wrote the published values
     * over the draft's own.
     */
    public function test_a_draft_is_what_the_form_continues_from(): void {
        $published = $this->repo->create('a_parent', SITEID, new override_set([], 'a_parent'));
        $drafted = $this->repo->create('another_parent', SITEID, new override_set([], 'another_parent'));
        $prototype = $this->repo->create(
            'in_progress',
            SITEID,
            new override_set([], 'in_progress'),
            $published->id,
            'The published summary'
        );
        $prototype = $this->repo->save_draft(
            $prototype,
            new override_set(['template' => 'DRAFTED'], 'in_progress'),
            $drafted->id,
            'The drafted summary'
        );

        $this->assertSame(
            $drafted->id,
            prototype_form::effective_parentid($prototype, null, null),
            'The form inherited from the published parent while editing a draft.'
        );

        $editing = $prototype->draftversion;
        $form = new prototype_form(null, [
            'prototype' => $prototype,
            'editing' => $editing,
            'inherited' => $this->repo->chain_for($drafted->id)->resolve(),
            'own' => $editing->overrides,
            'parentid' => $drafted->id,
            'parents' => [0 => 'None', $drafted->id => 'another_parent'],
            'courseid' => SITEID,
            'readonly' => false,
        ]);
        $mform = $this->quickform($form);

        $this->assertSame(
            'The drafted summary',
            $mform->getElement('summary')->getValue(),
            'The form showed the published summary while continuing a draft.'
        );
    }

    /**
     * A prototype is not saved with a merging document that cannot be read,
     * since every question built on it merges that document.
     *
     * Only readability is checked. Template parameters written in another
     * language are a program that produces JSON when it runs, and either
     * document may hold Twig, which is JSON only once rendered — so neither
     * is judged here.
     */
    public function test_an_unreadable_merging_document_is_refused(): void {
        $base = [
            'id' => 0,
            'name' => 'a_prototype',
            'courseid' => SITEID,
            'templateparamslang' => 'None',
        ];
        $form = $this->build_form(null);

        $broken = $form->validation($base + ['uiparameters' => '{"font_size": }'], []);
        $this->assertArrayHasKey('uiparameters', $broken);
        $this->assertSame(
            get_string('invalidjson', 'local_coderunner_manager'),
            $broken['uiparameters']
        );

        // Valid JSON, an empty document and a document holding Twig all pass.
        foreach (['{"font_size": 14}', '', '   ', '{"n": {{ 1 + 1 }} }'] as $acceptable) {
            $this->assertArrayNotHasKey(
                'uiparameters',
                $form->validation($base + ['uiparameters' => $acceptable], []),
                "'$acceptable' should not be refused."
            );
        }

        // Template parameters are only judged when they claim to be JSON
        // already: in any other language they are a program.
        $this->assertArrayHasKey(
            'templateparams',
            $form->validation($base + ['templateparams' => 'not json at all'], [])
        );
        $this->assertArrayNotHasKey(
            'templateparams',
            $form->validation(
                ['templateparamslang' => 'python3'] + $base + ['templateparams' => 'print("hi")'],
                []
            )
        );
    }

    /**
     * A section's heading counts what this prototype changes, and the marks
     * beside those fields are explained in the section that has them.
     *
     * The marks were already there; the count and the legend were in the
     * question form alone, so the editor marked two test case fields and said
     * nowhere what the marks meant.
     */
    public function test_a_section_header_counts_and_explains_its_changes(): void {
        $parent = $this->repo->create(
            'offers_everything',
            SITEID,
            new override_set(['showstdin' => 1, 'showtestextra' => 1], 'offers_everything')
        );
        $child = $this->repo->create(
            'offers_less',
            SITEID,
            new override_set(['showstdin' => 0, 'showtestextra' => 0], 'offers_less'),
            $parent->id
        );

        $mform = $this->quickform($this->build_form($child));
        $header = settings_form::section_header(form_section::TESTCASEFIELDS);

        $this->assertStringContainsString(
            get_string('sectionchanged', 'qtype_coderunner', 2),
            (string) $mform->getElement($header)->_text,
            'The heading did not say the section held changes.'
        );
        $this->assertTrue(
            $mform->elementExists($header . '_legend'),
            'The section marked two fields and explained nothing.'
        );
    }
    /**
     * Support files have a section of their own, not a place under Identity.
     *
     * They merge along the chain by filename, which is the behaviour of the
     * collected settings and not of the identity fields they used to sit
     * beside. Filed with those, the section carried no change count and the
     * file manager showed only the prototype's own files, so a prototype
     * owning three and inheriting nineteen read as a prototype with three.
     */
    public function test_support_files_have_their_own_section(): void {
        $parent = $this->repo->create('parent', SITEID, new override_set(), files: [
            $this->stored_file('shared.py', 'print(1)'),
        ]);
        $child = $this->repo->create('child', SITEID, new override_set(), $parent->id);

        $mform = $this->quickform($this->build_form_with_draft($child, [
            $this->stored_file('mine.py', 'own'),
        ]));

        $this->assertTrue($mform->elementExists('supportfilesheader'));
        $this->assertTrue($mform->elementExists('supportfiles'));
        $this->assertTrue($mform->elementExists('supportfilesinheritednote'));
        $this->assertTrue($mform->elementExists('supportfilesmarks'));

        // The inherited file is named rather than being put in the manager,
        // which would make the author own a copy of it on the next save.
        $note = $mform->getElement('supportfilesinheritednote')->toHtml();
        $this->assertStringContainsString('shared.py', $note);
        $this->assertStringContainsString('parent', $note);
    }

    /**
     * The header counts what the prototype does that its parent does not, so a
     * redundant copy is not a change.
     *
     * The same rule settings_form::changed_count() settled for overrides. A
     * reparented prototype owning twenty copies of its parent's files would
     * otherwise report twenty changes while changing nothing.
     */
    public function test_the_header_counts_only_files_that_change_something(): void {
        $parent = $this->repo->create('parent', SITEID, new override_set(), files: [
            $this->stored_file('shared.py', 'print(1)'),
            $this->stored_file('changed.py', 'parent'),
        ]);
        $child = $this->repo->create('child', SITEID, new override_set(), $parent->id);

        // One redundant, one shadowing, one its own: two changes, not three.
        $mform = $this->quickform($this->build_form_with_draft($child, [
            $this->stored_file('shared.py', 'print(1)'),
            $this->stored_file('changed.py', 'child'),
            $this->stored_file('mine.py', 'own'),
        ]));

        $label = $mform->getElement('supportfilesheader')->toHtml();
        $this->assertStringContainsString(
            get_string('sectionchanged', 'qtype_coderunner', 2),
            $label,
            'A redundant copy changes nothing, so it is not counted.'
        );

        $marks = $mform->getElement('supportfilesmarks')->toHtml();
        $this->assertStringContainsString('could be removed', $marks, 'The redundant file says so.');
    }

    /**
     * A prototype with no ancestors has nothing to inherit and says so.
     */
    public function test_a_root_prototype_reports_nothing_inherited(): void {
        $root = $this->repo->create('root', SITEID, new override_set());

        $mform = $this->quickform($this->build_form_with_draft($root, [
            $this->stored_file('a.py', 'x'),
        ]));

        $this->assertStringContainsString(
            get_string('supportfilesinheritednone', 'local_coderunner_manager'),
            $mform->getElement('supportfilesinheritednote')->toHtml()
        );
    }

    /**
     * Build the form with a draft area holding the given files.
     *
     * The section reads the draft rather than the stored version, because that
     * is what the author is looking at, so a test of it has to supply one.
     *
     * @param prototype $prototype the prototype being edited.
     * @param \stored_file[] $files the files to put in the draft area.
     * @return prototype_form the form.
     */
    private function build_form_with_draft(prototype $prototype, array $files): prototype_form {
        global $USER;

        $draftid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        foreach ($files as $file) {
            $fs->create_file_from_storedfile([
                'contextid' => \context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => '/',
                'filename' => $file->get_filename(),
            ], $file);
        }

        $inherited = $prototype->parentid()
            ? $this->repo->chain_for($prototype->parentid())->resolve()
            : (new prototype_chain([]))->resolve();

        return new prototype_form(null, [
            'prototype' => $prototype,
            'inherited' => $inherited,
            'own' => $prototype->currentversion->overrides,
            'parentid' => $prototype->parentid() ?? 0,
            'parents' => [0 => 'None'],
            'courseid' => SITEID,
            'readonly' => false,
            'draftitemid' => $draftid,
        ]);
    }

    /**
     * A stored file to seed a draft area or a prototype version from.
     *
     * @param string $filename its name.
     * @param string $content its content, which decides its hash.
     * @return \stored_file the file.
     */
    private function stored_file(string $filename, string $content): \stored_file {
        static $itemid = 0;

        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_coderunner_manager',
            'filearea' => 'testsource',
            'itemid' => ++$itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }
}
