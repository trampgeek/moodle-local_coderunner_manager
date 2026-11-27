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
 * Strings for the CodeRunner prototype manager.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['builtinsmissing'] = 'The built-in prototype definitions could not be found at {$a}.';
$string['builtinsunreadable'] = 'The built-in prototype definitions at {$a} could not be parsed.';
$string['cannotdeletesitedefaults'] = 'The Site defaults prototype sits at the root of every prototype chain and cannot be deleted.';
$string['cyclicparent'] = 'Prototype "{$a->name}" cannot have "{$a->parent}" as its parent, because that would form a cycle.';
$string['nameinuse'] = 'The prototype name "{$a}" is already used by another prototype visible from this course.';
$string['nameinuseatsite'] = 'The prototype name "{$a}" is already used by a site prototype.';
$string['nameinuseincourse'] = 'The prototype name "{$a}" is already used by a prototype in at least one course, so it cannot be used for a site prototype.';
$string['headerfieldsset'] = 'Fields set';
$string['headerprototype'] = 'Prototype';
$string['headerscope'] = 'Scope';
$string['headeractions'] = 'Actions';
$string['headerchildren'] = 'Child prototypes';
$string['headerchildrenscope'] = '(Site/Course)';
$string['headerquestions'] = 'Questions';
$string['headersummary'] = 'Summary';
$string['headerversion'] = 'Version';
$string['installerupdated'] = 'Updated by the CodeRunner prototype installer.';
$string['pluginname'] = 'CodeRunner prototype manager';
$string['privacy:metadata:prototypes'] = 'Information about the CodeRunner question prototypes an author has created or changed.';
$string['privacy:metadata:prototypes:name'] = 'The name of the prototype.';
$string['privacy:metadata:prototypes:timecreated'] = 'When the prototype was created.';
$string['privacy:metadata:prototypes:timemodified'] = 'When the prototype was last changed.';
$string['privacy:metadata:prototypes:usercreated'] = 'The user who created the prototype.';
$string['privacy:metadata:prototypes:usermodified'] = 'The user who last changed the prototype.';
$string['privacy:metadata:versions'] = 'Information about each saved version of a CodeRunner question prototype.';
$string['privacy:metadata:versions:commitmessage'] = 'The message the author recorded with this version.';
$string['privacy:metadata:versions:timecreated'] = 'When this version was saved.';
$string['privacy:metadata:versions:usermodified'] = 'The user who saved this version.';
$string['sitedefaultsname'] = 'Site defaults';
$string['sitedefaultssummary'] = 'The settings every CodeRunner question falls back on when no prototype and no question sets them.';
$string['taskinstallprototypes'] = 'Install CodeRunner question prototypes';
$string['noprototypesyet'] = 'No prototypes have been recorded yet. They are created by the "Install CodeRunner question prototypes" task, which runs on cron after an install or upgrade.';
$string['prototypelist'] = 'CodeRunner prototypes';
$string['scopesite'] = 'Site';
$string['selectall'] = 'Select all';
$string['selectnone'] = 'Select none';
$string['prototypehasquestions'] = 'Prototype "{$a->name}" cannot be deleted because {$a->count} question(s) use it.';
$string['prototypeinuse'] = 'Prototype "{$a}" cannot be deleted because other prototypes derive from it.';
$string['prototypenotfound'] = 'There is no CodeRunner prototype with id {$a}.';
$string['commitmessage'] = 'What changed';
$string['commitmessage_help'] = 'A short note saying what this version changes and why. It appears in the change history beside the version, so that someone comparing two versions can see the intent as well as the difference.';
$string['documentation'] = 'Documentation';
$string['documentation_help'] = 'The guidance question authors see when they choose this prototype. This replaces the question text of a CodeRunner 5 prototype question.';
$string['editprototype'] = 'Edit prototype: {$a}';
$string['exportnotsitedefaults'] = 'Site defaults cannot be exported: it is the root every chain stands on rather than a question type, and on its own it defines nothing.';
$string['exportfilename'] = 'coderunner_prototypes_{$a}';
$string['exportfilenamenocourse'] = 'coderunner_prototypes';
$string['exportnothingselected'] = 'No prototypes were selected, so there was nothing to export.';
$string['exportselected'] = 'Export selected';
$string['exportselected_help'] = 'Downloads the selected prototypes as a single Moodle XML file.

