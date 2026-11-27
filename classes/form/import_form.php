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
 * Choose a prototype export file to import.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager\form;

/**
 * The first of the import's two steps: which file.
 *
 * Deliberately one control. What the file would do is the *next* page's
 * business, because it cannot be known until the file has been read, and
 * nothing is written until the author has seen it.
 */
class import_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement(
            'filepicker',
            'importfile',
            get_string('importfile', 'local_coderunner_manager'),
            null,
            // Moodle XML only, which is what the export writes. The filter is
            // the picker's own, so a file of another type cannot be chosen
            // rather than being chosen and then refused.
            ['accepted_types' => ['.xml']]
        );
        $mform->addRule('importfile', null, 'required', null, 'client');
        $mform->addHelpButton('importfile', 'importfile', 'local_coderunner_manager');

        $this->add_action_buttons(true, get_string('importpreviewbutton', 'local_coderunner_manager'));
    }
}
