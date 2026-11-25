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
use qtype_coderunner\v6\question_cache;

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
     * @var string The backup_ids_temp item name a prototype is recorded under.
     *
     * Shared by the manager's backup and restore plugins and by the question
     * type's restorer, which needs the same mapping to recode an attempt's
     * recorded chain. `restore_plugin::set_mapping()` does not namespace the
     * item name and `backup_ids_temp` is keyed by the restore id, which every
     * plugin in one restore shares, so the lookup works across plugins --
     * provided both spell it the same, which is why it is a constant here
     * rather than a literal in each of the three.
     */
    const BACKUP_ITEM = 'local_coderunner_manager_prototype';

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
     * The one prototype a restore should reuse for a given uuid, if any.
     *
     * A uuid is deliberately not unique on a site: a restore that cannot reuse
     * the original creates a copy carrying the same identifier, and a site that
     * has done that before holds several. So "find it by uuid" needs a
     * tie-break, and the one used here is scope, because scope is what decides
     * which prototype a question can see: the asking course's own first, then
     * the site's, then the oldest of whatever is left so that two restores of
     * the same backup make the same choice.
     *
     * @param string $uuid the uuid to match.
     * @param int $courseid the course the restore is going into.
     * @return prototype|null the prototype to reuse, or null if the site has
     *      none with that uuid.
     */
    public function find_one_by_uuid(string $uuid, int $courseid): ?prototype {
        if ($uuid === '') {
            return null;
        }

        $matches = $this->find_by_uuid($uuid);
        if (!$matches) {
            return null;
        }

        foreach ([$courseid, SITEID] as $scope) {
            foreach ($matches as $candidate) {
                if ((int) $candidate->courseid === (int) $scope) {
                    return $candidate;
                }
            }
        }

        // Keyed by id, so the first is the lowest.
        return reset($matches);
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
        $split = $this->save_version_inside_transaction(
            $this->get_or_fail($builtin->id),
            $version ? $version->overrides : new \qtype_coderunner\v6\override_set([], $builtin->name),
            $stock->id,
            $version?->summary ?? '',
            $version?->documentation ?? '',
            get_string('splitfrombuiltin', 'local_coderunner_manager', $stock->name)
        );

        $transaction->allow_commit();

        // The split re-parents the prototype, which changes what every question
        // below it resolves to. Purged here rather than inside
        // save_new_version(), so that it happens after this method's own
        // commit and not in the middle of it.
        $this->notify_dependent_questions($builtin->id);

        return $split;
    }

    /**
     * A free name for the stock copy of a built-in.
     *
     * @param string $name the built-in's name.
     * @param int $courseid the scope.
     * @return string a name no prototype in that scope is using.
     */
    /**
     * A built-in of this canonical name, whatever uuid it happens to carry.
     *
     * The installer identifies a built-in by a uuid derived from its name
     * (decision 23), which is only useful if the stored uuid was derived the
     * same way. Built-ins installed before that scheme carry a random version
     * 4 uuid instead, so the installer cannot find them and treats each as a
     * new prototype whose name is taken — leaving `install_builtins()` inert,
     * silently, for every future release. This is the way back: find it by
     * name, in either the plain or the split-off stock form, and only if it is
     * still flagged built-in, so that the half of a split which holds the name
     * but is no longer stock is never mistaken for it.
     *
     * @param string $name the built-in's canonical name.
     * @param int $courseid the scope, which for a built-in is the site.
     * @return prototype|null the built-in, or null if there is none.
     */
    public function find_builtin_by_name(string $name, int $courseid): ?prototype {
        global $DB;

        $records = $DB->get_records_select(
            self::PROTOTYPES,
            'builtin = 1 AND courseid = :courseid AND (name = :name OR ' . $DB->sql_like('name', ':stock') . ')',
            [
                'courseid' => $courseid,
                'name' => $name,
                'stock' => $DB->sql_like_escape(get_string('stockname', 'local_coderunner_manager', $name)) . '%',
            ],
            'name',
            '*',
            0,
            1
        );

        $found = $this->prototypes_from_records($records);
        return $found ? reset($found) : null;
    }

    /**
     * Give a prototype the uuid it should have had.
     *
     * Used only to repair a built-in whose uuid predates the derived scheme.
     * The uuid is identity rather than content, so this is a field update and
     * not a new version: nothing about what the prototype *says* has changed.
     *
     * @param prototype $prototype the prototype to repair.
     * @param string $uuid the uuid it should carry.
     */
    public function adopt_uuid(prototype $prototype, string $uuid): void {
        global $DB;

        $DB->set_field(self::PROTOTYPES, 'uuid', $uuid, ['id' => $prototype->id]);
    }

    protected function stock_name(string $name, int $courseid): string {
        return $this->free_name(get_string('stockname', 'local_coderunner_manager', $name), $courseid);
    }

    /**
     * The given name, or the nearest one to it that is free in this scope.
     *
     * Two callers need a name that {@see name_clash()} will accept rather than
     * a refusal: splitting a built-in, which invents the stock copy's name
     * itself, and restoring a course, where the name comes from another site
     * and may well collide with a site prototype here -- `python3` being the
     * likeliest collision of all. Failing a restore that is otherwise fine is
     * worse than landing the prototype under a name an author can change, and
     * the uuid still records what it is.
     *
     * @param string $name the name wanted.
     * @param int $courseid the scope it has to be free in.
     * @param int $excludeid a prototype to ignore, when checking a rename.
     * @return string the name, suffixed if it had to be.
     */
    public function free_name(string $name, int $courseid, int $excludeid = 0): string {
        $candidate = $name;
        $suffix = 1;
        while ($this->name_clash($candidate, $courseid, $excludeid) !== null) {
            $suffix++;
            $candidate = $name . " ($suffix)";
        }
        return $candidate;
    }

    /**
     * How many questions depend on a prototype, directly or through its
     * descendants, counted per course.
     *
     * Counted in SQL rather than by loading the questions: a widely used
     * prototype has thousands of dependants — python3_scratchpad has 2,314 on
     * the development site — and this is wanted on a page that must open
     * quickly whatever the answer turns out to be.
     *
     * The grouping is by question bank *context*, which the caller maps to a
     * course. There are only as many contexts as there are banks, however many
     * questions they hold, so the mapping is cheap in PHP and avoids a context
     * walk in SQL that would differ between Moodle versions.
     *
     * Questions are counted as an author counts them, one per question bank
     * entry rather than one per stored version.
     *
     * @param int $prototypeid the prototype asked about.
     * @return array list of ['courseid' => ?int, 'contextid' => int,
     *      'questions' => int], busiest first. A null course id means a bank
     *      outside any course.
     */
    public function dependants_by_course(int $prototypeid): array {
        global $DB;

        $ids = array_merge([$prototypeid], $this->descendant_ids($prototypeid));
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'p');

        $rows = $DB->get_records_sql("
            SELECT qc.contextid, COUNT(DISTINCT qbe.id) AS questions
              FROM {question_coderunner_options} o
              JOIN {question} q ON q.id = o.questionid
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE o.prototypeid $insql
          GROUP BY qc.contextid", $params);

        // Several banks can belong to one course, so the per-context counts are
        // added up per course.
        $bycourse = [];
        foreach ($rows as $contextid => $row) {
            $context = \context::instance_by_id((int) $contextid, IGNORE_MISSING);
            $coursecontext = $context ? $context->get_course_context(false) : false;
            $courseid = $coursecontext ? (int) $coursecontext->instanceid : 0;

            $bycourse[$courseid] ??= ['courseid' => $courseid ?: null, 'questions' => 0];
            $bycourse[$courseid]['questions'] += (int) $row->questions;
        }

        uasort($bycourse, fn($a, $b) => $b['questions'] <=> $a['questions']);
        return array_values($bycourse);
    }

    /**
     * The questions of one course that depend on a prototype, named.
     *
     * The drill-down behind {@see dependants_by_course()}: the same questions,
     * listed rather than counted, for the one course the viewer asked about.
     * Counted the same way, one per question bank entry, with the highest
     * version that uses the prototype standing for the question — a question
     * whose older version used something else is one question here and one
     * question there, which is how an author sees it.
     *
     * The course restriction is made in SQL, over the context ids beneath the
     * course, because a widely used prototype has thousands of dependants and
     * only a few of them belong to the course being asked about.
     *
     * @param int $prototypeid the prototype asked about.
     * @param int $courseid the course to look in. Zero means the banks that
     *      belong to no course, which is the system context.
     * @return array list of objects with questionid, name, version, status,
     *      entryid, categoryid, categoryname, contextid and prototypeid, by
     *      name. The category name is the category's own, not its path: two
     *      categories of one bank may share a name, which is how the question
     *      bank's own filter shows them too.
     */
    public function dependant_questions(int $prototypeid, int $courseid): array {
        global $DB;

        $contextids = $this->bank_contextids_for_course($courseid);
        if (!$contextids) {
            return [];
        }

        $ids = array_merge([$prototypeid], $this->descendant_ids($prototypeid));
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'p');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

        $rows = $DB->get_records_sql("
            SELECT qv.id AS versionid, q.id AS questionid, q.name, qv.version, qv.status,
                   qbe.id AS entryid, qc.id AS categoryid, qc.name AS categoryname,
                   qc.contextid, o.prototypeid
              FROM {question_coderunner_options} o
              JOIN {question} q ON q.id = o.questionid
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE o.prototypeid $insql
                   AND qc.contextid $ctxsql
          ORDER BY qv.version", array_merge($params, $ctxparams));

        // One row per question, the latest version of it that uses the
        // prototype. The query is ordered by version, so the last row wins.
        $questions = [];
        foreach ($rows as $row) {
            $questions[(int) $row->entryid] = $row;
        }

        \core_collator::asort_objects_by_property($questions, 'name');
        return array_values($questions);
    }

    /**
     * The contexts a course's question banks can live in.
     *
     * A bank is a module instance of the course, so the course's own context
     * and everything beneath it is the set a question category may sit in. The
     * course context itself is included because categories made before question
     * banks became modules are still there.
     *
     * @param int $courseid the course, or zero for the banks outside any course.
     * @return array context ids.
     */
    protected function bank_contextids_for_course(int $courseid): array {
        global $DB;

        if (!$courseid) {
            return [(int) \context_system::instance()->id];
        }

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return [];
        }

        $below = $DB->get_fieldset_select(
            'context',
            'id',
            $DB->sql_like('path', ':path'),
            ['path' => $coursecontext->path . '/%']
        );
        return array_merge([(int) $coursecontext->id], array_map('intval', $below));
    }

    /**
     * The saved but unpublished version of a prototype, if it has one.
     *
     * @param \stdClass $record the prototype's row.
     * @return prototype_version|null the draft, or null.
     */
    protected function draft_version_of(\stdClass $record): ?prototype_version {
        global $DB;

        if (empty($record->draftversionid)) {
            return null;
        }
        $draft = $DB->get_record(self::VERSIONS, ['id' => $record->draftversionid]);
        return $draft ? $this->version_from_record($draft) : null;
    }

    /**
     * Save an edit without publishing it.
     *
     * A draft is an ordinary version that nothing resolves against yet: the
     * prototype keeps pointing at its published version, so questions go on
     * behaving as they did while their author works. Saving a second draft
     * replaces the first, since an author has one piece of work in progress
     * and a trail of abandoned drafts would be noise in the history.
     *
     * @param prototype $prototype the prototype.
     * @param override_set $overrides what the draft sets.
     * @param int|null $parentid the parent it would inherit from.
     * @param string $summary the one-line summary.
     * @param string $documentation the documentation.
     * @param string $commitmessage what the change is.
     * @param \stored_file[]|null $files the support files, or null to keep them.
     * @param int $documentationformat the documentation's format.
     * @return prototype the prototype, with its draft.
     */
    public function save_draft(
        prototype $prototype,
        override_set $overrides,
        ?int $parentid = null,
        string $summary = '',
        string $documentation = '',
        string $commitmessage = '',
        ?array $files = null,
        int $documentationformat = FORMAT_HTML
    ): prototype {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $published = $prototype->currentversion;
        $this->discard_draft_inside_transaction($prototype);

        // Deliberately not save_new_version(): the published version is put
        // back below, so nothing a question resolves has changed and there is
        // nothing to uncache. Purging here would cost a widely used prototype
        // a whole-cache purge on every keystroke-sized draft save.
        $saved = $this->save_version_inside_transaction(
            $this->get_or_fail($prototype->id),
            $overrides,
            $parentid,
            $summary,
            $documentation,
            $commitmessage,
            $files,
            $documentationformat
        );

        // save_new_version() published it, which a draft must not be. The
        // prototype is pointed back at what it was resolving against, and the
        // new version is recorded as the draft instead.
        $DB->update_record(self::PROTOTYPES, (object) [
            'id' => $prototype->id,
            'currentversionid' => $published?->id,
            'draftversionid' => $saved->currentversion->id,
        ]);

        $transaction->allow_commit();

        return $this->get_or_fail($prototype->id);
    }

    /**
     * Make a draft the version everything resolves against.
     *
     * @param prototype $prototype the prototype.
     * @return prototype the prototype, with the draft published.
     * @throws \coding_exception if there is no draft to publish.
     */
    public function publish_draft(prototype $prototype): prototype {
        global $DB;

        if (!$prototype->has_draft()) {
            throw new \coding_exception("Prototype '{$prototype->name}' has no draft to publish.");
        }

        $DB->update_record(self::PROTOTYPES, (object) [
            'id' => $prototype->id,
            'currentversionid' => $prototype->draftversion->id,
            'draftversionid' => null,
            'timemodified' => time(),
        ]);

        // This is the moment the draft becomes what questions resolve against,
        // and so the moment their cached resolutions go stale. The draft saves
        // that led up to it deliberately did not purge; this one must.
        $this->notify_dependent_questions($prototype->id);

        return $this->get_or_fail($prototype->id);
    }

    /**
     * Throw a draft away, leaving the published version in force.
     *
     * @param prototype $prototype the prototype.
     * @return prototype the prototype, with no draft.
     */
    public function discard_draft(prototype $prototype): prototype {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->discard_draft_inside_transaction($prototype);
        $transaction->allow_commit();

        return $this->get_or_fail($prototype->id);
    }

    /**
     * Remove a prototype's draft, assuming a transaction is already open.
     *
     * The version row goes with it, along with its files: an abandoned draft
     * is not history, and leaving it would make the version numbers jump for
     * no reason an author could explain.
     *
     * @param prototype $prototype the prototype.
     */
    protected function discard_draft_inside_transaction(prototype $prototype): void {
        global $DB;

        if (!$prototype->has_draft()) {
            return;
        }

        $draftid = $prototype->draftversion->id;
        $DB->set_field(self::PROTOTYPES, 'draftversionid', null, ['id' => $prototype->id]);
        get_file_storage()->delete_area_files(
            $this->file_contextid($prototype),
            self::COMPONENT,
            self::FILEAREA,
            $draftid
        );
        $DB->delete_records(self::VERSIONS, ['id' => $draftid]);
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
        array $files = [],
        int $documentationformat = FORMAT_HTML
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
            $documentationformat,
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
     * Write a prototype exactly as a backup describes it, history and all.
     *
     * A restore reproduces data; it does not replay the API. So this writes the
     * rows rather than calling {@see create()} and {@see save_new_version()}
     * once per version, for three reasons. The version *numbers* are preserved
     * as backed up, where replaying would renumber from one and a history whose
     * numbers moved is not the history the author wrote. Each version's
     * `overrides` is kept as the exact stored JSON, where re-encoding an
     * override_set could normalise it and so change what the question resolves
     * to. And the whole history lands in one transaction, where replaying would
     * leave a partly built prototype visible if it failed halfway.
     *
     * It is still in the repository rather than in the restore plugin, because
     * the invariants live here: the name is put through {@see free_name()}, and
     * every write to the two tables goes through this class.
     *
     * **The built-in and Site defaults flags are forced off** rather than taken
     * from the backup. The backup never writes either -- `collect_with_ancestors()`
     * stops at both, because every site installs its own and the restore
     * re-links by uuid -- so anything arriving here is neither, and a
     * hand-edited backup must not be able to create a second Site defaults or
     * a prototype the installer will overwrite.
     *
     * **A version's parent must already be resolved to a local id.** Resolving
     * it needs the restore's own mappings and a uuid lookup, which are the
     * restore plugin's business, not this class's.
     *
     * @param \stdClass $record the prototype's identity: name, uuid, and the
     *      created and modified stamps and users.
     * @param array $versions the version rows, oldest first. Each needs
     *      `version` and `overrides`, and may carry `parentid` (a local id or
     *      null), `summary`, `documentation`, `documentationformat`,
     *      `commitmessage`, `timecreated` and `usermodified`.
     * @param int $courseid the course the prototype is restored into.
     * @param int|null $currentversion the version *number* that is current.
     *      Null, or a number no version carries, falls back to the last.
     * @param int|null $draftversion the version number that is the unpublished
     *      draft, if the backup had one.
     * @return prototype the restored prototype, at its current version.
     * @throws \coding_exception if no versions are given.
     */
    public function restore_prototype(
        \stdClass $record,
        array $versions,
        int $courseid,
        ?int $currentversion = null,
        ?int $draftversion = null
    ): prototype {
        global $DB, $USER;

        if (!$versions) {
            throw new \coding_exception("Prototype '{$record->name}' was backed up with no versions.");
        }

        $transaction = $DB->start_delegated_transaction();

        $now = time();
        $id = $DB->insert_record(self::PROTOTYPES, (object) [
            'name' => $this->free_name((string) $record->name, $courseid),
            'courseid' => $courseid,
            'uuid' => (string) ($record->uuid ?: \core\uuid::generate()),
            'builtin' => 0,
            'issitedefaults' => 0,
            'currentversionid' => null,
            'draftversionid' => null,
            'timecreated' => (int) ($record->timecreated ?? $now),
            'timemodified' => (int) ($record->timemodified ?? $now),
            'usercreated' => (int) ($record->usercreated ?? $USER->id),
            'usermodified' => (int) ($record->usermodified ?? $USER->id),
        ]);

        // Keyed by version number, which is what the current and draft are
        // named by: the backup's version *ids* are another site's and are not
        // carried past this point.
        $newids = [];
        foreach ($versions as $version) {
            $version = (object) $version;
            $number = (int) $version->version;
            $newids[$number] = $DB->insert_record(self::VERSIONS, (object) [
                'prototypeid' => $id,
                'version' => $number,
                'parentid' => isset($version->parentid) && $version->parentid !== null
                    ? (int) $version->parentid
                    : null,
                'overrides' => (string) ($version->overrides ?? '{}'),
                'summary' => (string) ($version->summary ?? ''),
                'documentation' => (string) ($version->documentation ?? ''),
                'documentationformat' => (int) ($version->documentationformat ?? FORMAT_HTML),
                'commitmessage' => (string) ($version->commitmessage ?? ''),
                'timecreated' => (int) ($version->timecreated ?? $now),
                'usermodified' => (int) ($version->usermodified ?? $USER->id),
            ]);
        }

        // A prototype with no current version resolves to nothing, so the
        // fallback is the newest rather than null: a backup that lost track of
        // which version was current is still better restored at its last one.
        $currentid = $newids[$currentversion] ?? $newids[max(array_keys($newids))];
        $DB->update_record(self::PROTOTYPES, (object) [
            'id' => $id,
            'currentversionid' => $currentid,
            'draftversionid' => $draftversion !== null ? ($newids[$draftversion] ?? null) : null,
        ]);

        $transaction->allow_commit();

        return $this->get_or_fail($id);
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
        ?array $files = null,
        int $documentationformat = FORMAT_HTML
    ): prototype {
        $saved = $this->save_version_inside_transaction(
            $prototype,
            $overrides,
            $parentid,
            $summary,
            $documentation,
            $commitmessage,
            $files,
            $documentationformat
        );

        // The new version is now what everything resolves against, so every
        // question below is holding a resolution of the old one. Purged after
        // the commit, so that a save that rolls back does not purge.
        $this->notify_dependent_questions($prototype->id);

        return $saved;
    }

    /**
     * Write a new version, leaving its consequences to the caller.
     *
     * The body of {@see save_new_version()}, separated from the cache purge
     * that normally follows it, for the two callers that must not purge: a
     * draft save, which puts the published version back and so changes nothing
     * any question resolves, and {@see split_builtin()}, which has a
     * transaction of its own and purges once after committing it.
     *
     * @param prototype $prototype the prototype to add a version to.
     * @param override_set $overrides the field values the new version sets.
     * @param int|null $parentid the prototype it derives from, or null for a root.
     * @param string $summary one sentence for the picker.
     * @param string $documentation documentation for question authors.
     * @param string $commitmessage an optional note describing the change.
     * @param \stored_file[]|null $files the support files, or null to carry the
     *      previous version's forward.
     * @param int $documentationformat the documentation's format.
     * @return prototype the prototype, with its new current version.
     * @throws \moodle_exception if the parent would form a cycle or does not exist.
     */
    protected function save_version_inside_transaction(
        prototype $prototype,
        override_set $overrides,
        ?int $parentid = null,
        string $summary = '',
        string $documentation = '',
        string $commitmessage = '',
        ?array $files = null,
        int $documentationformat = FORMAT_HTML
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
            $documentationformat,
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
     * Clear the cached question data of every question that resolves through a
     * prototype, so that the next load sees the change.
     *
     * A question's prototype chain is resolved inside
     * qtype_coderunner::get_question_options(), and core holds that method's
     * result in the `core/questiondata` application cache (decision 15, which
     * defers a resolved-settings cache precisely because this one already
     * covers it). Every inherited field, every layer of template and UI
     * parameters, and the support-file areas -- which are recorded as version
     * ids -- are therefore frozen into that entry at the version the prototype
     * had when it was written. Saving a new version changes none of them until
     * the entry goes.
     *
     * Without this, a prototype fix simply does not reach the questions using
     * it: the test server showed exactly that in November 2026, when a support
     * file broken by a Python upgrade was corrected in the prototype and every
     * dependent question went on failing until it was opened and saved again,
     * one at a time. Re-saving a question is what purged its entry, which is
     * why editing with no change appeared to fix it.
     *
     * Descendants are included because a question resolves through the whole
     * chain. A change here reaches every question below, not only those naming
     * this prototype directly.
     *
     * How the questions are uncached, and when there are too many of them to
     * uncache one at a time, is {@see question_cache}'s business.
     *
     * Called by every operation that changes what a question resolves to.
     * {@see delete()} is not one of them: it refuses while any question or
     * child prototype depends on the prototype, so by the time it deletes
     * there is nothing left to notify.
     *
     * @param int $prototypeid the prototype that changed.
     */
    public function notify_dependent_questions(int $prototypeid): void {
        global $DB;

        $ids = array_merge([$prototypeid], $this->descendant_ids($prototypeid));
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'p');

        question_cache::uncache_matching("prototypeid $insql", $params);
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

        // No field value changes, but the name is each layer's label in the
        // resolved chain, which is cached with the question and is what the
        // authoring form names as the source of an inherited value. Without
        // this, a renamed prototype goes on being credited under its old name.
        $this->notify_dependent_questions($prototype->id);

        return $this->get_or_fail($prototype->id);
    }

    /**
     * Why this prototype cannot be deleted, or null if it can.
     *
     * The one rule, so that the button the library page offers and the refusal
     * {@see delete()} would make cannot drift apart: a disabled button whose
     * tooltip disagrees with the error is worse than no button at all.
     *
     * Counts may be passed in by a caller that has them already, so that a
     * page listing scores of prototypes can decide every row's button without
     * two more queries per row. The rule is still only written once; only
     * where the two numbers come from differs. {@see delete()} passes nothing,
     * because the decision it makes has to be made on the current state.
     *
     * @param prototype $prototype the prototype in question.
     * @param array|null $counts ['questions' => int, 'children' => int] if the
     *      caller has them, as {@see usage_counts()} returns them.
     * @return string|null the reason, ready to show, or null if it may go.
     */
    public function undeletable_reason(prototype $prototype, ?array $counts = null): ?string {
        global $DB;

        if ($prototype->issitedefaults) {
            return get_string('cannotdeletesitedefaults', 'local_coderunner_manager');
        }

        $children = $counts !== null
            ? (int) ($counts['children'] ?? 0)
            : count($this->children_of($prototype->id));
        if ($children) {
            return get_string('prototypeinuse', 'local_coderunner_manager', $prototype->name);
        }

        // A question holds its prototype's id, so deleting one a question uses
        // leaves that question unresolvable -- the broken state the additive
        // design exists to avoid, and the design says twice that the manager
        // refuses it. Counted the way usage_counts() counts, by question
        // rather than by version, so the refusal agrees with the number the
        // library page shows beside the prototype. Descendants need no
        // separate check: a prototype with children is refused above, so by
        // here it has none for a question to reach it through.
        $dependants = $counts !== null ? (int) ($counts['questions'] ?? 0) : $DB->count_records_sql("
            SELECT COUNT(DISTINCT qbe.id)
              FROM {question_coderunner_options} o
              JOIN {question} q ON q.id = o.questionid
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE o.prototypeid = :id", ['id' => $prototype->id]);
        if ($dependants) {
            return get_string('prototypehasquestions', 'local_coderunner_manager', (object) [
                'name' => $prototype->name,
                'count' => $dependants,
            ]);
        }

        return null;
    }

    /**
     * Delete every prototype a course owns, with its versions and its files.
     *
     * For course deletion, which is the one case that overrides the guards
     * `delete()` applies: a prototype other courses still use goes anyway,
     * because the course it belonged to is going. Its dependants then report
     * themselves broken, as anything borrowed from a deleted course does.
     *
     * Each one's dependent questions are told first, because their resolved
     * settings are cached and the cache outlives the row.
     *
     * Called from the `course_deleted` event, which fires once the deletion
     * has happened. By then Moodle has deleted the course context and every
     * file in it, so the support files are already gone and the context cannot
     * be looked up -- hence `IGNORE_MISSING` below, and no complaint when
     * there is nothing to delete. The files are only deleted here when the
     * course is still standing, which is the case for any other caller.
     *
     * @param int $courseid the course being deleted.
     * @return int how many prototypes were deleted.
     */
    public function delete_for_course(int $courseid): int {
        global $DB;

        $prototypes = $DB->get_records(self::PROTOTYPES, ['courseid' => $courseid], 'id');
        if (!$prototypes) {
            return 0;
        }

        $fs = get_file_storage();
        $transaction = $DB->start_delegated_transaction();

        foreach ($prototypes as $record) {
            $prototype = $this->prototype_from_record($record);
            $this->notify_dependent_questions((int) $prototype->id);

            $context = \context_course::instance((int) $prototype->courseid, IGNORE_MISSING);
            if ($context) {
                $versionids = $DB->get_fieldset_select(
                    self::VERSIONS,
                    'id',
                    'prototypeid = :id',
                    ['id' => $prototype->id]
                );
                foreach ($versionids as $versionid) {
                    $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $versionid);
                }
            }
            $DB->delete_records(self::VERSIONS, ['prototypeid' => $prototype->id]);
            $DB->delete_records(self::PROTOTYPES, ['id' => $prototype->id]);
        }

        $transaction->allow_commit();

        return count($prototypes);
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

        $reason = $this->undeletable_reason($prototype);
        if ($reason !== null) {
            throw new \moodle_exception('deleteundeletable', 'local_coderunner_manager', '', $reason);
        }

        $transaction = $DB->start_delegated_transaction();

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
     * Grouped queries rather than one per prototype, because the library view
     * asks about every prototype on the page at once and a course can have
     * scores of them.
     *
     * Questions are counted as an author counts them — one per question bank
     * entry, not one per stored version — since a question edited twenty times
     * is still one question depending on the prototype. Only direct dependants
     * are counted; the usage report walks the whole subtree.
     *
     * @param int[] $prototypeids the prototypes to count for.
     * @param int $courseid a course to count children in as well, or zero for
     *      the site-wide count alone. The key is absent rather than zero when
     *      no course is given, because zero would read as "none here" when the
     *      truth is that nobody asked.
     * @return array prototype id => ['questions' => int, 'children' => int],
     *      plus 'childrenincourse' => int when $courseid is given.
     */
    public function usage_counts(array $prototypeids, int $courseid = 0): array {
        global $DB;

        $counts = [];
        foreach ($prototypeids as $id) {
            $counts[(int) $id] = ['questions' => 0, 'children' => 0]
                + ($courseid ? ['childrenincourse' => 0] : []);
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

        // The same count again, narrowed to one course. The library page shows
        // both because the site-wide figure alone was read as this course's: a
        // prototype with children in other courses looked as though it had
        // them here, which is what the test server reported on 16 November.
        // A second aggregate rather than a second method, so the page still
        // asks once.
        if ($courseid) {
            $incourse = $DB->get_records_sql("
                SELECT v.parentid AS id, COUNT(*) AS n
                  FROM {" . self::PROTOTYPES . "} p
                  JOIN {" . self::VERSIONS . "} v ON v.id = p.currentversionid
                 WHERE v.parentid $insql AND p.courseid = :courseid
              GROUP BY v.parentid", $params + ['courseid' => $courseid]);
            foreach ($incourse as $row) {
                $counts[(int) $row->id]['childrenincourse'] = (int) $row->n;
            }
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
    public function file_contextid(prototype $prototype): int {
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
        int $documentationformat,
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
            'documentationformat' => $documentationformat,
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
            draftversion: $this->draft_version_of($record),
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
            documentationformat: (int) ($record->documentationformat ?? FORMAT_HTML),
            commitmessage: (string) ($record->commitmessage ?? ''),
            timecreated: (int) $record->timecreated,
            usermodified: (int) $record->usermodified,
        );
    }
}
