<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\monitoring_service;


global $PAGE, $OUTPUT, $USER;

$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();
$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php', [
    'courseid' => $courseid,
    'semester' => $semester,
    'tahunajaranid' => $tahunajaranid,
]));

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Monitoring Kelas');
$PAGE->set_heading('Monitoring Kelas');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/walikelasstyles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

/*
 * Tidak memakai JS monitoring_kelas lagi untuk dropdown mapel.
 * Dropdown mapel dibuat sebagai form GET biasa supaya courseid pasti terkirim.
 */

$data = monitoring_service::get_page_data(
    (int)$USER->id,
    $courseid,
    $semester,
    $tahunajaranid
);

// $data['periodfilter'] = period_filter_service::get_filter_ui_data(
//     '/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php',
//     ['courseid' => $data['selected_course'] ?? 0]
// );

echo $OUTPUT->header();

if (!empty($data['nokelas'])) {
    echo $OUTPUT->notification('Anda belum punya kelas', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/monitoring', $data);
echo $OUTPUT->footer();