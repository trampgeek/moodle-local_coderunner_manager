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
 * Event observers for the CodeRunner prototype manager.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

/**
 * What the manager does when Moodle tells it something has happened.
 */
class event_observers {
    /**
     * A course's prototypes go with the course.
     *
     * Anything shared out of a course stops being shared when the course is
     * deleted, and a prototype is no exception: a question elsewhere that was
     * built on it reports itself broken, which is what happens to anything
     * else borrowed from a course that has gone.
     *
     * **After, not before.** `\core_course\hook\before_course_deleted` would
     * also reach this, and would have the course's file context still intact to
     * delete the support files from -- but a course deletion that then fails
     * would have taken the prototypes with it irrecoverably. The event fires
     * once the deletion has happened, and by then
     * `remove_course_contents()` has called `delete_content()` on the course
     * context, which deletes every file in it. So there are no files left to
     * delete here, only rows. There is no after-deletion *hook* to use
     * instead: `before_course_deleted` is the only one Moodle defines.
     *
     * Site prototypes, the built-ins among them, are scoped to the front page
     * course, which is not deletable.
     *
     * @param \core\event\course_deleted $event the event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        (new prototype_repository())->delete_for_course((int) $event->courseid);
    }
}
