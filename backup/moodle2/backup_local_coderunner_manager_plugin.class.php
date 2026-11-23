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
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $prototypes = new backup_nested_element('prototypes');
        $prototype = new backup_nested_element('prototype', ['id'], [
            'name', 'courseid', 'uuid', 'builtin', 'issitedefaults',
            'currentversionid', 'draftversionid',
            'timecreated', 'timemodified', 'usercreated', 'usermodified',
        ]);

        $versions = new backup_nested_element('versions');
        $version = new backup_nested_element('version', ['id'], [
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
        ]);

        $wrapper->add_child($prototypes);
        $prototypes->add_child($prototype);
        $prototype->add_child($versions);
        $versions->add_child($version);

        // Computed here rather than queried, because "the course's prototypes
        // and their ancestors" is a walk up a chain and not a WHERE clause.
        // set_source_array() is evaluated once, which suits a point that runs
        // once per backup; the versions below are per prototype and so can use
        // an ordinary table source.
        $prototype->set_source_array($this->prototypes_for_course(
            (int) $this->task->get_courseid()
        ));

        // The whole history, oldest first, so a restore can rebuild the
        // numbering as it found it rather than inventing one. A left join
        // rather than set_source_table(), to carry the parent's identity; left,
        // so that a root version -- whose parentid is null -- still backs up.
        $version->set_source_sql(
            'SELECT v.id, v.version, v.parentid, v.overrides, v.summary,'
                . ' v.documentation, v.documentationformat, v.commitmessage,'
                . ' v.timecreated, v.usermodified,'
                . ' p.uuid AS parentuuid, p.issitedefaults AS parentissitedefaults'
                . ' FROM {' . \local_coderunner_manager\prototype_repository::VERSIONS . '} v'
                . ' LEFT JOIN {' . \local_coderunner_manager\prototype_repository::PROTOTYPES . '} p'
                . ' ON p.id = v.parentid'
                . ' WHERE v.prototypeid = :prototypeid'
                . ' ORDER BY v.version ASC',
            ['prototypeid' => backup::VAR_PARENTID]
        );

        // Each version's support files, which is where a prototype's
        // __pystylechecker.py and the like live. Annotated on the version
        // rather than the prototype because the file area's itemid is the
        // version id: a version is a complete record, files included.
        //
        // **An out-of-course ancestor's files do not travel.** The context a
        // file annotation searches is fixed when the structure is defined
        // (`backup_structure_processor::process_nested_element()` takes
        // `VAR_CONTEXTID` for every row), and a prototype's files live in its
        // own course's context, so one annotation cannot cover prototypes from
        // two courses. The course's own prototypes are therefore complete, and
        // an ancestor from elsewhere carries its rows but not its files -- which
        // costs nothing in the cases that matter, because such an ancestor is
        // matched by uuid on the target and keeps the files it already has. It
        // is only a cross-*site* restore of a chain whose ancestor is absent
        // there that lands a prototype without its files. The way to close
        // that is to stop scoping prototype files by course, which is a
        // migration and belongs with the phase 4 tidying, not here.
        $version->annotate_files(
            \local_coderunner_manager\prototype_repository::COMPONENT,
            \local_coderunner_manager\prototype_repository::FILEAREA,
            'id'
        );

        return $plugin;
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
