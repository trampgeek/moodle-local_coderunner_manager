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
$usage = $repo->usage_counts(array_keys($prototypes), $courseid ?: SITEID);
$coursenames = $prototypes
    ? $DB->get_records_list('course', 'id', array_unique(array_column($prototypes, 'courseid')), '', 'id, shortname')
    : [];

echo $OUTPUT->header();
echo html_writer::tag('p', $intro);

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/coderunner_manager/edit.php', ['courseid' => $courseid ?: SITEID]),
        get_string('newprototype', 'local_coderunner_manager'),
        ['class' => 'btn btn-primary me-1']
    ) . html_writer::link(
        new moodle_url('/local/coderunner_manager/import.php', $courseid ? ['courseid' => $courseid] : []),
        get_string('importprototypes', 'local_coderunner_manager'),
        ['class' => 'btn btn-secondary']
    ),
    'mb-3'
);

if (!$prototypes) {
    echo $OUTPUT->notification(get_string('noprototypesyet', 'local_coderunner_manager'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

// Index the prototypes by parent so the tree can be walked from the roots
// down. The rule for a prototype whose parent is not in this list -- a child
// of another course's prototype -- is prototype_candidates'; see
// children_by_parent(), which this page's own bug is named in.
$childrenof = prototype_candidates::children_by_parent($prototypes);

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
 * The export checkbox for one prototype.
 *
 * **Governed by what a recipient needs, not by what the viewer may edit**
 * (decision 36). Export is a read, and the content is already readable: the
 * editor shows a non-admin every field of a site prototype, and a question
 * export already carries the definitions of non-builtin site prototypes. So
 * this does not ask `may_edit()`, and a teacher can send a colleague on
 * CodeRunner 5 the site-level type their questions need -- the RoboTurtle case
 * that raised prototype export in the first place.
 *
 * *Site defaults* is the one row that cannot be exported, because flattened it
 * arrives as a question type called *Site defaults* that defines nothing. Its
 * box is **disabled rather than absent**, which is the Delete button's rule on
 * this page too: an author looking for the action finds it and is told why.
 *
 * **Every other row is in one toggle group, so *Select all* selects all of
 * them.** It briefly left the built-ins out, on the grounds that no recipient
 * needs them and they are the bulk of a library's bytes; the author's ruling
 * on 27 November is that a *Select all* which visibly does not is worse than
 * the bytes -- the common export is one prototype and its descendants, whole
 * libraries are rare, and a control that behaves as it does everywhere else in
 * Moodle is worth more than an optimisation for a case almost nobody meets.
 *
 * @param prototype $prototype the prototype.
 * @return string the rendered cell.
 */
function prototype_export_cell(prototype $prototype): string {
    if ($prototype->issitedefaults) {
        return html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'disabled' => 'disabled',
            'class' => 'form-check-input',
            'title' => get_string('exportnotsitedefaults', 'local_coderunner_manager'),
            'aria-label' => get_string('exportnotsitedefaults', 'local_coderunner_manager'),
        ]);
    }

    return html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'ids[]',
        'value' => $prototype->id,
        'class' => 'form-check-input',
        'data-action' => 'toggle',
        'data-toggle' => 'target',
        'data-togglegroup' => 'export',
        'aria-label' => get_string('exportthis', 'local_coderunner_manager', $prototype->name),
    ]);
}

/**
 * Why a row sits at the top of the table rather than inside the tree.
 *
 * **Only the remotely-parented roots get this**, and that asymmetry is the
 * point: every other row's parent is the row it is indented under, so naming
 * it would repeat what the indentation already says, and a column of its own
 * would be empty everywhere else. These rows are the ones with nothing above
 * them to explain them -- their parent is in another course and cannot be
 * drawn here -- so they are the ones that need telling.
 *
 * The parent is named in plain text rather than linked, because the viewer may
 * well not be able to open it; the library learnt that lesson once already,
 * when every row offered a link that answered "you don't have permission"
 * ({@see prototype_candidates::may_edit()}).
 *
 * Its course is named whatever the viewer's rights there, which is the answer
 * the editor's own scope menu already gives for exactly this case
 * ({@see \local_coderunner_manager\scope_picker::scopes()}), so the two
 * places cannot disagree about the same parent.
 *
 * @param prototype $prototype the prototype.
 * @return string the rendered note, or an empty string where there is none.
 */
function prototype_remote_parent_note(prototype $prototype): string {
    global $repo;

    $parentid = $prototype->parentid();
    if ($parentid === null) {
        return '';
    }

    $parent = $repo->get($parentid);
    if ($parent === null) {
        // A parent that has gone. Deletion refuses a prototype with children,
        // so this is dangling data rather than something an author did, and
        // the row says what it can: that the parent is not here.
        return '';
    }

    $course = (new prototype_candidates())->course_name((int) $parent->courseid);
    $note = $course === ''
        ? get_string('inheritsfromelsewhere', 'local_coderunner_manager', s($parent->name))
        : get_string('inheritsfromcourse', 'local_coderunner_manager', (object) [
            'parent' => s($parent->name),
            'course' => s($course),
        ]);

    return html_writer::tag('small', $note, ['class' => 'text-muted ms-2 fw-normal']);
}

