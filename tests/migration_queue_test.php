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
 * Unit tests for deciding when the migration task needs queuing.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

defined('MOODLE_INTERNAL') || die();

/**
 * The migration runs from an adhoc task, so a site whose task is lost has no
 * prototypes and no way to get any. Every upgrade therefore asks whether one is
 * needed. These tests cover that decision, since getting it wrong is silent in
 * both directions: too eager and every upgrade queues pointless work, too shy
 * and a broken site stays broken.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_installer::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class migration_queue_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * How many migration tasks are waiting.
     *
     * @return int the count.
     */
    private function queued(): int {
        global $DB;

        return $DB->count_records_select(
            'task_adhoc',
            $DB->sql_like('classname', ':classname'),
            ['classname' => '%install_prototypes']
        );
    }

    /**
     * With no prototypes at all, the task is queued. This is both a fresh
     * install and a site whose task was lost.
     */
    public function test_queued_when_there_are_no_prototypes(): void {
        $this->assertTrue(prototype_installer::queue_migration_if_needed());
        $this->assertSame(1, $this->queued());
    }

    /**
     * Asking twice queues one task, not two, so an upgrade cannot stack copies
     * of a task that is still waiting.
     */
    public function test_asking_twice_queues_only_one(): void {
        prototype_installer::queue_migration_if_needed();
        prototype_installer::queue_migration_if_needed();

        $this->assertSame(1, $this->queued());
    }

    /**
     * With the prototypes in place and every question converted, nothing is
     * queued: the ordinary upgrade of an already-migrated site.
     */
    public function test_nothing_queued_when_the_site_is_already_migrated(): void {
        (new prototype_repository())->create(
            'python3',
            SITEID,
            new override_set(['template' => '{{ STUDENT_ANSWER }}'], 'python3')
        );

        $this->assertFalse(prototype_installer::queue_migration_if_needed());
        $this->assertSame(0, $this->queued());
    }

    /**
     * Prototypes present but a question still without its prototype id means a
     * conversion that stopped part way, or a question restored from a pre-6.0
     * backup. Either way the task is queued, because the migrator is idempotent
     * and will convert only what is left.
     */
    public function test_queued_when_a_question_is_still_unconverted(): void {
        global $DB;

        (new prototype_repository())->create(
            'python3',
            SITEID,
            new override_set(['template' => '{{ STUDENT_ANSWER }}'], 'python3')
        );

        $DB->insert_record('question_coderunner_options', (object) [
            'questionid' => -1,
            'coderunnertype' => 'python3',
            'prototypetype' => 0,
            'overrides' => null,
        ]);

        $this->assertTrue(prototype_installer::queue_migration_if_needed());
        $this->assertSame(1, $this->queued());
    }
}
