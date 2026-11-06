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
 * The prototype editor's form.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager\form;

use local_coderunner_manager\prototype;
use qtype_coderunner\v6\form_section;
use qtype_coderunner\v6\override_set;
use qtype_coderunner\v6\question_settings;
use qtype_coderunner\v6\settings_form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Create or edit one prototype.
 *
 * The settings themselves are not laid out here: every field comes from the
 * registry through {@see settings_form}, which the question form uses too, so
 * that a field added to the registry appears in both without either being
 * edited. What this form adds is the prototype's own identity — its name, its
 * parent — and the record of the change being made.
 *
 * Saving writes a new version rather than altering the current one, so the
 * history is complete and a change can be compared against what it replaced.
 */
class prototype_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        // The prototype being edited, or null when creating one.
        $prototype = $this->_customdata['prototype'];
        // What the parent chain resolves to, which is what each field inherits.
        $inherited = $this->_customdata['inherited'];
        // What this prototype currently overrides of that.
        $own = $this->_customdata['own'];
        // Candidate parents, id => label, with cycles already excluded.
        $parents = $this->_customdata['parents'];

        $mform->addElement('hidden', 'id', $prototype ? $prototype->id : 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        // Identity.
        $mform->addElement('header', 'identityheader', get_string('identity', 'local_coderunner_manager'));
        $mform->setExpanded('identityheader');

        $mform->addElement('text', 'name', get_string('prototypename', 'local_coderunner_manager'), ['size' => 40]);
        $mform->setType('name', PARAM_RAW_TRIMMED);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'prototypename', 'local_coderunner_manager');

        $mform->addElement('select', 'parentid', get_string('parentprototype', 'local_coderunner_manager'), $parents);
        $mform->addHelpButton('parentid', 'parentprototype', 'local_coderunner_manager');

        $mform->addElement('text', 'summary', get_string('summary', 'local_coderunner_manager'), ['size' => 70]);
        $mform->setType('summary', PARAM_TEXT);
        $mform->addHelpButton('summary', 'summary', 'local_coderunner_manager');

        $mform->addElement(
            'textarea',
            'documentation',
            get_string('documentation', 'local_coderunner_manager'),
            ['rows' => 8, 'cols' => 80]
        );
        $mform->setType('documentation', PARAM_RAW);
        $mform->addHelpButton('documentation', 'documentation', 'local_coderunner_manager');

        // The settings, section by section, straight from the registry.
        foreach (self::sections() as $section) {
            if (!settings_form::fields_of($section)) {
                continue;
            }
            $header = 'section_' . $section->value;
            $mform->addElement('header', $header, get_string('section' . $section->value, 'local_coderunner_manager'));
            settings_form::add_section($mform, $section, $inherited, $own);
        }

        // What this change was.
        $mform->addElement('header', 'changeheader', get_string('thischange', 'local_coderunner_manager'));
        $mform->setExpanded('changeheader');
        $mform->addElement(
            'text',
            'commitmessage',
            get_string('commitmessage', 'local_coderunner_manager'),
            ['size' => 70]
        );
        $mform->setType('commitmessage', PARAM_TEXT);
        $mform->addHelpButton('commitmessage', 'commitmessage', 'local_coderunner_manager');

        $this->add_action_buttons(true, get_string('savenewversion', 'local_coderunner_manager'));

        if ($prototype) {
            $mform->setDefault('name', $prototype->name);
            $mform->setDefault('parentid', $prototype->parentid() ?? 0);
            $mform->setDefault('summary', $prototype->currentversion?->summary ?? '');
            $mform->setDefault('documentation', $prototype->currentversion?->documentation ?? '');
        }
    }

    /**
     * The settings sections the editor shows, in the order it shows them.
     *
     * Test case field switches are included: deciding which test case fields a
     * question offers is a prototype author's job, which is why the fields
     * exist. Instance-only sections are not, since a prototype has no answer.
     *
     * @return form_section[] the sections.
     */
    public static function sections(): array {
        return [
            form_section::TEMPLATE,
            form_section::PARAMETERS,
            form_section::GRADING,
            form_section::UI,
            form_section::TESTCASEFIELDS,
            form_section::SANDBOX,
            form_section::EXTRAS,
        ];
    }

    /**
     * Check the name is free in this scope.
     *
     * @param array $data submitted data.
     * @param array $files submitted files.
     * @return array errors, field name => message.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $repo = new \local_coderunner_manager\prototype_repository();
        $clash = $repo->name_clash($data['name'], (int) $data['courseid'], (int) $data['id']);
        if ($clash !== null) {
            $errors['name'] = get_string($clash, 'local_coderunner_manager', $data['name']);
        }

        return $errors;
    }
}
