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
 * Install-time setup for the CodeRunner prototype manager.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Queue the task that populates the prototype tables.
 *
 * Nothing is populated here. The question banks the installer must search do
 * not exist during an install or upgrade, so the work is deferred to a task.
 * Queueing is cheap and cannot fail part-way, so this step needs no recovery
 * hook even though Moodle records the plugin's version before running it.
 */
function xmldb_local_coderunner_manager_install(): void {
    \core\task\manager::queue_adhoc_task(new \local_coderunner_manager\task\install_prototypes());
}
