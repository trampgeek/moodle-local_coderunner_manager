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
 * The pair of menus by which a prototype is chosen from some course's library.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

/**
 * *From* ‹course› *use* ‹prototype›: the control by which one prototype is
 * chosen out of some course's library.
 *
 * **Two forms show it, so it lives here.** The question form picks the
 * prototype a question is built on; the prototype editor picks the parent a
 * prototype inherits from. The two are the same question asked about different
 * things, and they were allowed to drift apart once before — one showed the
 * library as an indented tree and the other as a flat list of bare names,
 * which an author noticed and no test did. `as_options()` settled that half by
 * being shared; this settles the rest.
 *
 * It lives in the manager rather than in the question type because the
 * dependency runs that way: the question type requires the manager, so only
 * the manager can hold something both use. Design section 5's split — the
 * scope rules and the queries here, the rendering there — holds for everything
 * each form does with the chosen value; what is shared is the pair of menus
 * and the agreement between them.
 *
 * **What the caller still owns**, because it differs between the two: the
 * label on the row, the help button, the required rule, anything else on the
 * row (the question form puts the chain's breadcrumb there), and what the
 * chosen value means.
 *
 * The course menu is drawn **only where it would hold more than one entry**,
 * so an author with rights in one course sees a single menu, which is nearly
 * every author. *Site* is never one of its entries: the built-in prototypes
 * are site-scope and are offered inside the question's own course, where a
 * course that writes most of its questions directly on python3 needs them
 * without changing scope first.
 */
class scope_picker {
    /** @var string The field holding the chosen prototype. */
    private string $field;

    /** @var string The field holding the course whose library is on offer. */
    private string $coursefield;

    /** @var int[] Prototype ids this picker must not offer. */
    private array $exclude;

    /** @var string|null The label of a leading option, or null for *Choose...*. */
    private ?string $rootlabel;

    /** @var string|int The value that leading option carries. */
    private string|int $rootvalue;

    /** @var prototype_candidates The scope rules. */
    private prototype_candidates $candidates;

    /**
     * @param string $field the name of the prototype menu, which is the value
     *      the form saves.
     * @param string $coursefield the name of the course menu beside it.
     * @param int[] $exclude prototype ids to leave out, which is how the
     *      prototype editor keeps a prototype from inheriting from itself or
     *      from its own descendants.
     * @param string|null $rootlabel the leading option's label. Null gives
     *      *Choose...* with an empty value, which is an unanswered required
     *      field; the editor passes *No parent*, which is a real choice.
     * @param string|int $rootvalue the value that option carries.
     * @param prototype_candidates|null $candidates the scope rules, or a new
     *      set of them.
     */
    public function __construct(
        string $field,
        string $coursefield,
        array $exclude = [],
        ?string $rootlabel = null,
        string|int $rootvalue = '',
        ?prototype_candidates $candidates = null
    ) {
        $this->field = $field;
        $this->coursefield = $coursefield;
        $this->exclude = array_map('intval', $exclude);
        $this->rootlabel = $rootlabel;
        $this->rootvalue = $rootvalue;
        $this->candidates = $candidates ?? new prototype_candidates();
    }

