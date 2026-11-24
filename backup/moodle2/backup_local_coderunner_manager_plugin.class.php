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
 * Backs the course's prototypes up with the course.
 *
 * @package    local_coderunner_manager
 * @copyright  2026, The University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Prototype data attached to a course backup.
 *
 * A local plugin may attach to the course, section, module, grade item,
 * grading area and question elements; this uses two of them. **The course
 * point cannot do the whole job**, because of the order the plan runs in:
 * `course.xml` is written in the course task, before the activities annotate
 * the question bank entries they use, and `questions.xml` is written by
 * `backup_questions_structure_step` in the final task. So the course hook
 * cannot know which questions are in the backup, and a prototype that only a
 * question needs has to be written by the question point. That point is a
 * separate increment; what is here is the course's own library.
 *
 * Built-ins are never written. They are installed by the manager on every
 * site, identified by a uuid derived from their canonical name (decision 23),
 * so writing them would at best duplicate what the target already has and at
 * worst overwrite a site's own edit of one.
 *
 * @copyright  2026, The University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_coderunner_manager_plugin extends backup_local_plugin {
    /**
     * @var int How far up a question's prototype chain the question point looks.
     *
     * The design warns an author when a chain would be deeper than about four
     * levels and contemplates a hard limit of about twenty, so eight is
     * generous for anything an author would build on purpose while keeping the
     * generated query to a readable size -- one UNION term and two joins per
     * level. A chain deeper than this loses its topmost layers on a cross-site
     * restore, where they are absent from the target; on the same site they
     * resolve by uuid as usual and nothing is lost.
     */
    const MAX_CHAIN_DEPTH = 8;
    /**
     * The course's own prototypes, and the ancestors they need.
     *
     * Ancestors outside the course travel too, because a child is meaningless
     * without the chain above it: restoring `cosc131_scratchpad` without
     * `python3_scratchpad` would leave every question beneath it resolving to
     * the registry defaults rather than to what its author wrote.
     *
     * @return backup_plugin_element the plugin's element, or null if nothing to write.
     */
    protected function define_course_plugin_structure() {
        $plugin = null;
        [$prototype, $version] = $this->build_prototype_subtree($plugin);

        // Computed here rather than queried, because "the course's prototypes
        // and their ancestors" is a walk up a chain and not a WHERE clause.
        // set_source_array() is evaluated once, which suits a point that runs
        // once per backup; the versions below are per prototype and so can use
        // an ordinary table source.
        $prototype->set_source_array($this->prototypes_for_course(
            (int) $this->task->get_courseid()
        ));

        $this->set_version_source($version);

        return $plugin;
    }

    /**
     * Prototypes a question needs that the course point did not write.
     *
     * **Why a second point is needed at all.** `course.xml` is written in the
     * course task, before the activities annotate the question bank entries
     * they use, and `questions.xml` is written by
     * `backup_questions_structure_step` in the final task. So the course hook
     * cannot know which questions are in the backup, and a prototype that only
     * a question needs -- one borrowed from another course's bank -- has no
     * other way out. A quiz-only backup (`TYPE_1ACTIVITY`) has no course task
     * at all, so for that shape of backup **this is the only point there is**
     * and every prototype the quiz's questions use comes through here.
     *
     * **Write-once, and it is the SQL that does it.** The source is evaluated
     * once per question, and fifty questions on one prototype must not write
     * it fifty times: a single `overrides` document can be 152 KB on the
     * development site, so duplication is measured in hundreds of megabytes,
     * not in tidiness. The filter is `NOT EXISTS` against `backup_ids_temp`,
     * and `annotate_ids()` on the prototype's id is what puts a written
     * prototype there -- `backup_structure_processor::process_final_element()`
     * annotates as it writes, so the next question's query already sees it.
     * The course point's own rows are in the same table under the same item
     * name, so a course backup never repeats what `course.xml` already holds.
     *
     * **The chain is walked in SQL, to a bounded depth**, because this source
     * cannot be a PHP array: `set_source_array()` is evaluated once at
     * structure-definition time and so cannot vary by question. A recursive
     * walk is not portable SQL either, so the query is a UNION of one term per
     * level, generated below. {@see MAX_CHAIN_DEPTH} is what bounds it.
     *
     * @return backup_plugin_element the plugin's element.
     */
    protected function define_question_plugin_structure() {
        $plugin = null;
        [$prototype, $version] = $this->build_prototype_subtree($plugin);

        $prototype->set_source_sql($this->chain_sql(), $this->chain_params());

        // What makes the filter above true for every question after the first
        // that needed this prototype.
        $prototype->annotate_ids(\local_coderunner_manager\prototype_repository::BACKUP_ITEM, 'id');

        $this->set_version_source($version);

        return $plugin;
    }

    /**
     * The prototype and version elements, built and wired to the plugin.
     *
     * Both connection points write the same shape, and the restore reads one
     * set of paths for both, so the shape is described once. Only the
     * prototype element's *source* differs between them.
     *
     * @param backup_plugin_element|null $plugin set to the plugin element.
     * @return array the prototype element and the version element.
     */
    protected function build_prototype_subtree(&$plugin): array {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $prototypes = new backup_nested_element('prototypes');
        $prototype = new backup_nested_element('prototype', ['id'], [
            'name', 'courseid', 'uuid', 'builtin', 'issitedefaults',
            'currentversionid', 'draftversionid',
            'timecreated', 'timemodified', 'usercreated', 'usermodified',
        ]);

        // Required here rather than at the top of the file: a *restore* loads
        // this class too, and at that point the backup includes are absent, so
        // a class extending backup_nested_element cannot be defined. This
        // method runs only in a backup.
        require_once(__DIR__ . '/backup_prototype_version_element.class.php');

        $versions = new backup_nested_element('versions');
        // The subclass is what carries each version's support files; see its
        // own file for why they cannot be annotated in the ordinary way.
        $version = new backup_prototype_version_element('version', ['id'], [
            'version', 'parentid', 'overrides', 'summary',
            'documentation', 'documentationformat', 'commitmessage',
            'timecreated', 'usermodified',
            // The parent's identity, beside its local id, for the same reason
            // the question element carries the prototype's: an id is this
            // site's and Moodle does not remap it. The uuid settles a parent
            // that is not in the backup because it is a built-in, since a
            // built-in's uuid is derived from its canonical name (decision 23)
            // and so is the same on every site. Site defaults needs the flag
            // instead: it is created by the installer with a *random* uuid, so
            // the target's own copy carries a different one and only "this is
            // the Site defaults prototype" crosses a site boundary.
            'parentuuid', 'parentissitedefaults',
            // The support files, base64 in this element rather than annotated
            // in the ordinary way; see backup_prototype_version_element for
            // why the ordinary way cannot reach them.
            'inlinefiles',
        ]);

        $wrapper->add_child($prototypes);
        $prototypes->add_child($prototype);
        $prototype->add_child($versions);
        $versions->add_child($version);

        return [$prototype, $version];
    }

    /**
     * The whole version history of whichever prototype is being written.
     *
     * Oldest first, so a restore can rebuild the numbering as it found it
     * rather than inventing one. A left join rather than set_source_table(),
     * to carry the parent's identity; left, so that a root version -- whose
     * parentid is null -- still backs up.
     *
     * @param backup_nested_element $version the version element.
     */
    protected function set_version_source(backup_nested_element $version): void {
        $version->set_source_sql(
            'SELECT v.id, v.version, v.parentid, v.overrides, v.summary,'
                . ' v.documentation, v.documentationformat, v.commitmessage,'
                . ' v.timecreated, v.usermodified,'
                . ' p.uuid AS parentuuid, p.issitedefaults AS parentissitedefaults,'
                . ' ownctx.id AS filecontextid'
                . ' FROM {' . \local_coderunner_manager\prototype_repository::VERSIONS . '} v'
                . ' JOIN {' . \local_coderunner_manager\prototype_repository::PROTOTYPES . '} own'
                . ' ON own.id = v.prototypeid'
                . ' JOIN {context} ownctx'
                . ' ON ownctx.instanceid = own.courseid AND ownctx.contextlevel = ' . CONTEXT_COURSE
                . ' LEFT JOIN {' . \local_coderunner_manager\prototype_repository::PROTOTYPES . '} p'
                . ' ON p.id = v.parentid'
                . ' WHERE v.prototypeid = :prototypeid'
                . ' ORDER BY v.version ASC',
            ['prototypeid' => backup::VAR_PARENTID]
        );
    }

    /**
     * The query that writes a question's prototype chain, root first.
     *
     * One UNION term per level of the chain, because a recursive walk is not
     * portable SQL and this source has to vary by question so it cannot be a
     * PHP array. Each level adds two joins: a prototype's parent is recorded
     * on its *current version*, not on the prototype, so the walk goes
     * prototype -> currentversionid -> parentid -> prototype.
     *
     * **Built-ins and Site defaults are excluded rather than stopped at.** The
     * course point's PHP walk stops when it meets one; a UNION cannot stop, so
     * the rows are filtered out instead. That is equivalent here, and slightly
     * more robust: everything above a built-in is itself a built-in or Site
     * defaults, since the installer creates those chains, so stopping and
     * excluding select the same set. The reason either way is that every site
     * installs its own and the restore re-links by uuid.
     *
     * Ordered by depth descending, so a root arrives before anything that
     * derives from it: the restore resolves a parent from the mappings of
     * prototypes already seen.
     *
     * @return string the SQL.
     */
    protected function chain_sql(): string {
        $prototypes = '{' . \local_coderunner_manager\prototype_repository::PROTOTYPES . '}';
        $versions = '{' . \local_coderunner_manager\prototype_repository::VERSIONS . '}';
        $columns = 'p%1$d.id, p%1$d.name, p%1$d.courseid, p%1$d.uuid, p%1$d.builtin,'
            . ' p%1$d.issitedefaults, p%1$d.currentversionid, p%1$d.draftversionid,'
            . ' p%1$d.timecreated, p%1$d.timemodified, p%1$d.usercreated, p%1$d.usermodified';

        $terms = [];
        for ($depth = 0; $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            // Level 0 is the prototype the question points at; each level after
            // it climbs one link.
            $joins = "JOIN $prototypes p0 ON p0.id = o.prototypeid";
            for ($step = 1; $step <= $depth; $step++) {
                $up = $step - 1;
                $joins .= "\n              JOIN $versions v$up ON v$up.id = p$up.currentversionid"
                    . "\n              JOIN $prototypes p$step ON p$step.id = v$up.parentid";
            }

            $terms[] = 'SELECT ' . sprintf($columns, $depth) . ", $depth AS chaindepth"
                . "\n         FROM {question_coderunner_options} o"
                . "\n              $joins"
                . "\n        WHERE o.questionid = :questionid$depth"
                . "\n          AND p$depth.builtin = 0"
                . "\n          AND p$depth.issitedefaults = 0"
                . "\n          AND NOT EXISTS (SELECT 1 FROM {backup_ids_temp} bi"
                . "\n                           WHERE bi.backupid = :backupid$depth"
                . "\n                             AND bi.itemname = :itemname$depth"
                . "\n                             AND bi.itemid = p$depth.id)";
        }

        return "SELECT * FROM (\n" . implode("\n   UNION ALL\n", $terms)
            . "\n) chain ORDER BY chaindepth DESC";
    }

    /**
     * The parameters {@see chain_sql()} wants, one set per UNION term.
     *
     * A named parameter may not be reused in one statement portably, so each
     * term carries its own copy under a numbered name. The question id and the
     * backup id are resolved per occurrence by the structure processor;
     * `sqlparam` is how a literal is passed instead of an element reference.
     *
     * @return array the parameters.
     */
    protected function chain_params(): array {
        $params = [];
        for ($depth = 0; $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            $params["questionid$depth"] = backup::VAR_PARENTID;
            $params["backupid$depth"] = backup::VAR_BACKUPID;
            $params["itemname$depth"] = [
                'sqlparam' => \local_coderunner_manager\prototype_repository::BACKUP_ITEM,
            ];
        }
        return $params;
    }

    /**
     * The rows to write for a course, parents before children.
     *
     * Parents first because a restore that meets a child before its parent has
     * nothing to point it at, and ordering the write is cheaper than making
     * the restore defer. Depth is the sort key rather than id: a prototype
     * created later can be the parent of an earlier one, after a re-parenting.
     *
     * @param int $courseid the course being backed up.
     * @return array the prototype rows, as plain objects.
     */
    protected function prototypes_for_course(int $courseid): array {
        global $DB;

        $repository = new \local_coderunner_manager\prototype_repository();
        $wanted = [];

        foreach ($repository->list_for_course($courseid) as $prototype) {
            if ($prototype->builtin || $prototype->issitedefaults
                    || (int) $prototype->courseid !== $courseid) {
                // list_for_course() includes the site's prototypes and any
                // borrowed from elsewhere, which are the course's to use and
                // not the course's to carry. Ancestors are added below, where
                // being needed is the test rather than being visible.
                continue;
            }
            $this->collect_with_ancestors($repository, $prototype->id, $wanted);
        }

        if (!$wanted) {
            return [];
        }

        // Written in the order collected, which is parents before children.
        $rows = $DB->get_records_list(
            \local_coderunner_manager\prototype_repository::PROTOTYPES,
            'id',
            array_keys($wanted)
        );
        $ordered = [];
        foreach (array_keys($wanted) as $id) {
            if (isset($rows[$id])) {
                $ordered[] = $rows[$id];
                backup_structure_dbops::insert_backup_ids_record(
                    $this->task->get_backupid(),
                    \local_coderunner_manager\prototype_repository::BACKUP_ITEM,
                    $id
                );
            }
        }
        return $ordered;
    }

    /**
     * Add a prototype and everything above it to the set, parents first.
     *
     * @param \local_coderunner_manager\prototype_repository $repository the repository to read.
     * @param int $id the prototype to add.
     * @param array $wanted the set so far, keyed by id, modified in place.
     */
    protected function collect_with_ancestors($repository, int $id, array &$wanted): void {
        if (isset($wanted[$id])) {
            return;
        }
        $prototype = $repository->get($id);
        if ($prototype === null || $prototype->builtin || $prototype->issitedefaults) {
            // Where the walk stops. A built-in is installed by the manager on
            // every site, so the target has its own and the restore re-links
            // by uuid. Site defaults is the same in kind -- the root every
            // chain ends at, created by the installer -- and carrying it would
            // be worse than redundant: a restored site prototype takes the
            // restored course's id (restoring a course must not create site
            // prototypes), so the target would gain a course-scoped copy of
            // *Site defaults*, which is a contradiction.
            return;
        }
        $parentid = $prototype->currentversion->parentid ?? null;
        if ($parentid !== null) {
            $this->collect_with_ancestors($repository, (int) $parentid, $wanted);
        }
        $wanted[$id] = true;
    }
}
