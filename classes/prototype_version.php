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
 * One saved version of a CodeRunner question prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;

/**
 * One saved version of a prototype: the field values it sets, the prototype it
 * derives from, and its documentation.
 *
 * Every version is stored complete. Differences between versions are computed
 * for display rather than stored, so restoring an earlier state is a matter of
 * saving its values as a new version.
 */
final readonly class prototype_version {
    /**
     * @param int $id the version row id, or 0 for a version not yet saved.
     * @param int $prototypeid the prototype this is a version of.
     * @param int $version the version number, counting from 1.
     * @param int|null $parentid the prototype this version derives from, or null for a root.
     * @param override_set $overrides the field values this version sets.
     * @param string $summary one sentence, shown in the prototype picker.
     * @param string $documentation documentation for question authors.
     * @param int $documentationformat the FORMAT_ constant it is written in.
     *      Held as an integer, which is what the column is; Moodle itself
     *      defines the FORMAT_ constants as strings.
     * @param string $commitmessage an optional note describing the change.
     * @param int $timecreated when this version was saved.
     * @param int $usermodified who saved it.
     */
    public function __construct(
        public int $id,
        public int $prototypeid,
        public int $version,
        public ?int $parentid,
        public override_set $overrides,
        public string $summary = '',
        public string $documentation = '',
        public int $documentationformat = FORMAT_HTML,
        public string $commitmessage = '',
        public int $timecreated = 0,
        public int $usermodified = 0,
    ) {
    }

    /**
     * Whether this version is the root of a chain.
     *
     * @return bool true if it derives from no other prototype.
     */
    /**
     * What changed between this version and the one before it, the parent
     * included.
     *
     * The parent is not a field of the override set, so comparing override
     * sets alone reports a reparenting as no change at all — which is exactly
     * what it looked like: a prototype moved under a new parent, gaining a
     * different template and different support files by inheritance, and its
     * history said *No fields changed*. It is the one change that alters every
     * resolved value without touching a single override, so it is reported
     * first, under its own name.
     *
     * @param prototype_version|null $previous the version before this one.
     * @return array in the shape override_set::differences_from() returns,
     *      keyed by field name, with 'parentid' first when it changed.
     */
    public function changes_from(?prototype_version $previous): array {
        if ($previous === null) {
            return [];
        }

        $differences = [];
        if ((int) $this->parentid !== (int) $previous->parentid) {
            $differences['parentid'] = [
                'change' => 'changed',
                'from' => $previous->parentid,
                'to' => $this->parentid,
            ];
        }

        return $differences + $this->overrides->differences_from($previous->overrides);
    }

    public function is_root(): bool {
        return $this->parentid === null;
    }
}
