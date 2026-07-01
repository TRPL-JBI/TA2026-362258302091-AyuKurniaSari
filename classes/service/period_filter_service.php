<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class period_filter_service {

    private const SESSION_SEMESTER_KEY = 'local_akademikmonitor_selected_semester';
    private const SESSION_TAHUN_KEY = 'local_akademikmonitor_selected_tahunajaranid';

    public static function get_selected_semester(): int {
        global $SESSION;

        $semester = optional_param('semester', 0, PARAM_INT);

        if (in_array($semester, [1, 2], true)) {
            $SESSION->{self::SESSION_SEMESTER_KEY} = $semester;
            return $semester;
        }

        if (
            isset($SESSION->{self::SESSION_SEMESTER_KEY})
            && in_array((int)$SESSION->{self::SESSION_SEMESTER_KEY}, [1, 2], true)
        ) {
            return (int)$SESSION->{self::SESSION_SEMESTER_KEY};
        }

        $semester = self::get_default_semester_from_config();

        $SESSION->{self::SESSION_SEMESTER_KEY} = $semester;

        return $semester;
    }

    public static function get_selected_tahunajaranid(): int {
        global $DB, $SESSION;

        $tahunajaranid = optional_param('tahunajaranid', 0, PARAM_INT);

        if ($tahunajaranid > 0 && $DB->record_exists('tahun_ajaran', ['id' => $tahunajaranid])) {
            $SESSION->{self::SESSION_TAHUN_KEY} = $tahunajaranid;
            return $tahunajaranid;
        }

        if (
            isset($SESSION->{self::SESSION_TAHUN_KEY})
            && (int)$SESSION->{self::SESSION_TAHUN_KEY} > 0
            && $DB->record_exists('tahun_ajaran', ['id' => (int)$SESSION->{self::SESSION_TAHUN_KEY}])
        ) {
            return (int)$SESSION->{self::SESSION_TAHUN_KEY};
        }

        $tahunajaranid = self::get_default_tahunajaranid_from_config();

        if ($tahunajaranid <= 0) {
            $tahunajaranid = self::get_active_tahunajaranid_from_table();
        }

        if ($tahunajaranid <= 0) {
            $tahunajaranid = self::get_latest_tahunajaranid();
        }

        $SESSION->{self::SESSION_TAHUN_KEY} = $tahunajaranid;

        return $tahunajaranid;
    }

    private static function get_default_semester_from_config(): int {
        $config = get_config('local_akademikmonitor');

        $candidates = [
            $config->active_semester ?? null,
            $config->semesterdefault ?? null,
            $config->semester_default ?? null,
            $config->defaultsemester ?? null,
        ];

        foreach ($candidates as $value) {
            $semester = self::normalize_semester_value($value);

            if (in_array($semester, [1, 2], true)) {
                return $semester;
            }
        }

        return 1;
    }

    private static function normalize_semester_value($value): int {
        if ($value === null) {
            return 0;
        }

        if (is_numeric($value)) {
            $semester = (int)$value;
            return in_array($semester, [1, 2], true) ? $semester : 0;
        }

        $value = \core_text::strtolower(trim((string)$value));

        if ($value === '') {
            return 0;
        }

        if (in_array($value, ['1', 'ganjil', 'gasal', 'semester 1', 'semester ganjil'], true)) {
            return 1;
        }

        if (in_array($value, ['2', 'genap', 'semester 2', 'semester genap'], true)) {
            return 2;
        }

        return 0;
    }

    private static function get_default_tahunajaranid_from_config(): int {
        global $DB;

        $config = get_config('local_akademikmonitor');

        $idcandidates = [
            $config->active_tahunajaranid ?? null,
            $config->tahunajaranid ?? null,
            $config->default_tahunajaranid ?? null,
            $config->tahunajaran_default ?? null,
        ];

        foreach ($idcandidates as $id) {
            $id = (int)$id;

            if ($id > 0 && $DB->record_exists('tahun_ajaran', ['id' => $id])) {
                return $id;
            }
        }

        $labelcandidates = [
            $config->tahunpelajarandefault ?? null,
            $config->tahun_ajaran_default ?? null,
            $config->tahunajaran_default_label ?? null,
        ];

        foreach ($labelcandidates as $label) {
            $id = self::find_tahunajaranid_by_label((string)$label);

            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private static function find_tahunajaranid_by_label(string $label): int {
        global $DB;

        $label = trim($label);

        if ($label === '') {
            return 0;
        }

        $columns = $DB->get_columns('tahun_ajaran');

        $possiblefields = [];

        foreach (['tahun_ajaran', 'nama', 'label'] as $field) {
            if (isset($columns[$field])) {
                $possiblefields[] = $field;
            }
        }

        foreach ($possiblefields as $field) {
            $record = $DB->get_record(
                'tahun_ajaran',
                [$field => $label],
                'id',
                IGNORE_MISSING
            );

            if ($record) {
                return (int)$record->id;
            }
        }

        return 0;
    }

    private static function get_active_tahunajaranid_from_config(): int {
        global $DB;

        $config = get_config('local_akademikmonitor');

        $idcandidates = [
            $config->active_tahunajaranid ?? null,
        ];

        foreach ($idcandidates as $id) {
            $id = (int)$id;

            if ($id > 0 && $DB->record_exists('tahun_ajaran', ['id' => $id])) {
                return $id;
            }
        }

        return 0;
    }

    private static function get_active_tahunajaranid_from_table(): int {
        global $DB;

        $columns = $DB->get_columns('tahun_ajaran');

        foreach (['is_active', 'aktif', 'active'] as $field) {
            if (!isset($columns[$field])) {
                continue;
            }

            $record = $DB->get_record(
                'tahun_ajaran',
                [$field => 1],
                'id',
                IGNORE_MULTIPLE
            );

            if ($record) {
                return (int)$record->id;
            }
        }

        return 0;
    }

    private static function get_latest_tahunajaranid(): int {
        global $DB;

        $records = $DB->get_records(
            'tahun_ajaran',
            null,
            'id DESC',
            'id',
            0,
            1
        );

        if (!$records) {
            return 0;
        }

        $record = reset($records);

        return (int)$record->id;
    }

    public static function get_active_tahunajaranid(): int {
        $tahunajaranid = self::get_active_tahunajaranid_from_config();

        if ($tahunajaranid > 0) {
            return $tahunajaranid;
        }

        $tahunajaranid = self::get_active_tahunajaranid_from_table();

        if ($tahunajaranid > 0) {
            return $tahunajaranid;
        }

        return self::get_default_tahunajaranid_from_config();
    }

    public static function can_edit_tahunajaran(int $tahunajaranid = 0): bool {
        if ($tahunajaranid <= 0) {
            $tahunajaranid = self::get_selected_tahunajaranid();
        }

        $activeid = self::get_active_tahunajaranid();

        if ($activeid <= 0 || $tahunajaranid <= 0) {
            return false;
        }

        return (int)$tahunajaranid === (int)$activeid;
    }
    public static function require_editable_selected_period(int $tahunajaranid = 0): void {
        if (!self::can_edit_tahunajaran($tahunajaranid)) {
            throw new \Exception(
                'Tahun ajaran arsip hanya bisa dilihat. Ubah data hanya diperbolehkan pada tahun ajaran aktif.'
            );
        }
    }

    public static function get_semester_label(int $semester): string {
        return ((int)$semester === 2) ? 'Genap' : 'Ganjil';
    }

    public static function get_tahunajaran_label(int $tahunajaranid): string {
        global $DB;

        if ($tahunajaranid <= 0) {
            return '-';
        }

        $tahun = $DB->get_record(
            'tahun_ajaran',
            ['id' => $tahunajaranid],
            '*',
            IGNORE_MISSING
        );

        if (!$tahun) {
            return '-';
        }

        if (property_exists($tahun, 'tahun_ajaran') && trim((string)$tahun->tahun_ajaran) !== '') {
            return (string)$tahun->tahun_ajaran;
        }

        if (property_exists($tahun, 'nama') && trim((string)$tahun->nama) !== '') {
            return (string)$tahun->nama;
        }

        if (property_exists($tahun, 'label') && trim((string)$tahun->label) !== '') {
            return (string)$tahun->label;
        }

        return '-';
    }

    public static function get_tahunajaran_options(int $selectedid = 0): array {
        global $DB;

        if ($selectedid <= 0) {
            $selectedid = self::get_selected_tahunajaranid();
        }

        $records = $DB->get_records(
            'tahun_ajaran',
            null,
            'id DESC'
        );

        $options = [];

        foreach ($records as $record) {
            $id = (int)$record->id;

            $options[] = [
                'id' => $id,
                'label' => self::get_tahunajaran_label($id),
                'selected' => $id === (int)$selectedid,
            ];
        }

        return $options;
    }

    public static function build_filter_data(): array {
        $semester = self::get_selected_semester();
        $tahunajaranid = self::get_selected_tahunajaranid();
        $activeid = self::get_active_tahunajaranid();
        $caneditperiod = self::can_edit_tahunajaran($tahunajaranid);

        return [
            'selectedsemester' => $semester,
            'selectedtahunajaranid' => $tahunajaranid,
            'selected_tahunajaranid' => $tahunajaranid,

            'active_tahunajaranid' => $activeid,
            'caneditperiod' => $caneditperiod,
            'readonlyperiod' => !$caneditperiod,

            'semester_label' => self::get_semester_label($semester),
            'tahunajaran_label' => self::get_tahunajaran_label($tahunajaranid),
            'active_tahunajaran_label' => self::get_tahunajaran_label($activeid),

            'semester_options' => [
                [
                    'value' => 1,
                    'label' => 'Ganjil',
                    'selected' => $semester === 1,
                ],
                [
                    'value' => 2,
                    'label' => 'Genap',
                    'selected' => $semester === 2,
                ],
            ],

            'tahunajaran_options' => self::get_tahunajaran_options($tahunajaranid),
        ];
    }

    public static function get_filter_ui_data(string $actionurl, array $extra = []): array {
        $semester = self::get_selected_semester();
        $tahunajaranid = self::get_selected_tahunajaranid();
        $activeid = self::get_active_tahunajaranid();
        $caneditperiod = self::can_edit_tahunajaran($tahunajaranid);

        $params = array_merge($extra, [
            'semester' => $semester,
            'tahunajaranid' => $tahunajaranid,
        ]);

        return [
            'periodfilter' => [
                'action_url' => (new \moodle_url($actionurl, $params))->out(false),
                'semester_options' => [
                    [
                        'value' => 1,
                        'label' => 'Ganjil',
                        'selected' => $semester === 1,
                    ],
                    [
                        'value' => 2,
                        'label' => 'Genap',
                        'selected' => $semester === 2,
                    ],
                ],
                'tahunajaran_options' => self::get_tahunajaran_options($tahunajaranid),
                'selectedsemester' => $semester,
                'selectedtahunajaranid' => $tahunajaranid,
                'selected_tahunajaranid' => $tahunajaranid,

                'active_tahunajaranid' => $activeid,
                'caneditperiod' => $caneditperiod,
                'readonlyperiod' => !$caneditperiod,

                'semester_label' => self::get_semester_label($semester),
                'tahunajaran_label' => self::get_tahunajaran_label($tahunajaranid),
                'active_tahunajaran_label' => self::get_tahunajaran_label($activeid),

                'extra_params' => self::build_extra_params($extra),
            ],
        ];
    }

    private static function build_extra_params(array $extra): array {
        $params = [];

        foreach ($extra as $name => $value) {
            if ($name === 'semester' || $name === 'tahunajaranid') {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $params[] = [
                'name' => (string)$name,
                'value' => (string)$value,
            ];
        }

        return $params;
    }

    public static function append_filter_params(array $params = []): array {
        $params['semester'] = self::get_selected_semester();
        $params['tahunajaranid'] = self::get_selected_tahunajaranid();

        return $params;
    }

    public static function reset_session(): void {
        global $SESSION;

        unset($SESSION->{self::SESSION_SEMESTER_KEY});
        unset($SESSION->{self::SESSION_TAHUN_KEY});
    }
}