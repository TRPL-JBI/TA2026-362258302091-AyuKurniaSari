<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

global $DB, $PAGE, $OUTPUT;

$id = required_param('id', PARAM_INT);

$kelas = $DB->get_record('kelas', ['id' => $id], '*', MUST_EXIST);
$jurusan = $DB->get_record('jurusan', ['id' => $kelas->id_jurusan], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kelas/histori.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title('Histori Kelas');
$PAGE->set_heading('Histori Kelas');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_histori_admin_urls(string $active): array {
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

$records = $DB->get_records_sql(
    "SELECT k.id, k.nama, k.tingkat, k.id_tahun_ajaran, j.nama_jurusan,
            ta.tahun_ajaran, u.firstname, u.lastname
       FROM {kelas} k
       JOIN {jurusan} j ON j.id = k.id_jurusan
  LEFT JOIN {tahun_ajaran} ta ON ta.id = k.id_tahun_ajaran
  LEFT JOIN {user} u ON u.id = k.id_user
      WHERE k.id_jurusan = :jurusanid
        AND k.nama = :nama
   ORDER BY k.id_tahun_ajaran ASC, k.tingkat ASC, k.id ASC",
    [
        'jurusanid' => (int)$kelas->id_jurusan,
        'nama' => (string)$kelas->nama,
    ]
);

$items = [];
$no = 1;
foreach ($records as $row) {
    $items[] = [
        'no' => $no++,
        'nama' => format_string($row->nama),
        'jurusan' => format_string($row->nama_jurusan),
        'tingkat' => format_string($row->tingkat),
        'tahun_ajaran' => isset($row->tahun_ajaran) ? format_string($row->tahun_ajaran) : '-',
        'wali_nama' => trim((string)($row->firstname ?? '') . ' ' . (string)($row->lastname ?? '')) ?: '-',
        'is_current' => ((int)$row->id === (int)$kelas->id),
    ];
}

$templatecontext = array_merge(local_akademikmonitor_histori_admin_urls('kelas'), [
    'kelas_nama' => format_string($kelas->nama),
    'jurusan_nama' => format_string($jurusan->nama_jurusan),
    'items' => $items,
    'has_items' => !empty($items),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kelas_histori', $templatecontext);
echo $OUTPUT->footer();
