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

use local_coderunner_manager\prototype_repository;
use local_coderunner_manager\usage_report;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

// The scope the viewer is browsing in, not the prototype's own: this report
// answers "what will I break if I change this?", which a teacher asks of a site
// prototype their course depends on as readily as an administrator does. So the
// capability is wanted where they are standing, and usage_report decides how
// much of the answer they see.
$courseid = optional_param('courseid', 0, PARAM_INT);

$repo = new prototype_repository();
$prototype = $repo->get_or_fail($id);

require_login($courseid ?: null);
$context = $courseid ? context_course::instance($courseid) : context_system::instance();
require_capability('qtype/coderunner:management', $context);

$scope = $courseid ? ['courseid' => $courseid] : [];
$url = new moodle_url('/local/coderunner_manager/usage.php', ['id' => $id] + $scope);
$listurl = new moodle_url('/local/coderunner_manager/index.php', $scope);
$title = get_string('usageof', 'local_coderunner_manager', $prototype->name);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->navbar->add(get_string('prototypelist', 'local_coderunner_manager'), $listurl);
$PAGE->navbar->add($title);

$descendants = $repo->descendant_ids($id);
$bycourse = $repo->dependants_by_course($id);
$report = usage_report::by_course_for_viewer($id, $repo);
$total = $report['total'];

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
        // A child may belong to another course, or to the site. Its report is
        // readable all the same, from the scope this viewer is browsing in, and
        // shows them what they may see of it.
        $label = html_writer::link(
            new moodle_url('/local/coderunner_manager/usage.php', ['id' => $child->id] + $scope),
            s($child->name)
        );
        echo html_writer::tag('li', $label . ' — '
            . get_string('usagedirectquestions', 'local_coderunner_manager', $own['questions']));
    }
    echo html_writer::end_tag('ul');
}

// Questions, by course. A viewer sees the name of a course they can manage
// CodeRunner questions in, with a link to the questions themselves, and every
// other course lumped into one anonymous row, so that the totals always add up
// without disclosing where the questions are.
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

foreach ($report['courses'] as $row) {
    // The course name leads to the questions of this prototype in that course,
    // which is what the report is for. The course itself is one click further
    // on and was never the question being asked.
    $name = html_writer::link(
        new moodle_url('/local/coderunner_manager/questions.php', [
            'id' => $id,
            'incourse' => $row['courseid'],
        ] + $scope),
        s($row['name'])
    );

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $name);
    echo html_writer::tag('td', $row['questions']);
    echo html_writer::end_tag('tr');
}

if ($report['other']['questions']) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', get_string(
        'usageothercourses',
        'local_coderunner_manager',
        $report['other']['courses']
    ));
    echo html_writer::tag('td', $report['other']['questions']);
    echo html_writer::end_tag('tr');
}

echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('usagetotal', 'local_coderunner_manager'));
echo html_writer::tag('th', $total);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('table');

echo html_writer::tag('p', html_writer::link($listurl, get_string('backtolist', 'local_coderunner_manager')));
echo $OUTPUT->footer();
