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
 * Populating the prototype tables from CodeRunner 5 data.
 *
 * @package   local_coderunner_manager
 * @copyright 2026 Richard Lobb, The University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coderunner_manager;

use core_question\local\bank\question_version_status;
use qtype_coderunner\v6\field_kind;
use qtype_coderunner\v6\field_registry;
use qtype_coderunner\v6\legacy_converter;
use qtype_coderunner\v6\override_set;

/**
 * Installs the built-in prototypes that ship with CodeRunner, and snapshots the
 * user-defined prototype questions already in the question bank.
 *
 * Both are idempotent: re-running adds a version only where the content has
 * actually changed, so the installer can run on every upgrade and be re-run by
 * hand without piling up versions.
 *
 * Neither touches a question. The question side of the migration is separate.
 */
class prototype_installer {
    /** @var prototype_repository where prototypes are stored. */
    protected prototype_repository $repo;

    /** @var array a tally of what the last run did. */
    protected array $counts = [];

    /**
     * The namespace that built-in prototype uuids are generated under.
     *
     * Any fixed UUID serves; this one was generated once and must never change,
     * because changing it would make every built-in look new to every site.
     */
    const BUILTIN_UUID_NAMESPACE = '6f9b1f1e-5c3a-5a4e-9d3b-2c8e7a1d4b60';

