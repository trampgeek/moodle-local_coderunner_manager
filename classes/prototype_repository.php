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
 * Storage for CodeRunner question prototypes.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use qtype_coderunner\v6\override_set;
use qtype_coderunner\v6\prototype_chain;

/**
 * All database access for prototypes and their versions.
 *
 * Nothing else reads or writes the two tables, so the invariants live in one
 * place: a name is unique among a course's prototypes and all site prototypes
 * taken together, a prototype's ancestry contains no cycle, and every prototype
 * has a current version.
 */
class prototype_repository {
    /** @var string The table holding prototype identity and scope. */
    const PROTOTYPES = 'local_coderunner_manager_prototypes';

    /** @var string The table holding one row per saved version. */
    const VERSIONS = 'local_coderunner_manager_versions';

    /** @var string The component owning prototype support files. */
    const COMPONENT = 'local_coderunner_manager';

    /** @var string The file area holding a version's support files. */
    const FILEAREA = 'prototypefile';

    /**
     * Load one prototype, with its current version.
     *
     * @param int $id the prototype id.
     * @return prototype|null the prototype, or null if there is no such row.
     */
    public function get(int $id): ?prototype {
        global $DB;

        $record = $DB->get_record(self::PROTOTYPES, ['id' => $id]);
        return $record ? $this->prototype_from_record($record) : null;
    }

    /**
     * Load one prototype, failing if it does not exist.
     *
     * @param int $id the prototype id.
     * @return prototype the prototype.
     * @throws \moodle_exception if there is no such prototype.
     */
    public function get_or_fail(int $id): prototype {
        $prototype = $this->get($id);
        if ($prototype === null) {
            throw new \moodle_exception('prototypenotfound', 'local_coderunner_manager', '', $id);
        }
        return $prototype;
    }

    /**
     * The Site defaults prototype, which sits at the root of every chain.
     *
     * It holds the values that CodeRunner 5 kept in code and in admin settings,
     * so that a field no prototype and no question sets has one editable place
     * it comes from. There is exactly one, it is site scoped, and it cannot be
     * deleted.
     *
     * @return prototype|null the Site defaults prototype, or null before it has
     *      been created.
     */
    public function site_defaults(): ?prototype {
        global $DB;

        $record = $DB->get_record(self::PROTOTYPES, ['issitedefaults' => 1]);
        return $record ? $this->prototype_from_record($record) : null;
    }

    /**
     * The prototypes an author in the given course may use: that course's own,
     * plus the site prototypes.
     *
     * Prototypes belonging to other courses are deliberately excluded. Using one
     * is a separate, deliberate step in the picker, and needs the management
     * capability in the course that owns it.
     *
     * @param int $courseid the course.
     * @return prototype[] prototypes, keyed by id, ordered by name.
     */
    public function list_for_course(int $courseid): array {
        global $DB;

        $params = ['courseid' => $courseid, 'siteid' => SITEID];
        $records = $DB->get_records_select(
            self::PROTOTYPES,
            'courseid = :courseid OR courseid = :siteid',
            $params,
            'name ASC'
        );
        return $this->prototypes_from_records($records);
    }

    /**
     * All prototypes with the given stable identifier.
     *
     * The identifier is not unique on a site: a restore or import that cannot
     * use the original creates a copy carrying the same one. Callers choose
     * between the results by scope.
     *
     * @param string $uuid the stable identifier.
     * @return prototype[] prototypes, keyed by id.
     */
    public function find_by_uuid(string $uuid): array {
        global $DB;

        return $this->prototypes_from_records($DB->get_records(self::PROTOTYPES, ['uuid' => $uuid]));
    }

