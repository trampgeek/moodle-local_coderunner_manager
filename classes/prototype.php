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
 * A CodeRunner question prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

/**
 * A prototype: its identity and scope, together with the version that questions
 * and child prototypes currently resolve against.
 *
 * A prototype is an uninstantiated question. It holds the fields that decide how
 * questions of its kind are displayed, run and graded, and none of the fields
 * that make up a particular question.
 */
final readonly class prototype {
    /**
     * @param int $id the prototype row id, or 0 for one not yet saved.
     * @param string $name the label authors see.
     * @param int $courseid the course it belongs to, or SITEID for a site prototype.
     * @param string $uuid a stable identifier kept through export, backup and restore.
     * @param bool $builtin true for a prototype shipped with CodeRunner.
     * @param bool $issitedefaults true for the single Site defaults prototype.
     * @param prototype_version|null $currentversion the version to resolve against.
     * @param prototype_version|null $draftversion a saved but unpublished
     *      version, if the author has one in progress.
     * @param int $timecreated when the prototype was created.
     * @param int $timemodified when it was last changed.
     * @param int $usercreated who created it.
     * @param int $usermodified who last changed it.
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $courseid,
        public string $uuid,
        public bool $builtin = false,
        public bool $issitedefaults = false,
        public ?prototype_version $currentversion = null,
        public ?prototype_version $draftversion = null,
        public int $timecreated = 0,
        public int $timemodified = 0,
        public int $usercreated = 0,
        public int $usermodified = 0,
    ) {
    }

    /**
     * Whether this is a site prototype rather than one belonging to a course.
     *
     * @return bool true if its scope is the site.
     */
    public function is_site_prototype(): bool {
        return $this->courseid == SITEID;
    }

    /**
     * The prototype this one derives from, according to its current version.
     *
     * @return int|null the parent's id, or null for a root.
     */
    public function parentid(): ?int {
        return $this->currentversion?->parentid;
    }

    /**
     * Whether a saved but unpublished version is waiting.
     *
     * @return bool true if there is a draft.
     */
    public function has_draft(): bool {
        return $this->draftversion !== null;
    }
}
