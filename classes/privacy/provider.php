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
 * Privacy provider for the CodeRunner prototype manager.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use local_coderunner_manager\prototype_repository;

/**
 * Privacy provider.
 *
 * The manager stores no student data. It records who created and who last
 * changed each prototype and each of its versions, which is personal data about
 * authors.
 *
 * Deleting a user does not delete their prototypes. A prototype outlives its
 * author, and other courses may depend on it, so the author references are
 * anonymised instead — which is what core does for questions, whose
 * `createdby` and `modifiedby` are likewise set to zero rather than the
 * questions being removed.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    /**
     * Describe the personal data this plugin stores.
     *
     * @param collection $collection the collection to add to.
     * @return collection the collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            prototype_repository::PROTOTYPES,
            [
                'name' => 'privacy:metadata:prototypes:name',
                'usercreated' => 'privacy:metadata:prototypes:usercreated',
                'usermodified' => 'privacy:metadata:prototypes:usermodified',
                'timecreated' => 'privacy:metadata:prototypes:timecreated',
                'timemodified' => 'privacy:metadata:prototypes:timemodified',
            ],
            'privacy:metadata:prototypes'
        );
        $collection->add_database_table(
            prototype_repository::VERSIONS,
            [
                'commitmessage' => 'privacy:metadata:versions:commitmessage',
                'usermodified' => 'privacy:metadata:versions:usermodified',
                'timecreated' => 'privacy:metadata:versions:timecreated',
            ],
            'privacy:metadata:versions'
        );
        return $collection;
    }

    /**
     * The contexts in which a user has authored or changed a prototype.
     *
     * Prototypes are scoped to a course, so a user who has authored one appears
     * in that course's context, and in the system context for site prototypes.
     *
     * @param int $userid the user to search for.
     * @return \core_privacy\local\request\contextlist the contexts.
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();

        $sql = "SELECT ctx.id
                  FROM {" . prototype_repository::PROTOTYPES . "} p
                  JOIN {context} ctx ON ctx.instanceid = p.courseid AND ctx.contextlevel = :courselevel
             LEFT JOIN {" . prototype_repository::VERSIONS . "} v ON v.prototypeid = p.id
                 WHERE p.usercreated = :usercreated
                       OR p.usermodified = :usermodified
                       OR v.usermodified = :versionuser";
        $contextlist->add_from_sql($sql, [
            'courselevel' => CONTEXT_COURSE,
            'usercreated' => $userid,
            'usermodified' => $userid,
            'versionuser' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * The users who have authored or changed a prototype in the given context.
     *
     * @param \core_privacy\local\request\userlist $userlist the userlist to add to.
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }

        $params = ['courseid' => $context->instanceid];
        $userlist->add_from_sql(
            'usercreated',
            'SELECT usercreated FROM {' . prototype_repository::PROTOTYPES . '} WHERE courseid = :courseid',
            $params
        );
        $userlist->add_from_sql(
            'usermodified',
            'SELECT usermodified FROM {' . prototype_repository::PROTOTYPES . '} WHERE courseid = :courseid',
            $params
        );
        $userlist->add_from_sql(
            'usermodified',
            "SELECT v.usermodified
               FROM {" . prototype_repository::VERSIONS . "} v
               JOIN {" . prototype_repository::PROTOTYPES . "} p ON p.id = v.prototypeid
              WHERE p.courseid = :courseid",
            $params
        );
    }

    /**
     * Export the prototypes a user authored or changed.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist the approved contexts.
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $records = $DB->get_records_select(
                prototype_repository::PROTOTYPES,
                'courseid = :courseid AND (usercreated = :usercreated OR usermodified = :usermodified)',
                ['courseid' => $context->instanceid, 'usercreated' => $userid, 'usermodified' => $userid],
                'name ASC'
            );
            if (!$records) {
                continue;
            }
            $data = [];
            foreach ($records as $record) {
                $data[] = (object) [
                    'name' => $record->name,
                    'created' => transform::datetime($record->timecreated),
                    'modified' => transform::datetime($record->timemodified),
                    'authored' => transform::yesno($record->usercreated == $userid),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_coderunner_manager')],
                (object) ['prototypes' => $data]
            );
        }
    }

    /**
     * Anonymise the author references in a context, keeping the prototypes.
     *
     * @param \context $context the context to delete within.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if (!$context instanceof \context_course) {
            return;
        }
        self::anonymise_in_course($context->instanceid);
    }

    /**
     * Anonymise one user's author references, keeping the prototypes.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist the approved contexts.
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                self::anonymise_in_course($context->instanceid, [$userid]);
            }
        }
    }

    /**
     * Anonymise the author references of several users in one context.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist the approved users.
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_course) {
            self::anonymise_in_course($context->instanceid, $userlist->get_userids());
        }
    }

    /**
     * Set the author references in a course's prototypes to zero, for the given
     * users or for everyone.
     *
     * The prototypes themselves are kept: a prototype outlives its author, and
     * questions and other courses may depend on it.
     *
     * @param int $courseid the course whose prototypes to anonymise.
     * @param array $userids the users to anonymise, or an empty array for all.
     */
    protected static function anonymise_in_course(int $courseid, array $userids = []): void {
        global $DB;

        $prototypeids = $DB->get_fieldset_select(
            prototype_repository::PROTOTYPES,
            'id',
            'courseid = :courseid',
            ['courseid' => $courseid]
        );
        if (!$prototypeids) {
            return;
        }
        [$protoin, $protoparams] = $DB->get_in_or_equal($prototypeids, SQL_PARAMS_NAMED, 'p');

        foreach (['usercreated', 'usermodified'] as $field) {
            $where = 'courseid = :courseid';
            $params = ['courseid' => $courseid];
            if ($userids) {
                [$userin, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
                $where .= " AND $field $userin";
                $params += $userparams;
            }
            $DB->set_field_select(prototype_repository::PROTOTYPES, $field, 0, $where, $params);
        }

        $where = "prototypeid $protoin";
        $params = $protoparams;
        if ($userids) {
            [$userin, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $where .= " AND usermodified $userin";
            $params += $userparams;
        }
        $DB->set_field_select(prototype_repository::VERSIONS, 'usermodified', 0, $where, $params);
    }
}
