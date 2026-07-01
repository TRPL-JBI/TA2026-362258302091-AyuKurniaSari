<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

use local_akademikmonitor\service\admin_presensi_guru_service;
use local_akademikmonitor\service\period_filter_service;

global $PAGE, $OUTPUT;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$tahunajaranid = optional_param('tahunajaranid', 0, PARAM_INT);
$semester = optional_param('semester', 0, PARAM_INT);
$kelasid = optional_param('kelasid', 0, PARAM_INT);
$teacherid = optional_param('teacherid', 0, PARAM_INT);

if ($tahunajaranid <= 0) {
    $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
}

if (!in_array($semester, [1, 2], true)) {
    $semester = period_filter_service::get_selected_semester();
}

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/presensi_guru/index.php', [
    'tahunajaranid' => $tahunajaranid,
    'semester' => $semester,
    'kelasid' => $kelasid,
    'teacherid' => $teacherid,
]));

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Monitoring Presensi Guru');
$PAGE->set_heading('Monitoring Presensi Guru');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/walikelasstyles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$data = admin_presensi_guru_service::get_page_data(
    $tahunajaranid,
    $semester,
    $kelasid,
    $teacherid
);

echo $OUTPUT->header();

if (empty($data['attendance_available'])) {
    echo $OUTPUT->notification('Plugin Attendance belum terpasang atau tabel attendance belum tersedia.', 'notifyproblem');
}

echo $OUTPUT->render_from_template('local_akademikmonitor/presensi_guru', $data);

echo $OUTPUT->footer();