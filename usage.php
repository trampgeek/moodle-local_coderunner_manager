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
 * What depends on a CodeRunner prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coderunner_manager\prototype_candidates;
use local_coderunner_manager\prototype_repository;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$repo = new prototype_repository();
$prototype = $repo->get_or_fail($id);
$courseid = $prototype->courseid;

require_login($courseid == SITEID ? null : $courseid);
$context = $courseid == SITEID ? context_system::instance() : context_course::instance($courseid);
require_capability('qtype/coderunner:management', $context);

$url = new moodle_url('/local/coderunner_manager/usage.php', ['id' => $id]);
$listurl = new moodle_url('/local/coderunner_manager/index.php', $courseid == SITEID ? [] : ['courseid' => $courseid]);
$title = get_string('usageof', 'local_coderunner_manager', $prototype->name);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->navbar->add(get_string('prototypelist', 'local_coderunner_manager'), $listurl);
$PAGE->navbar->add($title);

$descendants = $repo->descendant_ids($id);
$bycourse = $repo->dependants_by_course($id);
$total = array_sum(array_column($bycourse, 'questions'));

echo $OUTPUT->header();

echo html_writer::tag('p', get_string('usageintro', 'local_coderunner_manager', (object) [
    'questions' => $total,
    'courses' => count($bycourse),
    'children' => count($descendants),
]));

// Child prototypes, which inherit from this one and carry its changes on to
// their own questions.
if ($descendants) {
    echo $OUTPUT->heading(get_string('usagechildren', 'local_coderunner_manager'), 3);
    echo html_writer::start_tag('ul');
    foreach ($descendants as $descendantid) {
        $child = $repo->get($descendantid);
        if (!$child) {
            continue;
        }
        $own = $repo->usage_counts([$child->id])[$child->id] ?? ['questions' => 0];
        // A child may belong to another course, or to the site, and this
        // report takes its context from the prototype it is about — so a
        // viewer who cannot open the child's report is given its name rather
        // than a link to a refusal.
        $label = prototype_candidates::may_manage($child)
            ? html_writer::link(
                new moodle_url('/local/coderunner_manager/usage.php', ['id' => $child->id]),
                s($child->name)
            )
            : s($child->name);
        echo html_writer::tag('li', $label . ' — '
            . get_string('usagedirectquestions', 'local_coderunner_manager', $own['questions']));
    }
    echo html_writer::end_tag('ul');
}

// Questions, by course. A viewer sees the name of a course they can manage
// CodeRunner questions in, and a count for every other, so that the totals
// always add up without disclosing where the questions are.
echo $OUTPUT->heading(get_string('usagequestions', 'local_coderunner_manager'), 3);

if (!$bycourse) {
    echo $OUTPUT->notification(get_string('usagenodependants', 'local_coderunner_manager'), 'notifymessage');
    echo html_writer::tag('p', html_writer::link($listurl, get_string('backtolist', 'local_coderunner_manager')));
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('table', ['class' => 'table table-sm']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('usagecourse', 'local_coderunner_manager'));
echo html_writer::tag('th', get_string('usagequestioncount', 'local_coderunner_manager'));
echo html_writer::end_tag('tr');

foreach ($bycourse as $row) {
    $name = get_string('usageothercourse', 'local_coderunner_manager');

    if ($row['courseid']) {
        $coursecontext = context_course::instance($row['courseid'], IGNORE_MISSING);
        if ($coursecontext && has_capability('qtype/coderunner:management', $coursecontext)) {
            $course = get_course($row['courseid']);
            $name = html_writer::link(
                new moodle_url('/course/view.php', ['id' => $row['courseid']]),
                format_string($course->shortname)
            );
        }
    }

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $name);
    echo html_writer::tag('td', $row['questions']);
    echo html_writer::end_tag('tr');
}

echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('usagetotal', 'local_coderunner_manager'));
echo html_writer::tag('th', $total);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('table');

echo html_writer::tag('p', html_writer::link($listurl, get_string('backtolist', 'local_coderunner_manager')));
echo $OUTPUT->footer();
