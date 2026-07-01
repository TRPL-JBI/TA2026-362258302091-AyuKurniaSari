<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;
use local_akademikmonitor\service\mitra_service;

access_service::require_manage();

global $PAGE, $OUTPUT;

$context = context_system::instance();

$view = optional_param('view', 'active', PARAM_ALPHA);
$view = ($view === 'archived') ? 'archived' : 'active';

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/mitra/index.php', [
    'view' => $view,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Mitra');
$PAGE->set_heading('Mitra');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');
$PAGE->requires->js_call_amd('local_akademikmonitor/mitra', 'init');

$rows = mitra_service::list_mitra($view);

$data = [
    'sesskey' => sesskey(),
    'ajaxurl' => (new moodle_url('/local/akademikmonitor/pages/mitra/ajax.php'))->out(false),
    'rows' => $rows,

    // Import CSV.
    'template_csv_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/template.php'))->out(false),
    'import_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/import.php'))->out(false),

    // Tab control.
    'is_active_view' => ($view === 'active'),
    'is_archived_view' => ($view === 'archived'),
    'active_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php', [
        'view' => 'active',
    ]))->out(false),
    'archived_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php', [
        'view' => 'archived',
    ]))->out(false),

    // Sidebar flags.
    'is_dashboard' => false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_mata_pelajaran' => false,
    'is_kktp' => false,
    'is_notif' => false,
    'is_ekskul' => false,
    'is_mitra' => true,

    'dashboard_url' => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
    'tahun_ajaran_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
    'kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
    'manajemen_jurusan_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
    'manajemen_kelas_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
    'matpel_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
    'mata_pelajaran_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
    'kktp_url' => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
    'notif_url' => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
    'ekskul_url' => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
    'mitra_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/mitra', $data);
echo $OUTPUT->footer();