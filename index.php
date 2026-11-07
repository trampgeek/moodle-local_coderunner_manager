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

$intro = $courseid && $courseid != SITEID
    ? get_string(
        'prototypelist_intro_course',
        'local_coderunner_manager',
        format_string($DB->get_field('course', 'shortname', ['id' => $courseid]))
    )
    : get_string('prototypelist_intro_site', 'local_coderunner_manager');

$repo = new prototype_repository();
$prototypes = $repo->list_for_course($courseid ?: SITEID);

// Everything the rows need, gathered before the tree is walked rather than
// once per row: a course can hold scores of prototypes.
$usage = $repo->usage_counts(array_keys($prototypes));
$coursenames = $prototypes
    ? $DB->get_records_list('course', 'id', array_unique(array_column($prototypes, 'courseid')), '', 'id, shortname')
    : [];

echo $OUTPUT->header();
echo html_writer::tag('p', $intro);

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/coderunner_manager/edit.php', ['courseid' => $courseid ?: SITEID]),
        get_string('newprototype', 'local_coderunner_manager'),
        ['class' => 'btn btn-primary']
    ),
    'mb-3'
);

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
 * The links offered against one prototype.
 *
 * A built-in is rewritten by every CodeRunner release, so editing one would
 * lose the change; Site defaults is the exception, since it exists to be
 * edited. Either way a child can be made, which is the supported way to build
 * on a built-in.
 *
 * @param prototype $prototype the prototype.
 * @return string[] the rendered links.
 */
function prototype_name_cell(prototype $prototype, int $depth): string {
    $indent = str_repeat('&nbsp;', $depth * 4);
    $name = s($prototype->name);

    // A built-in is rewritten by every release, so it is not a link: editing it
    // would lose the change.
    if ($prototype->builtin && !$prototype->issitedefaults) {
        // Readable by anyone choosing it, and editable by an administrator
        // from the site page, where the first save splits it (decision 23).
        return $indent . html_writer::link(
            new moodle_url('/local/coderunner_manager/edit.php', ['id' => $prototype->id]),
            $name
        );
    }

    // Site defaults sits under every question on the server, so it is editable
    // only from the site prototypes page, where the surrounding text says what
    // the consequences are. Offering the same link from a course page invites
    // an administrator to change every course while thinking about one.
    global $COURSE;
    if ($prototype->issitedefaults && $COURSE->id != SITEID) {
        return $indent . $name . ' ' . html_writer::link(
            new moodle_url('/local/coderunner_manager/index.php'),
            get_string('editatsitelevel', 'local_coderunner_manager'),
            ['class' => 'small ms-2']
        );
    }

    return $indent . html_writer::link(
        new moodle_url('/local/coderunner_manager/edit.php', ['id' => $prototype->id]),
        $name
    );
}

/**
 * Print one prototype and, beneath it, everything that derives from it.
 *
 * @param prototype $prototype the prototype to print.
 * @param array $childrenof prototypes indexed by their parent's id.
 * @param int $depth how deep in the tree this prototype sits.
 */
function print_prototype_row(
    prototype $prototype,
    array $childrenof,
    array $usage,
    array $coursenames,
    int $depth = 0
): void {
    $version = $prototype->currentversion;
    $label = prototype_name_cell($prototype, $depth);

    // The kind is a column of its own. In the name cell it sat at whatever
    // depth the prototype was indented to, so the badges formed a ragged edge;
    // and on Site defaults it repeated the name.
    $kind = '';
    if ($prototype->builtin && !$prototype->issitedefaults) {
        $kind = get_string('isbuiltin', 'local_coderunner_manager');
    }

    $scope = $prototype->is_site_prototype()
        ? get_string('scopesite', 'local_coderunner_manager')
        : ($coursenames[$prototype->courseid]->shortname ?? $prototype->courseid);

    $counts = $usage[$prototype->id] ?? ['questions' => 0, 'children' => 0];

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $label, ['class' => 'text-nowrap']);
    echo html_writer::tag('td', $kind, ['class' => 'text-nowrap text-muted small']);
    echo html_writer::tag('td', s($scope), ['class' => 'text-nowrap']);
    echo html_writer::tag('td', $version ? $version->version : '-');
    echo html_writer::tag('td', $version ? $version->overrides->count() : '-');
    echo html_writer::tag('td', $counts['questions'] ?: '');
    echo html_writer::tag('td', $counts['children'] ?: '');
    echo html_writer::tag('td', s($version ? $version->summary : ''), ['class' => 'w-50']);
    echo html_writer::end_tag('tr');

    foreach ($childrenof[$prototype->id] ?? [] as $child) {
        print_prototype_row($child, $childrenof, $usage, $coursenames, $depth + 1);
    }
}

echo html_writer::start_tag('table', ['class' => 'table table-sm table-striped']);
echo html_writer::start_tag('tr');
foreach (['prototype', 'kind', 'scope', 'version', 'fieldsset', 'questions', 'children', 'summary'] as $header) {
    echo html_writer::tag('th', get_string('header' . $header, 'local_coderunner_manager'));
}
echo html_writer::end_tag('tr');
foreach ($childrenof[0] ?? [] as $root) {
    print_prototype_row($root, $childrenof, $usage, $coursenames);
}
echo html_writer::end_tag('table');

echo $OUTPUT->footer();
