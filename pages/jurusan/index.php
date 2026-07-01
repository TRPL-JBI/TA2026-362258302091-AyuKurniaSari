<?php
require_once(__DIR__ . '/../../../../config.php');
use local_akademikmonitor\service\access_service;

access_service::require_manage();

global $DB, $PAGE, $OUTPUT;
$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'));
$PAGE->set_context($context);
$PAGE->set_title('Manajemen Jurusan');
$PAGE->set_heading('Manajemen Jurusan');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_jurusan_admin_urls(string $active): array {
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

$jurusans = $DB->get_records('jurusan', null, 'id DESC');

$items = [];
$no = 1;
foreach ($jurusans as $j) {
    $totalmapel = $DB->count_records_sql(
        "SELECT COUNT(km.id)
           FROM {kurikulum_mapel} km
           JOIN {kurikulum_jurusan} kj ON kj.id = km.id_kurikulum_jurusan
          WHERE kj.id_jurusan = ?",
        [$j->id]
    );

    $totalcp = $DB->count_records_sql(
        "SELECT COUNT(cp.id)
           FROM {capaian_pembelajaran} cp
           JOIN {kurikulum_mapel} km ON km.id = cp.id_kurikulum_mapel
           JOIN {kurikulum_jurusan} kj ON kj.id = km.id_kurikulum_jurusan
          WHERE kj.id_jurusan = ?",
        [$j->id]
    );

    $totaltp = $DB->count_records_sql(
        "SELECT COUNT(tp.id)
           FROM {tujuan_pembelajaran} tp
           JOIN {capaian_pembelajaran} cp ON cp.id = tp.id_capaian_pembelajaran
           JOIN {kurikulum_mapel} km ON km.id = cp.id_kurikulum_mapel
           JOIN {kurikulum_jurusan} kj ON kj.id = km.id_kurikulum_jurusan
          WHERE kj.id_jurusan = ?",
        [$j->id]
    );

    $items[] = [
        'no' => $no++,
        'id' => (int)$j->id,
        'kode' => isset($j->kode_jurusan) ? format_string((string)$j->kode_jurusan) : '',
        'nama' => format_string($j->nama_jurusan),
        'total_mapel' => $totalmapel,
        'total_cp' => $totalcp,
        'total_tp' => $totaltp,
        'set_kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/setkurikulum.php', ['id' => $j->id]))->out(false),
        'set_mapel_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/setmapel.php', ['id' => $j->id]))->out(false),
        'detail_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/readsetkurikulum.php', ['id' => $j->id]))->out(false),
        'edit_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/form.php', ['id' => $j->id]))->out(false),
        'delete_action' => (new moodle_url(
            '/local/akademikmonitor/pages/jurusan/delete.php'
        ))->out(false),
        'delete_id' => $j->id,
        'delete_sesskey' => sesskey(),
    ];
}

$templatecontext = array_merge(local_akademikmonitor_jurusan_admin_urls('jurusan'), [
    'items' => $items,
    'has_items' => !empty($items),
    'tambah_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/form.php'))->out(false),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/jurusan', $templatecontext);
echo $OUTPUT->footer();
