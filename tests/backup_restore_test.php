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

        // **Recording a known limitation, not asserting desired behaviour.**
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
     * @param int $prototypeid the prototype it depends on.
     * @return int the question id.
     */
    private function make_question(prototype $prototype): int {
        global $DB;

        $question = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question(
            'coderunner',
            null,
            ['category' => $this->categoryid, 'name' => 'Backed up question']
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
    private function backup_course(): string {
        global $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
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
        global $USER;

        $newcourseid = \restore_dbops::create_new_course(
            'Restored',
            'restored_' . $backupid,
            (int) $this->getDataGenerator()->create_category()->id
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return (int) $newcourseid;
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
