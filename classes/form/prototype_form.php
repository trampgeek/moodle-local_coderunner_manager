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
use local_coderunner_manager\redundant_files;
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
     * The menu naming the prototype this one inherits from.
     */
    const PARENT_FIELD = 'parentid';

    /**
     * The hidden no-submit button that rebuilds the form on a change of parent.
     *
     * Changing the parent changes every value the prototype inherits and every
     * note saying where one comes from, and none of that can be worked out in
     * the browser. The question form rebuilds itself the same way when its
     * prototype changes.
     */
    const RELOAD_BUTTON = 'parentreload';

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
        // The version the author is continuing from: the draft when there is
        // one, which is where $own came from too. Reading the summary and the
        // documentation off the published version instead showed an author
        // continuing a draft the values it was drafted from, and saving again
        // wrote those back over the draft's own.
        $editing = $this->_customdata['editing']
            ?? ($prototype?->draftversion ?? $prototype?->currentversion);
        // Candidate parents, id => label, with cycles already excluded.
        $parents = $this->_customdata['parents'];
        // True when the prototype may be read but not changed.
        $readonly = !empty($this->_customdata['readonly']);
        // The language the template is highlighted in, resolved from the whole
        // chain including this prototype's own overrides.
        $language = (string) ($this->_customdata['language'] ?? '');

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

        $mform->addElement(
            'select',
            self::PARENT_FIELD,
            get_string('parentprototype', 'local_coderunner_manager'),
            $parents
        );
        $mform->addHelpButton(self::PARENT_FIELD, 'parentprototype', 'local_coderunner_manager');

        // The menu's reload button is registered here: unconditionally, so that
        // nothing about the request can leave a press unrecognised, and before
        // the button is created further down, so that it marks itself as
        // skipping the form's client-side validation. The question form's
        // picker had both of those the wrong way round, and the second branch
        // of its *From another course* step dropped the registration
        // altogether: pressing the button saved the question.
        $mform->registerNoSubmitButton(self::RELOAD_BUTTON);

        $mform->addElement('text', 'summary', get_string('summary', 'local_coderunner_manager'), ['size' => 70]);
        $mform->setType('summary', PARAM_TEXT);
        $mform->addHelpButton('summary', 'summary', 'local_coderunner_manager');

        // Moodle's editor, so an author gets whichever one they have chosen
        // and the documentation carries a format like any other Moodle text.
        // Embedded files are off: documentation that depends on files in a
        // draft area would have to travel with the prototype through backup,
        // restore and export, which is M5's problem and not yet solved.
        $mform->addElement(
            'editor',
            'documentation',
            get_string('documentation', 'local_coderunner_manager'),
            ['rows' => 10],
            ['maxfiles' => 0, 'noclean' => true]
        );
        $mform->setType('documentation', PARAM_RAW);
        $mform->addHelpButton('documentation', 'documentation', 'local_coderunner_manager');

        // Support files get a section of their own rather than sitting under
        // Identity. They are not identity: name, parent, summary and
        // documentation are all *about* the prototype and none of them
        // inherits, while support files merge along the chain by filename,
        // which is the behaviour of the collected settings. Filed with the
        // former, the form showed only the prototype's own files with nothing
        // to say that its ancestors supply more -- so a prototype owning three
        // files and inheriting nineteen read as a prototype with three -- and
        // the section carried no change count, so there was nothing to see in
        // "what changed" either.
        $this->add_support_files_section($mform);

        // The settings, section by section, straight from the registry.
        foreach (self::sections() as $section) {
            if (!settings_form::fields_of($section)) {
                continue;
            }
            $header = settings_form::section_header($section);
            // The heading counts the fields this prototype changes, so a
            // closed section cannot hide one, and the component adds the
            // legend explaining the marks beside those fields.
            $mform->addElement(
                'header',
                $header,
                settings_form::section_label($section, $own, $inherited)
            );
            settings_form::add_section($mform, $section, $inherited, $own, $language);
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

        if ($readonly) {
            // Every control is frozen rather than hidden, so the settings and
            // where each came from stay visible.
            $mform->hardFreeze();
        } else {
            // Publishing stays the primary action. Saving a draft is offered
            // beside it for a prototype with dependants, where an author may
            // want to look at the change before every question takes it up.
            $buttons = [
                $mform->createElement(
                    'submit',
                    'submitbutton',
                    get_string('savenewversion', 'local_coderunner_manager')
                ),
                $mform->createElement('cancel'),
            ];

            if ($prototype) {
                // Only an existing prototype can hold a draft: a new one has
                // nothing published for questions to go on resolving against.
                array_splice($buttons, 1, 0, [$mform->createElement(
                    'submit',
                    'savedraft',
                    get_string('savedraft', 'local_coderunner_manager'),
                    ['class' => 'btn-secondary']
                )]);
            }
            $mform->addGroup($buttons, 'buttonar', '', ' ', false);
            $mform->closeHeaderBefore('buttonar');

            // Changing the parent rebuilds the form, so that the values the
            // prototype inherits, and the notes saying which ancestor supplies
            // each one, are the new parent's. Without this the form answered
            // for the old parent until the prototype was saved and reloaded.
            //
            // Added after the save buttons, although the menu it belongs to is
            // at the top of the form: a browser's implicit submission presses
            // the *first* submit button, so a hidden one any earlier would
            // rebuild the form instead of saving it whenever an author pressed
            // Enter in a text field. The script finds it by id, so where it
            // sits makes no difference to it.
            $mform->addElement(
                'submit',
                self::RELOAD_BUTTON,
                get_string('parentreload', 'local_coderunner_manager'),
                ['class' => 'visually-hidden']
            );
        }

        // The parent the page worked out, which is the submitted one on a
        // reload and the stored one otherwise. Set for a new prototype as well
        // as an existing one: a form that showed Site defaults' values as
        // inherited while the menu read "No parent" saved the second.
        $mform->setDefault(self::PARENT_FIELD, $this->_customdata['parentid'] ?? 0);

        if ($prototype) {
            $mform->setDefault('name', $prototype->name);
            $mform->setDefault('summary', $editing?->summary ?? '');
            $mform->setDefault('documentation', [
                'text' => $editing?->documentation ?? '',
                'format' => $editing?->documentationformat ?? FORMAT_HTML,
            ]);
        }
    }

    /**
     * The support files section: the prototype's own files, what it inherits,
     * and which of its own it need not have.
     *
     * **The marks are read from the draft area, not from the stored version.**
     * The file manager works through a draft, so what the author is looking at
     * is not yet any version's file set; marks taken from the stored version
     * would be stale the moment a file was added or removed, and would be
     * wrong on a prototype whose parent has just been changed in the menu.
     *
     * **The inherited files are listed but not repeated in the manager.** The
     * manager holds what this prototype supplies, and seeding it with the
     * ancestors' files would make the author own a copy of each the moment they
     * saved — which is exactly the state decision 28 exists to undo. So they
     * are named beside it instead.
     *
     * @param \MoodleQuickForm $mform the form being built.
     */
    protected function add_support_files_section(\MoodleQuickForm $mform): void {
        $analyser = new redundant_files();
        $own = $this->draft_files();
        $inherited = $analyser->files_from_chain($this->form_parentid());
        $rows = $analyser->classify($own, $inherited);

        // Counted the way every other section counts: what this layer does
        // that the one above does not. A redundant copy changes nothing, so it
        // is not a change, which is the rule settings_form::changed_count()
        // settled for overrides.
        $changed = 0;
        foreach ($rows as $row) {
            if ($row['status'] !== redundant_files::REDUNDANT) {
                $changed++;
            }
        }
        $label = get_string('supportfilesheading', 'local_coderunner_manager');
        if ($changed > 0) {
            $label .= ' · ' . get_string('sectionchanged', 'qtype_coderunner', $changed);
        }

        $mform->addElement('header', 'supportfilesheader', $label);

        $mform->addElement(
            'filemanager',
            'supportfiles',
            get_string('supportfilesownheading', 'local_coderunner_manager'),
            null,
            ['subdirs' => 0, 'accepted_types' => '*']
        );
        $mform->addHelpButton('supportfiles', 'supportfiles', 'local_coderunner_manager');

        // What the chain supplies that this prototype does not have to.
        if ($inherited) {
            $names = [];
            foreach ($inherited as $name => $layer) {
                if (!isset($rows[$name])) {
                    $names[] = get_string('supportfilesinheritedfrom', 'local_coderunner_manager', [
                        'name' => $name,
                        'from' => $layer['from'],
                    ]);
                }
            }
            $text = $names
                ? get_string('supportfilesinherited', 'local_coderunner_manager', implode(', ', $names))
                : get_string('supportfilesinheritednone', 'local_coderunner_manager');
        } else {
            $text = get_string('supportfilesinheritednone', 'local_coderunner_manager');
        }
        $mform->addElement('static', 'supportfilesinheritednote', '', s($text));

        // One line per own file, saying what it does relative to the chain.
        $notes = [];
        foreach ($rows as $name => $row) {
            $reason = match ($row['status']) {
                redundant_files::REDUNDANT => get_string(
                    'supportfilesmarkredundant',
                    'local_coderunner_manager',
                    $row['inheritedfrom']
                ),
                redundant_files::SHADOWING => get_string(
                    'supportfilesmarkshadowing',
                    'local_coderunner_manager',
                    $row['inheritedfrom']
                ),
                default => get_string('supportfilesmarkown', 'local_coderunner_manager'),
            };
            $notes[] = \html_writer::tag('li', \html_writer::tag('strong', s($name)) . ' — ' . s($reason));
        }
        $mform->addElement(
            'static',
            'supportfilesmarks',
            '',
            $notes
                ? \html_writer::tag('ul', implode('', $notes), ['class' => 'supportfilemarks'])
                : s(get_string('supportfilesnone', 'local_coderunner_manager'))
        );
    }

    /**
     * The files currently in the support files draft area, keyed by filename.
     *
     * @return \stored_file[] the files, or an empty array when there is no area.
     */
    protected function draft_files(): array {
        global $USER;

        $draftitemid = (int) ($this->_customdata['draftitemid'] ?? 0);
        if (!$draftitemid) {
            return [];
        }

        $files = [];
        foreach (get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        ) as $file) {
            $files[$file->get_filename()] = $file;
        }
        return $files;
    }

    /**
     * The parent this form is working against, as its caller resolved it.
     *
     * Named apart from the static effective_parentid(), which is what edit.php
     * calls to work the answer out before building the form; by here it is
     * settled and arrives as customdata.
     *
     * @return int|null the parent prototype id, or null for a root prototype.
     */
    protected function form_parentid(): ?int {
        $parentid = (int) ($this->_customdata['parentid'] ?? 0);
        return $parentid ?: null;
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
     * The parent whose chain the form shows as inherited.
     *
     * The submission wins when there is one, so that the no-submit reload
     * after a change of parent answers for the new parent rather than for what
     * is stored. A prototype being created inherits from Site defaults unless
     * the author says otherwise, which is where design section 6 puts the root
     * of every chain.
     *
     * @param prototype|null $prototype the prototype being edited, or null
     *      when creating one.
     * @param int|null $submitted the parent the form submitted, or null when
     *      the form has not been submitted. Zero means *No parent*.
     * @param int|null $sitedefaultsid the Site defaults prototype's id.
     * @return int|null the parent's id, or null for a root.
     */
    public static function effective_parentid(
        ?prototype $prototype,
        ?int $submitted,
        ?int $sitedefaultsid
    ): ?int {
        if ($submitted !== null) {
            return $submitted ?: null;
        }
        if ($prototype) {
            // The draft's parent when there is a draft, since that is the
            // version being continued.
            $editing = $prototype->draftversion ?? $prototype->currentversion;
            return $editing?->parentid;
        }
        return $sitedefaultsid;
    }

    /**
     * Ask the page for the script that rebuilds the form on a change of parent.
     *
     * The script is the question type's, which owns the form component this
     * editor is built from; see decision 22 for why that coupling exists.
     *
     * Called by the page rather than from the form definition, so that building
     * a form has no side effects on $PAGE and can be done in a test.
     */
    public static function require_reload_on_change(): void {
        global $PAGE;

        $PAGE->requires->js_call_amd(
            'qtype_coderunner/prototypereload',
            'init',
            ['id_' . self::PARENT_FIELD, 'id_' . self::RELOAD_BUTTON]
        );
    }

    /**
     * Check the name is free in this scope, and that the documents every
     * question beneath this prototype will merge are readable.
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

        return $errors + $this->json_errors($data);
    }

    /**
     * Unreadable merging documents, which a prototype must not be saved with.
     *
     * The template parameters and the UI parameters of every layer are merged
     * into every question built on it, so a prototype holding a document that
     * cannot be read breaks all of them at once — and the question form, which
     * has validated both since CodeRunner 5, would report the breakage against
     * the question rather than against the prototype that caused it. This is a
     * readability check and nothing more: there are unlimited ways to write a
     * prototype that breaks its children, and only this one is cheap to catch.
     *
     * Two cases are not checked, because in them the document is not JSON yet.
     * Template parameters written in a language other than *None* are a program
     * that produces JSON when it is run — a Python one, commonly. And either
     * document may hold Twig, which is JSON only once it has been rendered,
     * which needs a question, a seed and a student.
     *
     * @param array $data submitted data.
     * @return array errors, field name => message.
     */
    protected function json_errors(array $data): array {
        $errors = [];

        $fields = settings_form::fields_of(form_section::PARAMETERS)
            + settings_form::fields_of(form_section::UI);
        foreach ($fields as $name => $field) {
            if (!$field->merges_with_inherited() || !isset($data[$name])) {
                continue;
            }

            $document = trim((string) $data[$name]);
            if ($document === '' || self::holds_twig($document)) {
                continue;
            }
            if ($name === 'templateparams' && ($data['templateparamslang'] ?? 'None') !== 'None') {
                continue;
            }

            if (!is_array(json_decode($document, true))) {
                $errors[$name] = get_string('invalidjson', 'local_coderunner_manager');
            }
        }

        return $errors;
    }

    /**
     * Whether a document holds Twig, and so cannot be read as JSON until it
     * has been rendered.
     *
     * @param string $document the document.
     * @return bool true if it holds Twig.
     */
    protected static function holds_twig(string $document): bool {
        return str_contains($document, '{{') || str_contains($document, '{%');
    }
}
