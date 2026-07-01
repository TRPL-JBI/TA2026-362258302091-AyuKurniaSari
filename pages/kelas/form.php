<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

global $DB, $PAGE, $OUTPUT;

$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kelas/form.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title($id ? 'Edit Kelas' : 'Tambah Kelas');
$PAGE->set_heading($id ? 'Edit Kelas' : 'Tambah Kelas');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');
$PAGE->requires->js_call_amd(
    'local_akademikmonitor/searchable_select',
    'init'
);

function local_akademikmonitor_backend_admin_urls(string $active): array {
    return [
        'is_dashboard' => $active === 'dashboard',
        'is_tahun_ajaran' => $active === 'tahun_ajaran',
        'is_kurikulum' => $active === 'kurikulum',
        'is_manajemen_jurusan' => $active === 'jurusan',
        'is_manajemen_kelas' => $active === 'kelas',
        'is_mata_pelajaran' => $active === 'mata_pelajaran',
        'is_matpel' => $active === 'mata_pelajaran',
        'is_kktp' => $active === 'kktp',
        'is_notif' => $active === 'notif',
        'is_ekskul' => $active === 'ekskul',
        'is_mitra' => $active === 'mitra',

        'dashboard_url' => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url' => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url' => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url' => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
    ];
}

function local_akademikmonitor_backend_tahun_label(?stdClass $tahun): string {
    if (!$tahun) {
        return '-';
    }

    if (property_exists($tahun, 'tahun_ajaran')) {
        return (string) $tahun->tahun_ajaran;
    }

    if (property_exists($tahun, 'nama')) {
        return (string) $tahun->nama;
    }

    return '-';
}

function local_akademikmonitor_backend_kurikulum_namefield(): string {
    global $DB;

    $columns = $DB->get_columns('kurikulum');

    return isset($columns['nama']) ? 'nama' : 'nama_kurikulum';
}

function local_akademikmonitor_backend_active_tahun(): ?stdClass {
    global $DB;

    $activeid = (int) get_config('local_akademikmonitor', 'active_tahunajaranid');

    if ($activeid > 0) {
        $record = $DB->get_record('tahun_ajaran', ['id' => $activeid], '*', IGNORE_MISSING);
        if ($record) {
            return $record;
        }
    }

    $columns = $DB->get_columns('tahun_ajaran');

    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('tahun_ajaran', [$field => 1], '*', IGNORE_MULTIPLE);
            if ($record) {
                return $record;
            }
        }
    }

    $records = $DB->get_records('tahun_ajaran', null, 'id DESC', '*', 0, 1);

    return $records ? reset($records) : null;
}

function local_akademikmonitor_backend_active_kurikulum(): ?stdClass {
    global $DB;

    $activeid = (int) get_config('local_akademikmonitor', 'active_kurikulumid');

    if ($activeid > 0) {
        $record = $DB->get_record('kurikulum', ['id' => $activeid], '*', IGNORE_MISSING);
        if ($record) {
            return $record;
        }
    }

    $columns = $DB->get_columns('kurikulum');

    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('kurikulum', [$field => 1], '*', IGNORE_MULTIPLE);
            if ($record) {
                return $record;
            }
        }
    }

    $records = $DB->get_records('kurikulum', null, 'id DESC', '*', 0, 1);

    return $records ? reset($records) : null;
}

function local_akademikmonitor_backend_ensure_kurikulum_jurusan(
    int $jurusanid,
    ?int $kurikulumid = null,
    ?int $tahunajaranid = null
): ?stdClass {
    global $DB;

    if ($jurusanid <= 0) {
        return null;
    }

    if (empty($kurikulumid)) {
        $kurikulum = local_akademikmonitor_backend_active_kurikulum();
        $kurikulumid = $kurikulum ? (int) $kurikulum->id : 0;
    }

    if (empty($tahunajaranid)) {
        $tahun = local_akademikmonitor_backend_active_tahun();
        $tahunajaranid = $tahun ? (int) $tahun->id : 0;
    }

    if ($kurikulumid <= 0 || $tahunajaranid <= 0) {
        return null;
    }

    $existing = $DB->get_record('kurikulum_jurusan', [
        'id_jurusan' => $jurusanid,
        'id_kurikulum' => $kurikulumid,
        'id_tahun_ajaran' => $tahunajaranid,
    ], '*', IGNORE_MULTIPLE);

    if ($existing) {
        return $existing;
    }

    $record = new stdClass();
    $record->id_jurusan = $jurusanid;
    $record->id_kurikulum = $kurikulumid;
    $record->id_tahun_ajaran = $tahunajaranid;
    $record->id = $DB->insert_record('kurikulum_jurusan', $record);

    return $record;
}

