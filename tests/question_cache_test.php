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
 * Unit tests for clearing cached question data when a prototype changes.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;
use qtype_coderunner\v6\question_cache;

/**
 * Tests that a change to a prototype reaches the questions using it.
 *
 * The question's resolved settings are cached by core, in `core/questiondata`,
 * so a prototype that changes without saying so leaves every question below it
 * running the version it had when the entry was written. That is not a
 * theoretical risk: in November 2026 a support file broken by a Python upgrade
 * was corrected in the prototype, and every question using it went on failing
 * until it was opened and saved again, one at a time.
 *
 * Every test here therefore reads back through question_bank::load_question_data(),
 * which is the cached path, rather than through the repository.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_cache::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class question_cache_test extends \advanced_testcase {
    /** @var prototype_repository the repository under test. */
    private prototype_repository $repo;

    /** @var int a course to scope prototypes and questions to. */
    private int $courseid;

    /** @var int the question category questions are made in. */
    private int $categoryid;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/question/engine/bank.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
        $this->courseid = (int) $this->getDataGenerator()->create_course()->id;

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->categoryid = (int) $generator->create_question_category(
            ['contextid' => \context_course::instance($this->courseid)->id]
        )->id;
    }

    /**
     * Saving a new version of a prototype reaches the questions using it.
     *
     * The support file areas are what is asserted on, because they are the part
     * of a resolved question that is a *version* id, and so the part the
     * November fault turned on: the question went on reading the old version's
     * files however often the prototype was corrected.
     */
    public function test_a_new_version_reaches_a_dependent_question(): void {
        $prototype = $this->repo->create('cached', $this->courseid, new override_set(['template' => 'ONE'], 'cached'));
        $questionid = $this->make_dependent_question($prototype->id);

        $before = $this->cached_file_area($questionid);
        $this->assertSame((int) $prototype->currentversion->id, $before);

        $saved = $this->repo->save_new_version($prototype, new override_set(['template' => 'TWO'], 'cached'));

        $this->assertNotSame($before, (int) $saved->currentversion->id, 'The save made no new version to see.');
        $this->assertSame(
            (int) $saved->currentversion->id,
            $this->cached_file_area($questionid),
            'The question is still resolving against the version the prototype had before the save.'
        );
    }

    /**
     * A question below a descendant of the prototype is reached too.
     *
     * A question resolves through the whole chain, so a change to a root
     * reaches every question under it, not only those naming it directly.
     */
    public function test_a_question_under_a_descendant_is_reached(): void {
        $root = $this->repo->create('root', $this->courseid, new override_set(['template' => 'ROOT'], 'root'));
        $child = $this->repo->create('child', $this->courseid, new override_set([], 'child'), $root->id);
        $questionid = $this->make_dependent_question($child->id);

        $this->assertSame('ROOT', $this->cached_template($questionid));

        $this->repo->save_new_version($root, new override_set(['template' => 'CHANGED'], 'root'));

        $this->assertSame(
            'CHANGED',
            $this->cached_template($questionid),
            'A change to the root never reached the question hanging off its child.'
        );
    }

    /**
     * Publishing a draft reaches the questions; saving one does not have to.
     *
     * A draft is not what anything resolves against, so a draft save leaves
     * every question exactly where it was -- which is why save_draft() is the
     * one save that deliberately does not purge. Publishing is the moment that
     * changes, and the moment the questions must be told about.
     */
    public function test_a_draft_reaches_the_questions_only_when_published(): void {
        $prototype = $this->repo->create('drafted', $this->courseid, new override_set(['template' => 'LIVE'], 'drafted'));
        $questionid = $this->make_dependent_question($prototype->id);

        $this->assertSame('LIVE', $this->cached_template($questionid));

        $withdraft = $this->repo->save_draft($prototype, new override_set(['template' => 'DRAFT'], 'drafted'));
        $this->assertSame('LIVE', $this->cached_template($questionid), 'A draft must not reach the questions.');

        $this->repo->publish_draft($withdraft);
        $this->assertSame(
            'DRAFT',
            $this->cached_template($questionid),
            'Publishing the draft left the questions on the version it replaced.'
        );
    }

    /**
     * Restoring an earlier version reaches the questions.
     */
    public function test_restoring_a_version_reaches_the_questions(): void {
        $prototype = $this->repo->create('restored', $this->courseid, new override_set(['template' => 'ONE'], 'restored'));
        $prototype = $this->repo->save_new_version($prototype, new override_set(['template' => 'TWO'], 'restored'));
        $questionid = $this->make_dependent_question($prototype->id);

        $this->assertSame('TWO', $this->cached_template($questionid));

        $this->repo->restore_version($prototype, 1);

        $this->assertSame('ONE', $this->cached_template($questionid), 'The restore never reached the questions.');
    }

    /**
     * Renaming a prototype reaches the questions, because the name is the
     * layer label the resolved chain records and the authoring form shows.
     */
    public function test_renaming_reaches_the_questions(): void {
        $prototype = $this->repo->create('oldname', $this->courseid, new override_set(['template' => 'T'], 'oldname'));
        $questionid = $this->make_dependent_question($prototype->id);

        // A layer is labelled name@id, so the name is matched as a prefix.
        $this->assertContains("oldname@{$prototype->id}", $this->cached_layer_labels($questionid));

        $this->repo->rename($prototype, 'newname');

        $labels = $this->cached_layer_labels($questionid);
        $this->assertContains("newname@{$prototype->id}", $labels);
        $this->assertNotContains(
            "oldname@{$prototype->id}",
            $labels,
            'The question still credits the prototype under its old name.'
        );
    }

    /**
     * Past the bulk threshold the whole definition goes instead.
     */
    public function test_too_many_questions_are_purged_rather_than_uncached(): void {
        $this->assertFalse(question_cache::too_many(\qtype_coderunner\constants::BULK_CACHE_PURGE_THRESHOLD));
        $this->assertTrue(question_cache::too_many(\qtype_coderunner\constants::BULK_CACHE_PURGE_THRESHOLD + 1));
    }

    /**
     * A question in this course that uses the given prototype.
     *
     * @param int $prototypeid the prototype it is to depend on.
     * @return int the question id.
     */
    private function make_dependent_question(int $prototypeid): int {
        global $DB;

        $question = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question(
            'coderunner',
            null,
            ['category' => $this->categoryid, 'name' => 'Depends on it']
        );
        // The question's own overrides must not pin the fields the prototype is
        // being asked about, or nothing the prototype does could show through.
        $DB->execute(
            'UPDATE {question_coderunner_options} SET prototypeid = ?, overrides = ? WHERE questionid = ?',
            [$prototypeid, json_encode([]), $question->id]
        );
        \question_bank::notify_question_edited($question->id);

        return (int) $question->id;
    }

    /**
     * The template a question resolves to, read through the cached path.
     *
     * @param int $questionid the question.
     * @return string|null the resolved template.
     */
    private function cached_template(int $questionid): ?string {
        return \question_bank::load_question_data($questionid)->options->template;
    }

    /**
     * The prototype id a question resolves through, read through the cached path.
     *
     * @param int $questionid the question.
     * @return int|null the prototype id, or null if the question is broken.
     */
    private function cached_prototype_id(int $questionid): ?int {
        $id = \question_bank::load_question_data($questionid)->options->prototypeid;
        return $id === null ? null : (int) $id;
    }

    /**
     * The version id of the one support file area a question resolves to.
     *
     * @param int $questionid the question.
     * @return int the itemid, which is a prototype version id.
     */
    private function cached_file_area(int $questionid): int {
        $areas = \question_bank::load_question_data($questionid)->options->supportfileareas;
        $this->assertCount(1, $areas, 'A one-prototype chain should give exactly one file area.');
        return (int) $areas[0]['itemid'];
    }

    /**
     * The names of the layers a question's resolved chain is built from.
     *
     * @param int $questionid the question.
     * @return string[] the layer labels.
     */
    private function cached_layer_labels(int $questionid): array {
        $layers = \question_bank::load_question_data($questionid)->options->chainlayers;
        return array_column($layers, 'source');
    }
}
