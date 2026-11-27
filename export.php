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
 * Download a selection of prototypes as one Moodle XML file.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coderunner_manager\prototype_repository;
use qtype_coderunner\v6\prototype_export;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

// The rows the author ticked. A POST, because a selection of a whole library
// does not fit a URL -- and `PARAM_SEQUENCE` is no use for an array parameter,
// so each id is cleaned as an integer.
$ids = array_values(array_unique(array_map('intval', optional_param_array('ids', [], PARAM_INT))));

// The scope the viewer is browsing in, as for the usage report: the capability
// is wanted where they are standing, and a prototype they can see in the
// library is one they can take a copy of.
$courseid = optional_param('courseid', 0, PARAM_INT);

require_login($courseid ?: null);
$context = $courseid ? context_course::instance($courseid) : context_system::instance();
require_capability('qtype/coderunner:management', $context);
require_sesskey();

$scope = $courseid ? ['courseid' => $courseid] : [];
$listurl = new moodle_url('/local/coderunner_manager/index.php', $scope);
$PAGE->set_url(new moodle_url('/local/coderunner_manager/export.php', $scope));
$PAGE->set_context($context);

if (!$ids) {
    // Pressing the button with nothing ticked. Said on the library page rather
    // than as an error, since it is a slip and not a fault.
    redirect(
        $listurl,
        get_string('exportnothingselected', 'local_coderunner_manager'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$repo = new prototype_repository();

// Read through the repository one at a time, so that a stale id -- a row the
// author had on screen while someone else deleted it -- is refused by name
// rather than silently dropped from the file.
$prototypes = [];
foreach ($ids as $id) {
    $prototype = $repo->get_or_fail($id);
    if ($prototype->issitedefaults) {
        // Its checkbox is disabled, so this is a hand-made post. Refused
        // rather than skipped: a file that silently lacks what was asked for
        // is worse than one that was not written.
        redirect(
            $listurl,
            get_string('exportnotsitedefaults', 'local_coderunner_manager'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $prototypes[] = $prototype;
}

try {
    $xml = prototype_export::file_for($prototypes, $repo);
} catch (qtype_coderunner_exception $e) {
    // The one thing a chain can hold that a CodeRunner 5 question type cannot:
    // template parameters written in two languages. Said on the library page,
    // where the author can act on it, rather than as an error page.
    redirect($listurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

// Named for one prototype where that is what was asked for, and for the
// library it came from otherwise, since a list of two dozen names is not a
// filename.
//
// **The course's shortname and not a count.** It was the count, and the author
// read `coderunner_prototypes_6.xml` as course 6 and went looking there for
// prototypes that had come from course 15 -- a bare number after an underscore
// reads as an id, and the one number nobody needs in the name is how many
// things are inside the file they just asked for.
$shortname = $DB->get_field('course', 'shortname', ['id' => $courseid ?: SITEID]);
if (count($prototypes) === 1) {
    $filename = get_string('legacyprototypename', 'qtype_coderunner', $prototypes[0]->name);
} else if (!empty($shortname)) {
    $filename = get_string('exportfilename', 'local_coderunner_manager', $shortname);
} else {
    $filename = get_string('exportfilenamenocourse', 'local_coderunner_manager');
}
send_file($xml, clean_filename($filename) . '.xml', 0, 0, true, true, 'application/xml');
