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
 * Structural tests for the prototype manager plugin.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;
use qtype_coderunner\v6\prototype_chain;

/**
 * Tests the properties of the plugin that have to hold for it to install,
 * uninstall and resolve cleanly, rather than the behaviour of any one class.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class plugin_structure_test extends \advanced_testcase {
    /**
     * Every table must be named with the component as its prefix, or uninstall
     * leaves it behind.
     *
     * `drop_plugin_tables()` skips any table whose name does not begin with the
     * component name (`lib/adminlib.php`), so a shorter prefix such as
     * `local_crmanager_` would orphan these tables on every uninstall, silently
     * and permanently. This is a hard requirement, not a naming preference.
     */
    public function test_table_names_are_prefixed_with_the_component(): void {
        global $CFG;

        $xml = simplexml_load_file($CFG->dirroot . '/local/coderunner_manager/db/install.xml');
        $this->assertNotFalse($xml, 'install.xml should parse.');

        $tables = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $tables[] = (string) $table['NAME'];
        }

        $this->assertNotEmpty($tables);
        foreach ($tables as $name) {
            $this->assertStringStartsWith(
                'local_coderunner_manager',
                $name,
                "Table $name would not be dropped when the plugin is uninstalled."
            );
        }
    }

    /**
     * Uninstalling removes the tables, the settings and the files, so the
     * plugin needs no uninstall hook of its own. This checks the assumption
     * holds rather than restating it: the file area is addressed by the
     * component name, which is what delete_component_files() works from.
     */
    public function test_files_are_stored_under_the_component_name(): void {
        $this->assertSame('local_coderunner_manager', prototype_repository::COMPONENT);
        $this->assertSame(
            'local_coderunner_manager',
            \core_component::normalize_componentname(prototype_repository::COMPONENT)
        );
    }

    /**
     * A chain built from stored rows is subject to the same depth limit as one
     * built by hand, so runaway data cannot produce an unbounded chain.
     *
     * The limit lives in prototype_chain, but reaching it through the
     * repository is the path real data takes.
     */
    public function test_a_stored_chain_is_subject_to_the_depth_limit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $repo = new prototype_repository();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;

        $parentid = null;
        for ($i = 0; $i <= prototype_chain::HARD_MAX_DEPTH; $i++) {
            $prototype = $repo->create("deep$i", $courseid, new override_set(), $parentid);
            $parentid = $prototype->id;
        }

        $this->expectException(\coding_exception::class);
        $repo->chain_for($parentid);
    }

    /**
     * A chain just inside the limit resolves, so the guard is not off by one.
     */
    public function test_a_chain_at_the_limit_still_resolves(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $repo = new prototype_repository();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;

        $parentid = null;
        for ($i = 0; $i < prototype_chain::HARD_MAX_DEPTH; $i++) {
            $prototype = $repo->create("ok$i", $courseid, new override_set(), $parentid);
            $parentid = $prototype->id;
        }

        $chain = $repo->chain_for($parentid);
        $this->assertSame(prototype_chain::HARD_MAX_DEPTH, $chain->depth());
        $this->assertTrue($chain->exceeds_advisory_depth());
    }
}
