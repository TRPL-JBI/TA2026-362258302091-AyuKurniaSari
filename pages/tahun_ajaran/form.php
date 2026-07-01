<?php
require_once(__DIR__ . '/../../../../config.php');
use local_akademikmonitor\service\access_service;
access_service::require_manage();


global $PAGE, $OUTPUT, $DB;

$id = optional_param('id', 0, PARAM_INT);

$context = context_system::instance();

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/form.php', [
    'id' => $id,
]));
$PAGE->set_context($context);
$PAGE->set_title($id ? 'Edit Tahun Ajaran' : 'Tambah Tahun Ajaran');
$PAGE->set_heading($id ? 'Edit Tahun Ajaran' : 'Tambah Tahun Ajaran');
$PAGE->set_pagelayout('admin');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$record = null;

if ($id) {
    $record = $DB->get_record('tahun_ajaran', ['id' => $id], '*', MUST_EXIST);
}

/**
 * Proses simpan data.
 *
 * require_sesskey() hanya boleh dipanggil saat POST.
 * Kalau dipanggil saat GET, Moodle akan menampilkan error:
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
            new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/form.php', [
                'id' => $id,
            ]),
            'Tahun ajaran wajib diisi.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($id) {
        $select = 'tahun_ajaran = :tahun_ajaran AND id <> :id';
        $params = [
            'tahun_ajaran' => $nama,
            'id' => $id,
        ];

        $exists = $DB->record_exists_select('tahun_ajaran', $select, $params);
    } else {
        $exists = $DB->record_exists('tahun_ajaran', [
            'tahun_ajaran' => $nama,
        ]);
    }

    if ($exists) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/form.php', [
                'id' => $id,
            ]),
            'Tahun ajaran sudah ada.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $data = new stdClass();
    $data->tahun_ajaran = $nama;

    if ($id) {
        $data->id = $id;
        $DB->update_record('tahun_ajaran', $data);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'),
            'Data tahun ajaran berhasil diperbarui.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $DB->insert_record('tahun_ajaran', $data);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'),
        'Data tahun ajaran berhasil ditambahkan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$templatecontext = [
    'id' => $id,
    'nama' => $record ? $record->tahun_ajaran : '',
    'title' => $id ? 'Edit Tahun Ajaran' : 'Tambah Tahun Ajaran',
    'breadcrumb' => $id ? '/ Tahun Ajaran / Edit' : '/ Tahun Ajaran / Tambah',

    'action_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/form.php', [
        'id' => $id,
    ]))->out(false),

    'back_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
    'sesskey' => sesskey(),

    'is_dashboard' => false,
    'is_tahun_ajaran' => true,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => false,
    'is_notif' => false,
    'is_ekskul' => false,
    'is_mitra' => false,

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
echo $OUTPUT->render_from_template('local_akademikmonitor/tahun_ajaran_form', $templatecontext);
echo $OUTPUT->footer();