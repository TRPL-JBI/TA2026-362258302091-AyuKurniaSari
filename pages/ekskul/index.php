<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

global $DB, $OUTPUT, $PAGE;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Ekstrakurikuler');
$PAGE->set_heading('Ekstrakurikuler');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');
$PAGE->requires->js_call_amd('local_akademikmonitor/ekskul', 'init');
$PAGE->requires->js_call_amd(
    'local_akademikmonitor/searchable_select',
    'init'
);

use local_akademikmonitor\service\ekskul_service;

global $DB, $OUTPUT;

// 1) Ambil data ekskul dari service (tanpa SQL manual di page).
$rows = ekskul_service::list_ekskul();

$pembina_options = ekskul_service::list_pembina_options();

// Data untuk mustache template.
$data = [
    'sesskey' => sesskey(),
    'ajaxurl' => (new moodle_url('/local/akademikmonitor/pages/ekskul/ajax.php'))->out(false),
    'rows' => $rows,
    'pembina_options' => $pembina_options,

    // flags sidebar (tetap seperti punyamu)
    'is_dashboard' =>false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => false,
    'is_notif' => false,
    'is_ekskul' => true,
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
echo $OUTPUT->render_from_template('local_akademikmonitor/ekskul', $data);
echo $OUTPUT->footer();