A CodeRunner 6 site imports that file through this page. A CodeRunner 5 site imports it through Moodle\'s own question import, where each prototype arrives as a question type with everything it inherits resolved into it -- so **import it before the questions that use it**, or those questions will refuse to run.';
$string['exportthis'] = 'Select {$a} for export';
$string['identity'] = 'Identity';
$string['importaction_create'] = 'New';
$string['importaction_refuse'] = 'Refused';
$string['importaction_unchanged'] = 'Unchanged';
$string['importaction_version'] = 'New version';
$string['importconfirmbutton'] = 'Import';
$string['importdone'] = 'Import finished: {$a->created} added, {$a->versioned} updated, {$a->unchanged} already up to date.';
$string['importfile'] = 'Prototype file';
$string['importfile_help'] = 'A Moodle XML file written by the <em>Export selected</em> button on the prototype library page, here or on another site.

A file of questions will not do: import that through Moodle\'s own question import instead.';
$string['importfilegone'] = 'The uploaded file is no longer available. Please choose it again.';
$string['importheaderaction'] = 'What will happen';
$string['importheaderfile'] = 'In the file';
$string['importheaderhere'] = 'Here';
$string['importnothere'] = 'Not on this site';
$string['importignoringbuiltin'] = 'The file also names one built-in question type, which every CodeRunner site already has. It is left alone.';
$string['importignoringbuiltins'] = 'The file also names {$a} built-in question types, which every CodeRunner site already has. They are left alone.';
$string['importplanintro'] = 'Nothing has been changed yet. This is what importing the file would do:';
$string['importpreviewbutton'] = 'Continue';
$string['importproblem_builtins'] = 'That file holds only CodeRunner\'s built-in question types, which every CodeRunner site already has, so there is nothing to import here. The file is still useful for a site running CodeRunner 5 that lacks one of them -- import it there through Moodle\'s own question import.';
$string['importproblem_nothing'] = 'That file contains no CodeRunner prototypes.';
$string['importproblem_questions'] = 'That file contains questions rather than prototypes. Import it through Moodle\'s own question import, which will bring in any prototypes the questions need along with the questions themselves.';
$string['importprototypes'] = 'Import prototypes';
$string['importprototypes_intro'] = 'Adds the prototypes in an exported file to this library. A prototype already here is recognised by its identifier rather than its name, and the incoming content is added to it as a new version, so nothing is overwritten and the change can be undone from its history.';
$string['importrefused'] = 'Nothing was imported. Resolve the problems above and try again -- importing part of a library would leave it in a state neither site has.';
$string['importversion'] = 'Version {$a}';
$string['inheritsfromcourse'] = 'inherits from {$a->parent} in {$a->course}';
$string['inheritsfromelsewhere'] = 'inherits from {$a}, in another course';
$string['invalidjson'] = 'Not valid JSON. Every question built on this prototype merges this document, so it has to be readable as it stands.';
$string['newprototype'] = 'New prototype';
$string['noparent'] = 'None';
$string['scopecourse'] = 'Prototypes from';
$string['scopefrom'] = 'From';
$string['scopegroup_othercourse'] = 'Another course';
$string['scopegroup_site'] = 'Site';
$string['scopegroup_thiscourse'] = 'This course';
$string['scopeprototype'] = 'Prototype';
$string['scopethiscourse'] = 'This course';
$string['scopeuse'] = 'use';
$string['parentprototype'] = 'Parent';
$string['parentisroot'] = 'None: Site defaults is the root of every prototype chain.';
$string['parentnotallowed'] = 'You do not have permission to inherit from that course\'s prototypes.';
$string['parentprototype_help'] = 'The prototype this one builds on. Every field left un-overridden here takes its value from that prototype, or from whatever that prototype inherits in turn.