function local_akademikmonitor_backend_get_kurikulum_jurusan(
    int $jurusanid,
    ?int $tahunajaranid = null
): ?stdClass {
    global $DB;

    if ($jurusanid <= 0) {
        return null;
    }

    $activekurikulum = local_akademikmonitor_backend_active_kurikulum();
    $activetahun = local_akademikmonitor_backend_active_tahun();

    $kurikulumid = $activekurikulum ? (int) $activekurikulum->id : 0;
    $tahunid = $tahunajaranid ?: ($activetahun ? (int) $activetahun->id : 0);

    if ($kurikulumid > 0 && $tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', [
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulumid,
            'id_tahun_ajaran' => $tahunid,
        ], '*', IGNORE_MULTIPLE);

        if ($record) {
            return $record;
        }

        return local_akademikmonitor_backend_ensure_kurikulum_jurusan(
            $jurusanid,
            $kurikulumid,
            $tahunid
        );
    }

    if ($tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', [
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunid,
        ], '*', IGNORE_MULTIPLE);

        if ($record) {
            return $record;
        }
    }

    $records = $DB->get_records(
        'kurikulum_jurusan',
        ['id_jurusan' => $jurusanid],
        'id DESC',
        '*',
        0,
        1
    );

    return $records ? reset($records) : null;
}

function local_akademikmonitor_backend_normalize_tingkat(string $tingkat): string {
    $tingkat = strtoupper(trim($tingkat));

    return in_array($tingkat, ['X', 'XI', 'XII'], true) ? $tingkat : 'X';
}

function local_akademikmonitor_backend_jam(string $jam): string {
    $jam = trim($jam);

    if ($jam === '') {
        return '';
    }

    if (preg_match('/^\d{1,2}$/', $jam)) {
        return $jam;
    }

    return substr($jam, 0, 8);
}

