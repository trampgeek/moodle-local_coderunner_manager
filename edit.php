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
 * Create or edit a CodeRunner prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coderunner_manager\form\prototype_form;
use local_coderunner_manager\output\history_table;
use local_coderunner_manager\output\version_diff;
use local_coderunner_manager\prototype_repository;
use qtype_coderunner\v6\override_set;
use qtype_coderunner\v6\prototype_chain;
use qtype_coderunner\v6\settings_form;

require_once(__DIR__ . '/../../config.php');
// The file manager's draft-area helpers are not loaded by the bootstrap.
require_once($CFG->libdir . '/filelib.php');

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
// The parent, either as a link's request to create a child of it or as the
// menu's current choice on a reload. -1 says the page was given neither, which
// is not the same as 0 — the author choosing *No parent*.
$parentid = optional_param('parentid', -1, PARAM_INT);
$restore = optional_param('restore', 0, PARAM_INT);
$publish = optional_param('publish', 0, PARAM_BOOL);
$discard = optional_param('discard', 0, PARAM_BOOL);
$diff = optional_param('diff', 0, PARAM_INT);
$allversions = optional_param('allversions', 0, PARAM_BOOL);

$repo = new prototype_repository();
$prototype = $id ? $repo->get_or_fail($id) : null;
if ($prototype) {
    $courseid = $prototype->courseid;
}
$courseid = $courseid ?: SITEID;

require_login($courseid == SITEID ? null : $courseid);
$context = $courseid == SITEID ? context_system::instance() : context_course::instance($courseid);
require_capability('qtype/coderunner:management', $context);

// A site prototype is every course's business, so editing one is reserved to
// administrators, as design section 8 intends. The capability is declared at
// course level, which governs where it can be overridden, not where it can be
// tested, so it cannot carry this on its own.
if ($courseid == SITEID && !is_siteadmin()) {
    throw new moodle_exception('siteprototypesareadminonly', 'local_coderunner_manager');
}

// A built-in can be edited, which splits it on save so that the change
// survives the next release (decision 23). That is an administrator's job, and
// only from the site prototypes page, where the surrounding text says what the
// consequences are. Everyone else sees it read-only, which they need: choosing
// a prototype means seeing what it sets.
$isbuiltin = $prototype && $prototype->builtin && !$prototype->issitedefaults;
$readonly = $isbuiltin && !(is_siteadmin() && $courseid == SITEID);

$pageparams = $id ? ['id' => $id] : ['courseid' => $courseid];
$url = new moodle_url('/local/coderunner_manager/edit.php', $pageparams);
$listurl = new moodle_url('/local/coderunner_manager/index.php', $courseid == SITEID ? [] : ['courseid' => $courseid]);

$PAGE->set_url($url);
$PAGE->set_context($context);
if (!$prototype) {
    $title = get_string('newprototype', 'local_coderunner_manager');
} else if ($readonly) {
    $title = get_string('viewprototype', 'local_coderunner_manager', $prototype->name);
} else {
    $title = get_string('editprototype', 'local_coderunner_manager', $prototype->name);
}
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->navbar->add(get_string('prototypelist', 'local_coderunner_manager'), $listurl);
$PAGE->navbar->add($title);

// What the prototype inherits: its parent chain, without the prototype itself.
$effectiveparent = prototype_form::effective_parentid(
    $prototype,
    $parentid < 0 ? null : $parentid,
    $repo->site_defaults()?->id
);
$inherited = $effectiveparent
    ? $repo->chain_for($effectiveparent)->resolve()
    : (new prototype_chain([]))->resolve();

// The draft, when there is one: an author editing again continues from what
// they last saved rather than from what is published.
$editing = $prototype?->draftversion ?? $prototype?->currentversion;
$own = $editing ? $editing->overrides : new override_set([], $prototype->name ?? '');

// Candidate parents: anything this course can see, less the prototype itself
// and anything descended from it, which would make a cycle.
$parents = [0 => get_string('noparent', 'local_coderunner_manager')];
$descendants = $prototype ? $repo->descendant_ids($prototype->id) : [];
foreach ($repo->list_for_course($courseid) as $candidate) {
    if ($prototype && ($candidate->id == $prototype->id || in_array($candidate->id, $descendants, true))) {
        continue;
    }
    $parents[$candidate->id] = $candidate->name;
}

if (($publish || $discard) && !$readonly && $prototype) {
    require_sesskey();
    $prototype = $publish ? $repo->publish_draft($prototype) : $repo->discard_draft($prototype);
    redirect($url, get_string($publish ? 'draftpublished' : 'draftdiscarded', 'local_coderunner_manager'));
}

if ($restore && !$readonly && $prototype) {
    require_sesskey();
    $prototype = $repo->restore_version($prototype, $restore);
    redirect($url, get_string('restoredfromversion', 'local_coderunner_manager', $restore));
}

// The language the template is highlighted in: resolved from the whole chain,
// this prototype's own overrides included, or one that sets its own language
// would get no colouring.
$resolved = $effectiveparent
    ? $repo->chain_for($effectiveparent, $own)->resolve()
    : prototype_chain::from_arrays([$own->to_array()])->resolve();
$language = (string) $resolved->get('language');

// The file manager works through a draft area, seeded from the current
// version's files. A prototype being created has none, and a draft area with
// no source is simply empty.
$draftid = file_get_submitted_draft_itemid('supportfiles');
file_prepare_draft_area(
    $draftid,
    $prototype ? $repo->file_contextid($prototype) : $context->id,
    prototype_repository::COMPONENT,
    prototype_repository::FILEAREA,
    // Seeded from the version being edited, draft included: a version carries
    // a complete set of files, and seeding from the published one instead
    // showed an author continuing a draft the files the draft replaced.
    $editing?->id ?: null,
    ['subdirs' => 0]
);