    /**
     * The prototype of the given name that a course's questions resolve against.
     *
     * A name is unique among one course's prototypes and all site prototypes
     * taken together, which {@see name_clash()} enforces, so this returns at
     * most one prototype and needs no tie-break. A course prototype cannot
     * shadow a site one, because the name would have been refused.
     *
     * This is the lookup the question type uses when it has only a name to go
     * on: a question saved through the CodeRunner 5 form, a legacy import, or
     * a restore whose prototype id could not be mapped.
     *
     * @param string $name the prototype name.
     * @param int $courseid the course whose questions are asking.
     * @return prototype|null the prototype, or null if the course cannot see
     *      one of that name.
     */
    public function find_by_name_for_course(string $name, int $courseid): ?prototype {
        global $DB;

        $record = $DB->get_record_select(
            self::PROTOTYPES,
            'name = :name AND (courseid = :courseid OR courseid = :siteid)',
            ['name' => $name, 'courseid' => $courseid, 'siteid' => SITEID]
        );
        return $record ? $this->prototype_from_record($record) : null;
    }

    /**
     * The prototypes whose current version derives from the given one.
     *
     * @param int $id the parent prototype's id.
     * @return prototype[] prototypes, keyed by id, ordered by name.
     */
    public function children_of(int $id): array {
        global $DB;

        $records = $DB->get_records_sql("
            SELECT p.*
              FROM {" . self::PROTOTYPES . "} p
              JOIN {" . self::VERSIONS . "} v ON v.id = p.currentversionid
             WHERE v.parentid = :id
          ORDER BY p.name ASC", ['id' => $id]);
        return $this->prototypes_from_records($records);
    }

    /**
     * Split a built-in so that an administrator's edit survives upgrades.
     *
     * The stock content is copied into a new prototype, flagged built-in and
     * renamed; the prototype being edited keeps its id, drops the built-in
     * flag, and becomes a child of the stock copy. Questions refer to
     * prototypes by id, so every question using it carries on using it, with
     * nothing re-pointed and no migration.
     *
     * Later releases rewrite the stock copy, which the installer finds by its
     * uuid rather than by name, and the administrator's overrides go on
     * applying over whatever the stock copy now says. See decision 23.
     *
     * @param prototype $builtin the built-in being edited.
     * @return prototype the same prototype, no longer a built-in, now a child.
     * @throws \coding_exception if the prototype is not a built-in.
     */
    public function split_builtin(prototype $builtin): prototype {
        global $DB;

        if (!$builtin->builtin) {
            throw new \coding_exception("Prototype '{$builtin->name}' is not a built-in and cannot be split.");
        }

        $transaction = $DB->start_delegated_transaction();

        $version = $builtin->currentversion;

        // The stock copy takes the built-in's identity: its uuid, by which the
        // installer will find it, and a name that says what it is.
        $stock = $this->create(
            $this->stock_name($builtin->name, $builtin->courseid),
            $builtin->courseid,
            $version ? $version->overrides : new \qtype_coderunner\v6\override_set([], $builtin->name),
            $version?->parentid,
            $version?->summary ?? '',
            $version?->documentation ?? '',
            true,
            $builtin->uuid
        );

        // The prototype being edited is no longer stock. It is given a uuid of
        // its own, so that the installer cannot mistake it for the built-in.
        $DB->set_field_select(
            self::PROTOTYPES,
            'builtin',
            0,
            'id = :id',
            ['id' => $builtin->id]
        );
        $DB->set_field_select(
            self::PROTOTYPES,
            'uuid',
            \core\uuid::generate(),
            'id = :id',
            ['id' => $builtin->id]
        );

        // Re-parenting is a new version, because a prototype's parent is
        // recorded on its version and not on the prototype itself. The split
        // is therefore a version of its own in the history, before whatever
        // change the administrator came to make, which is what the history
        // should show.
        $split = $this->save_new_version(
            $this->get_or_fail($builtin->id),
            $version ? $version->overrides : new \qtype_coderunner\v6\override_set([], $builtin->name),
            $stock->id,
            $version?->summary ?? '',
            $version?->documentation ?? '',
            get_string('splitfrombuiltin', 'local_coderunner_manager', $stock->name)
        );

        $transaction->allow_commit();

        return $split;
    }

    /**
     * A free name for the stock copy of a built-in.
     *
     * @param string $name the built-in's name.
     * @param int $courseid the scope.
     * @return string a name no prototype in that scope is using.
     */
    protected function stock_name(string $name, int $courseid): string {
        $candidate = get_string('stockname', 'local_coderunner_manager', $name);
        $suffix = 1;
        while ($this->name_clash($candidate, $courseid) !== null) {
            $suffix++;
            $candidate = get_string('stockname', 'local_coderunner_manager', $name) . " ($suffix)";
        }
        return $candidate;
    }

    /**
     * Bring an earlier version's content back as a new version.
     *
     * Nothing is deleted and nothing is rewound. Restoring writes a *new*
     * version holding the old content, so the history stays complete and the
     * restore is itself a visible entry in it — which matters when the thing
     * being undone was someone else's change, or when the restore turns out to
     * be the mistake.
     *
     * @param prototype $prototype the prototype to restore into.
     * @param int $version the version number whose content to bring back.
     * @return prototype the prototype, now at a new current version.
     * @throws \moodle_exception if there is no such version.
     */
    public function restore_version(prototype $prototype, int $version): prototype {
        $history = $this->history($prototype->id);
        if (!isset($history[$version])) {
            throw new \moodle_exception('nosuchversion', 'local_coderunner_manager', '', $version);
        }
        $old = $history[$version];

        return $this->save_new_version(
            $prototype,
            $old->overrides,
            $old->parentid,
            $old->summary,
            $old->documentation,
            get_string('restoredfromversion', 'local_coderunner_manager', $version),
            $this->version_files($prototype, $old->id) ?: null
        );
    }

    /**
     * Every prototype descended from the given one, however deep.
     *
     * The parent picker uses this to leave out the choices that would make a
     * cycle. save_new_version() rejects a cycle outright, which is the
     * backstop; this is so an author is never offered a parent that will be
     * refused.
     *
     * @param int $id the prototype whose descendants are wanted.
     * @return int[] the descendants' ids.
     */
    public function descendant_ids(int $id): array {
        $found = [];
        $frontier = [$id];

        // Breadth first, with a visited set: restored data can contain a cycle,
        // and this must not be the thing that hangs on it.
        while ($frontier) {
            $next = [];
            foreach ($frontier as $parentid) {
                foreach ($this->children_of($parentid) as $child) {
                    if ($child->id === $id || in_array($child->id, $found, true)) {
                        continue;
                    }
                    $found[] = $child->id;
                    $next[] = $child->id;
                }
            }
            $frontier = $next;
        }

        return $found;
    }

    /**
     * Whether a name may be used for a prototype in the given scope.
     *
     * A name must be unique among one course's prototypes and all site
     * prototypes taken together, so that a course prototype can never shadow a
     * site one and an author never sees two prototypes of the same name. A new
     * site prototype therefore needs a name that no course already uses.
     *
     * @param string $name the proposed name.
     * @param int $courseid the scope, or SITEID for a site prototype.
     * @param int $excludeid a prototype to ignore, when checking a rename.
     * @return string|null null if the name is free, else the language string id
     *      explaining the clash.
     */
    public function name_clash(string $name, int $courseid, int $excludeid = 0): ?string {
        global $DB;

        $params = ['name' => $name, 'excludeid' => $excludeid];

        if ($courseid == SITEID) {
            // A site prototype's name must be free everywhere.
            $where = 'name = :name AND id <> :excludeid';
            if ($DB->record_exists_select(self::PROTOTYPES, $where, $params)) {
                $params['siteid'] = SITEID;
                $incourse = $DB->record_exists_select(
                    self::PROTOTYPES,
                    'name = :name AND id <> :excludeid AND courseid <> :siteid',
                    $params
                );
                return $incourse ? 'nameinuseincourse' : 'nameinuse';
            }
            return null;
        }

        // A course prototype must not clash within its course or with a site prototype.
        $params['courseid'] = $courseid;
        if ($DB->record_exists_select(
            self::PROTOTYPES,
            'name = :name AND id <> :excludeid AND courseid = :courseid',
            $params
        )) {
            return 'nameinuse';
        }
        $params['siteid'] = SITEID;
        if ($DB->record_exists_select(
            self::PROTOTYPES,
            'name = :name AND id <> :excludeid AND courseid = :siteid',
            $params
        )) {
            return 'nameinuseatsite';
        }
        return null;
    }

    /**
     * Create a prototype and its first version.
     *
     * @param string $name the label authors see.
     * @param int $courseid the scope, or SITEID for a site prototype.
     * @param override_set $overrides the field values it sets.
     * @param int|null $parentid the prototype it derives from, or null for a root.
     * @param string $summary one sentence for the picker.
     * @param string $documentation documentation for question authors.
     * @param bool $builtin true for a prototype shipped with CodeRunner.
     * @param string $uuid a stable identifier, generated if not supplied.
     * @param bool $issitedefaults true for the single Site defaults prototype.
     * @param \stored_file[] $files support files for the first version.
     * @return prototype the new prototype, with its first version.
     * @throws \moodle_exception if the name clashes or the parent does not exist.
     */
    public function create(
        string $name,
        int $courseid,
        override_set $overrides,
        ?int $parentid = null,
        string $summary = '',
        string $documentation = '',
        bool $builtin = false,
        string $uuid = '',
        bool $issitedefaults = false,
        array $files = []
    ): prototype {
        global $DB, $USER;

        $transaction = $DB->start_delegated_transaction();

        $clash = $this->name_clash($name, $courseid);
        if ($clash !== null) {
            throw new \moodle_exception($clash, 'local_coderunner_manager', '', $name);
        }
        if ($parentid !== null) {
            $this->get_or_fail($parentid);
        }

        $now = time();
        $record = (object) [
            'name' => $name,
            'courseid' => $courseid,
            'uuid' => $uuid !== '' ? $uuid : \core\uuid::generate(),
            'builtin' => $builtin ? 1 : 0,
            'issitedefaults' => $issitedefaults ? 1 : 0,
            'currentversionid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usercreated' => (int) $USER->id,
            'usermodified' => (int) $USER->id,
        ];
        $record->id = $DB->insert_record(self::PROTOTYPES, $record);

        $versionid = $this->insert_version(
            $record->id,
            1,
            $parentid,
            $overrides,
            $summary,
            $documentation,
            '',
            $now
        );
        if ($files) {
            $this->copy_files_to_version($this->get_or_fail($record->id), $versionid, $files);
        }

        $transaction->allow_commit();

        return $this->get_or_fail($record->id);
    }

    /**
     * Save a new version of a prototype.
     *
     * Every save is a new version; nothing is overwritten. The parent may change,
     * in which case the new version records the new parent and earlier versions
     * keep the old one.
     *
     * @param prototype $prototype the prototype to add a version to.
     * @param override_set $overrides the field values the new version sets.
     * @param int|null $parentid the prototype it derives from, or null for a root.
     * @param string $summary one sentence for the picker.
     * @param string $documentation documentation for question authors.
     * @param string $commitmessage an optional note describing the change.
     * @param \stored_file[]|null $files the support files for the new version.
     *      Null carries the previous version's files forward, which is what an
     *      edit that does not touch the files should do.
     * @return prototype the prototype, with its new current version.
     * @throws \moodle_exception if the parent would form a cycle or does not exist.
     */
    public function save_new_version(
        prototype $prototype,
        override_set $overrides,
        ?int $parentid = null,
        string $summary = '',
        string $documentation = '',
        string $commitmessage = '',
        ?array $files = null
    ): prototype {
        global $DB, $USER;

        $transaction = $DB->start_delegated_transaction();

        if ($parentid !== null) {
            $parent = $this->get_or_fail($parentid);
            $this->reject_cycle($prototype, $parent);
        }

        $next = 1 + (int) $DB->get_field_sql(
            'SELECT MAX(version) FROM {' . self::VERSIONS . '} WHERE prototypeid = :id',
            ['id' => $prototype->id]
        );
        $now = time();
        $versionid = $this->insert_version(
            $prototype->id,
            $next,
            $parentid,
            $overrides,
            $summary,
            $documentation,
            $commitmessage,
            $now
        );

        // A version is a complete record, so the files carry forward unless the
        // caller supplies a new set. Copying costs a file record and no content,
        // because the file API stores content by hash.
        $carried = $files ?? ($prototype->currentversion
            ? $this->version_files($prototype, $prototype->currentversion->id)
            : []);
        $this->copy_files_to_version($prototype, $versionid, $carried);
        $DB->update_record(self::PROTOTYPES, (object) [
            'id' => $prototype->id,
            'timemodified' => $now,
            'usermodified' => (int) $USER->id,
        ]);

        $transaction->allow_commit();

        return $this->get_or_fail($prototype->id);
    }

    /**
     * Rename a prototype.
     *
     * The name is the label authors see and what imports match on; nothing else
     * depends on it, since questions and child prototypes refer to a prototype
     * by id. Renaming is therefore not a content change and does not create a
     * new version.
     *
     * @param prototype $prototype the prototype to rename.
     * @param string $name the new name.
     * @return prototype the renamed prototype.
     * @throws \moodle_exception if the new name clashes.
     */
    public function rename(prototype $prototype, string $name): prototype {
        global $DB, $USER;

        $transaction = $DB->start_delegated_transaction();

        $clash = $this->name_clash($name, $prototype->courseid, $prototype->id);
        if ($clash !== null) {
            throw new \moodle_exception($clash, 'local_coderunner_manager', '', $name);
        }
        $DB->update_record(self::PROTOTYPES, (object) [
            'id' => $prototype->id,
            'name' => $name,
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ]);

        $transaction->allow_commit();

        return $this->get_or_fail($prototype->id);
    }

    /**
     * Delete a prototype and its whole version history.
     *
     * Refused while any other prototype derives from it. Once questions refer to
     * prototypes by id, this must also refuse while any question depends on it;
     * that check belongs with the question-side schema and is not here yet.
     *
     * @param prototype $prototype the prototype to delete.
     * @throws \moodle_exception if another prototype derives from it.
     */
    public function delete(prototype $prototype): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        if ($prototype->issitedefaults) {
            throw new \moodle_exception('cannotdeletesitedefaults', 'local_coderunner_manager');
        }
        if ($this->children_of($prototype->id)) {
            throw new \moodle_exception('prototypeinuse', 'local_coderunner_manager', '', $prototype->name);
        }
        $fs = get_file_storage();
        $contextid = $this->file_contextid($prototype);
        foreach ($DB->get_fieldset_select(self::VERSIONS, 'id', 'prototypeid = :id', ['id' => $prototype->id]) as $vid) {
            $fs->delete_area_files($contextid, self::COMPONENT, self::FILEAREA, $vid);
        }
        $DB->delete_records(self::VERSIONS, ['prototypeid' => $prototype->id]);
        $DB->delete_records(self::PROTOTYPES, ['id' => $prototype->id]);

        $transaction->allow_commit();
    }

