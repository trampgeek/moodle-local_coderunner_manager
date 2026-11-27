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
 * What an import would do, shown to the author before it does it.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager\output;

use qtype_coderunner\v6\prototype_import;

/**
 * The import preview: one row per prototype, and what will become of it.
 *
 * **A table of rows rather than a diff of fields**, which is the choice worth
 * recording. A prototype's content is a template of some hundreds of lines and
 * a set of support files, and a field-by-field diff of a library release would
 * be pages of it -- the author would scroll past it, which is the same as not
 * showing it. What they need before saying yes is the shape of the change:
 * which of their prototypes gain a version, which arrive new, which the file
 * leaves alone, and whether anything is refused. Per-version detail already
 * exists one click away, in each prototype's own change history, and that is
 * where a reader who wants the fields should end up.
 *
 * The rows keep the file's order, which is parents before children, so a
 * library reads as its author assembled it.
 */
class import_plan {
    /**
     * The preview page: the plan, and the two buttons under it.
     *
     * @param array $plan as {@see prototype_import::plan()} returns it.
     * @param int $draftid the draft area holding the file, carried to the
     *      confirmation so that the file is read again rather than remembered.
     * @param \moodle_url $url this page.
     * @param \moodle_url $listurl the library, to go back to.
     * @return string the rendered page.
     */
    public static function render(array $plan, int $draftid, \moodle_url $url, \moodle_url $listurl): string {
        global $OUTPUT;

        if ($plan['problem'] !== null) {
            // Nothing to show and nothing to confirm: the file is not a
            // library. Said here rather than as an exception, because choosing
            // the wrong file is a slip.
            return $OUTPUT->notification(
                get_string('importproblem_' . $plan['problem'], 'local_coderunner_manager'),
                \core\output\notification::NOTIFY_ERROR
            ) . $OUTPUT->single_button($listurl, get_string('back'), 'get');
        }

        $refusals = array_filter(array_column($plan['entries'], 'reason'));

        $out = \html_writer::tag('p', get_string('importplanintro', 'local_coderunner_manager'));
        $out .= self::table($plan['entries']);

        if (!empty($plan['shipped'])) {
            // Said rather than passed over: the file names more prototypes
            // than this table has rows, and an author who counted them would
            // otherwise wonder which went missing.
            $out .= \html_writer::tag(
                'p',
                $plan['shipped'] === 1
                    ? get_string('importignoringbuiltin', 'local_coderunner_manager')
                    : get_string('importignoringbuiltins', 'local_coderunner_manager', $plan['shipped']),
                ['class' => 'text-muted']
            );
        }

        foreach ($refusals as $reason) {
            $out .= $OUTPUT->notification($reason, \core\output\notification::NOTIFY_ERROR);
        }

        if ($refusals) {
            // Refusing one prototype refuses the file: half an imported
            // library is worse than none, which is Moodle's own stop-on-error
            // rule. So there is nothing to confirm.
            $out .= $OUTPUT->notification(
                get_string('importrefused', 'local_coderunner_manager'),
                \core\output\notification::NOTIFY_WARNING
            );
            return $out . $OUTPUT->single_button($listurl, get_string('back'), 'get');
        }

        $confirmurl = new \moodle_url($url, [
            'draftid' => $draftid,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]);

        return $out . \html_writer::div(
            $OUTPUT->single_button($confirmurl, get_string('importconfirmbutton', 'local_coderunner_manager'))
                . $OUTPUT->single_button($listurl, get_string('cancel'), 'get'),
            'd-flex gap-2'
        );
    }

    /**
     * The plan as a table.
     *
     * @param array $entries the plan's entries.
     * @return string the rendered table.
     */
    private static function table(array $entries): string {
        $table = new \html_table();
        $table->attributes['class'] = 'table table-sm table-striped';
        $table->head = [
            get_string('headerprototype', 'local_coderunner_manager'),
            get_string('importheaderhere', 'local_coderunner_manager'),
            get_string('importheaderfile', 'local_coderunner_manager'),
            get_string('importheaderaction', 'local_coderunner_manager'),
        ];

        foreach ($entries as $entry) {
            $table->data[] = [
                s($entry['name']),
                $entry['localversion'] === null
                    ? \html_writer::tag('span', get_string('importnothere', 'local_coderunner_manager'), [
                        'class' => 'text-muted',
                    ])
                    : get_string('importversion', 'local_coderunner_manager', $entry['localversion']),
                get_string('importversion', 'local_coderunner_manager', $entry['fileversion']),
                self::action_cell($entry['action']),
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * What will become of one prototype, as a badge.
     *
     * Coloured, because the three outcomes are not equally interesting: an
     * author scanning a release wants to see at a glance which of their own
     * prototypes are about to change.
     *
     * @param string $action one of prototype_import's action constants.
     * @return string the rendered cell.
     */
    private static function action_cell(string $action): string {
        $classes = [
            prototype_import::CREATE => 'bg-success',
            prototype_import::VERSION => 'bg-primary',
            prototype_import::UNCHANGED => 'bg-secondary',
            prototype_import::REFUSE => 'bg-danger',
        ];

        return \html_writer::tag(
            'span',
            get_string('importaction_' . $action, 'local_coderunner_manager'),
            ['class' => 'badge ' . ($classes[$action] ?? 'bg-secondary')]
        );
    }

    /**
     * What an import did, as one sentence for the library page.
     *
     * @param array $done as {@see prototype_import::apply()} returns it.
     * @return array{message: string, refused: bool} the notification's text,
     *      and whether it is a failure.
     */
    public static function outcome(array $done): array {
        if ($done['problem'] !== null) {
            return [
                'message' => get_string('importproblem_' . $done['problem'], 'local_coderunner_manager'),
                'refused' => true,
            ];
        }
        if ($done['refused']) {
            return [
                'message' => get_string('importrefused', 'local_coderunner_manager'),
                'refused' => true,
            ];
        }

        return [
            'message' => get_string('importdone', 'local_coderunner_manager', (object) [
                'created' => count($done['created']),
                'versioned' => count($done['versioned']),
                'unchanged' => count($done['unchanged']),
            ]),
            'refused' => false,
        ];
    }
}
