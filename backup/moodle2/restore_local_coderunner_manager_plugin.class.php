<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Restores the prototypes a course backup carries.
 *
 * @package    local_coderunner_manager
 * @copyright  2026, The University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_coderunner_manager\prototype;
use local_coderunner_manager\prototype_repository;
use qtype_coderunner\v6\question_cache;

/**
 * Prototype data read back from a course backup.
 *
 * **An existing prototype is reused, never duplicated.** A prototype arriving
 * in a backup is matched by uuid against what the site already holds, anywhere
 * on it, and only created when there is no match. That is what keeps a restore
 * consistent with the question side: the question type's
 * `restore_qtype_coderunner_plugin::resolve_prototype()` runs in the root task,
 * before this class has created anything, and prefers the backed-up id when the
 * uuid agrees -- so on a same-site restore the questions are already pointing at
 * the originals by the time this runs. Creating copies here would leave them
 * orphaned, pointed at by nothing.
 *
 * **Two connection points, because one cannot do the job.** The course point
 * reads the course's own library. The question point is needed as well: the
 * plan writes `course.xml` before the activities annotate the question bank
 * entries they use, so the course hook cannot know which questions are in the
 * backup, and a prototype that only a borrowed question needs has to come
 * through the question element. The question point must be *declared* whatever
 * the backup contains, because `restore_structure_step::launch_after_restore_methods()`
 * iterates the declared path elements: with no path here, `after_restore_question()`
 * -- the linking stage -- would silently never run.
 *
 * **Restored questions are linked in `after_restore_question()`, not earlier.**
 * The root task restores questions before the course task restores the course's
 * prototypes, so a question built on its own course's prototype has nothing to
 * resolve against at the moment it is written and arrives with a null prototype
 * id. By `after_restore_question()`, which the final task schedules last, every
 * prototype exists. See section 1.3 of the milestones document.
 *
 * @copyright  2026, The University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_coderunner_manager_plugin extends restore_local_plugin {
    /**
     * @var string The item name a *version* is mapped under.
     *
     * Separate from the prototype's, because the support-file area's itemid is
     * the version id: files are restored by mapping the thing they hang off.
     */
    const VERSION_ITEM = 'local_coderunner_manager_version';

    /** @var prototype_repository|null The repository, made once per instance. */
    protected ?prototype_repository $repo = null;

    /**
     * The course's own prototype library.
     *
     * Grouped, so that a prototype arrives with its whole version history in
     * one call: the current version and the draft are named by id, and nothing
     * can decide which local row they became until every version is written.
     *
     * @return restore_path_element[] the paths handled here.
     */
    protected function define_course_plugin_structure() {
        return [
            new restore_path_element(
                'lcm_course_prototype',
                $this->get_pathfor('/prototypes/prototype'),
                true
            ),
            // Declared although nothing processes it, and that is not
            // redundant: the parser only produces a chunk for a path it has
            // been told about, and a grouped parent accumulates its children
            // from those chunks. Without this the prototype arrives with its
            // own fields and no `versions` at all. Core's one grouped example,
            // block_rss_client, declares its children the same way.
            // restore_plugin::define_plugin_structure() leaves this one's
            // processing object null, because by the time it is reached the
            // grouped parent above is already in the list.
            new restore_path_element(
                'lcm_course_version',
                $this->get_pathfor('/prototypes/prototype/versions/version')
            ),
        ];
    }

    /**
     * Prototypes carried by a question rather than by the course.
     *
     * Declared whether or not a backup holds any, because this is also what
     * makes `after_restore_question()` run. See the class comment.
     *
     * @return restore_path_element[] the paths handled here.
     */
    protected function define_question_plugin_structure() {
        return [
            new restore_path_element(
                'lcm_question_prototype',
                $this->get_pathfor('/prototypes/prototype'),
                true
            ),
            // As at the course point: the child path has to be declared for
            // the grouped parent to receive it.
            new restore_path_element(
                'lcm_question_version',
                $this->get_pathfor('/prototypes/prototype/versions/version')
            ),
        ];
    }

    /**
     * Handle a prototype met at the course connection point.
     *
     * @param array $data the prototype, with its versions nested.
     */
    public function process_lcm_course_prototype($data) {
        $this->restore_one_prototype($data);
    }

    /**
     * Handle a prototype met at the question connection point.
     *
     * @param array $data the prototype, with its versions nested.
     */
    public function process_lcm_question_prototype($data) {
        $this->restore_one_prototype($data);
    }

    /**
     * Bring the support files of the course's prototypes across.
     *
     * @return void
     */
    public function after_execute_course() {
        $this->add_related_files(
            prototype_repository::COMPONENT,
            prototype_repository::FILEAREA,
            self::VERSION_ITEM
        );
    }

    /**
     * Bring the support files of a question's prototypes across.
     *
     * A backup with data at both connection points reaches this and
     * {@see after_execute_course()}, over the one shared set of version
     * mappings. That is safe rather than a double restore:
     * `restore_dbops::send_files_to_pool()` creates each file only
     * `if (!$fs->file_exists(...))`, so the second pass finds them already
     * there and does nothing.
     *
     * @return void
     */
    public function after_execute_question() {
        $this->add_related_files(
            prototype_repository::COMPONENT,
            prototype_repository::FILEAREA,
            self::VERSION_ITEM
        );
    }

    /**
     * Point this restore's questions at the prototypes that have since arrived.
     *
     * Scheduled by the final task, so every task has run and every prototype in
     * the backup exists. What is left to fix is the questions that could not be
     * resolved when they were written, because their prototype came later in
     * the plan -- which is every question in a restored course built on that
     * course's own prototypes.
     *
     * **Only this restore's own prototypes are candidates**, and that
     * restriction is the safety property, not an optimisation. The question
     * type's resolver searches the whole of a course's and the site's scope,
     * which is right when a question is saved but wrong here: a cross-site
     * restore whose prototype name collides with an unrelated site prototype
     * -- `python3` being the likeliest collision of all -- would link every
     * restored question to a prototype that has nothing to do with it, and
     * grade them against someone else's template. Silently. Narrowing to what
     * this restore brought makes the worst case a question that reports itself
     * broken.
     *
     * The match within that set is still by name, because the name is what
     * failed to resolve earlier and `coderunnertype` still holds it
     * (decision 18); this is the same rule retried over a smaller set, not a
     * second rule. **A prototype whose name had to be suffixed is therefore
     * not linked**, because the name the question remembers is no longer the
     * name the prototype carries. That is the price of not guessing, and it is
     * confined to a restore that met a name clash.
     *
     * Only this restore's *questions* are touched too, found through the
     * `question_created` mappings, rather than sweeping the site: a real site
     * has hundreds of permanently broken question versions -- 405 on the
     * development site -- and a restore has no business deciding anything
     * about them.
     *
     * @return void
     */
    public function after_restore_question() {
        global $DB;

        $candidates = $this->prototypes_this_restore_brought();
        if (!$candidates) {
            return;
        }

        $pending = $DB->get_records_sql(
            'SELECT o.id, o.questionid, o.coderunnertype
               FROM {backup_ids_temp} bi
               JOIN {question_coderunner_options} o ON o.questionid = bi.newitemid
              WHERE bi.backupid = :restoreid
                AND bi.itemname = :itemname
                AND o.prototypetype = 0
                AND o.prototypeid IS NULL',
            ['restoreid' => $this->get_restoreid(), 'itemname' => 'question_created']
        );

        $linked = [];
        foreach ($pending as $row) {
            if (!isset($candidates[$row->coderunnertype])) {
                // Nothing this restore brought is called that. A pre-6.0
                // backup's own prototype *questions* become prototypes only
                // when the installer next runs, which the question type's
                // restorer has already queued; that sweep is the recovery
                // path. Leaving the id null is what makes the question report
                // itself broken in the meantime, rather than resolving to
                // something that is not its own.
                continue;
            }
            $DB->set_field(
                question_cache::OPTIONS,
                'prototypeid',
                $candidates[$row->coderunnertype],
                ['id' => $row->id]
            );
            $linked[] = (int) $row->questionid;
        }

        // The rows were written straight to the table, so nothing has told core
        // these questions changed, and their cached resolution still says
        // broken.
        question_cache::uncache($linked);
    }

    /**
     * The prototypes this restore created or reused, by name.
     *
     * Read from the mappings rather than kept in a property, because the
     * instance that created them is the course connection point's and the
     * instance asking is the question point's. `backup_ids_temp` is keyed by
     * the restore id, which both share.
     *
     * @return array prototype name => local id.
     */
    protected function prototypes_this_restore_brought(): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'backup_ids_temp',
            'newitemid',
            'backupid = :restoreid AND itemname = :itemname',
            ['restoreid' => $this->get_restoreid(), 'itemname' => prototype_repository::BACKUP_ITEM]
        );
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }

        $byname = [];
        foreach ($DB->get_records_list(prototype_repository::PROTOTYPES, 'id', $ids) as $prototype) {
            $byname[$prototype->name] = (int) $prototype->id;
        }
        return $byname;
    }

    /**
     * Reuse or create the prototype a backup element describes.
     *
     * @param array $data the prototype, with its versions nested.
     */
    protected function restore_one_prototype(array $data): void {
        $record = (object) $data;
        $oldid = (int) $record->id;

        // A prototype can reach us twice: written at both connection points, or
        // needed by two questions. The mapping is the record of having dealt
        // with it.
        if ($this->get_mappingid(prototype_repository::BACKUP_ITEM, $oldid, false) !== false) {
            return;
        }

        $courseid = (int) $this->task->get_courseid();
        $repo = $this->repo();

        $existing = $repo->find_one_by_uuid((string) ($record->uuid ?? ''), $courseid);
        if ($existing !== null) {
            // Reused, so nothing is written and no files are wanted: the
            // prototype already here keeps the ones it has.
            $this->set_mapping(prototype_repository::BACKUP_ITEM, $oldid, $existing->id);
            return;
        }

        $versions = $data['versions']['version'] ?? [];
        if (!$versions) {
            // A prototype with no version resolves to nothing and cannot be
            // written. Skipping it leaves the questions below it broken and
            // visibly so, which is better than inventing an empty version for
            // them to inherit from.
            return;
        }

        foreach ($versions as &$version) {
            $version['parentid'] = $this->resolve_parent($version, $courseid);
        }
        unset($version);

        $new = $repo->restore_prototype(
            $record,
            $versions,
            $courseid,
            $this->version_number_of($versions, $record->currentversionid ?? null),
            $this->version_number_of($versions, $record->draftversionid ?? null)
        );
        $this->set_mapping(prototype_repository::BACKUP_ITEM, $oldid, $new->id);

        // The support files hang off the version, so it is the versions that
        // are mapped with restorefiles set. Matched by version number, the one
        // identifier both sides agree on: the backup's version ids are the
        // source site's and mean nothing here.
        $history = $repo->history($new->id);
        foreach ($versions as $version) {
            $number = (int) $version['version'];
            if (isset($history[$number])) {
                $this->set_mapping(self::VERSION_ITEM, (int) $version['id'], $history[$number]->id, true);
                $this->write_inline_files($version, $new, $history[$number]->id, $repo);
                $this->claim_out_of_course_files($version, $new, $repo);
            }
        }
    }

    /**
     * Ask for a version's files when they were written from another context.
     *
     * `add_related_files()` looks for a file under the context the backup was
     * taken from, which is the backed-up course's, and maps it to the new
     * context through the restore's own context mappings. Neither step suits a
     * prototype from *another* course: its files are in `files.xml` under a
     * context nothing asks about, and that context has no mapping here, so
     * asking for it raises `unknown_context_mapping`. A prototype without its
     * `__pystylechecker.py` fails every question under it, so leaving them is
     * not an option.
     *
     * `restore_dbops::send_files_to_pool()` takes both halves explicitly: the
     * old context to look under, and a forced new context to put them in --
     * the escape hatch core added for questions, which "can end belonging to
     * non-standard context mappings". The new context is where the restored
     * prototype's files belong, which the repository decides.
     *
     * Only for a context that differs from the one the task will ask about;
     * the blanket calls in `after_execute_course()` and
     * `after_execute_question()` cover the rest, and are also what a backup
     * taken before the context was recorded still travels by.
     * `send_files_to_pool()` creates a file only when it is not already there,
     * so the overlap costs nothing.
     *
     * @param array $version the backed-up version row, already mapped.
     * @param prototype $new the prototype just created.
     * @param prototype_repository $repo the repository, for the file context.
     */
    protected function claim_out_of_course_files(
        array $version,
        prototype $new,
        prototype_repository $repo
    ): void {
        $filecontextid = (int) ($version['filecontextid'] ?? 0);
        if ($filecontextid === 0 || $filecontextid === (int) $this->task->get_old_contextid()) {
            return;
        }

        \restore_dbops::send_files_to_pool(
            $this->task->get_basepath(),
            $this->task->get_restoreid(),
            prototype_repository::COMPONENT,
            prototype_repository::FILEAREA,
            $filecontextid,
            $this->task->get_userid(),
            self::VERSION_ITEM,
            (int) $version['id'],
            $repo->file_contextid($new)
        );
    }

    /**
     * Write back the support files a version carried in its own XML.
     *
     * The backup carries them base64 in an `inlinefiles` element rather than
     * through Moodle's file annotation, because a prototype written at the
     * question attachment point is written by the final task, which has no
     * inforef, and `restore_load_included_files` loads a file only for a ref
     * it has read or for a component of core's own short list. Before that, a
     * quiz-only backup restored its prototypes without the support files their
     * questions need. {@see backup_prototype_version_element} has the whole
     * reasoning.
     *
     * The files land in the restored prototype's own context and under its own
     * new version id, which is what the repository would have written. Nothing
     * is overwritten: a prototype reused by uuid never reaches this method, and
     * one just created has no files yet.
     *
     * A backup made before this element existed carries nothing here and takes
     * the old route, which is what the `add_related_files()` calls in
     * `after_execute_course()` and `after_execute_question()` remain for.
     *
     * @param array $version the backed-up version row.
     * @param prototype $new the prototype just created.
     * @param int $versionid the new version's id, which owns the files.
     * @param prototype_repository $repo the repository, for the file context.
     */
    protected function write_inline_files(
        array $version,
        prototype $new,
        int $versionid,
        prototype_repository $repo
    ): void {
        $encoded = (string) ($version['inlinefiles'] ?? '');
        if ($encoded === '') {
            return;
        }

        $files = json_decode($encoded, true);
        if (!is_array($files)) {
            $this->log('A prototype version carried unreadable support files', backup::LOG_WARNING);
            return;
        }

        $fs = get_file_storage();
        $contextid = $repo->file_contextid($new);
        foreach ($files as $file) {
            $filename = (string) ($file['filename'] ?? '');
            if ($filename === '') {
                continue;
            }
            $filepath = (string) ($file['filepath'] ?? '/');
            if ($fs->file_exists(
                $contextid,
                prototype_repository::COMPONENT,
                prototype_repository::FILEAREA,
                $versionid,
                $filepath,
                $filename
            )) {
                continue;
            }
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => prototype_repository::COMPONENT,
                'filearea' => prototype_repository::FILEAREA,
                'itemid' => $versionid,
                'filepath' => $filepath,
                'filename' => $filename,
            ], base64_decode((string) ($file['content'] ?? '')));
        }
    }

    /**
     * The local id of the prototype a backed-up version derives from.
     *
     * Four routes, narrowest first, and each exists for a case the one before
     * it cannot reach.
     *
     * **A prototype restored earlier in this restore**, which the backup's
     * parents-before-children ordering guarantees has been seen by now. This is
     * the route for a chain that travelled whole.
     *
     * **Site defaults, by its flag.** It is never backed up -- every site has
     * its own -- and it cannot be found by uuid either, because the installer
     * creates it with a random one, so the target's copy carries a different
     * uuid from the source's. The flag is the only thing about it that crosses
     * a site boundary.
     *
     * **By uuid**, which is how a built-in parent is found: a built-in's uuid is
     * derived from its canonical name (decision 23), so it is the same on every
     * site, and built-ins are excluded from the backup for that reason.
     *
     * **Nothing**, leaving the version a root. Reachable only if a parent is
     * neither in the backup nor on this site, which means a built-in the
     * installer has not created. The chain is then shorter than it was, and the
     * questions below resolve from what remains of it rather than failing --
     * which is the same degradation a missing prototype has always produced.
     *
     * @param array $version the backed-up version row.
     * @param int $courseid the course being restored into.
     * @return int|null the parent's local id, or null for a root.
     */
    protected function resolve_parent(array $version, int $courseid): ?int {
        if (empty($version['parentid'])) {
            return null;
        }

        $mapped = $this->get_mappingid(prototype_repository::BACKUP_ITEM, (int) $version['parentid'], false);
        if ($mapped !== false) {
            return (int) $mapped;
        }

        if (!empty($version['parentissitedefaults'])) {
            return $this->repo()->site_defaults()?->id;
        }

        $byuuid = $this->repo()->find_one_by_uuid((string) ($version['parentuuid'] ?? ''), $courseid);
        return $byuuid?->id;
    }

    /**
     * The version number carrying a given backed-up version id.
     *
     * The current version and the draft are recorded on the prototype as ids,
     * which are the source site's. The number is what survives the crossing.
     *
     * @param array $versions the backed-up version rows.
     * @param mixed $oldversionid the id to find, or null.
     * @return int|null the version number, or null if there is no such version.
     */
    protected function version_number_of(array $versions, $oldversionid): ?int {
        if (empty($oldversionid)) {
            return null;
        }
        foreach ($versions as $version) {
            if ((int) $version['id'] === (int) $oldversionid) {
                return (int) $version['version'];
            }
        }
        return null;
    }

    /**
     * The repository, made once for this instance.
     *
     * @return prototype_repository the repository.
     */
    protected function repo(): prototype_repository {
        return $this->repo ??= new prototype_repository();
    }
}