Every prototype inherits from another, and the chain ends at the <em>Site defaults</em> prototype, which holds the values a question takes when nothing else sets them. Choose <em>Site defaults</em> itself for a prototype that starts afresh.';
$string['parentrequired'] = 'Choose the prototype this one inherits from. Every prototype builds on another; choose Site defaults for one that starts afresh.';
$string['parentreload'] = 'Reload with this parent';
$string['prototypename'] = 'Name';
$string['prototypename_help'] = 'The name authors see. It must be unique among the prototypes of this course and all site prototypes taken together, so that a course prototype can never shadow a site one.';
$string['prototypesaved'] = 'Prototype {$a} saved.';
$string['savenewversion'] = 'Save as new version';
$string['sectionextras'] = 'Extras';
$string['sectiongrading'] = 'Grading and penalties';
$string['sectionparameters'] = 'Template parameters';
$string['sectionsandbox'] = 'Sandbox, languages and limits';
$string['sectiontemplate'] = 'Template and grader';
$string['sectiontestcasefields'] = 'Test case fields';
$string['sectionui'] = 'User interface';
$string['siteprototypesareadminonly'] = 'Only site administrators may edit site prototypes, because every course depends on them.';
$string['summary'] = 'One-line summary';
$string['summary_help'] = 'A single sentence describing what this prototype is for. It is shown beside the prototype in the picker and in the library, so it should say what distinguishes it from its parent.';
$string['thischange'] = 'This change';
$string['prototypelist_intro_course'] = 'The prototypes {$a} can use: its own, and the site prototypes every course shares. Select one of this course\'s own prototypes to edit it. The site prototypes, built-in ones among them, belong to the whole server: a site administrator edits those from the site prototypes page, and the first change to a built-in splits it, so that later CodeRunner releases go on updating the original.';
$string['prototypelist_intro_site'] = 'The site prototypes, which every course on this server can use. Changing one affects questions in every course. Each course also has prototypes of its own, which are listed on that course\'s page.';
$string['editatsitelevel'] = '(edit on the site prototypes page)';
$string['prototypemanager'] = 'Prototype manager';
$string['prototypemanager_desc'] = 'Create and edit the prototypes that questions inherit their settings from.';
$string['viewprototype'] = 'View prototype: {$a}';
$string['builtinreadonly'] = 'This is a built-in prototype, shown read-only. A site administrator can edit it from the site prototypes page, where the first change splits it: the stock version moves to a copy that later CodeRunner releases go on updating, and this one keeps its name, its children and its questions. To build on it instead, make a child prototype and override only what differs.';
$string['stockname'] = '{$a} (stock)';
$string['builtinsplit'] = 'This was a built-in prototype. Your changes are kept in it, and the version CodeRunner ships has been copied to {$a}, which it inherits from. Future CodeRunner releases update that copy, and your changes continue to apply on top of it.';
$string['splitfrombuiltin'] = 'Separated from the built-in prototype, which was copied to {$a}.';
$string['nosuchversion'] = 'This prototype has no version {$a}.';
$string['restoredfromversion'] = 'Restored the content of version {$a}.';
$string['changehistory'] = 'Change history';
$string['historyversion'] = 'Version';
$string['historywhen'] = 'When';
$string['historywho'] = 'By';
$string['historywhat'] = 'What changed';
$string['historychanges'] = 'Fields changed';
$string['restoreversion'] = 'Restore';
$string['restoreconfirm'] = 'Bring back the content of version {$a}? It is saved as a new version, so nothing in the history is lost.';
$string['nochangesrecorded'] = 'No fields changed.';
$string['firstversion'] = 'First version.';
$string['showfullhistory'] = 'Show all {$a} versions';
$string['historytruncated'] = 'Showing the most recent {$a} versions.';
$string['backtoprototype'] = 'Back to the prototype';
$string['diffheading'] = 'What version {$a->version} changed, compared with version {$a->previous}';
$string['diffoffirstversion'] = 'What version {$a} set';
$string['difffield'] = 'Setting';
$string['diffbefore'] = 'Before';
$string['diffafter'] = 'After';
$string['diffnotset'] = 'not set';
$string['diffempty'] = 'empty';
$string['viewdiff'] = 'See what changed';
$string['supportfiles'] = 'Support files';
$string['supportfiles_help'] = 'Files made available to every question that uses this prototype, alongside the question\'s own support files. A question\'s own file of the same name wins, and so does a nearer prototype\'s, so a child prototype can replace one of its parent\'s files without copying the rest.';
$string['usageof'] = 'What depends on {$a}';
$string['usageintro'] = '{$a->questions} questions in {$a->courses} courses depend on this prototype, directly or through its {$a->children} child prototypes. Changing it changes all of them.';
$string['usagechildren'] = 'Child prototypes';
$string['usagequestions'] = 'Questions, by course';
$string['usagequestioncount'] = 'Questions';
$string['usagecourse'] = 'Course';
$string['usagetotal'] = 'Total';
$string['usagedirectquestions'] = '{$a} questions of its own';
$string['usageothercourses'] = 'Other courses ({$a} of them, which you cannot manage CodeRunner questions in)';
$string['usagesitebanks'] = 'Question banks outside any course';
$string['usagequestionsin'] = 'Questions using {$a->prototype} in {$a->course}';
$string['usagequestionsintro'] = '{$a} questions in this course are built on this prototype or one of its descendants. Each opens in the question bank that holds it.';
$string['usagequestion'] = 'Question';
$string['usagevia'] = 'Through child prototype';
$string['usagebank'] = 'Question bank';
$string['usagecategory'] = 'Category';
$string['usagewithheld'] = 'A further {$a} questions are in question banks you cannot view, and are not listed.';
$string['backtousage'] = 'Back to the usage report';
$string['usagenodependants'] = 'Nothing depends on this prototype yet.';
$string['backtolist'] = 'Back to the prototype list';
$string['usagereport'] = 'Usage';
$string['savedraft'] = 'Save as draft';
$string['draftsaved'] = 'Saved as a draft. Questions go on using the published version until you publish it.';
$string['draftpublished'] = 'Draft published. Every question that uses this prototype now takes it up.';
$string['draftdiscarded'] = 'Draft discarded. The published version is unchanged.';
$string['draftwaiting'] = 'This prototype has an unpublished draft. Publishing it will change {$a} questions.';
$string['publishdraft'] = 'Publish the draft';
$string['discarddraft'] = 'Discard it';
$string['scopesitebuiltin'] = 'Site (built in)';
$string['redundantfiles_commit'] = 'Dropped support files identical to the inherited ones.';
$string['supportfilesheading'] = 'Support files';
$string['supportfilesinherited'] = 'Inherited, and not repeated here: {$a}';
$string['supportfilesinheritednone'] = 'Parent: not set';
$string['supportfilesinheritedfrom'] = '{$a->name} (from {$a->from})';
$string['supportfilesmarkown'] = 'No ancestor supplies a file of this name.';
$string['supportfilesmarkredundant'] = 'Identical to the copy inherited from {$a}, so it could be removed: this prototype would then follow that one when it changes.';
$string['supportfilesmarkshadowing'] = 'Differs from the copy inherited from {$a}, which it replaces.';
$string['supportfilesnone'] = 'This prototype supplies no support files of its own.';
$string['supportfilesownheading'] = 'This prototype\'s own files';

// Deleting a prototype, from the library page.
$string['deleteprototype'] = 'Delete';

$string['deleteprototypeconfirm'] = 'Delete the prototype "{$a->name}"?

Warning - this will delete all {$a->versions} version(s) of this prototype, irrecoverably.';
$string['deleteprototypedone'] = 'Prototype "{$a}" was deleted.';
$string['deleteprototypenotallowed'] = 'This prototype cannot be deleted.';
$string['deleteundeletable'] = 'Cannot be deleted: {$a}';
