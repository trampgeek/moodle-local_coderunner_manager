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
 * Unit tests for saving a prototype edit without publishing it.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

defined('MOODLE_INTERNAL') || die();

/**
 * A draft lets an author work on a prototype that thousands of questions
 * depend on without those questions changing under them.
 *
 * The property that matters is that nothing resolves against a draft. Every
 * test here checks what a question would get, not just what the tables hold.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prototype_repository::class)]
#[\PHPUnit\Framework\Attributes\Group('local_coderunner_manager')]
final class draft_test extends \advanced_testcase {
    /** @var prototype_repository the repository. */
    private prototype_repository $repo;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->repo = new prototype_repository();
    }

    /**
     * Make a prototype with one published version.
     *
     * @return prototype the prototype.
     */
    private function published(): prototype {
        return $this->repo->create('p', SITEID, new override_set(['template' => 'PUBLISHED'], 'p'));
    }

    /**
     * A draft does not change what questions resolve to. This is the whole
     * point of the feature.
     */
    public function test_a_draft_changes_nothing_for_questions(): void {
        $prototype = $this->published();

        $withdraft = $this->repo->save_draft($prototype, new override_set(['template' => 'DRAFT'], 'p'));

        $this->assertTrue($withdraft->has_draft());
        $this->assertSame('DRAFT', $withdraft->draftversion->overrides->get('template'));
        $this->assertSame(
            'PUBLISHED',
            $this->repo->chain_for($prototype->id)->resolve()->get('template'),
            'A question resolved against the draft.'
        );
    }

    /**
     * Publishing makes the draft the version everything resolves against, and
     * leaves no draft behind.
     */
    public function test_publishing_makes_the_draft_current(): void {
        $prototype = $this->repo->save_draft($this->published(), new override_set(['template' => 'DRAFT'], 'p'));

        $published = $this->repo->publish_draft($prototype);

        $this->assertFalse($published->has_draft());
        $this->assertSame('DRAFT', $published->currentversion->overrides->get('template'));
        $this->assertSame('DRAFT', $this->repo->chain_for($prototype->id)->resolve()->get('template'));
    }

    /**
     * Discarding leaves the published version in force and removes the draft
     * from the history, since an abandoned draft is not something an author
     * needs to read later.
     */
    public function test_discarding_leaves_the_published_version_alone(): void {
        $prototype = $this->repo->save_draft($this->published(), new override_set(['template' => 'DRAFT'], 'p'));
        $this->assertCount(2, $this->repo->history($prototype->id));

        $discarded = $this->repo->discard_draft($prototype);

        $this->assertFalse($discarded->has_draft());
        $this->assertSame('PUBLISHED', $this->repo->chain_for($prototype->id)->resolve()->get('template'));
        $this->assertCount(1, $this->repo->history($prototype->id), 'The abandoned draft stayed in the history.');
    }

    /**
     * Saving a second draft replaces the first rather than stacking them up,
     * because an author has one piece of work in progress.
     */
    public function test_a_second_draft_replaces_the_first(): void {
        $prototype = $this->repo->save_draft($this->published(), new override_set(['template' => 'FIRST'], 'p'));
        $prototype = $this->repo->save_draft($prototype, new override_set(['template' => 'SECOND'], 'p'));

        $this->assertSame('SECOND', $prototype->draftversion->overrides->get('template'));
        $this->assertCount(2, $this->repo->history($prototype->id), 'Abandoned drafts accumulated.');
        $this->assertSame('PUBLISHED', $this->repo->chain_for($prototype->id)->resolve()->get('template'));
    }

    /**
     * Publishing when there is nothing to publish is a programming error, not
     * a silent no-op.
     */
    public function test_publishing_without_a_draft_is_refused(): void {
        $this->expectException(\coding_exception::class);
        $this->repo->publish_draft($this->published());
    }

    /**
     * Discarding when there is no draft does nothing and does not complain,
     * so a page can offer it without first checking.
     */
    public function test_discarding_without_a_draft_is_harmless(): void {
        $prototype = $this->published();

        $this->assertFalse($this->repo->discard_draft($prototype)->has_draft());
        $this->assertSame('PUBLISHED', $this->repo->chain_for($prototype->id)->resolve()->get('template'));
    }
}