    /**
     * The uuid a built-in prototype has, derived from its canonical name.
     *
     * Built-ins are found by uuid rather than by name, because an
     * administrator who customises one keeps the name: the edit splits the
     * prototype, the administrator's copy keeps the name and the id, and the
     * stock copy is renamed (decision 23). An installer matching on name would
     * find the customised one and overwrite it.
     *
     * The uuid is computed rather than stored, so `db/builtin_PROTOTYPES.xml`
     * needs no new element — it is still read by the CodeRunner 5 question
     * importer, and a foreign element in a Moodle XML export invites trouble.
     * Being a pure function of the name, it is also the same on every
     * CodeRunner site, which lets a cross-site restore recognise a built-in
     * with no mapping at all.
     *
     * This is a version 5 UUID: SHA-1 over the namespace and the name, with the
     * version and variant bits set as RFC 4122 requires. Moodle's core\uuid
     * generates version 4 only, which is random and therefore no use here.
     *
     * @param string $name the built-in's canonical name, such as 'python3'.
     * @return string the uuid.
     */
    public static function builtin_uuid(string $name): string {
        $namespace = str_replace('-', '', self::BUILTIN_UUID_NAMESPACE);
        $hash = sha1(hex2bin($namespace) . $name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            // Version 5.
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            // Variant bits 10xx.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }

    /**
     * Queue the migration task if the site still needs it.
     *
     * Called from the install step, where the tables have just been created and
     * are therefore empty, and again from every upgrade, where it is the only
     * route back for a site whose task was lost — dropped from the queue, or
     * never queued because the manager was installed before the task existed.
     * Without it such a site has no prototypes and no way to get any.
     *
     * Queueing is cheap and context-free, which is why it is safe in an install
     * or upgrade step when doing the work itself is not: the question banks the
     * migration must search do not exist yet.
     *
     * @return bool true if a task was queued.
     */
    public static function queue_migration_if_needed(): bool {
        global $DB;

        $needed = !$DB->record_exists(prototype_repository::PROTOTYPES, []);

        if (!$needed) {
            // The prototypes are there, but a question may still be waiting for
            // its prototype id — a conversion that stopped part way, or a
            // question restored from a pre-6.0 backup.
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('question_coderunner_options');
            $column = new \xmldb_field('overrides');
            if ($dbman->table_exists($table) && $dbman->field_exists($table, $column)) {
                $needed = $DB->record_exists_select(
                    'question_coderunner_options',
                    'prototypetype = 0 AND overrides IS NULL'
                );
            }
        }

        if (!$needed) {
            return false;
        }

        // Checking for an existing task, so an upgrade does not stack another copy of a
        // task that is still waiting to run.
        \core\task\manager::queue_adhoc_task(new task\install_prototypes(), true);
        return true;
    }

    /**
     * @param prototype_repository|null $repo the repository to write through.
     */
    public function __construct(?prototype_repository $repo = null) {
        $this->repo = $repo ?? new prototype_repository();
        $this->reset_counts();
    }

    /**
     * What the last run did: how many prototypes were created, updated with a
     * new version, left alone, or skipped with a reason.
     *
     * @return array counts keyed by outcome.
     */
    public function counts(): array {
        return $this->counts;
    }

    /**
     * Create the Site defaults prototype if it does not exist yet.
     *
     * It sits at the root of every chain and holds the values CodeRunner 5 kept
     * in code and in admin settings, so that a field no prototype and no
     * question sets still has one editable place it comes from. Its initial
     * values are the registry defaults, except the penalty regime, which comes
     * from the admin setting that supplies it today.
     *
     * Unlike a built-in it is **never rewritten**. Site administrators edit it,
     * and an upgrade that overwrote it would silently discard their settings.
     *
     * @return prototype the Site defaults prototype.
     */
    public function ensure_site_defaults(): prototype {
        $existing = $this->repo->site_defaults();
        if ($existing !== null) {
            return $existing;
        }

        $values = [];
        foreach (field_registry::all() as $name => $field) {
            if ($field->kind !== field_kind::INHERITED) {
                // Instance fields belong to a question, and collected fields
                // are gathered per layer rather than defaulted.
                continue;
            }
            if ($field->default === null) {
                // A null default means "not set", such as no CPU time limit,
                // which must stay distinct from a limit of zero.
                continue;
            }
            $values[$name] = $field->default;
        }

        $penaltyregime = get_config('qtype_coderunner', 'default_penalty_regime');
        if ($penaltyregime !== false && $penaltyregime !== '') {
            $values['penaltyregime'] = $penaltyregime;
        }

        return $this->repo->create(
            get_string('sitedefaultsname', 'local_coderunner_manager'),
            SITEID,
            new override_set($values),
            null,
            get_string('sitedefaultssummary', 'local_coderunner_manager'),
            '',
            false,
            '',
            true
        );
    }

    /**
     * Install or update the prototypes that ship with CodeRunner.
     *
     * These become site prototypes marked built-in, and each CodeRunner release
     * updates them, which is how CodeRunner 5 behaves too: its upgrade deletes
     * the built-in prototype questions and reloads them from the same file, so
     * local edits to a built-in are already not preserved.
     *
     * @return array the counts for this run.
     */
    public function install_builtins(): array {
        $this->reset_counts();
        $root = $this->repo->site_defaults() ?? $this->ensure_site_defaults();

        foreach ($this->read_builtin_definitions() as $name => $columns) {
            $this->upsert(
                $name,
                SITEID,
                legacy_converter::prototype_overrides($columns, $name),
                legacy_converter::documentation($columns),
                true,
                $root->id,
                [],
                self::builtin_uuid($name),
                legacy_converter::documentation_format($columns)
            );
        }
        return $this->counts;
    }

    /**
     * Snapshot the user-defined prototype questions in the question bank.
     *
     * A prototype question becomes a root prototype holding the fields it
     * supplied, at the scope of the course whose bank contains it, or at site
     * scope when it lives in the front page's bank. Its question text becomes
     * its documentation. Its answer, test cases and global extra are dropped:
     * the first two have no meaning for a prototype and the third never had any
     * effect.
     *
     * @return array the counts for this run.
     */
    public function snapshot_user_prototypes(): array {
        $this->reset_counts();

        $byscope = [];
        foreach ($this->read_user_prototype_questions() as $record) {
            $courseid = $this->scope_of($record);
            if ($courseid === null) {
                $this->counts['skipped']++;
                $this->counts['reasons'][$record->coderunnertype] = 'no course context';
                continue;
            }
            $byscope[$courseid . '/' . $record->coderunnertype][] = $record;
        }

        $root = $this->repo->site_defaults() ?? $this->ensure_site_defaults();

        foreach ($byscope as $key => $records) {
            [$courseid, $name] = explode('/', $key, 2);

            if (count($records) > 1) {
                // More than one prototype question of this name is visible from
                // the same scope. That is the duplicate-prototype condition that
                // already breaks every question using the name, since
                // get_prototype() cannot choose between them. Collapsing them
                // here would silently pick one and make a broken situation look
                // fixed, so they are left alone for the health report and their
                // author to resolve.
                $this->counts['skipped'] += count($records);
                $this->counts['reasons'][$name] = sprintf(
                    '%d prototype questions of this name in the same scope; resolve the duplicates first',
                    count($records)
                );
                continue;
            }

            $record = reset($records);
            $columns = (array) $record;
            $this->upsert(
                $name,
                (int) $courseid,
                legacy_converter::prototype_overrides($columns, $name),
                legacy_converter::documentation($columns),
                false,
                $root->id,
                $this->question_support_files($record),
                '',
                legacy_converter::documentation_format($columns)
            );
        }
        return $this->counts;
    }

    /**
     * Drop any override whose value is already what the parent chain resolves
     * to, so that a prototype states only what it actually changes.
     *
     * CodeRunner 5 stored a value for every inheritable field of a prototype
     * question, whether the author chose it or not: the editing form defaulted
     * the grader to EqualityGrader and "use Ace" to true, and almost nobody
     * touched either. Carrying those through as explicit overrides would make
     * the prototype state a preference nobody expressed, and would stop it
     * following a later edit to Site defaults — which is the whole point of
     * having Site defaults at the root.
     *
     * Values are compared in their canonical form, so a stored '1' and a
     * resolved 1 count as the same, and null and the empty string count as the
     * same for a string field but not for an integer one.
     *
     * Collected fields are never pruned: every layer contributes to them, so a
     * layer's contribution is not redundant merely because it matches.
     *
     * @param override_set $overrides the candidate overrides.
     * @param int|null $parentid the prototype they will derive from.
     * @return override_set the overrides with the redundant ones removed.
     */
    protected function prune_redundant(override_set $overrides, ?int $parentid): override_set {
        if ($parentid === null) {
            return $overrides;
        }
        $inherited = $this->repo->chain_for($parentid)->resolve();

        foreach ($overrides->names() as $name) {
            $field = field_registry::get($name);
            if (!$field->resolves_to_one_value()) {
                continue;
            }
            $mine = $field->normalise_for_comparison($overrides->get($name));
            $theirs = $field->normalise_for_comparison($inherited->get($name));
            if ($mine === $theirs) {
                $overrides = $overrides->without($name);
            }
        }
        return $overrides;
    }

    /**
     * Create a prototype, or add a version to it when its content has changed.
     *
     * @param string $name the prototype name.
     * @param int $courseid the scope.
     * @param override_set $overrides the fields it supplies.
     * @param string $documentation documentation for question authors.
     * @param bool $builtin whether it ships with CodeRunner.
     * @param int|null $parentid the prototype it derives from, normally Site defaults.
     * @param \stored_file[] $files support files to attach.
     */
    protected function upsert(
        string $name,
        int $courseid,
        override_set $overrides,
        string $documentation,
        bool $builtin,
        ?int $parentid = null,
        array $files = [],
        string $uuid = '',
        int $documentationformat = FORMAT_HTML
    ): void {
        $overrides = $this->prune_redundant($overrides, $parentid);
        // A built-in is looked up by its uuid, which survives the rename that
        // a split performs; everything else by name within its scope.
        $existing = $uuid !== '' ? $this->find_by_uuid($uuid) : $this->find($name, $courseid);

        if ($existing === null) {
            if ($this->repo->name_clash($name, $courseid) !== null) {
                // A name taken elsewhere in the scope that this run cannot
                // resolve. Renaming is a deliberate act with a notification
                // attached, which belongs with restore and import, not here.
                $this->counts['skipped']++;
                $this->counts['reasons'][$name] = 'name already in use';
                return;
            }
            $this->repo->create(
                $name,
                $courseid,
                $overrides,
                $parentid,
                '',
                $documentation,
                $builtin,
                $uuid,
                false,
                $files,
                $documentationformat
            );
            $this->counts['created']++;
            return;
        }

        if ($this->unchanged($existing, $overrides, $documentation, $files)) {
            $this->counts['unchanged']++;
            return;
        }

        $this->repo->save_new_version(
            $existing,
            $overrides,
            $existing->parentid(),
            $existing->currentversion->summary,
            $documentation,
            get_string('installerupdated', 'local_coderunner_manager'),
            $files ?: null,
            $documentationformat
        );
        $this->counts['updated']++;
    }

    /**
     * Whether a prototype's current version already holds these values, so that
     * re-running the installer adds nothing.
     *
     * @param prototype $prototype the existing prototype.
     * @param override_set $overrides the values this run would store.
     * @param string $documentation the documentation this run would store.
     * @param \stored_file[] $files the support files this run would store.
     * @return bool true if nothing would change.
     */
    protected function unchanged(
        prototype $prototype,
        override_set $overrides,
        string $documentation,
        array $files = []
    ): bool {
        $current = $prototype->currentversion;
        if ($current === null) {
            return false;
        }
        $before = $current->overrides->to_array();
        $after = $overrides->to_array();
        ksort($before);
        ksort($after);
        if ($before !== $after || $current->documentation !== $documentation) {
            return false;
        }
        return $this->same_files($this->repo->version_files($prototype, $current->id), $files);
    }

    /**
     * Whether two sets of support files hold the same names and the same
     * content, compared by the file API's content hashes rather than by reading
     * the files.
     *
     * @param \stored_file[] $stored the files a version already holds.
     * @param \stored_file[] $candidate the files a run would store.
     * @return bool true if they are the same set.
     */
    protected function same_files(array $stored, array $candidate): bool {
        $hash = static function (array $files): array {
            $map = [];
            foreach ($files as $file) {
                if (!$file->is_directory()) {
                    $map[$file->get_filepath() . $file->get_filename()] = $file->get_contenthash();
                }
            }
            ksort($map);
            return $map;
        };
        return $hash($stored) === $hash($candidate);
    }

    /**
     * The support files attached to a prototype question.
     *
     * @param \stdClass $record the prototype question row, with its context id.
     * @return \stored_file[] the files.
     */
    protected function question_support_files(\stdClass $record): array {
        if (empty($record->contextid) || empty($record->questionid)) {
            return [];
        }
        return get_file_storage()->get_area_files(
            (int) $record->contextid,
            'qtype_coderunner',
            'datafile',
            (int) $record->questionid,
            'filename',
            false
        );
    }

    /**
     * Find a prototype by name within one scope.
     *
     * @param string $name the prototype name.
     * @param int $courseid the scope.
     * @return prototype|null the prototype, or null if there is none.
     */
    /**
     * The built-in prototype carrying this uuid, if there is one.
     *
     * A uuid is not unique on a site — a restore may legitimately bring in a
     * copy — so the built-in flag picks out the stock prototype from any
     * copies. After a split the administrator's prototype is no longer a
     * built-in, so it cannot be mistaken for one.
     *
     * @param string $uuid the uuid.
     * @return prototype|null the stock prototype, or null.
     */
    protected function find_by_uuid(string $uuid): ?prototype {
        foreach ($this->repo->find_by_uuid($uuid) as $prototype) {
            if ($prototype->builtin) {
                return $prototype;
            }
        }
        return null;
    }

    /**
     * An existing prototype of this name in this scope, if there is one.
     *
     * @param string $name the name.
     * @param int $courseid the scope.
     * @return prototype|null the prototype, or null.
     */
    protected function find(string $name, int $courseid): ?prototype {
        global $DB;

        $id = $DB->get_field(
            prototype_repository::PROTOTYPES,
            'id',
            ['name' => $name, 'courseid' => $courseid]
        );
        return $id ? $this->repo->get((int) $id) : null;
    }

    /**
     * The scope a prototype question becomes, being the course whose bank holds
     * it, or the site when that course is the front page.
     *
     * @param \stdClass $record a prototype question row, with its context id.
     * @return int|null the course id, or null if the context has no course.
     */
    protected function scope_of(\stdClass $record): ?int {
        $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        $coursecontext = $context->get_course_context(false);
        return $coursecontext ? (int) $coursecontext->instanceid : null;
    }

    /**
     * The built-in prototype definitions shipped with the question type.
     *
     * The file is a Moodle XML question export, so each prototype is a question
     * element whose option fields are plain child elements. It is parsed here
     * rather than imported, because these become prototypes and not questions.
     *
     * @return array prototype name => column name => value.
     * @throws \moodle_exception if the file is missing or will not parse.
     */
    protected function read_builtin_definitions(): array {
        global $CFG;

        $path = $CFG->dirroot . '/question/type/coderunner/db/builtin_PROTOTYPES.xml';
        if (!is_readable($path)) {
            throw new \moodle_exception('builtinsmissing', 'local_coderunner_manager', '', $path);
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \moodle_exception('builtinsunreadable', 'local_coderunner_manager', '', $path);
        }

        $definitions = [];
        foreach ($xml->question as $question) {
            if ((string) $question['type'] !== 'coderunner') {
                continue;  // The category element that heads the file.
            }
            $columns = ['questiontext' => (string) $question->questiontext->text];
            foreach ($question->children() as $child) {
                $tag = $child->getName();
                if (in_array($tag, ['name', 'questiontext', 'generalfeedback', 'testcases'], true)) {
                    continue;
                }
                $columns[$tag] = (string) $child;
            }
            $name = $columns['coderunnertype'] ?? '';
            if ($name !== '') {
                $definitions[$name] = $columns;
            }
        }
        return $definitions;
    }

    /**
     * The user-defined prototype questions in the question bank.
     *
     * The version conditions match `qtype_coderunner::get_prototype()`: hidden
     * versions are excluded both when finding the most recent version of each
     * prototype and when matching it, so a prototype deleted from the bank is
     * not snapshotted.
     *
     * @return array rows, keyed by question id.
     */
    protected function read_user_prototype_questions(): array {
        global $DB;

        $params = [
            'hidden' => question_version_status::QUESTION_STATUS_HIDDEN,
            'hidden2' => question_version_status::QUESTION_STATUS_HIDDEN,
        ];
        return $DB->get_records_sql("
            SELECT q.id, qco.*, q.questiontext, qc.contextid
              FROM {question_coderunner_options} qco
              JOIN {question} q ON qco.questionid = q.id
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qv.questionbankentryid = qbe.id
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE (qv.version = (SELECT MAX(v.version)
                                    FROM {question_versions} v
                                    JOIN {question_bank_entries} be ON be.id = v.questionbankentryid
                                   WHERE be.id = qbe.id
                                     AND v.status <> :hidden)
                                 )
               AND qv.status <> :hidden2
               AND qco.prototypetype = 2
          ORDER BY qco.coderunnertype", $params);
    }

    /**
     * Start a fresh tally.
     */
    protected function reset_counts(): void {
        $this->counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'reasons' => []];
    }
}