$form = new prototype_form($url, [
    'prototype' => $prototype,
    'editing' => $editing,
    'inherited' => $inherited,
    'own' => $own,
    'parentid' => $effectiveparent ?? 0,
    'parents' => $parents,
    'courseid' => $courseid,
    'readonly' => $readonly,
    'language' => $language,
    // The support files section classifies what is in the draft area, not what
    // the stored version holds, so it needs the id of the area prepared above.
    // The form cannot recover it: file_get_submitted_draft_itemid() returns a
    // fresh id when nothing was submitted, which would be a different, empty
    // area.
    'draftitemid' => $draftid,
]);

$form->set_data(['supportfiles' => $draftid]);

if ($form->is_cancelled()) {
    redirect($listurl);
}

if (!$readonly && ($data = $form->get_data())) {
    $overrides = settings_form::overrides_from(
        $data,
        prototype_form::sections(),
        $own,
        $data->name
    );
    $newparent = empty($data->parentid) ? null : (int) $data->parentid;

    // An editor element submits an array of text and format.
    $documentation = $data->documentation['text'] ?? '';
    $documentationformat = (int) ($data->documentation['format'] ?? FORMAT_HTML);

    // Whatever is in the draft area is what the new version holds. An empty
    // array is meaningful — the author removed every file — so it is passed
    // as an array and not folded into null, which would carry the old set
    // forward instead.
    $submittedfiles = array_values(get_file_storage()->get_area_files(
        context_user::instance($USER->id)->id,
        'user',
        'draft',
        $data->supportfiles,
        'filename',
        false
    ));

    $splitinto = null;
    $asdraft = !empty($data->savedraft);

    if ($prototype) {
        if ($prototype->builtin && !$prototype->issitedefaults) {
            // The first edit to a built-in splits it: the stock content moves
            // to a copy that releases keep updating, and this prototype keeps
            // its id, its children and its questions.
            $prototype = $repo->split_builtin($prototype);
            $splitinto = $prototype->parentid();
        }
        if ($data->name !== $prototype->name) {
            $prototype = $repo->rename($prototype, $data->name);
        }
        $save = $asdraft ? 'save_draft' : 'save_new_version';
        $repo->$save(
            $prototype,
            // A split leaves the stock copy as the parent, which the form did
            // not know about when it was built.
            $overrides,
            $splitinto ?? $newparent,
            $data->summary,
            $documentation,
            $data->commitmessage,
            $submittedfiles,
            $documentationformat
        );
    } else {
        $repo->create(
            $data->name,
            $courseid,
            $overrides,
            $newparent,
            $data->summary,
            $documentation,
            false,
            '',
            false,
            $submittedfiles,
            $documentationformat
        );
    }

    if ($splitinto !== null) {
        redirect($listurl, get_string(
            'builtinsplit',
            'local_coderunner_manager',
            $repo->get_or_fail($splitinto)->name
        ));
    }
    if ($asdraft) {
        redirect($url, get_string('draftsaved', 'local_coderunner_manager'));
    }
    redirect($listurl, get_string('prototypesaved', 'local_coderunner_manager', $data->name));
}

// Code and JSON fields are edited in Ace, which the question type ships
// along with the wrapper that attaches it (decision 22). The template is
// highlighted in whatever language the prototype resolves to.
settings_form::require_ace(prototype_form::sections(), $language, false, $readonly);

// And the script that rebuilds the form when the author changes the parent, so
// that what each field inherits is the new parent's before anything is saved.
if (!$readonly) {
    prototype_form::require_reload_on_change();
}

echo $OUTPUT->header();

if ($diff && $prototype) {
    $history = $repo->history($prototype->id);
    if (!isset($history[$diff])) {
        throw new moodle_exception('nosuchversion', 'local_coderunner_manager', '', $diff);
    }
    echo version_diff::render($prototype, $history[$diff], $history[$diff - 1] ?? null);
    echo $OUTPUT->footer();
    exit;
}

if ($readonly) {
    echo $OUTPUT->notification(get_string('builtinreadonly', 'local_coderunner_manager'), 'notifymessage');
}
if ($prototype && $prototype->has_draft()) {
    // What publishing would affect, shown with the button rather than after
    // it: on a widely used prototype the number is the decision.
    $affected = array_sum(array_column($repo->dependants_by_course($prototype->id), 'questions'));

    echo $OUTPUT->notification(
        get_string('draftwaiting', 'local_coderunner_manager', $affected) . ' ' .
        html_writer::link(
            new moodle_url($url, ['publish' => 1, 'sesskey' => sesskey()]),
            get_string('publishdraft', 'local_coderunner_manager'),
            ['class' => 'btn btn-primary btn-sm ms-2']
        ) . ' ' .
        html_writer::link(
            new moodle_url($url, ['discard' => 1, 'sesskey' => sesskey()]),
            get_string('discarddraft', 'local_coderunner_manager'),
            ['class' => 'btn btn-secondary btn-sm']
        ),
        'notifywarning'
    );
}

if ($prototype) {
    echo html_writer::tag('p', html_writer::link(
        new moodle_url('/local/coderunner_manager/usage.php', ['id' => $prototype->id]),
        get_string('usagereport', 'local_coderunner_manager'),
        ['class' => 'btn btn-secondary btn-sm']
    ));
}

$form->display();

if ($prototype) {
    echo history_table::render($prototype, $repo->history($prototype->id), !$readonly, (bool) $allversions);
}

echo $OUTPUT->footer();
