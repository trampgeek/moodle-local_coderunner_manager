<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A backup element that carries a prototype version's support files inline.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * A prototype version element whose support files travel in its own XML.
 *
 * **Why the files are not annotated in the ordinary way.** Moodle's file
 * annotation puts a file's id in the inforef of the task that wrote it, and
 * `restore_load_included_files` loads a file from `files.xml` only when its ref
 * was annotated in an inforef it has read, or when the file's component is
 * `question` or begins with `qtype` -- core's own exception, which exists
 * because the questions step runs in the **final task, which writes no
 * inforef at all**. A prototype reaches the backup through that very step, at
 * the question attachment point, and its component is
 * `local_coderunner_manager`. So its files reached `files.xml` and were never
 * loaded: a quiz-only backup, and any prototype a borrowed question needed,
 * restored without the support files its questions cannot run without.
 *
 * Getting past that by renaming the component to `qtype_coderunner` would be
 * filing the manager's files under another plugin to satisfy a hard-coded
 * list, and would need a migration of every existing file. Carrying the bytes
 * in our own XML -- as Moodle XML question export does for a question's own
 * files -- needs neither, and does not care which task writes it.
 *
 * So each version's files are encoded into one `inlinefiles` element here, at
 * the moment the row is written, and the restore writes them back. The context
 * to read them from arrives in the row as `filecontextid`, because a
 * prototype's files live in the context of the course that owns it, which is
 * not always the course being backed up.
 */
class backup_prototype_version_element extends backup_nested_element {
    /**
     * Fill this occurrence's values, the inline files among them.
     *
     * @param array|object $values the source row.
     */
    public function fill_values($values) {
        parent::fill_values($values);

        $row = (array) $values;
        $element = $this->get_final_element('inlinefiles');
        if ($element === null) {
            return;
        }
        $element->set_value(self::encode_files(
            (int) ($row['filecontextid'] ?? 0),
            (int) ($row['id'] ?? 0)
        ));
    }

    /**
     * One version's support files, as a JSON document of base64 content.
     *
     * @param int $contextid the context the files are stored in.
     * @param int $versionid the version whose files they are.
     * @return string the document, or '' where there are none.
     */
    protected static function encode_files(int $contextid, int $versionid): string {
        if ($contextid === 0 || $versionid === 0) {
            return '';
        }

        $files = [];
        foreach (get_file_storage()->get_area_files(
            $contextid,
            \local_coderunner_manager\prototype_repository::COMPONENT,
            \local_coderunner_manager\prototype_repository::FILEAREA,
            $versionid,
            'filename',
            false
        ) as $file) {
            $files[] = [
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'content' => base64_encode($file->get_content()),
            ];
        }

        return $files ? json_encode($files) : '';
    }
}