    /**
     * Every saved version of a prototype, newest first.
     *
     * @param int $prototypeid the prototype id.
     * @return prototype_version[] versions, keyed by version number.
     */
    public function history(int $prototypeid): array {
        global $DB;

        $versions = [];
        $records = $DB->get_records(self::VERSIONS, ['prototypeid' => $prototypeid], 'version DESC');
        foreach ($records as $record) {
            $versions[(int) $record->version] = $this->version_from_record($record);
        }
        return $versions;
    }

    /**
     * The chain a question using this prototype resolves against, root first.
     *
     * Each version records its own parent, and resolution follows that parent to
     * the parent's *current* version, so the chain reflects what each ancestor
     * looks like now.
     *
     * This is where cycles in stored data are caught. The chain itself only sees
     * a flat list and cannot tell a cycle from a repeated name, so a visited set
     * is kept here. Bad data can reach the tables through a restore, so this is
     * not merely a guard against this plugin's own bugs.
     *
     * @param int $prototypeid the prototype a question uses.
     * @param override_set|null $questionoverrides the question's own overrides,
     *      appended as the last layer when given.
     * @return prototype_chain the chain, root first.
     * @throws \moodle_exception if a prototype in the chain does not exist.
     * @throws \coding_exception if the stored parent links form a cycle.
     */
    public function chain_for(int $prototypeid, ?override_set $questionoverrides = null): prototype_chain {
        $layers = [];
        foreach ($this->chain_prototypes($prototypeid) as $prototype) {
            $version = $prototype->currentversion;
            $layers[] = new override_set(
                $version === null ? [] : $version->overrides->to_array(),
                $this->layer_label($prototype)
            );
        }
        if ($questionoverrides !== null) {
            $layers[] = $questionoverrides;
        }
        return new prototype_chain($layers);
    }

