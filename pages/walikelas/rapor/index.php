<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\rapor_service;

global $PAGE, $OUTPUT, $USER;

$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    period_filter_service::require_editable_selected_period($tahunajaranid);

    $kelasid = required_param('kelasid', PARAM_INT);
    $sikapdata = optional_param_array('sikap', [], PARAM_ALPHA);

    rapor_service::save_nilai_sikap_kelas(
        $kelasid,
        $semester,
        $tahunajaranid,
        $sikapdata,
        (int)$USER->id
    );

    redirect(
        new moodle_url(
            '/local/akademikmonitor/pages/walikelas/rapor/index.php',
            period_filter_service::append_filter_params([])
        ),
        'Nilai sikap berhasil disimpan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_url('/local/akademikmonitor/pages/walikelas/rapor/index.php', [
    'semester' => $semester,
    'tahunajaranid' => $tahunajaranid,
]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Raport Kelas');
$PAGE->set_heading('Raport Kelas');

$PAGE->requires->css('/local/akademikmonitor/css/walikelasstyles.css');
$PAGE->requires->css('/local/akademikmonitor/css/styles.css');
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$data = rapor_service::get_page_data((int)$USER->id, $semester, $tahunajaranid);
// $data['periodfilter'] = period_filter_service::get_filter_ui_data(
//     '/local/akademikmonitor/pages/walikelas/rapor/index.php'
// );

echo $OUTPUT->header();

if (!empty($data['nokelas'])) {
    echo $OUTPUT->notification('Anda belum punya kelas', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/raport', $data);
echo $OUTPUT->footer();