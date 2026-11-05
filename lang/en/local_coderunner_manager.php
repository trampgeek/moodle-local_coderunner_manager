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
$string['headersummary'] = 'Summary';
$string['headerversion'] = 'Version';
$string['isbuiltin'] = 'built in';
$string['issitedefaults'] = 'site defaults';
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
$string['prototypelist_intro'] = 'The prototypes visible from this course, drawn as a tree from the site defaults down. Each prototype lists only the fields it changes; everything else it inherits from the prototype above it. This view is read-only.';
$string['scopesite'] = 'Site';
$string['prototypeinuse'] = 'Prototype "{$a}" cannot be deleted because other prototypes derive from it.';
$string['prototypenotfound'] = 'There is no CodeRunner prototype with id {$a}.';
