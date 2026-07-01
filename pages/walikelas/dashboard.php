<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

use local_akademikmonitor\service\walikelas\dashboard_service;

global $PAGE, $OUTPUT, $USER;

$PAGE->set_url('/local/akademikmonitor/pages/walikelas/dashboard.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Dashboard Wali Kelas');
$PAGE->set_heading('Dashboard Wali Kelas');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/walikelasstyles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/dashboard.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$data = dashboard_service::get_page_data((int)$USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/dashboard', $data);
echo $OUTPUT->footer();