    /**
     * The elements of the row, and the separators between them.
     *
     * Returned rather than added, so that the caller can put its own elements
     * on the row and give the group its own label: formslib has no way to add
     * to a group once it is made.
     *
     * @param \MoodleQuickForm $mform the form being built.
     * @param int $courseid the course the thing being edited belongs to.
     * @param int|null $selectedid the prototype currently chosen.
     * @return array [the elements, the separators between them].
     */
    public function elements(\MoodleQuickForm $mform, int $courseid, ?int $selectedid): array {
        $scopes = $this->scopes($courseid, $selectedid);
        $scopeid = $this->scope_courseid($courseid, $selectedid);

        $elements = [];
        $separators = [];

        if (count($scopes) > 1) {
            // The leading word is an element rather than a separator, because
            // a group's separators go *between* its elements and there is
            // nothing to the left of the first one. A static carries it and
            // adds nothing to the submission.
            $elements[] = $mform->createElement(
                'static',
                $this->coursefield . '_from',
                '',
                get_string('scopefrom', 'local_coderunner_manager')
            );
            $separators[] = '&nbsp;';
            $elements[] = $mform->createElement(
                'select',
                $this->coursefield,
                '',
                $scopes,
                ['aria-label' => get_string('scopecourse', 'local_coderunner_manager')]
            );
            $separators[] = '&nbsp;' . get_string('scopeuse', 'local_coderunner_manager') . '&nbsp;';

            // Which scope this render is showing, so that the next submission
            // can be read correctly; see scope_changed().
            $elements[] = $mform->createElement('hidden', $this->coursefield . '_rendered', $scopeid);
            $separators[] = '';

            // Both fields are this component's, so it declares their types
            // rather than leaving two callers to remember. The chosen
            // prototype's type stays the caller's: it is the value they save.
            $mform->setType($this->coursefield, PARAM_INT);
            $mform->setType($this->coursefield . '_rendered', PARAM_INT);
        }

        $elements[] = $this->menu_element($mform, $this->optgroups($courseid, $selectedid, $scopeid));
        $separators[] = '&nbsp;&nbsp;';

        return [$elements, $separators];
    }

    /**
     * Whether this submission came from a render of a *different* scope.
     *
     * **A caller whose leading option is a real answer has to ask.** The
     * prototype editor's is *No parent*, which an author may mean; and it is
     * also what the prototype menu shows them while they are looking at a
     * course that does not hold the current parent. The two submissions are
     * identical — `parentid` of 0 — so without this the parent of a prototype
     * whose author merely glanced at another course's library would be read as
     * deliberately cleared, and saving would silently make the prototype a
     * root. Seen in the browser, which is the only place it shows.
     *
     * So the render records the scope it drew, and a submission from another
     * scope is one whose prototype is stale by construction: the author
     * changed the course menu, and whatever the prototype menu posted was the
     * answer to the previous scope's question. The caller keeps what it had.
     *
     * The question form does not need to ask, because its leading option is an
     * unanswered required field: a stale one cannot be saved and is refused,
     * and what it falls back to is the question's stored prototype.
     *
     * @return bool true if the course menu changed in this submission.
     */
    public function scope_changed(): bool {
        return self::scope_changed_for($this->coursefield);
    }

    /**
     * The same question, asked by a page that has no picker to hand.
     *
     * @param string $coursefield the name of the course menu.
     * @return bool true if the course menu changed in this submission.
     */
    public static function scope_changed_for(string $coursefield): bool {
        $rendered = optional_param($coursefield . '_rendered', 0, PARAM_INT);
        $submitted = optional_param($coursefield, 0, PARAM_INT);

        return $rendered !== 0 && $submitted !== 0 && $rendered !== $submitted;
    }

