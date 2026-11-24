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
 * Download one prototype as a CodeRunner 5 prototype question.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coderunner_manager\prototype_repository;
use qtype_coderunner\v6\legacy_prototype_export;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

$id = required_param('id', PARAM_INT);

// The scope the viewer is browsing in, as for the usage report: the capability
// is wanted where they are standing, and a prototype they can see in the
// library is one they can take a copy of.
$courseid = optional_param('courseid', 0, PARAM_INT);

$repo = new prototype_repository();
$prototype = $repo->get_or_fail($id);

require_login($courseid ?: null);
$context = $courseid ? context_course::instance($courseid) : context_system::instance();
require_capability('qtype/coderunner:management', $context);
require_sesskey();

$scope = $courseid ? ['courseid' => $courseid] : [];
$listurl = new moodle_url('/local/coderunner_manager/index.php', $scope);
$PAGE->set_url(new moodle_url('/local/coderunner_manager/exportlegacy.php', ['id' => $id] + $scope));
$PAGE->set_context($context);

try {
    $xml = legacy_prototype_export::file($prototype, $repo);
} catch (qtype_coderunner_exception $e) {
    // The one thing a chain can hold that a CodeRunner 5 question type cannot:
    // template parameters written in two languages. Said on the library page,
    // where the author can act on it, rather than as an error page.
    redirect($listurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

$filename = clean_filename(get_string('legacyprototypename', 'qtype_coderunner', $prototype->name)) . '.xml';
send_file($xml, $filename, 0, 0, true, true, 'application/xml');
