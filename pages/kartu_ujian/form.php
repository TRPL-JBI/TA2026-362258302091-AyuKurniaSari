<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

global $DB, $PAGE, $OUTPUT;

$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/form.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title($id ? 'Edit Kartu Ujian' : 'Tambah Kartu Ujian');
$PAGE->set_heading($id ? 'Edit Kartu Ujian' : 'Tambah Kartu Ujian');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_kuform_admin_urls(string $active): array {
    return [
        'is_dashboard'=>false,'is_tahun_ajaran'=>false,'is_kurikulum'=>false,
        'is_manajemen_jurusan'=>false,'is_manajemen_kelas'=>false,
        'is_mata_pelajaran'=>false,'is_matpel'=>false,'is_kktp'=>false,
        'is_notif'=>false,'is_ekskul'=>false,'is_mitra'=>false,'is_kartu_ujian'=>true,
        'dashboard_url'         =>(new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url'      =>(new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url'         =>(new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' =>(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url'   =>(new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url'    =>(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url'            =>(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url'              =>(new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url'             =>(new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url'            =>(new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url'             =>(new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
        'kartu_ujian_url'       =>(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'))->out(false),
    ];
}

$existing = null;
if ($id > 0) {
    $existing = $DB->get_record('kartu_ujian', ['id' => $id], '*', MUST_EXIST);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $nama_ujian      = required_param('nama_ujian', PARAM_TEXT);
    $id_kelas        = required_param('id_kelas', PARAM_INT);
    $penandatangan   = optional_param('penandatangan', '', PARAM_TEXT);

    // Ambil tahun_ajaran dari kelas yang dipilih
    $kelas = $DB->get_record('kelas', ['id' => $id_kelas], '*', MUST_EXIST);
    $id_tahun_ajaran = (int)$kelas->id_tahun_ajaran;

    // Kurikulum aktif
/*
 * Ambil kurikulum berdasarkan kelas yang dipilih.
 *
 * Di plugin ini, kelas tidak langsung menyimpan id_kurikulum.
 * Relasinya adalah:
 * kelas.id_jurusan + kelas.id_tahun_ajaran
 * -> kurikulum_jurusan
 * -> kurikulum
 */
$kurikulum = $DB->get_record_sql("
    SELECT
        kur.id,
        kur.nama
    FROM {kurikulum_jurusan} kj
    JOIN {kurikulum} kur
        ON kur.id = kj.id_kurikulum
    WHERE kj.id_jurusan = :idjurusan
      AND kj.id_tahun_ajaran = :idtahunajaran
", [
    'idjurusan' => (int)$kelas->id_jurusan,
    'idtahunajaran' => (int)$kelas->id_tahun_ajaran,
], IGNORE_MULTIPLE);

$id_kurikulum = $kurikulum ? (int)$kurikulum->id : 0;

    // Semester otomatis dari tingkat kelas (X/XI/XII → ganjil/genap dipilih user karena kelas bisa ganjil/genap)
    $semester = required_param('semester', PARAM_TEXT);

    $data = new stdClass();
    $data->nama_ujian       = $nama_ujian;
    $data->id_kelas         = $id_kelas;
    $data->id_tahun_ajaran  = $id_tahun_ajaran;
    $data->id_kurikulum     = $id_kurikulum;
    $data->semester         = $semester;
    $data->penandatangan    = $penandatangan;
    $data->status           = 'draft';
    $data->timecreated      = time();
    $data->timemodified     = time();

    if ($existing) {
        $data->id     = $existing->id;
        $data->status = $existing->status; // pertahankan status
        $DB->update_record('kartu_ujian', $data);
        $msg = 'Kartu ujian berhasil diperbarui.';
    } else {
        $DB->insert_record('kartu_ujian', $data);
        $msg = 'Kartu ujian berhasil ditambahkan.';
    }

    redirect(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'), $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Daftar kelas untuk pilihan
$sql = "SELECT k.id, k.nama, k.tingkat, j.nama_jurusan, ta.tahun_ajaran
          FROM {kelas} k
          JOIN {jurusan} j ON j.id = k.id_jurusan
     LEFT JOIN {tahun_ajaran} ta ON ta.id = k.id_tahun_ajaran
      ORDER BY ta.tahun_ajaran DESC, k.nama ASC";
$kelasoptions = [];
foreach ($DB->get_records_sql($sql) as $k) {
    $kelasoptions[] = [
        'id'       => (int)$k->id,
        'label'    => format_string($k->nama . ' - ' . $k->nama_jurusan . ' (' . ($k->tahun_ajaran ?? '-') . ')'),
        'selected' => $existing && (int)$existing->id_kelas === (int)$k->id,
    ];
}

$semesteroptions = [
    ['value' => 'Ganjil', 'label' => 'Ganjil', 'selected' => $existing && $existing->semester === 'Ganjil'],
    ['value' => 'Genap',  'label' => 'Genap',  'selected' => $existing && $existing->semester === 'Genap'],
];

$templatecontext = array_merge(local_akademikmonitor_kuform_admin_urls('kartu_ujian'), [
    'is_edit'           => (bool)$existing,
    'id'                => $id,
    'nama_ujian'        => $existing ? s($existing->nama_ujian) : '',
    'penandatangan'     => $existing ? s($existing->penandatangan) : '',
    'kelas_options'     => $kelasoptions,
    'semester_options'  => $semesteroptions,
    'sesskey'           => sesskey(),
    'action_url'        => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/form.php', ['id' => $id]))->out(false),
    'back_url'          => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'))->out(false),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kartu_ujian_form', $templatecontext);
echo $OUTPUT->footer();
