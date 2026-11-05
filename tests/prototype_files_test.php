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
 * Unit tests for prototype support files.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

/**
 * Tests that support files belong to a version, carry forward unchanged, merge
 * by filename along a chain, and cost no extra storage when unchanged.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class prototype_files_test extends \advanced_testcase {
    /** @var prototype_repository the repository under test. */
    private prototype_repository $repo;

    /** @var int a course to scope prototypes to. */
    private int $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
        $this->courseid = (int) $this->getDataGenerator()->create_course()->id;
    }

    /**
     * Files given at creation belong to the prototype's first version.
     */
    public function test_files_are_attached_to_the_first_version(): void {
        $prototype = $this->repo->create(
            'withfiles',
            $this->courseid,
            new override_set(['template' => 'x']),
            files: [$this->make_file('helper.py', 'print(1)')]
        );

        $files = $this->repo->version_files($prototype, $prototype->currentversion->id);

        $this->assertSame(['helper.py'], array_keys($files));
        $this->assertSame('print(1)', $files['helper.py']->get_content());
    }

    /**
     * An edit that does not touch the files carries them forward, so each
     * version is a complete record and an old version can be read back whole.
     */
    public function test_files_carry_forward_to_a_new_version(): void {
        $prototype = $this->repo->create(
            'carried',
            $this->courseid,
            new override_set(['template' => 'first']),
            files: [$this->make_file('data.txt', 'hello')]
        );
        $firstversionid = $prototype->currentversion->id;

        $prototype = $this->repo->save_new_version($prototype, new override_set(['template' => 'second']));

        $this->assertSame(2, $prototype->currentversion->version);
        $carried = $this->repo->version_files($prototype, $prototype->currentversion->id);
        $this->assertSame(['data.txt'], array_keys($carried));
        $this->assertSame('hello', $carried['data.txt']->get_content());

        // The earlier version still has its own copy, so history stays readable.
        $this->assertSame(['data.txt'], array_keys($this->repo->version_files($prototype, $firstversionid)));
    }

    /**
     * Carrying a file forward costs a file record and no content, because the
     * file API stores content by hash. This is what keeps a long version
     * history cheap even for a prototype with large support files.
     */
    public function test_carrying_a_file_forward_stores_no_new_content(): void {
        global $DB;

        $prototype = $this->repo->create(
            'dedup',
            $this->courseid,
            new override_set(),
            files: [$this->make_file('big.txt', str_repeat('x', 10000))]
        );

        for ($i = 0; $i < 4; $i++) {
            $prototype = $this->repo->save_new_version($prototype, new override_set(['template' => "v$i"]));
        }

        $stats = $DB->get_record_sql(
            'SELECT COUNT(1) AS records, COUNT(DISTINCT contenthash) AS contents
               FROM {files}
              WHERE component = :component AND filearea = :filearea AND filename <> :dir',
            ['component' => prototype_repository::COMPONENT, 'filearea' => prototype_repository::FILEAREA, 'dir' => '.']
        );

        $this->assertSame(5, (int) $stats->records, 'One record per version.');
        $this->assertSame(1, (int) $stats->contents, 'But the content is stored once.');
    }

    /**
     * Replacing the file set on a save does not keep the old files.
     */
    public function test_a_new_file_set_replaces_the_old_one(): void {
        $prototype = $this->repo->create(
            'replaced',
            $this->courseid,
            new override_set(),
            files: [$this->make_file('old.py', 'old')]
        );

        $prototype = $this->repo->save_new_version(
            $prototype,
            new override_set(),
            null,
            '',
            '',
            '',
            [$this->make_file('new.py', 'new')]
        );

        $files = $this->repo->version_files($prototype, $prototype->currentversion->id);
        $this->assertSame(['new.py'], array_keys($files));
    }

    /**
     * Files merge by filename along the chain, with the nearest layer winning,
     * which is how a child prototype replaces one of its parent's support files
     * without copying the rest.
     */
    public function test_files_merge_by_filename_with_the_nearest_layer_winning(): void {
        $root = $this->repo->create(
            'root',
            $this->courseid,
            new override_set(),
            files: [
                $this->make_file('shared.py', 'from the root'),
                $this->make_file('onlyroot.py', 'root only'),
            ]
        );
        $child = $this->repo->create(
            'child',
            $this->courseid,
            new override_set(),
            $root->id,
            files: [
                $this->make_file('shared.py', 'from the child'),
                $this->make_file('onlychild.py', 'child only'),
            ]
        );

        $merged = $this->repo->support_files_for($child->id);

        ksort($merged);
        $this->assertSame(['onlychild.py', 'onlyroot.py', 'shared.py'], array_keys($merged));
        $this->assertSame('from the child', $merged['shared.py']->get_content());
        $this->assertSame('root only', $merged['onlyroot.py']->get_content());
    }

    /**
     * A chain whose layers have no files merges to nothing rather than failing.
     */
    public function test_a_chain_with_no_files_merges_to_nothing(): void {
        $root = $this->repo->create('nofiles', $this->courseid, new override_set());
        $child = $this->repo->create('alsonone', $this->courseid, new override_set(), $root->id);

        $this->assertSame([], $this->repo->support_files_for($child->id));
    }

    /**
     * Deleting a prototype takes its files with it, across every version, so no
     * orphaned file records are left behind.
     */
    public function test_deleting_a_prototype_removes_its_files(): void {
        global $DB;

        $prototype = $this->repo->create(
            'doomed',
            $this->courseid,
            new override_set(),
            files: [$this->make_file('gone.py', 'bye')]
        );
        $prototype = $this->repo->save_new_version($prototype, new override_set(['template' => 'x']));

        $this->repo->delete($prototype);

        $this->assertSame(0, $DB->count_records('files', [
            'component' => prototype_repository::COMPONENT,
            'filearea' => prototype_repository::FILEAREA,
        ]));
    }

    /**
     * A site prototype's files live in the front page course's context, so that
     * every prototype's files sit in the context of the course that owns it.
     */
    public function test_site_prototype_files_live_in_the_front_page_context(): void {
        global $DB;

        $prototype = $this->repo->create(
            'sitewide',
            SITEID,
            new override_set(),
            files: [$this->make_file('site.py', 'x')]
        );

        $contextid = $DB->get_field('files', 'contextid', [
            'component' => prototype_repository::COMPONENT,
            'itemid' => $prototype->currentversion->id,
            'filename' => 'site.py',
        ]);

        $this->assertSame(\context_course::instance(SITEID)->id, (int) $contextid);
    }

    /**
     * Make a file in a scratch area, for use as a source to copy from.
     *
     * @param string $filename the file name.
     * @param string $content the file content.
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