    /**
     * The prototypes a question using the given one inherits from, root first.
     *
     * Each version records its own parent, and the walk follows that parent to
     * the parent's *current* version, so the chain reflects what each ancestor
     * looks like now.
     *
     * This is where cycles in stored data are caught. A flattened chain cannot
     * tell a cycle from a repeated name, and bad parent links can reach the
     * tables through a restore, so this is not merely a guard against this
     * plugin's own bugs.
     *
     * @param int $prototypeid the prototype a question uses.
     * @return prototype[] the prototypes, root first.
     * @throws \moodle_exception if a prototype in the chain does not exist.
     * @throws \coding_exception if the stored parent links form a cycle.
     */
    public function chain_prototypes(int $prototypeid): array {
        $chain = [];
        $visited = [];
        $id = $prototypeid;

        while ($id !== null) {
            if (isset($visited[$id])) {
                throw new \coding_exception(
                    "Prototype $id appears twice in its own ancestry, so the stored parent links form a cycle."
                );
            }
            $visited[$id] = true;

            $prototype = $this->get_or_fail($id);
            $chain[] = $prototype;
            $id = $prototype->currentversion?->parentid;
        }

        return array_reverse($chain);
    }

    /**
     * The support files one version of a prototype holds.
     *
     * @param prototype $prototype the prototype the version belongs to, which
     *      decides the context the files live in.
     * @param int $versionid the version whose files are wanted.
     * @return \stored_file[] files, keyed by filename.
     */
    public function version_files(prototype $prototype, int $versionid): array {
        $fs = get_file_storage();
        $files = [];
        foreach ($fs->get_area_files(
            $this->file_contextid($prototype),
            self::COMPONENT,
            self::FILEAREA,
            $versionid,
            'filename',
            false
        ) as $file) {
            $files[$file->get_filename()] = $file;
        }
        return $files;
    }

