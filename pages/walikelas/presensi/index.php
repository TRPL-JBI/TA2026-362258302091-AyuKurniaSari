<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\presensi_service;

global $PAGE, $OUTPUT, $USER;

$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();
$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/walikelas/presensi/index.php', [
    'courseid' => $courseid,
    'semester' => $semester,
    'tahunajaranid' => $tahunajaranid,
]));

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Monitoring Presensi');
$PAGE->set_heading('Monitoring Presensi');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/walikelasstyles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$data = presensi_service::get_page_data(
    (int)$USER->id,
    $courseid,
    $semester,
    $tahunajaranid
);

echo $OUTPUT->header();

if (!empty($data['nokelas'])) {
    echo $OUTPUT->notification('Anda belum punya kelas pada tahun ajaran yang dipilih.', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

if (empty($data['attendance_available'])) {
    echo $OUTPUT->notification('Plugin Attendance belum terpasang atau tabel attendance belum tersedia.', 'notifyproblem');
}

echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/presensi', $data);
echo $OUTPUT->footer();