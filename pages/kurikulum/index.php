<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\access_service;
access_service::require_manage();
global $PAGE, $OUTPUT, $DB;

$context = context_system::instance();

$q = optional_param('q', '', PARAM_TEXT);
$q = trim($q);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php', [
    'q' => $q,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Data Kurikulum');
$PAGE->set_heading('Data Kurikulum');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

/**
 * Ambil data kurikulum.
 *
 * Kolom nama kurikulum di plugin ini umumnya bernama "nama".
 * Kalau di database kamu ternyata namanya "nama_kurikulum",
 * kode ini tetap mencoba menyesuaikan.
 */
$columns = $DB->get_columns('kurikulum');
$namefield = isset($columns['nama']) ? 'nama' : 'nama_kurikulum';

if ($q !== '') {
    $select = $DB->sql_like($namefield, ':q', false);
    $params = [
        'q' => '%' . $DB->sql_like_escape($q) . '%',
    ];

    $kurikulums = $DB->get_records_select(
        'kurikulum',
        $select,
        $params,
        'id DESC'
    );
} else {
    $kurikulums = $DB->get_records('kurikulum', null, 'id DESC');
}

/**
 * Kurikulum aktif disimpan di config plugin.
 * Ini aman karena tidak mengubah struktur database dan tidak menghapus data lama.
 */
$activeid = (int) get_config('local_akademikmonitor', 'active_kurikulumid');

if (!$activeid) {
    $first = reset($kurikulums);
    $activeid = $first ? (int) $first->id : 0;
}

$items = [];
$no = 1;

foreach ($kurikulums as $kurikulum) {
    $isactive = ((int) $kurikulum->id === $activeid);

    $items[] = [
        'id' => $kurikulum->id,
        'no' => $no++,
        'nama' => format_string($kurikulum->{$namefield} ?? '-'),

        'is_active' => $isactive,
        'status_label' => $isactive ? 'Aktif' : 'Arsip',
        'status_class' => $isactive ? 'on' : 'off',

        'edit_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/form.php', [
            'id' => $kurikulum->id,
        ]))->out(false),

        'aktif_url' => (new moodle_url(
            '/local/akademikmonitor/pages/kurikulum/aktifkan.php'
        ))->out(false),

        'aktif_id' => $kurikulum->id,
        'aktif_sesskey' => sesskey(),
        
    ];
}

$data = [
    'items' => $items,
    'q' => s($q),

    'tambah_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/form.php'))->out(false),
    'search_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),

    // Sidebar active state.
    'is_dashboard' => false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => true,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => false,
    'is_notif' => false,
    'is_ekskul' => false,
    'is_mitra' => false,

    // Sidebar URLs.
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
echo $OUTPUT->render_from_template('local_akademikmonitor/kurikulum', $data);
echo $OUTPUT->footer();