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
 * Unit tests for backing a course's prototypes up and restoring them.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');

/**
 * Tests that a course's prototypes travel with it and come back usable.
 *
 * Read through the question rather than through the tables wherever it can be:
 * a restore that produces the right rows and leaves every question broken has
 * failed, and the rows alone cannot say which happened. The assertions that
 * matter are therefore what a restored question resolves to, and whether the
 * support files it inherits are there -- a prototype restored without its
 * `__pystylechecker.py` fails every question under it.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_local_coderunner_manager_plugin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_local_coderunner_manager_plugin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class backup_restore_test extends \advanced_testcase {
    /** @var prototype_repository the repository. */
    private prototype_repository $repo;

    /** @var \stdClass the course being backed up. */
    private \stdClass $course;

    /** @var int the question category in that course's bank. */
    private int $categoryid;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        // Otherwise the logger keeps the file open and the backup cannot be
        // cleaned up, which is what core's own backup tests do about it.
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $this->repo = new prototype_repository();
        $this->course = $this->getDataGenerator()->create_course();

        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        $bankcontext = \context_module::instance($qbank->cmid);
        $this->categoryid = (int) question_get_default_category($bankcontext->id)->id;
    }

    /**
     * On the same site the prototype is reused, not copied.
     *
     * The question type's restorer runs in the root task and prefers the
     * backed-up id when the uuid agrees, so the restored questions are already
     * pointing at the original prototype before the manager's course point
     * runs. Creating a copy here would leave that copy pointed at by nothing.
     */
    public function test_a_same_site_restore_reuses_the_prototype(): void {
        global $DB;

        $prototype = $this->make_prototype('reused', 'REUSED');
        $questionid = $this->make_question($prototype);

        $newcourseid = $this->backup_and_restore();

        $this->assertSame(
            1,
            $DB->count_records(prototype_repository::PROTOTYPES, ['uuid' => $prototype->uuid]),
            'The restore made a second copy of a prototype the site already had.'
        );

        $restoredid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame(
            (int) $prototype->id,
            $this->resolved_prototype_id($restoredid),
            'The restored question is not using the prototype that was already here.'
        );
        $this->assertSame('REUSED', $this->resolved_template($restoredid));
    }

    /**
     * Across sites the prototype is created, with its history and its files.
     *
     * Simulated by taking the backup and then removing the course and the
     * prototype, which leaves the site in the state another site is in: holding
     * the backup and nothing it refers to.
     *
     * This is also the test of the linking stage. The root task restores
     * questions before the course task restores the course's prototypes, so at
     * the moment the question is written there is nothing of its name to
     * resolve against and it is stored broken. Only `after_restore_question()`
     * can fix it, and if it did not run the question would still be broken here.
     */
    public function test_a_cross_site_restore_creates_the_prototype(): void {
        global $DB;

        $prototype = $this->make_prototype('travelled', 'FIRST');
        $prototype = $this->repo->save_new_version(
            $prototype,
            new override_set(['template' => 'SECOND'], 'travelled')
        );
        $this->add_support_file($prototype, 'helper.py', 'print("v2")');
        $questionid = $this->make_question($prototype);

        $backupid = $this->backup_course();

        // Everything the backup refers to now goes, as it would never have
        // existed on another site.
        delete_course($this->course, false);
        $DB->delete_records(prototype_repository::VERSIONS, ['prototypeid' => $prototype->id]);
        $DB->delete_records(prototype_repository::PROTOTYPES, ['id' => $prototype->id]);

        $newcourseid = $this->restore($backupid);

        $created = $this->repo->find_one_by_uuid($prototype->uuid, $newcourseid);
        $this->assertNotNull($created, 'The prototype did not come back at all.');
        $this->assertSame($newcourseid, (int) $created->courseid, 'It was not scoped to the restored course.');
        $this->assertSame('travelled', $created->name);
        $this->assertFalse((bool) $created->builtin, 'A restored prototype must never be flagged built-in.');

        // The history, with its numbering as it was.
        $history = $this->repo->history($created->id);
        $this->assertSame([2, 1], array_keys($history), 'The version history did not survive, or was renumbered.');
        $this->assertSame('SECOND', $history[2]->overrides->get('template'));
        $this->assertSame('FIRST', $history[1]->overrides->get('template'));
        $this->assertSame((int) $history[2]->id, (int) $created->currentversion->id);

        // The support files, which hang off the current version.
        $files = $this->repo->version_files($created, (int) $created->currentversion->id);
        $this->assertArrayHasKey('helper.py', $files, 'The prototype arrived without its support files.');

        // And the question, which was stored broken and must have been linked.
        $restoredid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame(
            (int) $created->id,
            $this->resolved_prototype_id($restoredid),
            'after_restore_question() did not link the restored question to its restored prototype.'
        );
        $this->assertSame('SECOND', $this->resolved_template($restoredid));
    }

    /**
     * A chain travels whole, and the restored child derives from the restored
     * parent rather than from the original.
     */
    public function test_a_chain_is_restored_as_a_chain(): void {
        global $DB;

        $parent = $this->make_prototype('ancestor', 'FROM PARENT');
        $child = $this->repo->create(
            'descendant',
            (int) $this->course->id,
            new override_set([], 'descendant'),
            $parent->id
        );
        $questionid = $this->make_question($child);

        $backupid = $this->backup_course();

        delete_course($this->course, false);
        foreach ([$child->id, $parent->id] as $id) {
            $DB->delete_records(prototype_repository::VERSIONS, ['prototypeid' => $id]);
            $DB->delete_records(prototype_repository::PROTOTYPES, ['id' => $id]);
        }

        $newcourseid = $this->restore($backupid);

        $newparent = $this->repo->find_one_by_uuid($parent->uuid, $newcourseid);
        $newchild = $this->repo->find_one_by_uuid($child->uuid, $newcourseid);
        $this->assertNotNull($newparent, 'The ancestor did not travel.');
        $this->assertNotNull($newchild, 'The child did not travel.');
        $this->assertSame(
            (int) $newparent->id,
            (int) $newchild->currentversion->parentid,
            'The restored child points at something other than the restored parent.'
        );

        // Which is only worth anything if the chain still resolves through it.
        $restoredid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame('FROM PARENT', $this->resolved_template($restoredid));
    }

    /**
     * A prototype with a different uuid but a clashing name is still restored,
     * under a name that is free.
     *
     * Across sites `python3` is the likeliest collision of all, and failing a
     * restore that is otherwise fine is worse than landing the prototype under
     * a name an author can change.
     *
     * The second half of this test records what happens to the *question* in
     * that case, which is a known limitation rather than the intended result.
     */
    public function test_a_clashing_name_is_suffixed_rather_than_refused(): void {
        global $DB;

        $prototype = $this->make_prototype('contested', 'MINE');
        $questionid = $this->make_question($prototype);
        $backupid = $this->backup_course();

        delete_course($this->course, false);
        $DB->delete_records(prototype_repository::VERSIONS, ['prototypeid' => $prototype->id]);
        $DB->delete_records(prototype_repository::PROTOTYPES, ['id' => $prototype->id]);

        // A different prototype of the same name, at site level, as another
        // site's administrator might have made.
        $incumbent = $this->repo->create('contested', SITEID, new override_set(['template' => 'THEIRS'], 'contested'));

        $newcourseid = $this->restore($backupid);

        $created = $this->repo->find_one_by_uuid($prototype->uuid, $newcourseid);
        $this->assertNotNull($created, 'The name clash lost the prototype altogether.');
        $this->assertSame('contested (2)', $created->name);
        $this->assertNotSame($incumbent->uuid, $created->uuid, 'Two prototypes were conflated by their name.');

        // Recording a known limitation here, not asserting desired behaviour.
        // The question is left pointing at the *incumbent* -- an unrelated
        // prototype that merely shares the name -- because the question type's
        // restorer resolved it by name in the root task, before this
        // prototype had been restored and under a name it no longer carries.
        // The linking stage cannot undo that: it only fills in a question with
        // no prototype at all, and it matches by name, which the suffix has
        // changed.
        //
        // Kept as a test so the behaviour is visible and any change to it is
        // deliberate. Why the name fallback stays is argued under M5 in
        // docs/v6/coderunner6-code-checks-and-milestones.md: a quiz-only
        // restore has no course task, so nothing restores a prototype at all
        // and the name is the only route there is.
        $restoredid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame(
            (int) $incumbent->id,
            $this->resolved_prototype_id($restoredid),
            'The known limitation has changed; see the M5 note on name fallback.'
        );
    }

    /**
     * A prototype borrowed from another course travels with the question.
     *
     * The course point cannot carry it: `prototypes_for_course()` writes only
     * the prototypes the course owns, plus their ancestors, and a borrowed
     * prototype is neither. So this is the question connection point's own
     * case, and nothing else can cover it.
     */
    public function test_a_borrowed_prototype_travels_with_the_question(): void {
        global $DB;

        $lender = $this->getDataGenerator()->create_course();
        $borrowed = $this->repo->create(
            'lent_out',
            (int) $lender->id,
            new override_set(['template' => 'LENT'], 'lent_out')
        );
        $this->add_support_file($borrowed, 'lent.py', 'print("lent")');
        $questionid = $this->make_question($borrowed);

        $backupid = $this->backup_course();

        // Both courses and the prototype go, as they would be absent on
        // another site.
        delete_course($this->course, false);
        delete_course($lender, false);
        $DB->delete_records(prototype_repository::VERSIONS, ['prototypeid' => $borrowed->id]);
        $DB->delete_records(prototype_repository::PROTOTYPES, ['id' => $borrowed->id]);

        $newcourseid = $this->restore($backupid);

        $created = $this->repo->find_one_by_uuid($borrowed->uuid, $newcourseid);
        $this->assertNotNull($created, 'The borrowed prototype never left the lending course.');
        $this->assertSame($newcourseid, (int) $created->courseid);

        $restoredid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame(
            (int) $created->id,
            $this->resolved_prototype_id($restoredid),
            'The question was not linked to the prototype that travelled with it.'
        );
        $this->assertSame('LENT', $this->resolved_template($restoredid));
    }

    /**
     * A borrowed prototype's own ancestors travel too.
     *
     * The question point walks the chain in SQL, because its source has to
     * vary by question and so cannot be a PHP array. A chain short by a layer
     * would resolve from what is left of it and grade against settings the
     * author never wrote, which is the kind of failure that does not announce
     * itself.
     */
    public function test_a_borrowed_chain_travels_whole(): void {
        global $DB;

        $lender = $this->getDataGenerator()->create_course();
        $root = $this->repo->create(
            'lent_root',
            (int) $lender->id,
            new override_set(['template' => 'FROM LENT ROOT'], 'lent_root')
        );
        $leaf = $this->repo->create(
            'lent_leaf',
            (int) $lender->id,
            new override_set([], 'lent_leaf'),
            $root->id
        );
        $questionid = $this->make_question($leaf);

        $backupid = $this->backup_course();

        delete_course($this->course, false);
        delete_course($lender, false);
        foreach ([$leaf->id, $root->id] as $id) {
            $DB->delete_records(prototype_repository::VERSIONS, ['prototypeid' => $id]);
            $DB->delete_records(prototype_repository::PROTOTYPES, ['id' => $id]);
        }

        $newcourseid = $this->restore($backupid);

        $newroot = $this->repo->find_one_by_uuid($root->uuid, $newcourseid);
        $newleaf = $this->repo->find_one_by_uuid($leaf->uuid, $newcourseid);
        $this->assertNotNull($newroot, 'The borrowed chain lost its root.');
        $this->assertNotNull($newleaf, 'The borrowed chain lost its leaf.');
        $this->assertSame(
            (int) $newroot->id,
            (int) $newleaf->currentversion->parentid,
            'The restored leaf does not derive from the restored root.'
        );

        $restoredid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame('FROM LENT ROOT', $this->resolved_template($restoredid));
    }

    /**
     * However many questions use a prototype, it is written once.
     *
     * Not tidiness: the source is evaluated once per question, and a single
     * `overrides` document can be 152 KB on the development site, so writing
     * it per question is measured in hundreds of megabytes. The write-once is
     * the `NOT EXISTS` against `backup_ids_temp` that `annotate_ids()` feeds.
     */
    public function test_a_prototype_is_written_once_however_many_questions_use_it(): void {
        $lender = $this->getDataGenerator()->create_course();
        $borrowed = $this->repo->create(
            'written_once',
            (int) $lender->id,
            new override_set(['template' => 'ONCE'], 'written_once')
        );
        for ($i = 0; $i < 3; $i++) {
            $this->make_question($borrowed);
        }

        $xml = $this->questions_xml($this->backup_course());

        $this->assertSame(
            3,
            substr_count($xml, '<qtype>coderunner</qtype>'),
            'The fixture did not produce three CodeRunner questions to share the prototype.'
        );
        $this->assertSame(
            1,
            substr_count($xml, '<name>written_once</name>'),
            'The prototype was written once per question instead of once per backup.'
        );
    }

    /**
     * A quiz-only backup carries the prototypes its questions need.
     *
     * The case the question point exists for most of all: `TYPE_1ACTIVITY` has
     * no course task, so `define_course_plugin_structure()` never runs and the
     * question point is the only source of prototype content there is.
     */
    public function test_a_quiz_only_backup_carries_its_prototypes(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $prototype = $this->make_prototype('quizzed', 'FROM QUIZ PROTOTYPE');
        $this->add_support_file($prototype, 'quizhelper.py', 'print("quiz")');
        $questionid = $this->make_question($prototype);

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        quiz_add_quiz_question($questionid, $quiz);

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            (int) $quiz->cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        delete_course($this->course, false);
        $DB->delete_records(prototype_repository::VERSIONS, ['prototypeid' => $prototype->id]);
        $DB->delete_records(prototype_repository::PROTOTYPES, ['id' => $prototype->id]);

        // A module backup restores into an existing course, adding to it.
        $target = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            (int) $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $created = $this->repo->find_one_by_uuid($prototype->uuid, (int) $target->id);
        $this->assertNotNull($created, 'A quiz backup carried none of its prototypes.');
        $this->assertArrayHasKey(
            'quizhelper.py',
            $this->repo->version_files($created, (int) $created->currentversion->id),
            'The prototype arrived without its support files, so its questions cannot run.'
        );

        $restoredid = $this->restored_question_id($questionid, (int) $target->id);
        $this->assertSame(
            'FROM QUIZ PROTOTYPE',
            $this->resolved_template($restoredid),
            'The restored quiz question does not resolve through the prototype that travelled with it.'
        );
    }

    /**
     * Duplicating a quiz reuses its questions instead of copying them.
     *
     * The regression test the duplication bug never had. Moodle matches a
     * backed-up question against an existing one by a hash the question type
     * supplies, and until a question type supplies one correctly, duplicating
     * a single quiz copies the whole question bank behind it. CodeRunner lived
     * through exactly that, and one surviving site now reports 560,000
     * questions.
     *
     * The defect this guards against is in the question type's
     * `define_excluded_identity_hash_fields()`, not in this plugin: anything
     * present on one side of the comparison and not the other changes the hash,
     * and the hash is a sorted multiset of *values*, so a single extra one is
     * enough. Carrying the prototype's uuid in the backup re-armed it, which is
     * what this test was written to catch.
     */
    public function test_duplicating_a_quiz_does_not_copy_its_questions(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $prototype = $this->make_prototype('duplicated', 'DUP');
        $questionid = $this->make_question($prototype);

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        quiz_add_quiz_question($questionid, $quiz);

        $before = $DB->count_records('question', ['qtype' => 'coderunner']);

        \core_courseformat\formatactions::cm((int) $this->course->id)->duplicate(cmid: (int) $quiz->cmid);

        $this->assertSame(
            $before,
            $DB->count_records('question', ['qtype' => 'coderunner']),
            'Duplicating the quiz copied its questions instead of reusing them, which is the bug '
                . 'that grows a question bank exponentially.'
        );
    }

    /**
     * A course copy reuses the prototype rather than copying it.
     *
     * Moodle's *Copy course* is a backup and restore in MODE_COPY, run by an
     * adhoc task; here both halves run in line. The copy gets a bank of its
     * own, so its questions are new by design -- what must not be new is the
     * prototype, which is on this site already and matches by uuid.
     */
    public function test_a_course_copy_reuses_the_prototype(): void {
        global $DB;

        $prototype = $this->make_prototype('copied', 'COPIED');
        $questionid = $this->make_question($prototype);

        $newcourseid = $this->copy_course();

        $this->assertSame(
            1,
            $DB->count_records(prototype_repository::PROTOTYPES, ['uuid' => $prototype->uuid]),
            'The copy made a second prototype of the same identity.'
        );

        $copiedid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame(
            (int) $prototype->id,
            $this->resolved_prototype_id($copiedid),
            "The copied question is not using the original course's prototype."
        );
        $this->assertSame('COPIED', $this->resolved_template($copiedid));
    }

    /**
     * Importing a course into an existing one brings working questions.
     *
     * *Import course data* restores with TARGET_CURRENT_ADDING into a course
     * that already exists. The question is new by design and that is not a
     * fault: an import recreates the backup's question *bank module* in the
     * target, so the incoming question lands in a category of its own, and
     * core matches an incoming question against an existing one only within
     * one category. Measured rather than assumed -- two imports leave two
     * qbank modules, each with its own copy -- so what is asserted here is
     * what the import does promise: the prototype is reused, and the imported
     * question resolves through it. The duplication the identity hash guards
     * is quiz duplication, which has its own test below.
     *
     * The work on this path is the *question type's* restorer rather than the
     * manager's course point: an import is same-site, so `resolve_prototype()`
     * trusts the backed-up id once the uuid agrees, and the question is
     * pointing at the original before any prototype is restored. Checked by
     * neutering that method, which fails this test; neutering the manager's
     * uuid reuse does not.
     */
    public function test_a_course_import_reuses_the_prototype(): void {
        global $DB;

        $prototype = $this->make_prototype('imported', 'IMPORTED');
        $questionid = $this->make_question($prototype);

        $target = (int) $this->getDataGenerator()->create_course()->id;
        $this->restore_into($this->backup_course(), $target, \backup::TARGET_CURRENT_ADDING);

        $this->assertSame(
            1,
            $DB->count_records(prototype_repository::PROTOTYPES, ['uuid' => $prototype->uuid]),
            'The import made a second prototype of the same identity.'
        );

        $importedid = $this->restored_question_id($questionid, $target);
        $this->assertSame(
            (int) $prototype->id,
            $this->resolved_prototype_id($importedid),
            'The imported question is not using the prototype this site already had.'
        );
        $this->assertSame('IMPORTED', $this->resolved_template($importedid));
    }

    /**
     * A quiz borrowing from another course's shared bank keeps working.
     *
     * The seventh exit scenario, and the one the design gives a paragraph of
     * its own: when course A's quiz uses questions from a shared bank in
     * course B, a backup of A contains *copies of just those questions*, not
     * B's bank, and a restore on another site puts the copies in a new bank of
     * their own. The sharing is lost -- existing Moodle behaviour CodeRunner
     * cannot change -- so what has to hold is that the copies still carry the
     * prototypes they need from B. That is the question attachment point's
     * reason for existing.
     *
     * Unlike test_a_borrowed_prototype_travels_with_the_question, where the
     * question was in this course's bank and only its prototype was elsewhere,
     * here the *question* belongs to the other course too.
     *
     * This shape is also what found the file annotation fault: a prototype
     * from another course arrived with its rows and none of its support files,
     * because one annotation records one context and a prototype's files live
     * in its own course's.
     */
    public function test_a_quiz_borrowing_another_courses_bank_keeps_its_prototypes(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        // Course B: a shared bank, a prototype of its own, and a question.
        $lender = $this->getDataGenerator()->create_course();
        $lentbank = $this->getDataGenerator()->create_module('qbank', ['course' => $lender->id]);
        $lentcategory = (int) question_get_default_category(
            \context_module::instance($lentbank->cmid)->id
        )->id;
        $borrowed = $this->repo->create(
            'shared_bank_type',
            (int) $lender->id,
            new override_set(['template' => 'FROM THE LENDER'], 'shared_bank_type')
        );
        $this->add_support_file($borrowed, 'shared.py', 'print("shared")');
        $questionid = $this->make_question($borrowed, $lentcategory);

        // Course A: a quiz using course B's question.
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        quiz_add_quiz_question($questionid, $quiz);

        $backupid = $this->backup_course();

        // Another site: neither course and no prototype.
        delete_course($this->course, false);
        delete_course($lender, false);
        $DB->delete_records(prototype_repository::VERSIONS, ['prototypeid' => $borrowed->id]);
        $DB->delete_records(prototype_repository::PROTOTYPES, ['id' => $borrowed->id]);

        $newcourseid = $this->restore($backupid);

        $created = $this->repo->find_one_by_uuid($borrowed->uuid, $newcourseid);
        $this->assertNotNull(
            $created,
            "The lending course's prototype never travelled, so the quiz's questions are broken."
        );

        $restoredid = $this->restored_question_id($questionid, $newcourseid);
        $this->assertSame(
            (int) $created->id,
            $this->resolved_prototype_id($restoredid),
            'The borrowed question was not linked to the prototype that travelled with it.'
        );
        $this->assertSame('FROM THE LENDER', $this->resolved_template($restoredid));

        // The files travel too, which they did not until the annotation's
        // context was made to follow the row it is written from. A prototype
        // whose __pystylechecker.py stayed behind imports and then fails every
        // question under it.
        $this->assertArrayHasKey(
            'shared.py',
            $this->repo->version_files($created, (int) $created->currentversion->id),
            "The borrowed prototype arrived without the support file its questions need."
        );
    }

    /**
     * A prototype in the course, with one version.
     *
     * @param string $name the prototype name.
     * @param string $template the template it supplies.
     * @return prototype the prototype.
     */
    private function make_prototype(string $name, string $template): prototype {
        return $this->repo->create(
            $name,
            (int) $this->course->id,
            new override_set(['template' => $template], $name)
        );
    }

    /**
     * Put a support file on a prototype's current version.
     *
     * @param prototype $prototype the prototype.
     * @param string $filename the file name.
     * @param string $content the content.
     */
    private function add_support_file(prototype $prototype, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $this->repo->file_contextid($prototype),
            'component' => prototype_repository::COMPONENT,
            'filearea' => prototype_repository::FILEAREA,
            'itemid' => (int) $prototype->currentversion->id,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * A CodeRunner question in the course's bank, using the given prototype.
     *
     * @param prototype $prototype the prototype it depends on.
     * @param int|null $categoryid the question category, defaulting to this
     *      course's own bank.
     * @return int the question id.
     */
    private function make_question(prototype $prototype, ?int $categoryid = null): int {
        global $DB;

        $question = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question(
            'coderunner',
            null,
            ['category' => $categoryid ?? $this->categoryid, 'name' => 'Backed up question']
        );
        // Overriding nothing, so that what the prototype supplies shows
        // through; and `coderunnertype` carrying the prototype's name, which
        // is what a real question has (decision 18) and what the restore's
        // linking stage matches on.
        $DB->execute(
            'UPDATE {question_coderunner_options}'
                . ' SET prototypeid = ?, overrides = ?, coderunnertype = ? WHERE questionid = ?',
            [$prototype->id, json_encode([]), $prototype->name, $question->id]
        );

        return (int) $question->id;
    }

    /**
     * Back the course up and restore it into a fresh course.
     *
     * @return int the new course's id.
     */
    private function backup_and_restore(): int {
        return $this->restore($this->backup_course());
    }

    /**
     * Back the course up, without zipping it.
     *
     * @return string the backup id.
     */
    private function backup_course(int $mode = \backup::MODE_IMPORT): string {
        global $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            $mode,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        return $backupid;
    }

    /**
     * Restore a backup into a new course.
     *
     * @param string $backupid the backup to restore.
     * @return int the new course's id.
     */
    private function restore(string $backupid): int {
        $newcourseid = \restore_dbops::create_new_course(
            'Restored',
            'restored_' . $backupid,
            (int) $this->getDataGenerator()->create_category()->id
        );

        return $this->restore_into($backupid, $newcourseid);
    }

    /**
     * Copy the course the way *Copy course* does, in line.
     *
     * The sequence is `\core\task\asynchronous_copy_task::execute()`'s, which
     * is the only authority on it, run here rather than through the adhoc task
     * that queues it. Three things differ from an ordinary restore and each is
     * why a plainer version of this did not work: MODE_COPY on the restore
     * refuses to be constructed without the copy data; a MODE_COPY backup
     * leaves a *file* rather than an unzipped directory, so it has to be
     * extracted into the plan's basepath; and `prepare_copy()` is what moves
     * the controller into the state a precheck will accept.
     *
     * @return int the new course's id.
     */
    private function copy_course(): int {
        global $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_COPY,
            $USER->id,
            \backup::RELEASESESSION_YES
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $basepath = $bc->get_plan()->get_basepath();
        $bc->destroy();

        $newcourseid = (int) \restore_dbops::create_new_course(
            'Copy',
            'copy_' . $backupid,
            (int) $this->getDataGenerator()->create_category()->id
        );

        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_COPY,
            $USER->id,
            \backup::TARGET_NEW_COURSE,
            null,
            \backup::RELEASESESSION_NO,
            $this->copy_data($backupid)
        );
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $basepath);
        $rc->prepare_copy();
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * The copy data a MODE_COPY restore insists on.
     *
     * The fields `copy_helper::process_formdata()` requires, plus the roles it
     * adds; none of them is what this test is about, so they are the course's
     * own values and no user data.
     *
     * @param string $backupid the backup, for a distinct short name.
     * @return \stdClass the copy data.
     */
    private function copy_data(string $backupid): \stdClass {
        return (object) [
            'courseid' => (int) $this->course->id,
            'fullname' => 'Copy',
            'shortname' => 'copy_' . $backupid,
            'category' => (int) $this->course->category,
            'visible' => 1,
            'startdate' => (int) $this->course->startdate,
            'enddate' => 0,
            'idnumber' => '',
            'userdata' => 0,
            'keptroles' => [],
        ];
    }

    /**
     * Restore a backup into a course that already exists.
     *
     * @param string $backupid the backup to restore.
     * @param int $courseid the course to restore into.
     * @param int $target one of the backup::TARGET_ constants.
     * @param int $mode one of the backup::MODE_ constants.
     * @param \stdClass|null $copydata the copy data MODE_COPY insists on.
     * @return int the course restored into.
     */
    private function restore_into(
        string $backupid,
        int $courseid,
        int $target = \backup::TARGET_NEW_COURSE,
        int $mode = \backup::MODE_GENERAL,
        ?\stdClass $copydata = null
    ): int {
        global $USER;

        $rc = new \restore_controller(
            $backupid,
            $courseid,
            \backup::INTERACTIVE_NO,
            $mode,
            $USER->id,
            $target,
            null,
            \backup::RELEASESESSION_NO,
            $copydata
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $courseid;
    }

    /**
     * The questions.xml of an unzipped backup.
     *
     * MODE_IMPORT leaves the backup as a directory rather than a zip, which is
     * what lets a test read what was actually written.
     *
     * @param string $backupid the backup.
     * @return string the file's contents.
     */
    private function questions_xml(string $backupid): string {
        global $CFG;

        $path = $CFG->tempdir . '/backup/' . $backupid . '/questions.xml';
        $this->assertFileExists($path, 'The backup wrote no questions.xml.');

        return (string) file_get_contents($path);
    }

    /**
     * The copy of a question that a restore made in the new course.
     *
     * Found by name within the new course's contexts, because the restore
     * creates a new question with a new id.
     *
     * @param int $originalid the question that was backed up.
     * @param int $newcourseid the course restored into.
     * @return int the restored question's id.
     */
    private function restored_question_id(int $originalid, int $newcourseid): int {
        global $DB;

        $name = $DB->get_field('question', 'name', ['id' => $originalid], IGNORE_MISSING);
        $this->assertNotFalse($name !== false ? $name : 'Backed up question');

        $ids = $DB->get_fieldset_sql(
            "SELECT q.id
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {context} ctx ON ctx.id = qc.contextid
              WHERE q.qtype = 'coderunner'
                AND ctx.path LIKE :path
           ORDER BY q.id DESC",
            ['path' => '%/' . \context_course::instance($newcourseid)->id . '/%']
        );
        $this->assertNotEmpty($ids, 'No CodeRunner question was restored into the new course.');

        return (int) reset($ids);
    }

    /**
     * The prototype a question resolves through, read the way a page load does.
     *
     * @param int $questionid the question.
     * @return int|null the prototype id, or null if the question is broken.
     */
    private function resolved_prototype_id(int $questionid): ?int {
        $id = \question_bank::load_question_data($questionid)->options->prototypeid;
        return $id === null ? null : (int) $id;
    }

    /**
     * The template a question resolves to.
     *
     * @param int $questionid the question.
     * @return string|null the template.
     */
    private function resolved_template(int $questionid): ?string {
        return \question_bank::load_question_data($questionid)->options->template;
    }
}