    /**
     * Settle what each menu shows, once the form holds its data.
     *
     * Called from the form's `definition_after_data()`, and it has to be: on a
     * no-submit reload formslib populates every element from the submission, in
     * preference to the stored values, and the submission is one render out of
     * date. An author who looks at another course and comes back submits the
     * *leading option* as the prototype, because that is what the menu showed
     * them while they were away, and the stored value would then read as
     * *Choose...* in a scope that holds it.
     *
     * This settles the rendering only. What is saved is whatever the author
     * submits, and the caller's own validation decides whether that will do.
     *
     * @param \MoodleQuickForm $mform the form, with its data.
     * @param string $group the name of the group the elements are in.
     * @param int $courseid the course the thing being edited belongs to.
     * @param int|null $selectedid the prototype currently chosen.
     */
    public function settle(\MoodleQuickForm $mform, string $group, int $courseid, ?int $selectedid): void {
        if (!$mform->elementExists($group)) {
            return;
        }

        $scopeid = $this->scope_courseid($courseid, $selectedid);
        $inscope = $this->selected_in_scope($selectedid, $this->optgroups($courseid, $selectedid, $scopeid));

        foreach ($mform->getElement($group)->getElements() as $element) {
            if ($element->getName() === $this->field) {
                $element->setSelected((string) ($inscope ?? $this->rootvalue));
            } else if ($element->getName() === $this->coursefield) {
                $element->setSelected((string) $scopeid);
            } else if ($element->getName() === $this->coursefield . '_rendered') {
                // Forced here for the same reason the menus are, and it is
                // the whole point of the field: formslib fills a hidden
                // element from the submission too, so the value created with
                // it was overwritten by the scope of the render *before* this
                // one. Two scope changes in a row then looked like none at
                // all, and the parent the author had come back to was read as
                // deliberately cleared. Found in the browser; the field was
                // in the page and carrying the wrong number.
                $element->setValue((string) $scopeid);
            }
        }
    }

    /**
     * The courses whose libraries this author may be offered, in the order the
     * course menu lists them.
     *
     * The own course first, named for its relation to the thing being edited
     * rather than by its shortname, because that is the entry an author who
     * never borrows anything will use. Then the courses that own prototypes
     * and that the author may build on, by name.
     *
     * **And the course that owns the current value, whatever the author's
     * rights in it.** A question or a child prototype built on another
     * course's prototype must stay editable by an author with rights only in
     * its own course, and a course menu that could not reach the scope holding
     * the current value would make that value unreachable.
     *
     * @param int $courseid the course the thing being edited belongs to.
     * @param int|null $selectedid the prototype currently chosen.
     * @return array course id => the name to show.
     */
    public function scopes(int $courseid, ?int $selectedid): array {
        $scopes = [$courseid => get_string('scopethiscourse', 'local_coderunner_manager')];
        foreach ($this->candidates->borrowable_courses($courseid) as $id => $name) {
            $scopes[$id] = $name;
        }

        $owner = $this->candidates->course_of($selectedid);
        if ($owner !== null && $owner !== (int) SITEID && !isset($scopes[$owner])) {
            $scopes[$owner] = $this->candidates->course_name($owner);
        }

        return $scopes;
    }

    /**
     * The course whose library the prototype menu is showing.
     *
     * The author's own choice while they have made one, and otherwise the
     * course that owns the current value, so that an existing question or child
     * prototype opens on the scope its own value is in. A site prototype needs
     * no scope of its own: the site library is offered within the own course.
     *
     * Read with `optional_param()` rather than from the form's data, because
     * the options have to be in place before formslib will accept the
     * submitted value, which is to say before the form's data can be read at
     * all. This is the same reason a repeated element's count is read this way.
     *
     * @param int $courseid the course the thing being edited belongs to.
     * @param int|null $selectedid the prototype currently chosen.
     * @return int the course id.
     */
    public function scope_courseid(int $courseid, ?int $selectedid): int {
        $submitted = optional_param($this->coursefield, 0, PARAM_INT);
        if ($submitted) {
            return isset($this->scopes($courseid, $selectedid)[$submitted]) ? $submitted : $courseid;
        }

        $owner = $this->candidates->course_of($selectedid);
        if ($owner !== null && $owner !== (int) SITEID && isset($this->scopes($courseid, $selectedid)[$owner])) {
            return $owner;
        }

        return $courseid;
    }

