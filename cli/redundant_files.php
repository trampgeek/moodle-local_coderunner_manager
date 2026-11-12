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
 * Report, and optionally drop, support files a prototype could inherit.
 *
 * Decision 28: the migration gave each prototype a snapshot of its CodeRunner 5
 * question's files, so a prototype reparented under another owns copies of
 * nearly everything it could inherit. Dropping such a copy changes nothing
 * about how the prototype resolves today; what it changes is that an edit to
 * the ancestor's file starts reaching this prototype, which is the maintenance
 * cost the design exists to remove.
 *
 * Reports by default and changes nothing. Dropping happens only with --drop,
 * and goes through save_new_version(), so the previous file set stays in the
 * prototype's history and restore_version() puts it back.
 *
 * Usage:
 *   php redundant_files.php [--courseid=N | --prototypeid=N] [--drop]
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coderunner_manager\prototype_repository;
use local_coderunner_manager\redundant_files;

[$options, $unrecognised] = cli_get_params(
    ['courseid' => 0, 'prototypeid' => 0, 'drop' => false, 'help' => false],
    ['c' => 'courseid', 'p' => 'prototypeid', 'd' => 'drop', 'h' => 'help']
);

if ($options['help'] || $unrecognised) {
    cli_writeln("Report support files a prototype owns but could inherit unchanged.

Every prototype on the site is examined unless a course or a prototype is named.

Options:
  -c, --courseid=N     Only prototypes scoped to this course.
  -p, --prototypeid=N  Only this prototype.
  -d, --drop           Drop the redundant files, as a new version of each
                       prototype. Without this the script changes nothing.
  -h, --help           Print this help.
");
    exit(0);
}

$repo = new prototype_repository();
$analyser = new redundant_files($repo);

if ($options['prototypeid']) {
    $prototypes = [$repo->get_or_fail((int) $options['prototypeid'])];
} else {
    $conditions = $options['courseid'] ? ['courseid' => (int) $options['courseid']] : [];
    $prototypes = [];
    foreach ($DB->get_records(prototype_repository::PROTOTYPES, $conditions, 'courseid, name', 'id') as $row) {
        $prototypes[] = $repo->get_or_fail((int) $row->id);
    }
}

$totalredundant = 0;
$totaldropped = 0;
$examined = 0;

foreach ($prototypes as $prototype) {
    $rows = $analyser->analyse($prototype->id);
    if (!$rows) {
        continue;
    }
    $examined++;

    $redundant = array_keys(array_filter(
        $rows,
        fn($row) => $row['status'] === redundant_files::REDUNDANT
    ));
    $totalredundant += count($redundant);

    // A sweep lists only the prototypes with something to fix, or the output
    // buries them. Asked about one prototype, the breakdown is the answer and
    // is printed whether or not anything is redundant.
    if (!$redundant && !$options['prototypeid']) {
        continue;
    }

    cli_writeln(sprintf(
        '%s (id %d, course %d): %d of %d own files could be inherited',
        $prototype->name,
        $prototype->id,
        $prototype->courseid,
        count($redundant),
        count($rows)
    ));
    foreach ($rows as $name => $row) {
        $note = match ($row['status']) {
            redundant_files::REDUNDANT => 'identical to ' . $row['inheritedfrom'] . "'s",
            redundant_files::SHADOWING => 'differs from ' . $row['inheritedfrom'] . "'s, so kept",
            default => 'no ancestor has it',
        };
        cli_writeln(sprintf('    %-12s %-28s %s', $row['status'], $name, $note));
    }

    if ($options['drop']) {
        $dropped = $analyser->drop_redundant($prototype->id);
        $totaldropped += count($dropped);
        cli_writeln(sprintf(
            '    dropped %d file(s) as version %d',
            count($dropped),
            $repo->get_or_fail($prototype->id)->currentversion->version
        ));
    }
    cli_writeln('');
}

cli_writeln(str_repeat('-', 70));
cli_writeln(sprintf(
    'Examined %d prototype(s) with files; %d redundant file(s) found.',
    $examined,
    $totalredundant
));
if ($options['drop']) {
    cli_writeln(sprintf('Dropped %d file(s).', $totaldropped));
} else if ($totalredundant) {
    cli_writeln('Nothing was changed. Re-run with --drop to remove them.');
}
