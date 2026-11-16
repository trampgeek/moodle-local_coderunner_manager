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
use local_coderunner_manager\prototype_candidates;
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

// Deleting one, which happens from this page because this is where an author
// surveys the library. An edit they regret is undone from the editor's own
// history instead, by restoring an earlier version.
$deleteid = optional_param('delete', 0, PARAM_INT);
if ($deleteid) {
    $doomed = $repo->get_or_fail($deleteid);

    // The capability is checked again here, against the prototype's own scope
    // rather than the page's: this listing shows a course the site prototypes
    // it may use, and a teacher who may manage their own course must not be
    // able to delete one of those by its id.
    if (!prototype_candidates::may_edit($doomed)) {
        throw new moodle_exception('nopermissions', 'error', $url, 'delete prototype');
    }
    $reason = $repo->undeletable_reason($doomed);
    if ($reason !== null) {
        throw new moodle_exception('deleteundeletable', 'local_coderunner_manager', $url, $reason);
    }

    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        $name = $doomed->name;
        $repo->delete($doomed);
        redirect(
            $url,
            get_string('deleteprototypedone', 'local_coderunner_manager', $name),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('deleteprototypeconfirm', 'local_coderunner_manager', (object) [
            'name' => $doomed->name,
            'versions' => count($repo->history($doomed->id)),
        ]),
        new moodle_url($url, ['delete' => $doomed->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}

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
 * The name cell for one prototype: a link to the editor where this user can
 * open it, and the name alone where they cannot.
 *
 * @param prototype $prototype the prototype.
 * @param int $depth how deep in the tree it sits.
 * @return string the rendered cell.
 */
function prototype_name_cell(prototype $prototype, int $depth): string {
    $indent = str_repeat('&nbsp;', $depth * 4);
    $name = s($prototype->name);

    if (!prototype_candidates::may_edit($prototype)) {
        // Named, but not offered: a built-in and every other site prototype is
        // an administrator's to change, and the row still has to say what the
        // prototype is and what uses it.
        return $indent . $name;
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
 * The actions cell for one prototype: a Delete button, disabled with its
 * reason where the prototype may not go.
 *
 * Disabled rather than absent, so that an author looking for the action finds
 * it and is told why it is not available, instead of hunting a page that
 * simply does not offer one. The reason comes from the repository, which is
 * also what refuses the deletion itself, so the two agree.
 *
 * @param prototype $prototype the prototype.
 * @param array $counts its question and child counts, already gathered.
 * @param moodle_url $pageurl the library page, to come back to.
 * @return string the rendered cell.
 */
function prototype_actions_cell(prototype $prototype, array $counts, moodle_url $pageurl): string {
    global $repo;

    if (!prototype_candidates::may_edit($prototype)) {
        // Not theirs to change, so not theirs to delete; the row still shows
        // what the prototype is and what depends on it.
        return '';
    }

    $label = get_string('deleteprototype', 'local_coderunner_manager');
    $reason = $repo->undeletable_reason($prototype, $counts);

    if ($reason !== null) {
        return html_writer::tag('span', $label, [
            'class' => 'btn btn-sm btn-outline-danger disabled',
            'aria-disabled' => 'true',
            'title' => get_string('deleteundeletable', 'local_coderunner_manager', $reason),
        ]);
    }

    return html_writer::link(
        new moodle_url($pageurl, ['delete' => $prototype->id]),
        $label,
        ['class' => 'btn btn-sm btn-outline-danger']
    );
}

/**
 * Print one prototype and, beneath it, everything that derives from it.
 *
 * @param prototype $prototype the prototype to print.
 * @param array $childrenof prototypes indexed by their parent's id.
 * @param array $usage question and child counts, indexed by prototype id.
 * @param array $coursenames course shortnames, indexed by course id.
 * @param int $browsingcourseid the course whose library this is, zero for the
 *      site's. Carried into the usage report so that it keeps the viewer's
 *      scope rather than taking the prototype's.
 * @param int $depth how deep in the tree this prototype sits.
 */
function print_prototype_row(
    prototype $prototype,
    array $childrenof,
    array $usage,
    array $coursenames,
    int $browsingcourseid = 0,
    int $depth = 0
): void {
    global $PAGE;

    $version = $prototype->currentversion;
    $label = prototype_name_cell($prototype, $depth);

    // Built-ins are created at site scope and stay there, even the stock copy
    // a split leaves behind, so saying so in the scope cell costs nothing and
    // a column of its own would be empty for every other row. Site defaults is
    // left out: its name already says what it is.
    if (!$prototype->is_site_prototype()) {
        $scope = $coursenames[$prototype->courseid]->shortname ?? $prototype->courseid;
    } else if ($prototype->builtin && !$prototype->issitedefaults) {
        $scope = get_string('scopesitebuiltin', 'local_coderunner_manager');
    } else {
        $scope = get_string('scopesite', 'local_coderunner_manager');
    }

    $counts = $usage[$prototype->id] ?? ['questions' => 0, 'children' => 0];

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $label, ['class' => 'text-nowrap']);
    echo html_writer::tag('td', s($scope), ['class' => 'text-nowrap']);
    echo html_writer::tag('td', $version ? $version->version : '-');
    echo html_writer::tag('td', $version ? $version->overrides->count() : '-');
    // The count is a link whoever is looking, because the report is readable by
    // anyone who can see the row: it answers what a change to this prototype
    // would break, which is as much a teacher's question about a site prototype
    // as an administrator's. What the report then shows depends on the viewer —
    // see local_coderunner_manager\usage_report.
    $used = $counts['questions'] || $counts['children'];
    $usagecell = '';
    if ($used) {
        $usagecell = html_writer::link(
            new moodle_url(
                '/local/coderunner_manager/usage.php',
                ['id' => $prototype->id] + ($browsingcourseid ? ['courseid' => $browsingcourseid] : [])
            ),
            $counts['questions'] ?: '0',
            ['title' => get_string('usagereport', 'local_coderunner_manager')]
        );
    }
    echo html_writer::tag('td', $usagecell);
    echo html_writer::tag('td', $counts['children'] ?: '');
    echo html_writer::tag('td', s($version ? $version->summary : ''), ['class' => 'w-50']);
    echo html_writer::tag(
        'td',
        prototype_actions_cell($prototype, $counts, $PAGE->url),
        ['class' => 'text-nowrap']
    );
    echo html_writer::end_tag('tr');

    foreach ($childrenof[$prototype->id] ?? [] as $child) {
        print_prototype_row($child, $childrenof, $usage, $coursenames, $browsingcourseid, $depth + 1);
    }
}

echo html_writer::start_tag('table', ['class' => 'table table-sm table-striped']);
echo html_writer::start_tag('tr');
foreach (['prototype', 'scope', 'version', 'fieldsset', 'questions', 'children', 'summary', 'actions'] as $header) {
    echo html_writer::tag('th', get_string('header' . $header, 'local_coderunner_manager'));
}
echo html_writer::end_tag('tr');
foreach ($childrenof[0] ?? [] as $root) {
    print_prototype_row($root, $childrenof, $usage, $coursenames, (int) $courseid);
}
echo html_writer::end_tag('table');

echo $OUTPUT->footer();
