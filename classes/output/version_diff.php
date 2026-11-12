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
 * What one version of a prototype changed.
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
 * Shows the values a version changed, beside the values it replaced.
 *
 * The history table names the fields; this is where the values live, because
 * a template runs to hundreds of lines and belongs on a page of its own rather
 * than in a cell. Each changed field is shown before and after, unchanged
 * fields are left out entirely, and long values scroll rather than pushing
 * everything else off the screen.
 */
class version_diff {
    /**
     * Render the comparison of one version with the one before it.
     *
     * @param prototype $prototype the prototype.
     * @param prototype_version $version the version to describe.
     * @param prototype_version|null $previous the version before it, if any.
     * @return string the rendered HTML.
     */
    public static function render(
        prototype $prototype,
        prototype_version $version,
        ?prototype_version $previous
    ): string {
        $back = \html_writer::link(
            new \moodle_url('/local/coderunner_manager/edit.php', ['id' => $prototype->id]),
            get_string('backtoprototype', 'local_coderunner_manager')
        );

        $heading = $previous === null
            ? get_string('diffoffirstversion', 'local_coderunner_manager', $version->version)
            : get_string('diffheading', 'local_coderunner_manager', (object) [
                'version' => $version->version,
                'previous' => $previous->version,
            ]);

        $out = \html_writer::tag('h3', $heading);
        if ($version->commitmessage !== '') {
            $out .= \html_writer::tag('p', s($version->commitmessage), ['class' => 'lead']);
        }

        $differences = $previous === null
            ? self::everything($version)
            : $version->changes_from($previous);

        if (!$differences) {
            $out .= \html_writer::tag('p', get_string('nochangesrecorded', 'local_coderunner_manager'));
            return $out . \html_writer::tag('p', $back);
        }

        $out .= \html_writer::start_tag('table', ['class' => 'table']);
        $out .= \html_writer::start_tag('tr');
        foreach (['difffield', 'diffbefore', 'diffafter'] as $header) {
            $out .= \html_writer::tag('th', get_string($header, 'local_coderunner_manager'));
        }
        $out .= \html_writer::end_tag('tr');

        foreach ($differences as $name => $difference) {
            $out .= \html_writer::start_tag('tr');
            $out .= \html_writer::tag(
                'td',
                s(self::change_label($name)),
                ['class' => 'text-nowrap fw-bold']
            );
            $out .= \html_writer::tag('td', self::change_value($name, $difference['from']), ['class' => 'w-50']);
            $out .= \html_writer::tag('td', self::change_value($name, $difference['to']), ['class' => 'w-50']);
            $out .= \html_writer::end_tag('tr');
        }

        $out .= \html_writer::end_tag('table');
        return $out . \html_writer::tag('p', $back);
    }

    /**
     * A changed item's value, before or after.
     *
     * The parent is stored as an id, which says nothing to a reader, so it is
     * shown by name. A prototype that has since been deleted keeps its id, as
     * the honest answer to what the version recorded.
     *
     * @param string $name the field name, or 'parentid'.
     * @param mixed $value the value.
     * @return string the rendered value.
     */
    protected static function change_value(string $name, $value): string {
        if ($name !== 'parentid') {
            return self::value($value);
        }
        if (empty($value)) {
            return self::value(get_string('noparent', 'local_coderunner_manager'));
        }

        $parent = (new \local_coderunner_manager\prototype_repository())->get((int) $value);
        return self::value($parent ? $parent->name : $value);
    }

    /**
     * A changed item's label.
     *
     * The parent is reported alongside the fields but is not one of them, so
     * it has no registry entry to take a label from.
     *
     * @param string $name the field name, or 'parentid'.
     * @return string the label.
     */
    protected static function change_label(string $name): string {
        return $name === 'parentid'
            ? get_string('parentprototype', 'local_coderunner_manager')
            : field_registry::get($name)->label();
    }

    /**
     * The first version has nothing to compare against, so everything it sets
     * counts as added.
     *
     * @param prototype_version $version the first version.
     * @return array in the shape override_set::differences_from() returns.
     */
    protected static function everything(prototype_version $version): array {
        $differences = [];
        foreach ($version->overrides->to_array() as $name => $value) {
            $differences[$name] = ['change' => 'added', 'from' => null, 'to' => $value];
        }
        return $differences;
    }

    /**
     * One value, rendered so that a long one does not take over the page.
     *
     * @param mixed $value the value, or null when the field was not set.
     * @return string the rendered value.
     */
    protected static function value($value): string {
        if ($value === null) {
            return \html_writer::tag(
                'em',
                get_string('diffnotset', 'local_coderunner_manager'),
                ['class' => 'text-muted']
            );
        }
        if ($value === '') {
            return \html_writer::tag(
                'em',
                get_string('diffempty', 'local_coderunner_manager'),
                ['class' => 'text-muted']
            );
        }

        return \html_writer::tag(
            'pre',
            s((string) $value),
            ['style' => 'max-height: 20em; overflow: auto; white-space: pre-wrap; margin: 0;']
        );
    }
}
