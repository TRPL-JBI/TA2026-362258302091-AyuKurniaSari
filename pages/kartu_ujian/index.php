<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

global $DB, $PAGE, $OUTPUT;

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'));
$PAGE->set_context($context);
$PAGE->set_title('Kartu Ujian');
$PAGE->set_heading('Kartu Ujian');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_ku_admin_urls(string $active): array {
    return [
        // Flag sidebar.
        'is_dashboard' => false,
        'is_tahun_ajaran' => false,
        'is_kurikulum' => false,
        'is_manajemen_jurusan' => false,
        'is_manajemen_kelas' => false,
        'is_matpel' => false,
        'is_kktp' => false,
        'is_notif' => false,
        'is_ekskul' => false,
        'is_mitra' => false,
        'is_kartu_ujian' => true,

        // URL sidebar.
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
        'kartu_ujian_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'))->out(false),
    ];
}

// Ambil semua kartu ujian beserta info kelas & jurusan
$sql = "SELECT ku.id,
               ku.nama_ujian,
               ku.id_kelas,
               ku.id_tahun_ajaran,
               ku.semester,
               ku.status,
               ku.penandatangan,
               ku.timecreated,
               k.nama AS nama_kelas,
               k.tingkat,
               j.nama_jurusan,
               ta.tahun_ajaran
          FROM {kartu_ujian} ku
          JOIN {kelas} k  ON k.id  = ku.id_kelas
          JOIN {jurusan} j ON j.id = k.id_jurusan
     LEFT JOIN {tahun_ajaran} ta ON ta.id = ku.id_tahun_ajaran
      ORDER BY ku.id_tahun_ajaran DESC, k.nama ASC";

$records = $DB->get_records_sql($sql);

$groupsmap = [];
$no = 1;
foreach ($records as $r) {
    $tahunlabel = $r->tahun_ajaran ?? '-';
    $row = [
        'no'          => $no++,
        'id'          => (int)$r->id,
        'nama_ujian'  => format_string($r->nama_ujian),
        'nama_kelas'  => format_string($r->nama_kelas),
        'tingkat'     => format_string($r->tingkat),
        'nama_jurusan'=> format_string($r->nama_jurusan),
        'tahun_ajaran'=> format_string($tahunlabel),
        'semester'    => format_string($r->semester),
        'status'      => $r->status,
        'is_published'=> $r->status === 'published',
        'is_draft'    => $r->status !== 'published',
        'detail_url'  => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $r->id]))->out(false),
        'edit_url'    => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/form.php', ['id' => $r->id]))->out(false),
        'delete_action' => (new moodle_url(
            '/local/akademikmonitor/pages/kartu_ujian/delete.php'
        ))->out(false),

        'delete_id' => $r->id,
        'delete_sesskey' => sesskey(),
    ];
    if (!isset($groupsmap[$tahunlabel])) {
        $groupsmap[$tahunlabel] = ['tahun' => format_string($tahunlabel), 'items' => []];
    }
    $groupsmap[$tahunlabel]['items'][] = $row;
}

$templatecontext = array_merge(local_akademikmonitor_ku_admin_urls('kartu_ujian'), [
    'groups'    => array_values($groupsmap),
    'has_items' => !empty($records),
    'add_url'   => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/form.php'))->out(false),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kartu_ujian', $templatecontext);
echo $OUTPUT->footer();
