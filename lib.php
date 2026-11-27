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
 * Library functions for the CodeRunner prototype manager.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Put *Prototype manager* in the secondary navigation of the pages it is
 * wanted from.
 *
 * **Why a local plugin's hook and not the question type's.** Reaching the
 * library took three clicks from a question -- out to the course, into
 * *CodeRunner management*, then on to the manager -- because the question
 * type's `extend_navigation_course()` can only reach a course's own
 * administration node, and a question is edited in a question bank *module*,
 * whose secondary navigation is that activity's. Of the hooks that run for
 * every context, `extend_settings_navigation()` is offered to `local` plugins
 * alone (`settings_navigation::load_local_plugin_settings()`), and this plugin
 * is one -- so the shortest road to the library runs through here rather than
 * through the question type, which cannot get there from a module page at all.
 *
 * **The node has to be hung under the right parent or it is silently
 * dropped.** The secondary navigation does not render the settings tree; it
 * copies nodes out of one branch of it, chosen by the kind of page --
 * `modulesettings` for an activity, `courseadmin` for a course, `frontpage`
 * for the site home (`secondary::load_module_navigation()` and its siblings).
 * A node added at the root of `$nav` therefore appears nowhere at all, which
 * is what the first attempt at this did: the hook ran, the node existed, and
 * three pages showed no link.
 *
 * **Those three branches and no others.** Not every page: the node would
 * otherwise follow an author onto their dashboard and into every forum they
 * read. A module context is the one that matters, because that is where a
 * question bank lives and so where a question is edited.
 *
 * @param settings_navigation $nav the navigation tree being built.
 * @param context $context the context of the page it is being built for.
 */
function local_coderunner_manager_extend_settings_navigation(
    settings_navigation $nav,
    context $context
): void {
    $coursecontext = $context->get_course_context(false);

    // Whichever branch this page's secondary navigation is built from. The
    // order is narrowest first, because a module page's tree holds a course
    // node as well and the activity's own branch is the one on screen.
    $parent = $nav->find('modulesettings', navigation_node::TYPE_SETTING)
        ?: $nav->find('courseadmin', navigation_node::TYPE_COURSE)
        ?: $nav->find('frontpage', navigation_node::TYPE_SETTING);
    if (!$parent) {
        return;
    }

    // The site course is not a course here: the front page's context is
    // `context_course` for the site course, so asking whether this page sits
    // in a course is not the question. What matters is whether the link will
    // carry a course id, because the library reads site scope and course
    // scope under different capability contexts -- `index.php` asks at system
    // level when it is given no course id, and in the course when it is.
    $courseid = $coursecontext ? (int) $coursecontext->instanceid : (int) SITEID;
    $issite = $courseid === (int) SITEID;

    // The capability the destination will ask for, and in the context it will
    // ask in, so that a node is never offered to somebody that page refuses.
    // Checked in the course context for a course, and at system level for the
    // site's own library -- which in practice means administrators, since the
    // capability is declared at course level and granting it on the front page
    // course does not grant it over the site.
    if (!has_capability('qtype/coderunner:management', $issite ? context_system::instance() : $coursecontext)) {
        return;
    }

    $params = $issite ? [] : ['courseid' => $courseid];

    $node = navigation_node::create(
        get_string('prototypelist', 'local_coderunner_manager'),
        new moodle_url('/local/coderunner_manager/index.php', $params),
        navigation_node::TYPE_SETTING,
        null,
        'coderunnerprototypes',
        new pix_icon('i/settings', '')
    );

    // Directly after *CodeRunner management*, where there is one. This
    // hook runs last of all (`settings_navigation::initialise()` calls
    // `load_local_plugin_settings()` after everything else), and the secondary
    // navigation appends whatever it does not recognise, so left alone the
    // node lands at the end of the menu: the author found *CodeRunner
    // management* eighth and this twelfth, with ten unrelated items between
    // two entries that are a pair. Adjacent, they explain each other.
    $parent->add_node($node, local_coderunner_manager_node_after($parent, 'coderunnermanagement'));
}

/**
 * The key of whatever follows the given node among its siblings.
 *
 * `navigation_node::add_node()` can only insert *before* a key, so placing a
 * node after another means naming that other's successor. Null where there is
 * no successor -- the node is last, or is not there at all -- which `add_node()`
 * reads as *append*, and appending after the last node is the same position.
 *
 * @param navigation_node $parent the node whose children to look through.
 * @param string $key the node to place something after.
 * @return string|int|null the successor's key, or null to append.
 */
function local_coderunner_manager_node_after(navigation_node $parent, string $key) {
    $keys = $parent->children->get_key_list();
    $at = array_search($key, $keys, true);

    return $at === false ? null : ($keys[$at + 1] ?? null);
}