$record = $id ? $DB->get_record('kelas', ['id' => $id], '*', MUST_EXIST) : null;
$activetahun = local_akademikmonitor_backend_active_tahun();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $postid = optional_param('id', 0, PARAM_INT);

    if ($postid > 0) {
        $id = $postid;
    }

    $nama = trim(required_param('nama', PARAM_TEXT));
    $jurusanid = required_param('id_jurusan', PARAM_INT);
    $tingkat = local_akademikmonitor_backend_normalize_tingkat(required_param('tingkat', PARAM_TEXT));
    $tahunid = required_param('id_tahun_ajaran', PARAM_INT);
    $waliid = optional_param('id_user', 0, PARAM_INT);

    if ($nama === '') {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/kelas/form.php', ['id' => $id]),
            'Nama kelas wajib diisi.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $DB->get_record('jurusan', ['id' => $jurusanid], '*', MUST_EXIST);
    $DB->get_record('tahun_ajaran', ['id' => $tahunid], '*', MUST_EXIST);

    if ($waliid > 0) {
        $DB->get_record('user', [
            'id' => $waliid,
            'deleted' => 0,
            'suspended' => 0,
        ], '*', MUST_EXIST);
    }

    local_akademikmonitor_backend_get_kurikulum_jurusan($jurusanid, $tahunid);

    $exists = $DB->record_exists_select(
        'kelas',
        'nama = :nama
            AND tingkat = :tingkat
            AND id_jurusan = :jurusanid
            AND id_tahun_ajaran = :tahunid
            AND id <> :id',
        [
            'nama' => $nama,
            'tingkat' => $tingkat,
            'jurusanid' => $jurusanid,
            'tahunid' => $tahunid,
            'id' => $id,
        ]
    );

    if ($exists) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/kelas/form.php', ['id' => $id]),
            'Kelas dengan jurusan, tingkat, dan tahun ajaran tersebut sudah ada.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $data = new stdClass();
    $data->nama = $nama;
    $data->tingkat = $tingkat;
    $data->id_jurusan = $jurusanid;
    $data->id_tahun_ajaran = $tahunid;
    $data->id_user = $waliid > 0 ? $waliid : null;

    if ($id) {
        $data->id = $id;
        $DB->update_record('kelas', $data);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/kelas/index.php'),
            'Data kelas berhasil diperbarui.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $DB->insert_record('kelas', $data);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/index.php'),
        'Data kelas berhasil ditambahkan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$jurusanoptions = [];

foreach ($DB->get_records('jurusan', null, 'nama_jurusan ASC') as $j) {
    $selected = $record ? ((int) $record->id_jurusan === (int) $j->id) : false;

    $jurusanoptions[] = [
        'id' => (int) $j->id,
        'nama' => format_string($j->nama_jurusan),
        'selected' => $selected,
    ];
}

$tahunoptions = [];
$selectedtahunid = $record ? (int) $record->id_tahun_ajaran : ($activetahun ? (int) $activetahun->id : 0);

foreach ($DB->get_records('tahun_ajaran', null, 'id DESC') as $ta) {
    $tahunoptions[] = [
        'id' => (int) $ta->id,
        'nama' => format_string(local_akademikmonitor_backend_tahun_label($ta)),
        'selected' => ((int) $ta->id === $selectedtahunid),
    ];
}

$tingkatoptions = [];

foreach (['X', 'XI', 'XII'] as $t) {
    $tingkatoptions[] = [
        'value' => $t,
        'label' => $t,
        'selected' => $record ? ((string) $record->tingkat === $t) : false,
    ];
}

$walioptions = [];

/*
 * Di Moodle baru, jangan pakai get_all_user_name_fields().
 * Gunakan \core_user\fields::for_name().
 *
 * Fungsi for_name() mengambil field yang dibutuhkan oleh fullname($u),
 * misalnya firstname, lastname, firstnamephonetic, lastnamephonetic,
 * middlename, dan alternatename.
 *
 * including('email') dipakai karena dropdown wali kelas menampilkan email.
 */
$userfields = \core_user\fields::for_name()
    ->including('email')
    ->get_sql('u', false, '', '', false);

$selects = 'u.id';

if (!empty($userfields->selects)) {
    $selects .= ', ' . $userfields->selects;
}

$sql = "SELECT DISTINCT {$selects}
          FROM {user} u
          JOIN {role_assignments} ra
            ON ra.userid = u.id
          JOIN {context} ctx
            ON ctx.id = ra.contextid
          JOIN {role} r
            ON r.id = ra.roleid
         WHERE u.deleted = 0
           AND u.suspended = 0
           AND u.id > 1
           AND r.shortname IN ('teacher', 'editingteacher')
      ORDER BY u.firstname ASC, u.lastname ASC";

$users = $DB->get_records_sql($sql, $userfields->params);

foreach ($users as $u) {
    $email = !empty($u->email) ? ' (' . s($u->email) . ')' : '';

    $walioptions[] = [
        'id' => (int) $u->id,
        'nama' => fullname($u) . $email,
        'selected' => $record && !empty($record->id_user) && ((int) $record->id_user === (int) $u->id),
    ];
}

$templatecontext = array_merge(local_akademikmonitor_backend_admin_urls('kelas'), [
    'id' => $id,
    'nama' => $record ? s((string) $record->nama) : '',
    'title' => $id ? 'Edit Kelas' : 'Tambah Kelas',
    'breadcrumb' => $id ? '/ Manajemen Kelas / Edit' : '/ Manajemen Kelas / Tambah',
    'is_edit' => (bool) $record,
    'jurusan_options' => $jurusanoptions,
    'tahun_ajaran_options' => $tahunoptions,
    'tingkat_options' => $tingkatoptions,
    'wali_options' => $walioptions,
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/form.php', ['id' => $id]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
    'sesskey' => sesskey(),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kelas_form', $templatecontext);
echo $OUTPUT->footer();