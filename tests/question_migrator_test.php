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
 * Unit tests for the question side of the CodeRunner 6 migration.
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
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');
require_once($CFG->dirroot . '/question/type/coderunner/tests/helper.php');
require_once($CFG->dirroot . '/question/type/coderunner/tests/test.php');
require_once($CFG->dirroot . '/question/type/coderunner/db/upgradelib.php');

/**
 * Tests the conversion rules for a question, which are the opposite of those
 * for a prototype, and the properties the migration must have: idempotent,
 * additive, and leaving an unresolvable question broken rather than guessing.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_migrator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(legacy_converter::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class question_migrator_test extends \qtype_coderunner_testcase {
    /** @var int|null The context of a question bank in this test's own course. */
    private ?int $bankcontextid = null;

    // The question type's own test base class is used because these tests
    // migrate its data: it installs the built-in prototype questions that the
    // question fixtures derive from, which the install task deliberately does
    // not do under PHPUnit, and it points the sandbox at the test configuration.

    /**
     * A field CodeRunner 5 did not inherit is pinned whether the column holds a
     * value or not, because the question's value was effective and must not
     * start following its prototype or the Site defaults instead.
     */
    public function test_fields_not_inherited_in_v5_are_pinned(): void {
        $overrides = legacy_converter::question_overrides([
            'penaltyregime' => '20, 40, ...',
            'allornothing' => '0',
            'globalextra' => '',
            'filenamesregex' => null,
        ]);

        $this->assertSame('20, 40, ...', $overrides->get('penaltyregime'));
        $this->assertSame(0, $overrides->get('allornothing'));

        // Pinned even when empty or null, because that was the effective value.
        $this->assertTrue($overrides->has('globalextra'));
        $this->assertSame('', $overrides->get('globalextra'));
        $this->assertTrue($overrides->has('filenamesregex'));
        $this->assertNull($overrides->get('filenamesregex'));
    }

    /**
     * A field CodeRunner 5 did inherit keeps the "null or empty means inherit"
     * rule, so a question that inherited a field goes on inheriting it.
     */
    public function test_fields_inherited_in_v5_keep_inheriting(): void {
        $overrides = legacy_converter::question_overrides([
            'template' => 'MY TEMPLATE',
            'grader' => null,
            'acelang' => '',
        ]);

        $this->assertSame('MY TEMPLATE', $overrides->get('template'));
        $this->assertFalse($overrides->has('grader'), 'A null inherited column still means inherit.');
        $this->assertFalse($overrides->has('acelang'), 'And so does an empty one.');
    }

    /**
     * Instance fields keep their own columns and do not go into the overrides
     * document, which holds only what the question overrides of its prototype.
     */
    public function test_instance_fields_stay_out_of_the_overrides(): void {
        $overrides = legacy_converter::question_overrides([
            'answer' => 'print(42)',
            'answerpreload' => '# start',
            'validateonsave' => '1',
            'showsource' => '1',
            'template' => 'x',
        ]);

        foreach (['answer', 'answerpreload', 'validateonsave', 'showsource'] as $field) {
            $this->assertFalse($overrides->has($field), "$field is instance data, not an override.");
        }
    }

    /**
     * The question rule and the prototype rule differ, and must: a prototype's
     * value for a newly inheritable field was ignored by CodeRunner 5, so
     * carrying it over would invent a preference, whereas a question's value
     * was effective and must be preserved.
     */
    public function test_the_question_and_prototype_rules_differ(): void {
        $columns = ['penaltyregime' => '50, 100', 'template' => 'x'];

        $asquestion = legacy_converter::question_overrides($columns);
        $asprototype = legacy_converter::prototype_overrides($columns);

        $this->assertTrue($asquestion->has('penaltyregime'));
        $this->assertFalse($asprototype->has('penaltyregime'));
        $this->assertTrue($asquestion->has('template'));
        $this->assertTrue($asprototype->has('template'));
    }

    /**
     * Every field the converter pins must be one the registry knows and one
     * that is stored in a column, so a field added to the registry without a
     * column cannot silently start being pinned.
     */
    public function test_pinned_fields_are_all_real_columns(): void {
        global $DB;

        $columns = array_keys($DB->get_columns('question_coderunner_options'));
        foreach (legacy_converter::fields_pinned_on_questions() as $name) {
            $this->assertContains($name, $columns, "Pinned field $name is not a column.");
        }
    }

    /**
     * A prototype carries its template parameters.
     *
     * CodeRunner 5 merged a prototype question's template parameters into every
     * question using it, through evaluate_merged_parameters() rather than
     * through the inherited-field list. A converter driven only by that list
     * discards them, which on the development site silently affected 11,883 of
     * 12,346 questions.
     */
    public function test_a_prototype_carries_its_template_parameters(): void {
        $overrides = legacy_converter::prototype_overrides([
            'templateparams' => '{"isfunction": false}',
            'templateparamslang' => 'twig',
            'templateparamsevalpertry' => 0,
        ])->to_array();

        $this->assertSame('{"isfunction": false}', $overrides['templateparams']);
        $this->assertSame('twig', $overrides['templateparamslang']);
        $this->assertArrayHasKey('templateparamsevalpertry', $overrides);
    }

    /**
     * A prototype carries its UI parameters, which reached a question through
     * evaluate_merged_ui_parameters() for the same reason.
     */
    public function test_a_prototype_carries_its_ui_parameters(): void {
        $overrides = legacy_converter::prototype_overrides([
            'uiparameters' => '{"wrapper_src": "prototypeextra"}',
        ])->to_array();

        $this->assertSame('{"wrapper_src": "prototypeextra"}', $overrides['uiparameters']);
    }

    /**
     * The language and per-try settings describe how to evaluate template
     * parameters, so they are not carried when there are none. Otherwise every
     * migrated prototype would state a preference about nothing, and collected
     * fields are never pruned.
     */
    public function test_evaluation_settings_are_not_carried_without_parameters(): void {
        $overrides = legacy_converter::prototype_overrides([
            'templateparams' => '',
            'templateparamslang' => 'python3',
            'templateparamsevalpertry' => 1,
        ])->to_array();

        $this->assertArrayNotHasKey('templateparams', $overrides);
        $this->assertArrayNotHasKey('templateparamslang', $overrides);
        $this->assertArrayNotHasKey('templateparamsevalpertry', $overrides);
    }

    /**
     * Migrating twice converts nothing the second time, so a partial run can be
     * resumed and the task can run on every upgrade.
     */
    public function test_migration_is_idempotent(): void {
        $this->unmigrate($this->make_question_with_prototype());
        $migrator = new question_migrator();

        $first = $migrator->migrate();
        $this->assertGreaterThan(0, $first['converted']);

        $second = $migrator->migrate();
        $this->assertSame(0, $second['converted']);
        $this->assertSame(0, $second['broken']);
    }

    /**
     * A question whose prototype cannot be resolved is left with a null
     * prototype id and still gets its overrides document, so it is recorded as
     * broken rather than silently acquiring someone else's prototype.
     */
    public function test_an_unresolvable_question_is_left_broken(): void {
        global $DB;

        $question = $this->make_plain_question('no_such_prototype');
        $this->unmigrate($question);
        (new question_migrator())->migrate();

        $row = $DB->get_record('question_coderunner_options', ['questionid' => $question->id]);
        $this->assertNull($row->prototypeid);
        $this->assertNotNull($row->overrides, 'The overrides are still recorded, so the row is migrated.');
    }

    /**
     * A question that has its overrides document but no prototype id has its
     * reference resolved on a later run, and its document left alone.
     *
     * This is the restored course. A pre-6.0 backup's questions are converted
     * as they arrive, so they have documents; but those built on the course's
     * own prototypes have no prototype id, because the prototype questions
     * they name are only snapshotted into the manager when this task runs.
     * Selecting on a missing document alone would have skipped them for ever,
     * leaving every question in the restored course broken.
     */
    public function test_a_question_awaiting_its_prototype_is_resolved_later(): void {
        global $DB;

        // The state a restore leaves: converted, but nothing of that name to
        // point at yet, because the prototype question has not been snapshotted.
        $this->make_prototype_question('restored_type');
        $question = $this->make_plain_question('restored_type');
        $document = json_encode(['penaltyregime' => '0, 10, 20, ...']);
        $DB->set_field('question_coderunner_options', 'prototypeid', null, ['questionid' => $question->id]);
        $DB->set_field('question_coderunner_options', 'overrides', $document, ['questionid' => $question->id]);

        // What the task does, in its order: snapshot, then sweep.
        (new prototype_installer())->snapshot_user_prototypes();
        $counts = (new question_migrator())->migrate();

        $row = $DB->get_record('question_coderunner_options', ['questionid' => $question->id]);
        $this->assertNotNull($row->prototypeid, 'The reference was never resolved.');
        $this->assertSame(
            $document,
            $row->overrides,
            'The document it was restored with was rewritten.'
        );
        $this->assertGreaterThan(0, $counts['converted']);
    }

    /**
     * The migration adds to the row and changes nothing that was there, so the
     * old columns remain available to verify against and to re-run from.
     */
    public function test_migration_leaves_the_old_columns_alone(): void {
        global $DB;

        $question = $this->make_question_with_prototype();
        $this->unmigrate($question);
        $before = $DB->get_record('question_coderunner_options', ['questionid' => $question->id]);

        (new question_migrator())->migrate();

        $after = $DB->get_record('question_coderunner_options', ['questionid' => $question->id]);
        foreach ((array) $before as $field => $value) {
            if (in_array($field, ['prototypeid', 'overrides'], true)) {
                continue;
            }
            $this->assertSame($value, $after->$field, "The migration changed the $field column.");
        }
        $this->assertNotNull($after->prototypeid);
    }

    /**
     * A question saved after the upgrade is converted by the question type as
     * it is written, so the migration finds nothing left to do.
     *
     * This is why every test above calls unmigrate() first: without it a
     * fixture built through the form arrives already converted, and a test of
     * the migration would pass without the migration having run.
     */
    public function test_a_question_saved_after_the_upgrade_needs_no_migration(): void {
        global $DB;

        $question = $this->make_question_with_prototype();

        $row = $DB->get_record('question_coderunner_options', ['questionid' => $question->id]);
        $this->assertNotNull($row->prototypeid, 'The save path records the prototype reference.');
        $this->assertNotNull($row->overrides);

        $counts = (new question_migrator())->migrate();
        $this->assertSame(0, $counts['converted']);
        $this->assertSame(0, $counts['broken']);
    }

    /**
     * Return a question's options row to the state CodeRunner 5 left it in.
     *
     * The question type records the prototype reference whenever a question is
     * saved, so a fixture built through the form is already converted. The
     * migration exists for rows written before 6.0, so a test of it has to
     * recreate that state deliberately.
     *
     * @param object $question the question whose row to reset.
     */
    private function unmigrate(object $question): void {
        global $DB;

        $DB->set_field('question_coderunner_options', 'prototypeid', null, ['questionid' => $question->id]);
        $DB->set_field('question_coderunner_options', 'overrides', null, ['questionid' => $question->id]);
    }

    /**
     * Create a prototype, snapshot it, and make a question that uses it.
     *
     * @return object the question.
     */
    private function make_question_with_prototype(): object {
        $this->make_prototype_question('migrate_me');
        (new prototype_installer())->snapshot_user_prototypes();
        return $this->make_plain_question('migrate_me');
    }

    /**
     * Create a prototype question in a question bank of a new course.
     *
     * @param string $coderunnertype the prototype name.
     * @return object the question.
     */
    private function make_prototype_question(string $coderunnertype): object {
        $form = $this->question_form('PROTOTYPE_' . $coderunnertype, $coderunnertype);
        $form->prototypetype = 2;
        $form->testcases = [];
        $form->template = '{{ STUDENT_ANSWER }}';
        return $this->save($form);
    }

    /**
     * Create an ordinary question that names the given prototype.
     *
     * Named to avoid clashing with the base class's own make_question().
     *
     * @param string $coderunnertype the prototype the question names.
     * @return object the question.
     */
    private function make_plain_question(string $coderunnertype): object {
        return $this->save($this->question_form('a question', $coderunnertype));
    }

    /**
     * Build question form data of the sqr type, retargeted at a prototype name.
     *
     * @param string $name the question name.
     * @param string $coderunnertype the prototype name.
     * @return object the form data.
     */
    private function question_form(string $name, string $coderunnertype): object {
        $form = \test_question_maker::make_question('coderunner', 'sqr');
        $form->name = $name;
        $form->coderunnertype = $coderunnertype;
        $form->prototypetype = 0;
        return $form;
    }

    /**
     * Save question form data into a question bank of a shared course.
     *
     * @param object $form the form data.
     * @return object the saved question.
     */
    private function save(object $form): object {
        // Not cached in a static: resetAfterTest() rolls the database back
        // between tests, so a context id held across them is stale.
        if ($this->bankcontextid === null) {
            $courseid = (int) $this->getDataGenerator()->create_course()->id;
            $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $courseid]);
            $this->bankcontextid = (int) \context_module::instance($bank->cmid)->id;
        }

        $category = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question_category(['contextid' => $this->bankcontextid]);
        $form->category = $category->id;
        $form->contextid = $category->contextid;

        \question_bank::load_question_definition_classes('coderunner');
        $row = new \qtype_coderunner_question();
        \test_question_maker::initialise_a_question($row);
        $row->qtype = 'coderunner';
        (new \qtype_coderunner())->save_question($row, $form);
        return $row;
    }
}
