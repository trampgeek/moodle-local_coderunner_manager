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
 * Finding a prototype's support files that it could inherit instead of owning.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

/**
 * Classifies each of a prototype's own support files against what it inherits.
 *
 * Decision 28: a child prototype's support files are merged with its
 * ancestors' by filename, so a file it does not own, it inherits. The
 * migration produced the opposite, because each prototype got a snapshot of
 * its CodeRunner 5 question's files; a prototype reparented under another
 * therefore owns copies of nearly everything it could inherit. The
 * development site's `python3_files_function` owns twenty files, nineteen of
 * which its new parent `python3_scratchpad` also owns.
 *
 * Dropping such a file changes nothing about how the prototype resolves today,
 * which is what makes this safe. What it changes is the future: while the copy
 * exists, editing the ancestor's file does not reach this prototype, and that
 * is the maintenance cost the whole design exists to remove. So this is worth
 * re-running after any edit to a shared file, not once.
 *
 * **Only the nearest ancestor owning a filename counts.** The merge lets each
 * layer shadow the one above it, so a file is redundant when it matches the
 * copy it would actually inherit — not when it matches some copy further up
 * that a middle layer already hides.
 */
class redundant_files {
    /** @var string The prototype's copy is identical to the one it would inherit. */
    const REDUNDANT = 'redundant';

    /** @var string The prototype's copy differs, so it deliberately shadows. */
    const SHADOWING = 'shadowing';

    /** @var string No ancestor offers this filename, so it is genuinely its own. */
    const OWN = 'own';

    /** @var prototype_repository The repository to read through. */
    protected prototype_repository $repo;

    /**
     * @param prototype_repository|null $repo the repository to read through.
     */
    public function __construct(?prototype_repository $repo = null) {
        $this->repo = $repo ?? new prototype_repository();
    }

    /**
     * Classify every support file a prototype owns.
     *
     * A prototype with no current version owns nothing, and the root of a
     * chain inherits nothing, so both come back with everything as OWN.
     *
     * @param int $prototypeid the prototype to examine.
     * @return array filename => ['status' => one of the constants,
     *      'inheritedfrom' => the ancestor's name or null,
     *      'contenthash' => the prototype's own copy's hash].
     */
    public function analyse(int $prototypeid): array {
        $chain = $this->repo->chain_prototypes($prototypeid);

        // chain_prototypes() returns root first, so the prototype itself is
        // last and everything before it is an ancestor.
        $self = array_pop($chain);
        if ($self === null || $self->currentversion === null) {
            return [];
        }

        // The file each ancestor would supply, nearest ancestor winning. The
        // ancestors are still in root-first order, so a later assignment is a
        // nearer layer and must overwrite an earlier one.
        $inherited = [];
        foreach ($chain as $ancestor) {
            if ($ancestor->currentversion === null) {
                continue;
            }
            foreach ($this->repo->version_files($ancestor, $ancestor->currentversion->id) as $name => $file) {
                $inherited[$name] = ['file' => $file, 'from' => $ancestor->name];
            }
        }

        $result = [];
        $ownfiles = $this->repo->version_files($self, $self->currentversion->id);
        foreach ($ownfiles as $name => $file) {
            $candidate = $inherited[$name] ?? null;
            if ($candidate === null) {
                $status = self::OWN;
                $from = null;
            } else {
                // Content identity comes from the file API's own hash, so a
                // large file costs nothing to compare and the answer is exact
                // rather than a heuristic on size or time.
                $status = $candidate['file']->get_contenthash() === $file->get_contenthash()
                    ? self::REDUNDANT
                    : self::SHADOWING;
                $from = $candidate['from'];
            }
            $result[$name] = [
                'status' => $status,
                'inheritedfrom' => $from,
                'contenthash' => $file->get_contenthash(),
            ];
        }

        return $result;
    }

    /**
     * The filenames a prototype owns that it could inherit unchanged.
     *
     * @param int $prototypeid the prototype to examine.
     * @return string[] the redundant filenames.
     */
    public function redundant_for(int $prototypeid): array {
        $names = [];
        foreach ($this->analyse($prototypeid) as $name => $row) {
            if ($row['status'] === self::REDUNDANT) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Drop a prototype's redundant files, as a new version.
     *
     * **A new version rather than an edit of the current one.** Versions are
     * the record of what a prototype has held, so removing files from the
     * current version in place would make that record wrong, and would leave
     * an author no way back. Going through save_new_version() keeps the
     * history honest, puts a reason in the log, and makes the change
     * reversible with restore_version().
     *
     * The new version carries the same overrides, parent, summary and
     * documentation, so the only difference is the file set. Nothing about how
     * the prototype resolves changes, since every dropped file is replaced by
     * an identical inherited one.
     *
     * @param int $prototypeid the prototype to tidy.
     * @param string $commitmessage the reason to record against the version.
     * @return string[] the filenames dropped; empty if there was nothing to do.
     */
    public function drop_redundant(int $prototypeid, string $commitmessage = ''): array {
        $redundant = $this->redundant_for($prototypeid);
        if (!$redundant) {
            return [];
        }

        $prototype = $this->repo->get_or_fail($prototypeid);
        $version = $prototype->currentversion;
        $keep = [];
        foreach ($this->repo->version_files($prototype, $version->id) as $name => $file) {
            if (!in_array($name, $redundant, true)) {
                $keep[$name] = $file;
            }
        }

        $this->repo->save_new_version(
            $prototype,
            $version->overrides,
            $version->parentid,
            $version->summary ?? '',
            $version->documentation ?? '',
            $commitmessage !== '' ? $commitmessage : get_string('redundantfiles_commit', 'local_coderunner_manager'),
            array_values($keep),
            $version->documentationformat
        );

        return $redundant;
    }
}
