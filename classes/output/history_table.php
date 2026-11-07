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
 * The change history of one prototype.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager\output;

use local_coderunner_manager\prototype;
use local_coderunner_manager\prototype_version;
use qtype_coderunner\v6\field_registry;

/**
 * Renders a prototype's versions, newest first, saying what each one changed.
 *
 * Each row is compared with the version before it and the *fields* that differ
 * are named, rather than the text of the overrides document: a reordered JSON
 * document is not a change anyone made, and a template of several hundred
 * lines is not readable as a diff in a table cell.
 *
 * The list is capped by default. A prototype under active development runs to
 * well over a hundred versions — the production python3_scratchpad is at 182 —
 * and nobody reads the hundred and eightieth.
 */
class history_table {
    /** @var int Versions shown before the list is capped. */
    const DEFAULT_LIMIT = 20;

    /**
     * Render the history.
     *
     * @param prototype $prototype the prototype.
     * @param prototype_version[] $versions its versions, newest first.
     * @param bool $canrestore true if the viewer may restore a version.
     * @param bool $showall true to show every version.
     * @return string the rendered HTML.
     */
    public static function render(
        prototype $prototype,
        array $versions,
        bool $canrestore,
        bool $showall = false
    ): string {
        $shown = $showall ? $versions : array_slice($versions, 0, self::DEFAULT_LIMIT, true);

        $out = \html_writer::tag('h3', get_string('changehistory', 'local_coderunner_manager'));

        if (count($versions) > count($shown)) {
            $out .= \html_writer::tag(
                'p',
                get_string('historytruncated', 'local_coderunner_manager', count($shown)) . ' ' .
                \html_writer::link(
                    new \moodle_url('/local/coderunner_manager/edit.php', [
                        'id' => $prototype->id,
                        'allversions' => 1,
                    ]),
                    get_string('showfullhistory', 'local_coderunner_manager', count($versions))
                ),
                ['class' => 'text-muted small']
            );
        }

        $out .= \html_writer::start_tag('table', ['class' => 'table table-sm']);
        $out .= \html_writer::start_tag('tr');
        foreach (['historyversion', 'historywhen', 'historywho', 'historywhat', 'historychanges'] as $header) {
            $out .= \html_writer::tag('th', get_string($header, 'local_coderunner_manager'));
        }
        if ($canrestore) {
            $out .= \html_writer::tag('th', '');
        }
        $out .= \html_writer::end_tag('tr');

        // Walked oldest first so each row can be compared with the one before
        // it, then reversed again so the newest is at the top.
        $rows = [];
        $previous = null;
        foreach (array_reverse($shown, true) as $version) {
            $rows[] = self::row($prototype, $version, $previous, $canrestore);
            $previous = $version;
        }
        $out .= implode('', array_reverse($rows));

        $out .= \html_writer::end_tag('table');
        return $out;
    }

    /**
     * One version's row.
     *
     * @param prototype $prototype the prototype.
     * @param prototype_version $version the version.
     * @param prototype_version|null $previous the version before it, if any.
     * @param bool $canrestore true if the viewer may restore it.
     * @return string the rendered row.
     */
    protected static function row(
        prototype $prototype,
        prototype_version $version,
        ?prototype_version $previous,
        bool $canrestore
    ): string {
        global $DB;

        $changes = $previous === null
            ? get_string('firstversion', 'local_coderunner_manager')
            : self::describe($version->overrides->differences_from($previous->overrides));

        $user = $version->usermodified ? $DB->get_record('user', ['id' => $version->usermodified]) : null;

        $row = \html_writer::start_tag('tr');
        $row .= \html_writer::tag('td', \html_writer::link(
            new \moodle_url('/local/coderunner_manager/edit.php', [
                'id' => $prototype->id,
                'diff' => $version->version,
            ]),
            $version->version,
            ['title' => get_string('viewdiff', 'local_coderunner_manager')]
        ));
        $row .= \html_writer::tag(
            'td',
            $version->timecreated ? userdate($version->timecreated, get_string('strftimedatetimeshort')) : '',
            ['class' => 'text-nowrap']
        );
        $row .= \html_writer::tag('td', $user ? s(fullname($user)) : '', ['class' => 'text-nowrap']);
        $row .= \html_writer::tag('td', s($version->commitmessage));
        $row .= \html_writer::tag('td', $changes, ['class' => 'small']);

        if ($canrestore) {
            $iscurrent = $prototype->currentversion && $prototype->currentversion->id === $version->id;
            $row .= \html_writer::tag('td', $iscurrent ? '' : \html_writer::link(
                new \moodle_url('/local/coderunner_manager/edit.php', [
                    'id' => $prototype->id,
                    'restore' => $version->version,
                    'sesskey' => sesskey(),
                ]),
                get_string('restoreversion', 'local_coderunner_manager'),
                ['class' => 'btn btn-secondary btn-sm']
            ));
        }

        return $row . \html_writer::end_tag('tr');
    }

    /**
     * Name the fields a version changed.
     *
     * Field labels, not values: a template or a set of template parameters is
     * far too long for a table cell, and knowing *which* settings moved is
     * what tells an author whether a version is the one they want.
     *
     * @param array $differences from override_set::differences_from().
     * @return string the rendered description.
     */
    protected static function describe(array $differences): string {
        if (!$differences) {
            return \html_writer::tag(
                'span',
                get_string('nochangesrecorded', 'local_coderunner_manager'),
                ['class' => 'text-muted']
            );
        }

        $classes = ['added' => 'text-success', 'removed' => 'text-danger', 'changed' => ''];
        $parts = [];
        foreach ($differences as $name => $difference) {
            $parts[] = \html_writer::tag(
                'span',
                s(field_registry::get($name)->label()),
                ['class' => $classes[$difference['change']] ?? '']
            );
        }
        return implode(', ', $parts);
    }
}
