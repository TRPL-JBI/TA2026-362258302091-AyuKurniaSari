<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

$context = context_system::instance();

global $DB, $PAGE, $OUTPUT;

$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/form.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title($id ? 'Edit Jurusan' : 'Tambah Jurusan');
$PAGE->set_heading($id ? 'Edit Jurusan' : 'Tambah Jurusan');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

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

function local_akademikmonitor_jurusan_kode_is_integer(): bool {
    global $DB;
    $columns = $DB->get_columns('jurusan');
    if (!isset($columns['kode_jurusan'])) {
        return false;
    }
    $meta = strtolower((string)($columns['kode_jurusan']->meta_type ?? ''));
    $type = strtolower((string)($columns['kode_jurusan']->type ?? ''));
    return in_array($meta, ['i', 'n'], true) || strpos($type, 'int') !== false;
}

$record = $id ? $DB->get_record('jurusan', ['id' => $id], '*', MUST_EXIST) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $postid = optional_param('id', 0, PARAM_INT);
    if ($postid > 0) {
        $id = $postid;
    }

    $kodeinput = trim(required_param('kode', PARAM_TEXT));
    $nama = trim(required_param('nama', PARAM_TEXT));

    if ($nama === '') {
        redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/form.php', ['id' => $id]), 'Nama jurusan wajib diisi.', null, \core\output\notification::NOTIFY_ERROR);
    }

    $data = new stdClass();
    $data->nama_jurusan = $nama;
    $data->kode_jurusan = local_akademikmonitor_jurusan_kode_is_integer()
        ? (is_numeric($kodeinput) ? (int)$kodeinput : 0)
        : $kodeinput;

    $exists = $id
        ? $DB->record_exists_select('jurusan', 'nama_jurusan = :nama AND id <> :id', ['nama' => $nama, 'id' => $id])
        : $DB->record_exists('jurusan', ['nama_jurusan' => $nama]);

    if ($exists) {
        redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/form.php', ['id' => $id]), 'Nama jurusan sudah ada.', null, \core\output\notification::NOTIFY_ERROR);
    }

    if ($id) {
        $data->id = $id;
        $DB->update_record('jurusan', $data);
        redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'), 'Data jurusan berhasil diperbarui.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    $transaction = $DB->start_delegated_transaction();

    try {

        $newid = $DB->insert_record('jurusan', $data);

        $activekurikulum = (int)get_config('local_akademikmonitor', 'active_kurikulumid');
        $activetahun = (int)get_config('local_akademikmonitor', 'active_tahunajaranid');

        if (
            $activekurikulum > 0 &&
            $activetahun > 0 &&
            !$DB->record_exists(
                'kurikulum_jurusan',
                [
                    'id_jurusan' => $newid,
                    'id_kurikulum' => $activekurikulum,
                    'id_tahun_ajaran' => $activetahun
                ]
            )
        ) {

            $kj = new stdClass();
            $kj->id_jurusan = $newid;
            $kj->id_kurikulum = $activekurikulum;
            $kj->id_tahun_ajaran = $activetahun;

            $DB->insert_record('kurikulum_jurusan', $kj);
        }

        $transaction->allow_commit();

        redirect(
            new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'),
            'Data jurusan berhasil ditambahkan.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );

    } catch (\Throwable $e) {

        $transaction->rollback($e);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/jurusan/form.php', ['id' => $id]),
            'Gagal menyimpan data: ' . $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );

    }
}

$templatecontext = array_merge(local_akademikmonitor_backend_admin_urls('jurusan'), [
    'id' => $id,
    'kode' => $record ? (($record->kode_jurusan ?? '') === '0' ? '' : (string)($record->kode_jurusan ?? '')) : '',
    'nama' => $record ? (string)$record->nama_jurusan : '',
    'title' => $id ? 'Edit Jurusan' : 'Tambah Jurusan',
    'breadcrumb' => $id ? '/ Manajemen Jurusan / Edit' : '/ Manajemen Jurusan / Tambah',
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/form.php', ['id' => $id]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
    'sesskey' => sesskey(),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/jurusan_form', $templatecontext);
echo $OUTPUT->footer();
