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
 * Unit tests for populating the prototype tables from CodeRunner 5 data.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\legacy_converter;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');

/**
 * Tests that the installer is idempotent, carries over only what CodeRunner 5
 * prototypes actually supplied, and refuses to guess when a name is claimed by
 * more than one prototype question.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_installer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(legacy_converter::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class prototype_installer_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The built-ins install once and then report themselves unchanged, so the
     * installer can run on every upgrade without piling up versions.
     */
    public function test_builtins_install_once_and_are_then_unchanged(): void {
        $installer = new prototype_installer();

        $first = $installer->install_builtins();
        $this->assertGreaterThan(0, $first['created'], 'The shipped file should contain prototypes.');
        $this->assertSame(0, $first['updated']);
        $this->assertSame(0, $first['skipped']);

        $second = $installer->install_builtins();
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame($first['created'], $second['unchanged']);
    }

    /**
     * Built-ins become site prototypes marked built-in, each with a first
     * version and documentation taken from the question text.
     */
    public function test_builtins_are_site_prototypes(): void {
        (new prototype_installer())->install_builtins();
        $repo = new prototype_repository();

        $python3 = null;
        foreach ($repo->list_for_course(SITEID) as $prototype) {
            if ($prototype->name === 'python3') {
                $python3 = $prototype;
            }
        }

        $this->assertNotNull($python3, 'The python3 built-in should have been installed.');
        $this->assertTrue($python3->builtin);
        $this->assertTrue($python3->is_site_prototype());
        $this->assertSame(1, $python3->currentversion->version);
        $this->assertNotSame('', $python3->currentversion->overrides->get('template'));

        // A built-in is not a root: it derives from Site defaults, so that a
        // field it does not set still has one editable place it comes from.
        $this->assertFalse($python3->currentversion->is_root());
        $this->assertSame($repo->site_defaults()->id, $python3->parentid());
    }

    /**
     * A changed definition adds a version rather than overwriting, so the
     * history records what each release changed.
     */
    public function test_a_changed_builtin_adds_a_version(): void {
        $installer = new prototype_installer();
        $installer->install_builtins();
        $repo = new prototype_repository();

        $before = null;
        foreach ($repo->list_for_course(SITEID) as $prototype) {
            if ($prototype->name === 'python3') {
                $before = $prototype;
            }
        }
        $repo->save_new_version(
            $before,
            $before->currentversion->overrides->with('template', 'LOCALLY EDITED'),
            null,
            '',
            $before->currentversion->documentation
        );

        $counts = $installer->install_builtins();

        $this->assertSame(1, $counts['updated'], 'The edited built-in should be restored by the installer.');
        $after = $repo->get_or_fail($before->id);
        $this->assertSame(3, $after->currentversion->version);
        $this->assertNotSame('LOCALLY EDITED', $after->currentversion->overrides->get('template'));
    }

    /**
     * A user prototype question is converted once, and a later run leaves the
     * prototype alone however much the two have diverged.
     *
     * The snapshot has to stay re-runnable — for a conversion that stopped
     * part way, and for a course restored from a pre-6.0 backup — so it runs
     * on every upgrade. Written as an upsert, each run re-read every prototype
     * question and wrote a new version of any prototype that differed, which
     * silently reverted an author's work in the prototype editor to whatever
     * the dead prototype question still said. It is also what the legacy
     * prototype-question banner promises does not happen.
     */
    public function test_an_edited_prototype_is_not_reverted_by_a_later_run(): void {
        $installer = new prototype_installer();
        $repo = new prototype_repository();

        $this->make_prototype_question('reverted_me');
        $first = $installer->snapshot_user_prototypes();
        $this->assertSame(1, $first['created']);

        $prototype = $repo->find_by_name_for_course('reverted_me', $this->courseid);
        $this->assertNotNull($prototype);
        $repo->save_new_version(
            $prototype,
            $prototype->currentversion->overrides->with('template', 'EDITED IN THE MANAGER'),
            $prototype->parentid(),
            '',
            $prototype->currentversion->documentation,
            'An author at work.'
        );

        $second = $installer->snapshot_user_prototypes();

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated'], 'The prototype question was re-imported over the edit.');
        $this->assertSame(1, $second['unchanged']);

        $after = $repo->get_or_fail($prototype->id);
        $this->assertSame(
            'EDITED IN THE MANAGER',
            $after->currentversion->overrides->get('template'),
            'The author\'s edit was reverted.'
        );
        $this->assertSame(2, $after->currentversion->version, 'A version was added on top of the edit.');
    }

    /**
     * A prototype question that has not been converted yet is still converted
     * by a later run, which is what keeps the snapshot a recovery path.
     */
    public function test_a_new_prototype_question_is_still_converted_later(): void {
        $installer = new prototype_installer();

        $this->make_prototype_question('first_one');
        $this->assertSame(1, $installer->snapshot_user_prototypes()['created']);

        // What a restored course brings: a prototype question the manager has
        // not seen.
        $this->make_prototype_question('arrived_later');
        $counts = $installer->snapshot_user_prototypes();

        $this->assertSame(1, $counts['created'], 'The new prototype question was not converted.');
        $this->assertSame(1, $counts['unchanged'], 'The first one should have been left alone.');
    }

    /**
     * The Site defaults prototype is created once and sits at the root of every
     * chain, holding the values CodeRunner 5 kept in code and admin settings.
     */
    public function test_site_defaults_is_created_as_the_root(): void {
        $installer = new prototype_installer();
        $repo = new prototype_repository();

        $defaults = $installer->ensure_site_defaults();

        $this->assertTrue($defaults->issitedefaults);
        $this->assertTrue($defaults->is_site_prototype());
        $this->assertFalse($defaults->builtin, 'It is edited by administrators, not reinstalled by releases.');
        $this->assertNull($defaults->parentid(), 'Nothing sits above it.');
        $this->assertSame($defaults->id, $repo->site_defaults()->id);

        // It supplies the fields that CodeRunner 5 never inherited.
        $overrides = $defaults->currentversion->overrides;
        foreach (['penaltyregime', 'allornothing', 'hidecheck', 'answerboxlines', 'displayfeedback'] as $field) {
            $this->assertTrue($overrides->has($field), "Site defaults should supply $field.");
        }
    }

    /**
     * It is created once and never rewritten, because administrators edit it and
     * an upgrade that overwrote it would discard their settings silently.
     */
    public function test_site_defaults_is_never_overwritten(): void {
        $installer = new prototype_installer();
        $repo = new prototype_repository();

        $defaults = $installer->ensure_site_defaults();
        $edited = $repo->save_new_version(
            $defaults,
            $defaults->currentversion->overrides->with('penaltyregime', '0, 0, 0'),
            null
        );

        $installer->install_builtins();
        $installer->ensure_site_defaults();

        $after = $repo->get_or_fail($edited->id);
        $this->assertSame('0, 0, 0', $after->currentversion->overrides->get('penaltyregime'));
        $this->assertSame($edited->currentversion->version, $after->currentversion->version);
    }

    /**
     * It cannot be deleted, since every chain roots at it.
     */
    public function test_site_defaults_cannot_be_deleted(): void {
        $defaults = (new prototype_installer())->ensure_site_defaults();

        $this->expectException(\moodle_exception::class);
        (new prototype_repository())->delete($defaults);
    }

    /**
     * Both the built-ins and the migrated user prototypes derive from Site
     * defaults, so a field no prototype sets still has one editable place it
     * comes from. The design doc calls migrated user prototypes "root
     * prototypes", meaning directly under Site defaults rather than parentless:
     * it also requires them to pick the test case field switches up from Site
     * defaults, which only works if it is in their chain.
     */
    public function test_builtins_derive_from_site_defaults(): void {
        $installer = new prototype_installer();
        $repo = new prototype_repository();

        $installer->install_builtins();
        $defaults = $repo->site_defaults();

        $children = $repo->children_of($defaults->id);
        $this->assertNotEmpty($children);
        foreach ($children as $child) {
            $this->assertSame($defaults->id, $child->parentid());
        }

        // And a built-in's chain roots at Site defaults.
        $python3 = null;
        foreach ($children as $child) {
            if ($child->name === 'python3') {
                $python3 = $child;
            }
        }
        $this->assertNotNull($python3);
        $chain = $repo->chain_for($python3->id);
        $this->assertSame(2, $chain->depth());
        $this->assertStringStartsWith($defaults->name . '@', $chain->source_labels()[0]);
    }

    /**
     * A value no prototype sets resolves from Site defaults, with provenance
     * naming it, rather than silently falling to a registry default.
     */
    public function test_unset_fields_resolve_from_site_defaults(): void {
        $installer = new prototype_installer();
        $repo = new prototype_repository();
        $installer->install_builtins();

        $defaults = $repo->site_defaults();
        $python3 = null;
        foreach ($repo->children_of($defaults->id) as $child) {
            if ($child->name === 'python3') {
                $python3 = $child;
            }
        }
        $settings = $repo->chain_for($python3->id)->resolve();

        $this->assertTrue($settings->was_set('penaltyregime'));
        $this->assertStringStartsWith($defaults->name . '@', $settings->source_of('penaltyregime'));
        $this->assertNotSame('', $settings->get('penaltyregime'));

        // And the question form's default for a new question comes from here,
        // rather than from a separate admin setting that could disagree.
        $this->assertSame(
            $settings->get('penaltyregime'),
            \qtype_coderunner_util::default_penalty_regime()
        );
    }

    /**
     * Only the fields a prototype supplied in CodeRunner 5 are carried over.
     * The fields that become inheritable in CodeRunner 6 were ignored on a
     * prototype question, so whatever was stored in them is not a decision
     * anybody made and must not become an inherited setting.
     */
    public function test_only_the_v5_inherited_fields_are_carried_over(): void {
        $columns = [
            'template' => 'TPL',
            'grader' => 'TemplateGrader',
            'penaltyregime' => '50, 100',
            'allornothing' => '0',
            'attachments' => '3',
            'answer' => 'print(1)',
            'globalextra' => 'ignored',
            'answerpreload' => 'preload',
        ];

        $overrides = legacy_converter::prototype_overrides($columns);

        $this->assertSame(['template', 'grader'], $overrides->names());
        $this->assertFalse($overrides->has('penaltyregime'), 'Newly inheritable fields must not be carried over.');
        $this->assertFalse($overrides->has('allornothing'));
        $this->assertFalse($overrides->has('attachments'));
        $this->assertFalse($overrides->has('answer'), 'A prototype has no instance fields.');
        $this->assertFalse($overrides->has('globalextra'), 'A prototype global extra never had any effect.');
    }

    /**
     * A value that matches what the parent chain already resolves to is not
     * stored, so a prototype states only what it actually changes.
     *
     * CodeRunner 5 kept a value for every inheritable field of a prototype
     * question whether the author chose it or not, so a migrated prototype
     * would otherwise claim preferences nobody expressed and would stop
     * following a later edit to Site defaults.
     */
    public function test_overrides_matching_the_parent_are_not_stored(): void {
        $installer = new prototype_installer();
        $repo = new prototype_repository();
        $installer->install_builtins();
        $defaults = $repo->site_defaults();

        foreach ($repo->children_of($defaults->id) as $child) {
            $overrides = $child->currentversion->overrides;
            foreach ($overrides->names() as $name) {
                $field = \qtype_coderunner\v6\field_registry::get($name);
                if (!$field->resolves_to_one_value()) {
                    continue;
                }
                $this->assertNotSame(
                    $field->normalise_for_comparison($defaults->currentversion->overrides->get($name)),
                    $field->normalise_for_comparison($overrides->get($name)),
                    "{$child->name} stores $name although Site defaults already supplies that value."
                );
            }
        }
    }

    /**
     * Pruning must not change what anything resolves to: a value is only
     * dropped when the chain already yields it.
     */
    public function test_pruning_does_not_change_resolution(): void {
        $installer = new prototype_installer();
        $repo = new prototype_repository();
        $installer->install_builtins();

        $python3 = null;
        foreach ($repo->children_of($repo->site_defaults()->id) as $child) {
            if ($child->name === 'python3') {
                $python3 = $child;
            }
        }
        $settings = $repo->chain_for($python3->id)->resolve();

        // Dropped because Site defaults already supplies them, but still resolved.
        $this->assertFalse($python3->currentversion->overrides->has('grader'));
        $this->assertSame('EqualityGrader', $settings->get('grader'));
        $this->assertFalse($python3->currentversion->overrides->has('useace'));
        $this->assertSame(1, $settings->get('useace'));

        // Kept, because python3 genuinely differs from the root here.
        $this->assertTrue($python3->currentversion->overrides->has('template'));
    }

    /**
     * A null or empty inheritable column is not an override, so the field falls
     * through exactly as CodeRunner 5's "null means inherit" rule intended.
     */
    public function test_empty_and_null_columns_are_not_overrides(): void {
        $overrides = legacy_converter::prototype_overrides([
            'template' => 'TPL',
            'sandbox' => null,
            'acelang' => '',
            'testsplitterre' => '',
        ]);

        $this->assertSame(['template'], $overrides->names());
    }

    /**
     * Values arrive as strings from the database and from XML, and are cast to
     * the type the registry declares so that verification does not report a
     * difference between a resolved '5' and a resolved 5.
     */
    public function test_values_are_cast_to_their_registry_types(): void {
        $overrides = legacy_converter::prototype_overrides([
            'cputimelimitsecs' => '5',
            'memlimitmb' => '64',
            'useace' => '1',
            'allowmultiplestdins' => '0',
            'template' => 'TPL',
        ]);

        $this->assertSame(5, $overrides->get('cputimelimitsecs'));
        $this->assertSame(64, $overrides->get('memlimitmb'));
        $this->assertSame(1, $overrides->get('useace'));
        $this->assertSame('TPL', $overrides->get('template'));

        // A boolean stored as the string "0" is an override to false, because a
        // CodeRunner 5 prototype storing 0 did pass 0 down to its questions. It
        // is only null and the empty string that mean "not set".
        $this->assertTrue($overrides->has('allowmultiplestdins'));
        $this->assertSame(0, $overrides->get('allowmultiplestdins'));
    }

    /**
     * For a boolean field a stored null and a stored 0 mean the same thing, and
     * for an integer field they do not. `allowmultiplestdins` is only ever read
     * in a boolean test, so null and 0 are interchangeable; `cputimelimitsecs`
     * is read through isset(), so null means no limit and 0 means a limit of
     * zero. Verification has to normalise accordingly, and this pins the
     * distinction the registry types encode.
     */
    public function test_null_differs_from_zero_only_for_integers(): void {
        $registry = \qtype_coderunner\v6\field_registry::class;

        $this->assertSame(
            \qtype_coderunner\v6\field_type::BOOLEAN,
            $registry::get('allowmultiplestdins')->type
        );
        $this->assertSame(0, $registry::get('allowmultiplestdins')->default);

        $this->assertSame(
            \qtype_coderunner\v6\field_type::INTEGER,
            $registry::get('cputimelimitsecs')->type
        );
        $this->assertNull(
            $registry::get('cputimelimitsecs')->default,
            'No limit must stay distinct from a limit of zero.'
        );
    }

    /**
     * A prototype question's question text becomes the prototype's
     * documentation, the role it already plays for authors.
     */
    public function test_question_text_becomes_documentation(): void {
        $this->assertSame(
            'How to use this type.',
            legacy_converter::documentation(['questiontext' => 'How to use this type.'])
        );
        $this->assertSame('', legacy_converter::documentation([]));
    }

    /**
     * The declared CodeRunner 5 inherited set must stay equal to what the old
     * code actually inherited, so the converter cannot drift from it.
     */
    public function test_the_v5_inherited_set_matches_the_old_code(): void {
        $extra = \qtype_coderunner::extra_question_fields_static();
        array_shift($extra);
        $derived = array_values(array_diff($extra, \qtype_coderunner::noninherited_fields()));
        $declared = legacy_converter::fields_inherited_in_v5();

        sort($derived);
        sort($declared);
        $this->assertSame($derived, $declared);
    }

    /** @var int|null the course whose bank holds the fixture questions. */
    private ?int $courseid = null;

    /** @var int|null the context of that bank. */
    private ?int $bankcontextid = null;

    /**
     * Create a CodeRunner 5 prototype question in a question bank of a course.
     *
     * Built through the question generator rather than `test_question_maker`,
     * which resolves a fixture's type against the built-in prototype
     * *questions* and so needs the question type's own test base class.
     *
     * @param string $coderunnertype the prototype name it defines.
     * @return object the saved question.
     */
    private function make_prototype_question(string $coderunnertype): object {
        if ($this->bankcontextid === null) {
            // Saving a new prototype question makes CodeRunner 5 look for one
            // of the same name, and its search starts at the site's own
            // question bank, which prototype_contexts() refuses to run without.
            \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
                get_site(),
                true
            );

            // Not cached across tests: resetAfterTest() rolls the database
            // back, so a context id held in a static would be stale.
            $this->courseid = (int) $this->getDataGenerator()->create_course()->id;
            $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->courseid]);
            $this->bankcontextid = (int) \context_module::instance($bank->cmid)->id;
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $this->bankcontextid]);

        return $generator->create_question('coderunner', 'sqr', [
            'category' => $category->id,
            'name' => 'PROTOTYPE_' . $coderunnertype,
            'coderunnertype' => $coderunnertype,
            'prototypetype' => 2,
            // CodeRunner 5's form carried the new type's name in its own
            // field, and clean_question_form() still reads it when saving a
            // customised prototype question.
            'typename' => $coderunnertype,
            'template' => '{{ STUDENT_ANSWER }}',
        ]);
    }
}
