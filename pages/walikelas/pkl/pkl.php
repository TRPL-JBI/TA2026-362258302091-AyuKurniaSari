<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\pkl_service;

global $PAGE, $OUTPUT, $USER;

$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();

$PAGE->set_url('/local/akademikmonitor/pages/walikelas/pkl/pkl.php', [
    'semester' => $semester,
    'tahunajaranid' => $tahunajaranid,
]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('PKL Siswa');
$PAGE->set_heading('PKL Siswa');

$PAGE->requires->css('/local/akademikmonitor/css/walikelasstyles.css');
$PAGE->requires->css('/local/akademikmonitor/css/styles.css');
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');
$PAGE->requires->js_call_amd('local_akademikmonitor/pkl_siswa', 'init', [$semester]);

$data = pkl_service::get_page_data
    ((int)$USER->id, 
    $semester,
    $tahunajaranid);

$data += period_filter_service::build_filter_data();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/pkl', $data);
echo $OUTPUT->footer();