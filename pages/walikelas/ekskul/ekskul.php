<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\ekskul_service;

global $PAGE, $OUTPUT, $USER;

$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php', [
    'semester' => $semester,
    'tahunajaranid' => $tahunajaranid,
]));

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Ekstrakurikuler Siswa');
$PAGE->set_heading('Ekstrakurikuler Siswa');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/walikelasstyles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));

$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

/*
 * JS ini wajib aktif karena tombol Tambah, Edit, Import,
 * Close modal, dan Simpan menggunakan data-action dari file AMD ini.
 *
 * Kalau baris ini dimatikan, tombol tetap terlihat,
 * tetapi modal tidak akan terbuka karena tidak ada event listener.
 */
$PAGE->requires->js_call_amd('local_akademikmonitor/ekskul_siswa', 'init', [
    $semester,
    $tahunajaranid,
]);

$data = ekskul_service::get_page_data(
    (int)$USER->id,
    $semester,
    $tahunajaranid
);

$data += period_filter_service::build_filter_data();

echo $OUTPUT->header();

if (!empty($data['nokelas'])) {
    echo $OUTPUT->notification('Anda belum punya kelas.', 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/ekskul', $data);
echo $OUTPUT->footer();