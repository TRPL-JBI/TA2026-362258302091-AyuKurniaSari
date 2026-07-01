<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

use local_akademikmonitor\service\admin_presensi_service;
use local_akademikmonitor\service\period_filter_service;

global $PAGE, $OUTPUT;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$tahunajaranid = optional_param('tahunajaranid', 0, PARAM_INT);
$semester = optional_param('semester', 0, PARAM_INT);
$kelasid = optional_param('kelasid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// membaca filter tahun ajaran
if ($tahunajaranid <= 0) {
    $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
}
// membaca filter semester
if (!in_array($semester, [1, 2], true)) {
    $semester = period_filter_service::get_selected_semester();
}

// url halaman sekarang, dengan filter tahun ajaran, semester, kelas, course
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/presensi/index.php', [
    'tahunajaranid' => $tahunajaranid,
    'semester' => $semester,
    'kelasid' => $kelasid,
    'courseid' => $courseid,
]));

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Monitoring Presensi');
$PAGE->set_heading('Monitoring Presensi');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/walikelasstyles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$data = admin_presensi_service::get_page_data(
    $tahunajaranid,
    $semester,
    $kelasid,
    $courseid
);

echo $OUTPUT->header();

if (empty($data['attendance_available'])) {
    echo $OUTPUT->notification('Plugin Attendance belum terpasang atau tabel attendance belum tersedia.', 'notifyproblem');
}

echo $OUTPUT->render_from_template('local_akademikmonitor/presensi', $data);

echo $OUTPUT->footer();