    /**
     * One scope's library, as option groups.
     *
     * The own course is two groups — the course's own prototypes, then the
     * site's — and any other course is one, which renders as a plain menu with
     * no heading at all.
     *
     * @param int $courseid the course the thing being edited belongs to.
     * @param int|null $selectedid the prototype currently chosen.
     * @param int $scopecourseid the course whose library to show.
     * @return array group heading => [prototype id => label].
     */
    public function optgroups(int $courseid, ?int $selectedid, int $scopecourseid): array {
        if ($scopecourseid === $courseid) {
            $groups = $this->candidates->groups_for_course($courseid);
        } else {
            $group = $this->candidates->for_other_course($scopecourseid, $selectedid);
            $groups = $group ? [$group] : [];
        }

        $optgroups = [];
        foreach ($groups as $group) {
            $heading = get_string('scopegroup_' . $group['key'], 'local_coderunner_manager');
            $options = [];
            foreach ($group['options'] as $id => $option) {
                if (in_array((int) $id, $this->exclude, true)) {
                    // The prototype editor's exclusions: a prototype may not
                    // inherit from itself or from anything below it, which
                    // would make a cycle. Applied here rather than by the
                    // caller so that the count of groups, and so whether the
                    // menu is grouped at all, is of what is really offered.
                    continue;
                }
                // The indentation is the manager's to spell: the same rule
                // shows the same library in both menus.
                $options[$id] = prototype_candidates::indented_label($option);
            }
            if ($options) {
                $optgroups[$heading] = $options;
            }
        }
        return $optgroups;
    }

    /**
     * The chosen prototype, if the menu as built is offering it.
     *
     * @param int|null $selectedid the prototype currently chosen.
     * @param array $optgroups the menu, as {@see optgroups()} builds it.
     * @return int|null the prototype id, or null if this scope does not hold it.
     */
    public function selected_in_scope(?int $selectedid, array $optgroups): ?int {
        if (!$selectedid) {
            return null;
        }
        foreach ($optgroups as $options) {
            if (isset($options[$selectedid])) {
                return $selectedid;
            }
        }
        return null;
    }

    /**
     * The prototype menu: grouped where there is more than one group to choose
     * between, and a plain menu where there is not.
     *
     * **A `selectgroups`, which is what Moodle's own question category
     * selector is.** An `autocomplete` cannot carry the groups:
     * `form-autocomplete.js` rebuilds its suggestions from the select's direct
     * children, so anything inside an `optgroup` vanishes once the JavaScript
     * loads. A native select also keeps the conventional layout — the menu
     * beside its label with the current value showing in it — and the keyboard
     * type-ahead that stands in for a filter box.
     *
     * @param \MoodleQuickForm $mform the form being built.
     * @param array $optgroups group heading => [prototype id => label].
     * @return object the form element.
     */
    private function menu_element(\MoodleQuickForm $mform, array $optgroups) {
        $leading = [$this->rootvalue => $this->rootlabel ?? get_string('choosedots')];
        $attributes = ['aria-label' => get_string('scopeprototype', 'local_coderunner_manager')];

        if (count($optgroups) <= 1) {
            // One group is no choice between groups, so the heading would be
            // an unselectable row at the top of the menu saying nothing. A
            // site whose courses add no prototypes of their own — most sites,
            // for a while — sees a plain menu of the built-ins.
            return $mform->createElement(
                'select',
                $this->field,
                '',
                $leading + ($optgroups ? reset($optgroups) : []),
                $attributes
            );
        }

        // The leading option is prepended as a group of its own rather than
        // asked for through `selectgroups`' $showchoose, which does not work on
        // this path: its export_for_template() appends the entry to
        // $optionsgroups while rendering $optiongroups, so the option is
        // silently dropped and the browser selects the first real prototype. A
        // new question therefore opened claiming to be built on whichever
        // prototype sorted first, which the author had not chosen. The
        // toHtml() path has it right, so the fault shows only where a theme
        // renders through the template — which is to say everywhere, in Boost.
        //
        // Core is not ours to patch, and a group of our own survives either
        // path. Its heading is empty because the entry is not a group of
        // prototypes; browsers draw an unlabelled optgroup as a plain
        // separator above it.
        $placeholder = ['' => $leading];

        return $mform->createElement('selectgroups', $this->field, '', $placeholder + $optgroups, $attributes);
    }
}
