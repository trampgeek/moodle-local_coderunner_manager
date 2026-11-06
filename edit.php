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
use local_coderunner_manager\prototype_repository;
use qtype_coderunner\v6\override_set;
use qtype_coderunner\v6\prototype_chain;
use qtype_coderunner\v6\settings_form;

require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$parentid = optional_param('parentid', 0, PARAM_INT);

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

// A built-in is rewritten by every CodeRunner release, so an edit to one would
// be silently lost. Site defaults is the exception: it exists to be edited.
if ($prototype && $prototype->builtin && !$prototype->issitedefaults) {
    throw new moodle_exception('cannoteditbuiltin', 'local_coderunner_manager', '', $prototype->name);
}

$pageparams = $id ? ['id' => $id] : ['courseid' => $courseid];
$url = new moodle_url('/local/coderunner_manager/edit.php', $pageparams);
$listurl = new moodle_url('/local/coderunner_manager/index.php', $courseid == SITEID ? [] : ['courseid' => $courseid]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$title = $prototype
    ? get_string('editprototype', 'local_coderunner_manager', $prototype->name)
    : get_string('newprototype', 'local_coderunner_manager');
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->navbar->add(get_string('prototypelist', 'local_coderunner_manager'), $listurl);
$PAGE->navbar->add($title);

// What the prototype inherits: its parent chain, without the prototype itself.
$effectiveparent = $prototype ? $prototype->parentid() : ($parentid ?: $repo->site_defaults()?->id);
$inherited = $effectiveparent
    ? $repo->chain_for($effectiveparent)->resolve()
    : (new prototype_chain([]))->resolve();

$own = $prototype && $prototype->currentversion
    ? $prototype->currentversion->overrides
    : new override_set([], $prototype->name ?? '');

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

$form = new prototype_form($url, [
    'prototype' => $prototype,
    'inherited' => $inherited,
    'own' => $own,
    'parents' => $parents,
    'courseid' => $courseid,
]);

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    $overrides = settings_form::overrides_from(
        $data,
        prototype_form::sections(),
        $own,
        $data->name
    );
    $newparent = empty($data->parentid) ? null : (int) $data->parentid;

    if ($prototype) {
        if ($data->name !== $prototype->name) {
            $prototype = $repo->rename($prototype, $data->name);
        }
        $repo->save_new_version(
            $prototype,
            $overrides,
            $newparent,
            $data->summary,
            $data->documentation,
            $data->commitmessage
        );
    } else {
        $repo->create(
            $data->name,
            $courseid,
            $overrides,
            $newparent,
            $data->summary,
            $data->documentation
        );
    }

    redirect($listurl, get_string('prototypesaved', 'local_coderunner_manager', $data->name));
}

// Code and JSON fields are edited in Ace, which the question type ships
// along with the wrapper that attaches it (decision 22). The template is
// highlighted in whatever language the prototype resolves to.
settings_form::require_ace(
    prototype_form::sections(),
    (string) ($inherited->was_set('language') ? $inherited->get('language') : '')
);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
