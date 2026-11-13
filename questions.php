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
 * The questions of one course that depend on a CodeRunner prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coderunner_manager\prototype_repository;
use local_coderunner_manager\usage_report;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);
// The course whose questions are being listed. Zero means the question banks
// that belong to no course. Distinct from courseid, which is the scope the
// viewer is browsing the library in.
$incourse = required_param('incourse', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$repo = new prototype_repository();
$prototype = $repo->get_or_fail($id);

require_login($courseid ?: null);
$context = $courseid ? context_course::instance($courseid) : context_system::instance();
require_capability('qtype/coderunner:management', $context);

// And the questions of a course are the business of someone who manages that
// course's CodeRunner questions, which is not implied by managing another's.
// The usage report names only the courses this passes for, so reaching here
// without it means the address was typed rather than followed.
$incoursecontext = $incourse
    ? context_course::instance($incourse, IGNORE_MISSING)
    : context_system::instance();
if (!$incoursecontext) {
    throw new moodle_exception('invalidcourseid', 'error');
}
require_capability('qtype/coderunner:management', $incoursecontext);

$scope = $courseid ? ['courseid' => $courseid] : [];
$url = new moodle_url('/local/coderunner_manager/questions.php', ['id' => $id, 'incourse' => $incourse] + $scope);
$listurl = new moodle_url('/local/coderunner_manager/index.php', $scope);
$usageurl = new moodle_url('/local/coderunner_manager/usage.php', ['id' => $id] + $scope);

$where = $incourse
    ? format_string($DB->get_field('course', 'shortname', ['id' => $incourse]))
    : get_string('usagesitebanks', 'local_coderunner_manager');
$title = get_string('usagequestionsin', 'local_coderunner_manager', (object) [
    'prototype' => $prototype->name,
    'course' => $where,
]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->navbar->add(get_string('prototypelist', 'local_coderunner_manager'), $listurl);
$PAGE->navbar->add(get_string('usageof', 'local_coderunner_manager', $prototype->name), $usageurl);
$PAGE->navbar->add($where);

$questions = $repo->dependant_questions($id, $incourse);

echo $OUTPUT->header();

if (!$questions) {
    echo $OUTPUT->notification(get_string('usagenodependants', 'local_coderunner_manager'), 'notifymessage');
    echo html_writer::tag('p', html_writer::link($usageurl, get_string('backtousage', 'local_coderunner_manager')));
    echo $OUTPUT->footer();
    exit;
}

// Managing a course's prototypes is not permission to read its question banks,
// so a bank the viewer may not see is counted and not listed. Saying how many
// is what keeps this page's total the one the usage report showed.
$withheld = 0;
$names = [];
$rows = [];

foreach ($questions as $question) {
    if (!usage_report::may_see_bank((int) $question->contextid)) {
        $withheld++;
        continue;
    }

    $pid = (int) $question->prototypeid;
    if (!array_key_exists($pid, $names)) {
        $used = $repo->get($pid);
        $names[$pid] = $used ? $used->name : '';
    }

    $bank = context::instance_by_id((int) $question->contextid, IGNORE_MISSING);
    $rows[] = [
        'name' => $question->name,
        'prototype' => $pid === $id ? '' : $names[$pid],
        'category' => $question->categoryname,
        'bank' => $bank ? $bank->get_context_name(false) : '',
        'url' => usage_report::question_bank_url($question),
    ];
}

// The prototype column says which child the dependence runs through, so it is
// empty whenever the report is about a leaf — which is the usual case, because
// that is the prototype an author's questions are built on. An empty column is
// not shown at all rather than shown empty.
$viachild = (bool) array_filter(array_column($rows, 'prototype'));

$columns = ['name'];
$headers = [get_string('usagequestion', 'local_coderunner_manager')];
if ($viachild) {
    $columns[] = 'prototype';
    $headers[] = get_string('usagevia', 'local_coderunner_manager');
}
$columns[] = 'category';
$headers[] = get_string('usagecategory', 'local_coderunner_manager');
$columns[] = 'bank';
$headers[] = get_string('usagebank', 'local_coderunner_manager');

$table = new flexible_table('local_coderunner_manager-usage-questions');
$table->define_columns($columns);
$table->define_headers($headers);
$table->define_baseurl($url);
$table->sortable(true, 'name', SORT_ASC);
$table->set_attribute('class', 'table table-sm');
$table->setup();

// Sorted here rather than in SQL: the rows have already been reduced to one
// per question and filtered by what this viewer may see, so the set the table
// shows is not the set a query would order.
//
// One comparator over every sort column rather than a pass per column, because
// core_collator::asort() hands the work to intl, whose sort is not stable, so a
// second pass undoes the first — which is what jumbled the names inside each
// category when both columns were sorted. The cost of not using the collator is
// that accented names sort by code point rather than by the viewer's language;
// case is still folded in a UTF-8 aware way.
$sortcolumns = array_intersect_key($table->get_sort_columns(), array_flip($columns));

usort($rows, function (array $a, array $b) use ($sortcolumns): int {
    foreach ($sortcolumns as $column => $direction) {
        $order = strnatcmp(
            core_text::strtolower((string) $a[$column]),
            core_text::strtolower((string) $b[$column])
        );
        if ($order !== 0) {
            return $direction == SORT_DESC ? -$order : $order;
        }
    }
    return 0;
});

echo html_writer::tag('p', get_string('usagequestionsintro', 'local_coderunner_manager', count($rows)));

foreach ($rows as $row) {
    $cells = [html_writer::link($row['url'], s($row['name']), ['target' => '_blank'])];
    if ($viachild) {
        $cells[] = s($row['prototype']);
    }
    $cells[] = s($row['category']);
    $cells[] = s($row['bank']);
    $table->add_data($cells);
}
$table->finish_output();

if ($withheld) {
    echo html_writer::tag('p', get_string('usagewithheld', 'local_coderunner_manager', $withheld));
}

echo html_writer::tag('p', html_writer::link($usageurl, get_string('backtousage', 'local_coderunner_manager')));
echo $OUTPUT->footer();
