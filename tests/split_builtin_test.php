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
 * Unit tests for splitting a built-in prototype on its first edit.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

defined('MOODLE_INTERNAL') || die();

/**
 * Decision 23: an administrator edits a built-in, and the first save splits it
 * so that later releases keep updating the stock content underneath.
 *
 * The invariants are what matter. The edited prototype must keep its id, or
 * every question using it is orphaned; the stock copy must keep the uuid, or
 * the installer cannot find it again and will overwrite the customisation.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_installer::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class split_builtin_test extends \advanced_testcase {
    /** @var prototype_repository the repository. */
    private prototype_repository $repo;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
    }

    /**
     * Create a built-in the way the installer does.
     *
     * @param string $name the canonical name.
     * @return prototype the built-in.
     */
    private function make_builtin(string $name = 'python3'): prototype {
        return $this->repo->create(
            $name,
            SITEID,
            new override_set(['template' => 'STOCK TEMPLATE', 'sandbox' => 'jobesandbox'], $name),
            null,
            'Stock summary',
            'Stock documentation',
            true,
            prototype_installer::builtin_uuid($name)
        );
    }

    /**
     * The edited prototype keeps its id, so every question that used it still
     * does. This is the whole reason for splitting rather than cloning.
     */
    public function test_the_edited_prototype_keeps_its_id(): void {
        $builtin = $this->make_builtin();
        $id = $builtin->id;

        $split = $this->repo->split_builtin($builtin);

        $this->assertSame($id, $split->id);
    }

    /**
     * After the split the edited prototype is no longer a built-in, and it
     * inherits from a stock copy that is.
     */
    public function test_the_split_leaves_a_stock_parent(): void {
        $split = $this->repo->split_builtin($this->make_builtin());

        $this->assertFalse($split->builtin, 'The edited prototype is still flagged built-in.');

        $stock = $this->repo->get_or_fail($split->parentid());
        $this->assertTrue($stock->builtin, 'The stock copy is not flagged built-in.');
        $this->assertStringContainsString('python3', $stock->name);
        $this->assertNotSame($split->name, $stock->name, 'Both prototypes kept the same name.');
    }

    /**
     * The stock copy keeps the built-in's uuid and the edited one gets a new
     * one, so a later release finds the stock copy and leaves the
     * administrator's work alone.
     */
    public function test_the_stock_copy_keeps_the_builtin_uuid(): void {
        $builtin = $this->make_builtin();
        $uuid = $builtin->uuid;

        $split = $this->repo->split_builtin($builtin);
        $stock = $this->repo->get_or_fail($split->parentid());

        $this->assertSame($uuid, $stock->uuid, 'The stock copy lost the built-in uuid.');
        $this->assertNotSame($uuid, $split->uuid, 'The edited prototype kept the built-in uuid.');
    }

    /**
     * The stock copy holds what the built-in held, so nothing is lost in the
     * split and the chain still resolves to the same settings.
     */
    public function test_the_settings_survive_the_split(): void {
        $builtin = $this->make_builtin();
        $before = $this->repo->chain_for($builtin->id)->resolve();

        $split = $this->repo->split_builtin($builtin);
        $after = $this->repo->chain_for($split->id)->resolve();

        $this->assertSame($before->get('template'), $after->get('template'));
        $this->assertSame($before->get('sandbox'), $after->get('sandbox'));
    }

    /**
     * A second release run finds the stock copy by uuid and updates it, rather
     * than finding the administrator's prototype by name and overwriting it.
     */
    public function test_a_later_release_updates_the_stock_copy_only(): void {
        $builtin = $this->make_builtin();
        $split = $this->repo->split_builtin($builtin);

        // The administrator's own change.
        $this->repo->save_new_version(
            $split,
            new override_set(['template' => 'MY TEMPLATE'], $split->name),
            $split->parentid()
        );

        // What a later release does: upsert by uuid.
        $installer = new prototype_installer($this->repo);
        $method = new \ReflectionMethod(prototype_installer::class, 'upsert');
        $method->invoke(
            $installer,
            'python3',
            SITEID,
            new override_set(['template' => 'NEW STOCK TEMPLATE'], 'python3'),
            'Stock documentation',
            true,
            null,
            [],
            prototype_installer::builtin_uuid('python3')
        );

        $resolved = $this->repo->chain_for($split->id)->resolve();
        $this->assertSame('MY TEMPLATE', $resolved->get('template'), "The release overwrote the administrator's change.");

        $stock = $this->repo->get_or_fail($this->repo->get_or_fail($split->id)->parentid());
        $stockversion = $stock->currentversion;
        $this->assertSame(
            'NEW STOCK TEMPLATE',
            $stockversion->overrides->get('template'),
            'The release did not update the stock copy.'
        );
    }

    /**
     * Splitting something that is not a built-in is a programming error, not a
     * silent no-op.
     */
    public function test_splitting_a_normal_prototype_is_refused(): void {
        $ordinary = $this->repo->create('mine', SITEID, new override_set([], 'mine'));

        $this->expectException(\coding_exception::class);
        $this->repo->split_builtin($ordinary);
    }

    /**
     * The uuid of a built-in is a pure function of its name, so every site
     * derives the same one and a restore can recognise it.
     */
    public function test_builtin_uuids_are_stable_and_distinct(): void {
        $this->assertSame(
            prototype_installer::builtin_uuid('python3'),
            prototype_installer::builtin_uuid('python3')
        );
        $this->assertNotSame(
            prototype_installer::builtin_uuid('python3'),
            prototype_installer::builtin_uuid('python3_scratchpad')
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            prototype_installer::builtin_uuid('python3')
        );
    }
}
