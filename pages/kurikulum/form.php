<?php
require_once(__DIR__ . '/../../../../config.php');
use local_akademikmonitor\service\access_service;
access_service::require_manage();

global $PAGE, $OUTPUT, $DB;

$id = optional_param('id', 0, PARAM_INT);

$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kurikulum/form.php', [
    'id' => $id,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($id ? 'Edit Kurikulum' : 'Tambah Kurikulum');
$PAGE->set_heading($id ? 'Edit Kurikulum' : 'Tambah Kurikulum');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

/**
 * Deteksi nama kolom.
 * Ini supaya aman jika tabel kurikulum kamu memakai "nama" atau "nama_kurikulum".
 */
$columns = $DB->get_columns('kurikulum');
$namefield = isset($columns['nama']) ? 'nama' : 'nama_kurikulum';

$record = null;

if ($id) {
    $record = $DB->get_record('kurikulum', ['id' => $id], '*', MUST_EXIST);
}

/**
 * Proses tambah/edit kurikulum.
 *
 * require_sesskey() hanya dipanggil saat POST.
 * Kalau dipanggil saat GET, Moodle akan error:
 * "A required parameter (sesskey) was missing".
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $postid = optional_param('id', 0, PARAM_INT);
    $nama = required_param('nama', PARAM_TEXT);
    $nama = trim($nama);

    if ($postid > 0) {
        $id = $postid;
    }

    if ($nama === '') {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/kurikulum/form.php', [
                'id' => $id,
            ]),
            'Nama kurikulum wajib diisi.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($id) {
        $select = $namefield . ' = :nama AND id <> :id';
        $params = [
            'nama' => $nama,
            'id' => $id,
        ];

        $exists = $DB->record_exists_select('kurikulum', $select, $params);
    } else {
        $exists = $DB->record_exists('kurikulum', [
            $namefield => $nama,
        ]);
    }

    if ($exists) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/kurikulum/form.php', [
                'id' => $id,
            ]),
            'Kurikulum sudah ada.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $data = new stdClass();
    $data->{$namefield} = $nama;

    if ($id) {
        $data->id = $id;
        $DB->update_record('kurikulum', $data);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'),
            'Data kurikulum berhasil diperbarui.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $DB->insert_record('kurikulum', $data);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'),
        'Data kurikulum berhasil ditambahkan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$data = [
    'id' => $id,
    'nama' => $record ? ($record->{$namefield} ?? '') : '',
    'title' => $id ? 'Edit Kurikulum' : 'Tambah Kurikulum',
    'breadcrumb' => $id ? '/ Kurikulum / Edit' : '/ Kurikulum / Tambah',

    /**
     * Action diarahkan ke form.php sendiri.
     * Jadi tidak lagi mencari save.php yang menyebabkan 404.
     */
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/form.php', [
        'id' => $id,
    ]))->out(false),

    'back_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
    'sesskey' => sesskey(),

    // Sidebar active state.
    'is_dashboard' => false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => true,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => false,
    'is_notif' => false,
    'is_ekskul' => false,
    'is_mitra' => false,

    // Sidebar URLs.
    'dashboard_url' => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
    'tahun_ajaran_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
    'kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
    'manajemen_jurusan_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
    'manajemen_kelas_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
    'matpel_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
    'kktp_url' => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
    'notif_url' => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
    'ekskul_url' => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
    'mitra_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kurikulum_form', $data);
echo $OUTPUT->footer();