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
 * The task that populates the prototype tables after an install or upgrade.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager\task;

use local_coderunner_manager\prototype_installer;
use local_coderunner_manager\prototype_repository;
use local_coderunner_manager\question_migrator;

/**
 * Installs the built-in prototypes and snapshots the user-defined ones.
 *
 * This runs as a task rather than from an install or upgrade step because the
 * question banks it must search do not exist at that point. `mod_qbank` is new
 * in Moodle 5.0 and the fixed plugin-type order installs `qtype` and `local`
 * plugins before `mod` ones, so on an upgrade from Moodle 4.5 there are no bank
 * instances and the question categories have not yet been moved into them.
 *
 * Worse, `mod_qbank` queues that move as an adhoc task of its own, and adhoc
 * tasks run in id order, so this task is queued *first* and would otherwise run
 * *before* the categories had moved. It therefore waits for that task to
 * finish before doing anything.
 */
class install_prototypes extends \core\task\adhoc_task {
    /** @var string The core task this one must not run ahead of. */
    const TRANSFER_TASK = '\mod_qbank\task\transfer_question_categories';

    /** @var int How long to wait before looking again, in seconds. */
    const RETRY_DELAY = 300;

    /**
     * A name for the task, shown on the admin task pages.
     *
     * @return string the name.
     */
    public function get_name(): string {
        return get_string('taskinstallprototypes', 'local_coderunner_manager');
    }

    /**
     * Install the built-ins and snapshot the user prototypes, unless the
     * question categories have yet to be moved into banks.
     */
    public function execute(): void {
        if (PHPUNIT_TEST) {
            // Populating the tables here would bake prototypes into the base
            // dataset that every test starts from, so no test could exercise a
            // first install and every test would inherit whatever the shipped
            // file happened to contain. Tests drive prototype_installer
            // directly instead. The question type guards its own prototype
            // installation the same way, in update_question_types_internal().
            return;
        }

        if ($this->categories_not_yet_transferred()) {
            mtrace('CodeRunner prototype manager: question categories have not yet been moved into '
                . 'question banks, so prototypes cannot be resolved. Waiting.');
            $this->requeue();
            return;
        }

        $installer = new prototype_installer();

        $builtins = $installer->install_builtins();
        mtrace($this->describe('Built-in prototypes', $builtins));

        $snapshot = $installer->snapshot_user_prototypes();
        mtrace($this->describe('User-defined prototypes', $snapshot));

        // Now that every prototype exists, give each question its prototype id
        // and its overrides document. The old columns are left untouched, so
        // the result can be verified against them afterwards and the conversion
        // re-run if a fault is found later.
        $migrated = (new question_migrator())->migrate();
        mtrace(sprintf(
            'CodeRunner prototype manager: questions - %d converted, %d left broken for want of a '
                . 'resolvable prototype.',
            $migrated['converted'],
            $migrated['broken']
        ));
        foreach ($migrated['reasons'] as $name => $count) {
            mtrace("  no single prototype named $name: $count question version(s)");
        }

        $this->retire_superseded_settings();
    }

    /**
     * Remove the admin settings whose values now live in Site defaults.
     *
     * Only once Site defaults exists, since that is where the value has been
     * captured. Leaving the config row in place would mean a question field had
     * two places it appeared to be configured, with nothing to say which one
     * the running code reads.
     */
    protected function retire_superseded_settings(): void {
        if ((new prototype_repository())->site_defaults() === null) {
            return;
        }
        if (get_config('qtype_coderunner', 'default_penalty_regime') !== false) {
            unset_config('default_penalty_regime', 'qtype_coderunner');
            mtrace('CodeRunner prototype manager: the default_penalty_regime setting has been retired; '
                . 'the value now lives in the Site defaults prototype.');
        }
    }

    /**
     * Whether `mod_qbank`'s category transfer is still outstanding.
     *
     * Only a pending row counts. A task that has failed all its attempts would
     * otherwise keep this one waiting for ever, so a row with no attempts left
     * is treated as finished and reported rather than waited on.
     *
     * @return bool true if the transfer has yet to run.
     */
    protected function categories_not_yet_transferred(): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists('task_adhoc')) {
            return false;
        }
        return $DB->record_exists_select(
            'task_adhoc',
            'classname = :classname AND (attemptsavailable > 0 OR attemptsavailable IS NULL)',
            ['classname' => self::TRANSFER_TASK]
        );
    }

    /**
     * Queue this task again, to look once more later.
     */
    protected function requeue(): void {
        $next = new self();
        $next->set_next_run_time(time() + self::RETRY_DELAY);
        \core\task\manager::queue_adhoc_task($next);
    }

    /**
     * A one-line summary of what a run did, for the cron log.
     *
     * @param string $what which set of prototypes was processed.
     * @param array $counts the installer's tally.
     * @return string the summary.
     */
    protected function describe(string $what, array $counts): string {
        $line = sprintf(
            'CodeRunner prototype manager: %s - %d created, %d updated, %d unchanged, %d skipped.',
            $what,
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['skipped']
        );
        foreach ($counts['reasons'] as $name => $reason) {
            $line .= "\n  skipped $name: $reason";
        }
        return $line;
    }
}
