<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\notif_service;

require_login();

global $PAGE, $OUTPUT;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/notif/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Pengaturan Notifikasi');
$PAGE->set_heading('Pengaturan Notifikasi');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');
$PAGE->requires->js_call_amd('local_akademikmonitor/notif', 'init');

notif_service::ensure_default_rules();

$setting = notif_service::get_setting();
$list = notif_service::list_rules();
$telegramusers = notif_service::get_connected_telegram_users();
foreach ($telegramusers as $user) {
    $user->disconnect_action =
        (new moodle_url(
            '/local/akademikmonitor/pages/notif/disconnect_telegram.php'
        ))->out(false);

    $user->disconnect_id = $user->id;
    $user->disconnect_sesskey = sesskey();
}
$data = [
    'sesskey' => sesskey(),
    'ajaxurl' => (new moodle_url('/local/akademikmonitor/pages/notif/ajax.php'))->out(false),

    'telegramusers' => array_values($telegramusers),

    'export_telegram_siswa_url' => (new moodle_url(
        '/local/akademikmonitor/pages/notif/export_telegram_siswa.php',
        ['sesskey' => sesskey()]
    ))->out(false),
    'bot_token' => $setting->bot_token ?? '',
    'rules' => $list['rules'],
    'reports' => $list['reports'],

    // Sidebar.
    'is_dashboard' => false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => false,
    'is_notif' => true,
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
echo $OUTPUT->render_from_template('local_akademikmonitor/notif', $data);
echo $OUTPUT->footer();