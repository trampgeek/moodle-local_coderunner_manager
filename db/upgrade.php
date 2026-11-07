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
 * Upgrade steps for the CodeRunner prototype manager.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Apply the upgrade steps.
 *
 * @param int $oldversion the version being upgraded from.
 * @return bool true on success.
 */
function xmldb_local_coderunner_manager_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026110501) {
        $table = new xmldb_table('local_coderunner_manager_prototypes');
        $field = new xmldb_field('issitedefaults', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'builtin');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110501, 'local', 'coderunner_manager');
    }

    if ($oldversion < 2026110700) {
        upgrade_plugin_savepoint(true, 2026110700, 'local', 'coderunner_manager');
    }

    if ($oldversion < 2026110701) {
        // Documentation is edited through Moodle's editor, so it carries a
        // format like any other text field. Existing rows default to HTML,
        // which is what every migrated prototype's question text was.
        $table = new xmldb_table('local_coderunner_manager_versions');
        $field = new xmldb_field(
            'documentationformat',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'documentation'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110701, 'local', 'coderunner_manager');
    }

    if ($oldversion < 2026110702) {
        // A prototype may hold one saved but unpublished version. Questions go
        // on resolving against the published one until it is published.
        $table = new xmldb_table('local_coderunner_manager_prototypes');
        $field = new xmldb_field(
            'draftversionid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'currentversionid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110702, 'local', 'coderunner_manager');
    }

    // Checked on every upgrade rather than inside a version guard, because the
    // case it recovers from is a task that was lost, which carries no version
    // of its own. It queues nothing when the prototypes are present and every
    // question already has its prototype id.
    \local_coderunner_manager\prototype_installer::queue_migration_if_needed();

    return true;
}
