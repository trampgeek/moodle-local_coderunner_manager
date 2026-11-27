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
 * Import a prototype export file into a library, after showing what it will do.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coderunner_manager\form\import_form;
use local_coderunner_manager\output\import_plan;
use qtype_coderunner\v6\prototype_import;

require_once(__DIR__ . '/../../config.php');
// Neither is loaded by the bootstrap: formslib holds `moodleform`, which the
// upload form extends, and the question type holds the import itself.
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/question/type/coderunner/questiontype.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
// The uploaded file, carried from the preview to the confirmation. The file
// stays in the author's draft area between the two requests and is read again
// rather than remembered: a plan is only worth acting on against the library
// as it is now.
$draftid = optional_param('draftid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$courseid = $courseid ?: SITEID;

require_login($courseid == SITEID ? null : $courseid);
$context = $courseid == SITEID ? context_system::instance() : context_course::instance($courseid);
require_capability('qtype/coderunner:management', $context);

// Importing writes to the library, so the same rule as editing one: a site
// prototype is every course's business and is an administrator's to change.
// The capability is declared at course level, which governs where it can be
// overridden rather than where it can be tested, so it cannot carry this.
if ($courseid == SITEID && !is_siteadmin()) {
    throw new moodle_exception('siteprototypesareadminonly', 'local_coderunner_manager');
}

$scope = $courseid == SITEID ? [] : ['courseid' => $courseid];
$url = new moodle_url('/local/coderunner_manager/import.php', $scope);
$listurl = new moodle_url('/local/coderunner_manager/index.php', $scope);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('importprototypes', 'local_coderunner_manager'));
$PAGE->set_heading(get_string('importprototypes', 'local_coderunner_manager'));
$PAGE->navbar->add(get_string('prototypelist', 'local_coderunner_manager'), $listurl);
$PAGE->navbar->add(get_string('importprototypes', 'local_coderunner_manager'));

$form = new import_form($url, ['courseid' => $courseid == SITEID ? 0 : $courseid]);

if ($form->is_cancelled()) {
    redirect($listurl);
}

/**
 * The one file in a draft area, as a string.
 *
 * @param int $draftid the draft area's item id.
 * @return string|null the file's contents, or null if the area is empty.
 */
function import_draft_contents(int $draftid): ?string {
    global $USER;

    $files = get_file_storage()->get_area_files(
        context_user::instance($USER->id)->id,
        'user',
        'draft',
        $draftid,
        'id DESC',
        false
    );

    $file = reset($files);

    return $file ? $file->get_content() : null;
}

// Step 2 of 2: the author has seen the plan and said yes.
if ($confirm && $draftid) {
    require_sesskey();

    $xml = import_draft_contents($draftid);
    if ($xml === null) {
        redirect(
            $url,
            get_string('importfilegone', 'local_coderunner_manager'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $done = prototype_import::apply($xml, $courseid);
    $summary = import_plan::outcome($done);
    redirect(
        $listurl,
        $summary['message'],
        null,
        $summary['refused'] ? \core\output\notification::NOTIFY_ERROR : \core\output\notification::NOTIFY_SUCCESS
    );
}

// Step 1 of 2: a file has been chosen, so say what it would do.
if ($data = $form->get_data()) {
    $xml = $form->get_file_content('importfile');
    $plan = prototype_import::plan((string) $xml, $courseid);

    echo $OUTPUT->header();
    echo import_plan::render($plan, $data->importfile, $url, $listurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('importprototypes_intro', 'local_coderunner_manager'));
$form->display();
echo $OUTPUT->footer();