/**
 * The actions cell for one prototype: an export and a Delete button, the
 * latter disabled with its reason where the prototype may not go.
 *
 * Disabled rather than absent, so that an author looking for the action finds
 * it and is told why it is not available, instead of hunting a page that
 * simply does not offer one. The reason comes from the repository, which is
 * also what refuses the deletion itself, so the two agree.
 *
 * **Export is not here**, though it used to be. It is a checkbox on the row
 * and one button under the table (decision 36), because a per-row button can
 * only ever send one prototype and a library is sent as a library. Its old
 * place here is also what gated it on `may_edit()`, by way of the early
 * return above -- reasoning meant for deletion, applied to a read.
 *
 * @param prototype $prototype the prototype.
 * @param array $counts its question and child counts, already gathered.
 * @param moodle_url $pageurl the library page, to come back to.
 * @return string the rendered cell.
 */
function prototype_actions_cell(prototype $prototype, array $counts, moodle_url $pageurl): string {
    global $repo, $OUTPUT;

    if (!prototype_candidates::may_edit($prototype)) {
        // Not theirs to change, so not theirs to delete; the row still shows
        // what the prototype is and what depends on it.
        return '';
    }

    $label = get_string('deleteprototype', 'local_coderunner_manager');
    $reason = $repo->undeletable_reason($prototype, $counts);

    if ($reason !== null) {
        // A faded icon, not a link, and not a disabled button: the pair of
        // outline buttons this replaced differed by a shade an author could
        // not see, which the author said on 27 November. Red against grey at
        // half opacity is a difference nobody has to look for, and it does not
        // rely on colour alone, since the live one is a link and this is not.
        return html_writer::tag(
            'span',
            $OUTPUT->pix_icon('t/delete', '', 'moodle', ['aria-hidden' => 'true']),
            [
                'class' => 'text-muted opacity-50 mx-1 p-1 d-inline-block',
                'title' => get_string('deleteundeletable', 'local_coderunner_manager', $reason),
                'aria-label' => get_string('deleteundeletable', 'local_coderunner_manager', $reason),
                'role' => 'img',
            ]
        );
    }

    // The icon and class core's own question bank deletes with
    // (`qbank_deletequestion\delete_action`: `t/delete`, then `text-danger`),
    // through the helper it goes through, so this row looks like every other
    // table of actions in Moodle and inherits whatever a theme does to them.
    // The word *Delete* is the link's accessible name rather than its text,
    // which is what `action_icon()` does with it.
    return $OUTPUT->action_icon(
        new moodle_url($pageurl, ['delete' => $prototype->id]),
        new pix_icon('t/delete', $label),
        null,
        ['class' => 'text-danger']
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

    // A root with a parent is one whose parent is not in this listing: it is
    // in another course, so this row stands outside the tree and says why.
    // Only a root can be in that position -- anything whose parent is here is
    // printed under it, by construction -- so the depth is the test.
    if ($depth === 0) {
        $label .= prototype_remote_parent_note($prototype);
    }

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

    $counts = $usage[$prototype->id] ?? ['questions' => 0, 'children' => 0, 'childrenincourse' => 0];

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', prototype_export_cell($prototype), ['class' => 'text-nowrap']);
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
    $childcell = $counts['children']
        ? $counts['children'] . '/' . ($counts['childrenincourse'] ?? 0)
        : '';
    echo html_writer::tag('td', $childcell, ['class' => 'text-nowrap']);
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

// The table is a form, because the export acts on a selection of its rows.
// `core/checkbox-toggleall` drives the two links below: a non-checkbox toggler
// selects or clears its group according to `data-checkall`, so *Select all* and
// *Select none* need no script of this plugin's. Registered unconditionally,
// since the module binds one delegated listener per page however many groups
// are on it.
$PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/coderunner_manager/export.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'courseid',
    'value' => $courseid ?: 0,
]);

// The togglers. *Select all* means every row that has a box, which is how the
// control behaves everywhere else in Moodle and so needs no explaining.
$togglers = html_writer::link('#', get_string('selectall', 'local_coderunner_manager'), [
    'data-action' => 'toggle',
    'data-toggle' => 'toggler',
    'data-togglegroup' => 'export',
    'data-checkall' => 1,
]);
$togglers .= ' · ' . html_writer::link('#', get_string('selectnone', 'local_coderunner_manager'), [
    'data-action' => 'toggle',
    'data-toggle' => 'toggler',
    'data-togglegroup' => 'export',
    'data-checkall' => 0,
]);
echo html_writer::div($togglers, 'mb-2');

echo html_writer::start_tag('table', ['class' => 'table table-sm table-striped']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', '', ['class' => 'text-nowrap']);
foreach (['prototype', 'scope', 'version', 'fieldsset', 'questions', 'children', 'summary', 'actions'] as $header) {
    $text = get_string('header' . $header, 'local_coderunner_manager');
    if ($header === 'children') {
        // Two numbers in the cell need saying which is which, and the heading
        // is the only place to say it without a note under every table.
        $text .= html_writer::empty_tag('br')
            . html_writer::tag(
                'small',
                get_string('headerchildrenscope', 'local_coderunner_manager'),
                ['class' => 'text-muted fw-normal']
            );
    }
    echo html_writer::tag('th', $text);
}
echo html_writer::end_tag('tr');
foreach (prototype_candidates::roots_in_order($childrenof) as $root) {
    print_prototype_row($root, $childrenof, $usage, $coursenames, (int) $courseid);
}
echo html_writer::end_tag('table');

// One button, not a menu: there is one export file and both CodeRunner
// versions read it (decision 36). The help carries what the old per-row
// button's tooltip said and is still true -- a CodeRunner 5 recipient must
// import the prototypes before the questions that use them.
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('exportselected', 'local_coderunner_manager'),
        'class' => 'btn btn-secondary',
    ]) . $OUTPUT->help_icon('exportselected', 'local_coderunner_manager'),
    'mb-3'
);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
