<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

use local_akademikmonitor\service\kktp_service;

global $PAGE, $OUTPUT;
// Ambil filter dulu supaya set_url konsisten.
$jurusanid = optional_param('jurusanid', 0, PARAM_INT);
$tingkat   = optional_param('tingkat', 'X', PARAM_TEXT);

// Setup page.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kktp/index.php', [
    'jurusanid' => $jurusanid,
    'tingkat' => $tingkat,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Pengaturan KKTP');
$PAGE->set_heading('Pengaturan KKTP');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

// SAVE
if (optional_param('save', 0, PARAM_INT)) {
    require_sesskey();

    $jurusanid = required_param('jurusanid', PARAM_INT);
    $tingkat   = required_param('tingkat', PARAM_TEXT);
    $kktp      = optional_param_array('kktp', [], PARAM_INT);

    kktp_service::update_bulk($kktp);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kktp/index.php', [
            'jurusanid' => $jurusanid,
            'tingkat' => $tingkat
        ]),
        'KKTP berhasil disimpan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Dropdown jurusan
$jurusanopts = kktp_service::get_jurusan_options();
if ($jurusanid === 0 && !empty($jurusanopts)) {
    $jurusanid = (int)$jurusanopts[0]['id'];
}
foreach ($jurusanopts as &$jo) {
    $jo['selected'] = ((int)$jo['id'] === (int)$jurusanid);
}
unset($jo);

// Dropdown tingkat
$tingkatopts = kktp_service::get_tingkat_options();
foreach ($tingkatopts as &$to) {
    $to['selected'] = ($to['value'] === $tingkat);
}
unset($to);

// Data rows
$rows = kktp_service::list_kktp($jurusanid, $tingkat);
foreach ($rows as &$r) {
    $r['kktp_options'] = kktp_service::build_kktp_options((int)$r['kktp']);
}
unset($r);

$data = [
    'sesskey' => sesskey(),
    'jurusanid' => $jurusanid,
    'tingkat' => $tingkat,
    'jurusan_options' => $jurusanopts,
    'tingkat_options' => $tingkatopts,
    'rows' => $rows,

    // sidebar
    'is_dashboard' => false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => true,
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
echo $OUTPUT->render_from_template('local_akademikmonitor/kktp', $data);
echo $OUTPUT->footer();