    /**
     * The support files a question using the given prototype gets, merged by
     * filename along the chain with the nearest layer winning.
     *
     * The question's own files are merged in by the question type, which owns
     * them; this is only the prototype side of the merge.
     *
     * @param int $prototypeid the prototype a question uses.
     * @return \stored_file[] files, keyed by filename.
     */
    public function support_files_for(int $prototypeid): array {
        $files = [];
        foreach ($this->chain_prototypes($prototypeid) as $prototype) {
            $version = $prototype->currentversion;
            if ($version === null) {
                continue;
            }
            // Later layers overwrite earlier ones of the same filename.
            $files = array_merge($files, $this->version_files($prototype, $version->id));
        }
        return $files;
    }

    /**
     * How many questions and child prototypes depend on each of the given
     * prototypes, directly.
     *
     * Two grouped queries rather than one per prototype, because the library
     * view asks about every prototype on the page at once and a course can
     * have scores of them.
     *
     * Questions are counted as an author counts them — one per question bank
     * entry, not one per stored version — since a question edited twenty times
     * is still one question depending on the prototype. Only direct dependants
     * are counted; the usage report walks the whole subtree.
     *
     * @param int[] $prototypeids the prototypes to count for.
     * @return array prototype id => ['questions' => int, 'children' => int].
     */
    public function usage_counts(array $prototypeids): array {
        global $DB;

        $counts = [];
        foreach ($prototypeids as $id) {
            $counts[(int) $id] = ['questions' => 0, 'children' => 0];
        }
        if (!$prototypeids) {
            return $counts;
        }

        [$insql, $params] = $DB->get_in_or_equal($prototypeids, SQL_PARAMS_NAMED, 'p');

        $questions = $DB->get_records_sql("
            SELECT o.prototypeid AS id, COUNT(DISTINCT qbe.id) AS n
              FROM {question_coderunner_options} o
              JOIN {question} q ON q.id = o.questionid
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE o.prototypeid $insql
          GROUP BY o.prototypeid", $params);
        foreach ($questions as $row) {
            $counts[(int) $row->id]['questions'] = (int) $row->n;
        }

        $children = $DB->get_records_sql("
            SELECT v.parentid AS id, COUNT(*) AS n
              FROM {" . self::PROTOTYPES . "} p
              JOIN {" . self::VERSIONS . "} v ON v.id = p.currentversionid
             WHERE v.parentid $insql
          GROUP BY v.parentid", $params);
        foreach ($children as $row) {
            $counts[(int) $row->id]['children'] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * The file areas a question's support files are merged from, root first.
     *
     * Only the locations, not the files. The question type reads the files
     * themselves through the file API on the attempt path, where decision 16
     * forbids touching the prototype tables; resolving the locations here means
     * that walk happens once per questiondata cache entry instead of once per
     * attempt.
     *
     * @param int $prototypeid the prototype a question uses.
     * @return array list of ['contextid' => int, 'itemid' => int], root first.
     */
    public function file_areas_for(int $prototypeid): array {
        $areas = [];
        foreach ($this->chain_prototypes($prototypeid) as $prototype) {
            $version = $prototype->currentversion;
            if ($version === null) {
                continue;
            }
            $areas[] = [
                'contextid' => $this->file_contextid($prototype),
                'itemid' => $version->id,
            ];
        }
        return $areas;
    }

    /**
     * Copy files into a version's file area from another area.
     *
     * The file API stores content by hash, so copying a file that is already
     * stored adds a file record and no content. A version that leaves a file
     * unchanged therefore costs almost nothing, however large the file.
     *
     * @param prototype $prototype the prototype the version belongs to.
     * @param int $versionid the version to attach the files to.
     * @param \stored_file[] $files the files to copy.
     */
    public function copy_files_to_version(prototype $prototype, int $versionid, array $files): void {
        $fs = get_file_storage();
        $contextid = $this->file_contextid($prototype);

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            if ($fs->file_exists(
                $contextid,
                self::COMPONENT,
                self::FILEAREA,
                $versionid,
                $file->get_filepath(),
                $file->get_filename()
            )) {
                continue;
            }
            $fs->create_file_from_storedfile([
                'contextid' => $contextid,
                'component' => self::COMPONENT,
                'filearea' => self::FILEAREA,
                'itemid' => $versionid,
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
            ], $file);
        }
    }

    /**
     * The context a prototype's files live in.
     *
     * A prototype belongs to a course, so its files live in that course's
     * context and travel with the course in a backup. A site prototype's files
     * live in the front page course's context.
     *
     * @param prototype $prototype the prototype.
     * @return int the context id.
     */
    protected function file_contextid(prototype $prototype): int {
        return \context_course::instance($prototype->courseid)->id;
    }

    /**
     * The label recorded against each value a prototype supplies, shown to
     * authors as the source of an inherited value.
     *
     * @param prototype $prototype the prototype.
     * @return string the label.
     */
    protected function layer_label(prototype $prototype): string {
        return $prototype->name . '@' . $prototype->id;
    }

    /**
     * Refuse a parent that is this prototype or descends from it.
     *
     * @param prototype $prototype the prototype being given a parent.
     * @param prototype $parent the proposed parent.
     * @throws \moodle_exception if the parent would form a cycle.
     */
    protected function reject_cycle(prototype $prototype, prototype $parent): void {
        $id = $parent->id;
        $visited = [];
        while ($id !== null) {
            if ($id === $prototype->id) {
                throw new \moodle_exception('cyclicparent', 'local_coderunner_manager', '', (object) [
                    'name' => $prototype->name,
                    'parent' => $parent->name,
                ]);
            }
            if (isset($visited[$id])) {
                return;  // Pre-existing bad data, reported by chain_for() rather than here.
            }
            $visited[$id] = true;
            $id = $this->get($id)?->parentid();
        }
    }

    /**
     * Insert a version row and point its prototype at it.
     *
     * @param int $prototypeid the prototype.
     * @param int $version the version number.
     * @param int|null $parentid the parent prototype, or null for a root.
     * @param override_set $overrides the field values this version sets.
     * @param string $summary one sentence for the picker.
     * @param string $documentation documentation for question authors.
     * @param string $commitmessage an optional note describing the change.
     * @param int $now the time to record.
     * @return int the new version row's id.
     */
    protected function insert_version(
        int $prototypeid,
        int $version,
        ?int $parentid,
        override_set $overrides,
        string $summary,
        string $documentation,
        string $commitmessage,
        int $now
    ): int {
        global $DB, $USER;

        $versionid = $DB->insert_record(self::VERSIONS, (object) [
            'prototypeid' => $prototypeid,
            'version' => $version,
            'parentid' => $parentid,
            'overrides' => json_encode($overrides->to_array()),
            'summary' => $summary,
            'documentation' => $documentation,
            'commitmessage' => $commitmessage,
            'timecreated' => $now,
            'usermodified' => (int) $USER->id,
        ]);
        $DB->set_field(self::PROTOTYPES, 'currentversionid', $versionid, ['id' => $prototypeid]);
        return $versionid;
    }

    /**
     * Build prototypes from their rows, loading each one's current version in a
     * single further query rather than one per prototype.
     *
     * @param array $records rows from the prototypes table.
     * @return prototype[] prototypes, keyed by id, in the order given.
     */
    protected function prototypes_from_records(array $records): array {
        global $DB;

        if (!$records) {
            return [];
        }
        $versionids = array_filter(array_column($records, 'currentversionid'));
        $versions = $versionids ? $DB->get_records_list(self::VERSIONS, 'id', $versionids) : [];

        $prototypes = [];
        foreach ($records as $record) {
            $version = $versions[$record->currentversionid] ?? null;
            $prototypes[(int) $record->id] = $this->prototype_from_record($record, $version, false);
        }
        return $prototypes;
    }

    /**
     * Build a prototype from its row.
     *
     * @param \stdClass $record a row from the prototypes table.
     * @param \stdClass|null $versionrecord its current version's row, when already loaded.
     * @param bool $loadversion true to fetch the current version when not supplied.
     * @return prototype the prototype.
     */
    protected function prototype_from_record(
        \stdClass $record,
        ?\stdClass $versionrecord = null,
        bool $loadversion = true
    ): prototype {
        global $DB;

        if ($versionrecord === null && $loadversion && $record->currentversionid) {
            $versionrecord = $DB->get_record(self::VERSIONS, ['id' => $record->currentversionid]) ?: null;
        }

        return new prototype(
            id: (int) $record->id,
            name: $record->name,
            courseid: (int) $record->courseid,
            uuid: $record->uuid,
            builtin: (bool) $record->builtin,
            issitedefaults: (bool) ($record->issitedefaults ?? false),
            currentversion: $versionrecord ? $this->version_from_record($versionrecord) : null,
            timecreated: (int) $record->timecreated,
            timemodified: (int) $record->timemodified,
            usercreated: (int) $record->usercreated,
            usermodified: (int) $record->usermodified,
        );
    }

    /**
     * Build a version from its row.
     *
     * @param \stdClass $record a row from the versions table.
     * @return prototype_version the version.
     */
    protected function version_from_record(\stdClass $record): prototype_version {
        $values = json_decode($record->overrides ?? '{}', true);

        return new prototype_version(
            id: (int) $record->id,
            prototypeid: (int) $record->prototypeid,
            version: (int) $record->version,
            parentid: $record->parentid === null ? null : (int) $record->parentid,
            overrides: new override_set(is_array($values) ? $values : []),
            summary: (string) ($record->summary ?? ''),
            documentation: (string) ($record->documentation ?? ''),
            commitmessage: (string) ($record->commitmessage ?? ''),
            timecreated: (int) $record->timecreated,
            usermodified: (int) $record->usermodified,
        );
    }
}
