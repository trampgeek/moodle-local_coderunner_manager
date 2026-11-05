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
 * A read-only view of the prototypes visible from a course.
 *
 * This is deliberately minimal: enough to see what the prototype tables hold.
 * Creating and editing prototypes comes with the prototype editor.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coderunner_manager\prototype;
use local_coderunner_manager\prototype_repository;

require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);

require_login($courseid ?: null);
$context = $courseid ? context_course::instance($courseid) : context_system::instance();
require_capability('qtype/coderunner:management', $context);

$url = new moodle_url('/local/coderunner_manager/index.php', $courseid ? ['courseid' => $courseid] : []);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('prototypelist', 'local_coderunner_manager'));
$PAGE->set_heading(get_string('prototypelist', 'local_coderunner_manager'));

$repo = new prototype_repository();
$prototypes = $repo->list_for_course($courseid ?: SITEID);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('prototypelist', 'local_coderunner_manager'));
echo html_writer::tag('p', get_string('prototypelist_intro', 'local_coderunner_manager'));

if (!$prototypes) {
    echo $OUTPUT->notification(get_string('noprototypesyet', 'local_coderunner_manager'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

// Index the prototypes by parent so the tree can be walked from the roots down.
$childrenof = [];
foreach ($prototypes as $prototype) {
    $childrenof[$prototype->parentid() ?? 0][] = $prototype;
}

/**
 * Print one prototype and, beneath it, everything that derives from it.
 *
 * @param prototype $prototype the prototype to print.
 * @param array $childrenof prototypes indexed by their parent's id.
 * @param int $depth how deep in the tree this prototype sits.
 */
function print_prototype_row(prototype $prototype, array $childrenof, int $depth = 0): void {
    global $DB;

    $version = $prototype->currentversion;
    $label = s($prototype->name);
    if ($prototype->issitedefaults) {
        $label .= ' ' . html_writer::tag(
            'span',
            get_string('issitedefaults', 'local_coderunner_manager'),
            ['class' => 'badge bg-primary text-white']
        );
    } else if ($prototype->builtin) {
        $label .= ' ' . html_writer::tag(
            'span',
            get_string('isbuiltin', 'local_coderunner_manager'),
            ['class' => 'badge bg-secondary text-white']
        );
    }

    $scope = $prototype->is_site_prototype()
        ? get_string('scopesite', 'local_coderunner_manager')
        : ($DB->get_field('course', 'shortname', ['id' => $prototype->courseid]) ?: $prototype->courseid);

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', str_repeat('&nbsp;', $depth * 4) . $label);
    echo html_writer::tag('td', s($scope));
    echo html_writer::tag('td', $version ? $version->version : '-');
    echo html_writer::tag('td', $version ? $version->overrides->count() : '-');
    echo html_writer::tag('td', s($version ? $version->summary : ''));
    echo html_writer::end_tag('tr');

    foreach ($childrenof[$prototype->id] ?? [] as $child) {
        print_prototype_row($child, $childrenof, $depth + 1);
    }
}

echo html_writer::start_tag('table', ['class' => 'table table-sm table-striped']);
echo html_writer::start_tag('tr');
foreach (['prototype', 'scope', 'version', 'fieldsset', 'summary'] as $header) {
    echo html_writer::tag('th', get_string('header' . $header, 'local_coderunner_manager'));
}
echo html_writer::end_tag('tr');
foreach ($childrenof[0] ?? [] as $root) {
    print_prototype_row($root, $childrenof);
}
echo html_writer::end_tag('table');

echo $OUTPUT->footer();
