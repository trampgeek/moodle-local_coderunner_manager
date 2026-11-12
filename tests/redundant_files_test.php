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
 * Unit tests for finding support files a prototype could inherit.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

/**
 * Tests the three states a prototype's own support file can be in, and that
 * dropping the redundant ones leaves what a question resolves to untouched.
 *
 * Decision 28: the migration gave each prototype a snapshot of its CodeRunner 5
 * question's files, so a prototype reparented under another owns copies of
 * nearly everything it could inherit — nineteen of twenty, on the development
 * site's `python3_files_function`. Clearing them by hand meant opening each
 * file and comparing it, which is what this replaces.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(redundant_files::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class redundant_files_test extends \advanced_testcase {
    /** @var prototype_repository the repository the analyser reads through. */
    private prototype_repository $repo;

    /** @var redundant_files the analyser under test. */
    private redundant_files $analyser;

    /** @var int a course to scope prototypes to. */
    private int $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
        $this->analyser = new redundant_files($this->repo);
        $this->courseid = (int) $this->getDataGenerator()->create_course()->id;
    }

    /**
     * The three states, each reached by the condition that defines it.
     */
    public function test_a_child_file_is_classified_against_what_it_inherits(): void {
        $parent = $this->repo->create('parent', $this->courseid, new override_set(), files: [
            $this->make_file('shared.py', 'print(1)'),
            $this->make_file('changed.py', 'parent version'),
        ]);
        $child = $this->repo->create('child', $this->courseid, new override_set(), $parent->id, files: [
            $this->make_file('shared.py', 'print(1)'),
            $this->make_file('changed.py', 'child version'),
            $this->make_file('mine.py', 'only here'),
        ]);

        $rows = $this->analyser->analyse($child->id);

        $this->assertSame(redundant_files::REDUNDANT, $rows['shared.py']['status']);
        $this->assertSame('parent', $rows['shared.py']['inheritedfrom']);

        $this->assertSame(redundant_files::SHADOWING, $rows['changed.py']['status']);
        $this->assertSame('parent', $rows['changed.py']['inheritedfrom']);

        $this->assertSame(redundant_files::OWN, $rows['mine.py']['status']);
        $this->assertNull($rows['mine.py']['inheritedfrom']);

        $this->assertSame(['shared.py'], $this->analyser->redundant_for($child->id));
    }

    /**
     * A root prototype inherits nothing, so every file it has is its own.
     */
    public function test_a_root_prototype_owns_everything(): void {
        $root = $this->repo->create('root', $this->courseid, new override_set(), files: [
            $this->make_file('a.py', 'x'),
        ]);

        $rows = $this->analyser->analyse($root->id);

        $this->assertSame(redundant_files::OWN, $rows['a.py']['status']);
        $this->assertSame([], $this->analyser->redundant_for($root->id));
    }

    /**
     * Only the nearest ancestor owning a filename counts.
     *
     * This is the rule that makes the answer correct rather than merely
     * plausible. The merge lets each layer shadow the one above it, so a file
     * matching the grandparent's is **not** redundant when the parent supplies
     * a different one — dropping it would change what the prototype resolves
     * to, which is the one thing this must never do.
     */
    public function test_the_nearest_ancestor_decides(): void {
        $grandparent = $this->repo->create('grandparent', $this->courseid, new override_set(), files: [
            $this->make_file('f.py', 'grandparent'),
        ]);
        $parent = $this->repo->create('parent', $this->courseid, new override_set(), $grandparent->id, files: [
            $this->make_file('f.py', 'parent'),
        ]);

        $matchesgrandparent = $this->repo->create(
            'matchesgrandparent',
            $this->courseid,
            new override_set(),
            $parent->id,
            files: [$this->make_file('f.py', 'grandparent')]
        );
        $matchesparent = $this->repo->create(
            'matchesparent',
            $this->courseid,
            new override_set(),
            $parent->id,
            files: [$this->make_file('f.py', 'parent')]
        );

        $hidden = $this->analyser->analyse($matchesgrandparent->id);
        $this->assertSame(
            redundant_files::SHADOWING,
            $hidden['f.py']['status'],
            'The grandparent\'s copy is hidden by the parent, so matching it is a real override.'
        );
        $this->assertSame('parent', $hidden['f.py']['inheritedfrom']);

        $nearest = $this->analyser->analyse($matchesparent->id);
        $this->assertSame(redundant_files::REDUNDANT, $nearest['f.py']['status']);
        $this->assertSame('parent', $nearest['f.py']['inheritedfrom']);
    }

    /**
     * Dropping the redundant files changes nothing a question would see.
     *
     * The point of the operation: the prototype stops owning copies, starts
     * following its ancestor, and resolves to exactly the same file set by
     * content. Asserted by content hash rather than by filename, since
     * filenames would match even if the wrong file had been dropped.
     */
    public function test_dropping_leaves_the_resolved_files_identical(): void {
        $parent = $this->repo->create('parent', $this->courseid, new override_set(), files: [
            $this->make_file('shared.py', 'print(1)'),
            $this->make_file('changed.py', 'parent version'),
        ]);
        $child = $this->repo->create('child', $this->courseid, new override_set(), $parent->id, files: [
            $this->make_file('shared.py', 'print(1)'),
            $this->make_file('changed.py', 'child version'),
            $this->make_file('mine.py', 'only here'),
        ]);

        $before = $this->hashes($this->repo->support_files_for($child->id));

        $dropped = $this->analyser->drop_redundant($child->id, 'tidying');

        $this->assertSame(['shared.py'], $dropped);
        $this->assertSame($before, $this->hashes($this->repo->support_files_for($child->id)));
    }

    /**
     * Dropping is a new version, so the previous file set is still recoverable.
     */
    public function test_dropping_is_recorded_as_a_new_version(): void {
        $parent = $this->repo->create('parent', $this->courseid, new override_set(), files: [
            $this->make_file('shared.py', 'print(1)'),
        ]);
        $child = $this->repo->create(
            'child',
            $this->courseid,
            new override_set(['template' => 'kept']),
            $parent->id,
            files: [$this->make_file('shared.py', 'print(1)'), $this->make_file('mine.py', 'x')]
        );
        $firstversion = $child->currentversion->version;

        $this->analyser->drop_redundant($child->id, 'tidying');
        $after = $this->repo->get_or_fail($child->id);

        $this->assertSame($firstversion + 1, $after->currentversion->version);
        $this->assertSame('tidying', $after->currentversion->commitmessage);
        $this->assertSame($parent->id, $after->currentversion->parentid, 'The parent must carry over.');
        $this->assertSame(
            'kept',
            $after->currentversion->overrides->get('template'),
            'Only the file set changes.'
        );
        $this->assertSame(['mine.py'], array_keys($this->repo->version_files($after, $after->currentversion->id)));

        // The dropped file is still in the version it was dropped from.
        $previous = $this->repo->version_files($after, $child->currentversion->id);
        $this->assertArrayHasKey('shared.py', $previous);
    }

    /**
     * Nothing redundant means no new version, so re-running is free.
     */
    public function test_a_clean_prototype_is_left_alone(): void {
        $parent = $this->repo->create('parent', $this->courseid, new override_set(), files: [
            $this->make_file('a.py', 'parent'),
        ]);
        $child = $this->repo->create($name = 'child', $this->courseid, new override_set(), $parent->id, files: [
            $this->make_file('a.py', 'child'),
        ]);
        $version = $child->currentversion->version;

        $this->assertSame([], $this->analyser->drop_redundant($child->id));
        $this->assertSame($version, $this->repo->get_or_fail($child->id)->currentversion->version);
    }

    /**
     * The content hash of each file, keyed by filename.
     *
     * @param \stored_file[] $files the files to summarise.
     * @return array filename => content hash.
     */
    private function hashes(array $files): array {
        $hashes = [];
        foreach ($files as $name => $file) {
            $hashes[$name] = $file->get_contenthash();
        }
        ksort($hashes);
        return $hashes;
    }

    /**
     * Make a stored file to hand to the repository.
     *
     * @param string $filename the name to give it.
     * @param string $content its content, which decides its hash.
     * @return \stored_file the file.
     */
    private function make_file(string $filename, string $content): \stored_file {
        static $itemid = 0;

        return get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($this->courseid)->id,
            'component' => 'local_coderunner_manager',
            'filearea' => 'testsource',
            'itemid' => ++$itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }
}
