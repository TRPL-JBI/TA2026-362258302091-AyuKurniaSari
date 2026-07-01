<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

global $PAGE, $OUTPUT, $DB;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$q = optional_param('q', '', PARAM_TEXT);
$q = trim($q);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php', [
    'q' => $q,
]));

/**
 * Pakai layout admin seperti menu Ekskul.
 *
 * Jangan pakai 'standard' untuk halaman admin plugin ini,
 * karena layout standard bisa membuat area konten menjadi lebih sempit.
 */
$PAGE->set_pagelayout('admin');

$PAGE->set_title('Data Tahun Ajaran');
$PAGE->set_heading('Data Tahun Ajaran');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

/**
 * Ambil data tahun ajaran.
 *
 * Kalau ada q dari search, data difilter berdasarkan kolom tahun_ajaran.
 * Kalau q kosong, semua data ditampilkan.
 */
if ($q !== '') {
    $select = $DB->sql_like('tahun_ajaran', ':q', false);
    $params = [
        'q' => '%' . $DB->sql_like_escape($q) . '%',
    ];

    $tahunajarans = $DB->get_records_select(
        'tahun_ajaran',
        $select,
        $params,
        'id DESC'
    );
} else {
    $tahunajarans = $DB->get_records('tahun_ajaran', null, 'id DESC');
}

/**
 * Ambil tahun ajaran aktif dari config plugin.
 *
 * Kenapa pakai config?
 * Karena ini tidak mengubah struktur tabel database.
 * Tahun ajaran yang aktif cukup disimpan sebagai setting plugin.
 */
$activeid = (int) get_config('local_akademikmonitor', 'active_tahunajaranid');

if (!$activeid) {
    $first = reset($tahunajarans);
    $activeid = $first ? (int) $first->id : 0;
}

$items = [];
$no = 1;

foreach ($tahunajarans as $ta) {
    $isactive = ((int) $ta->id === $activeid);

    $items[] = [
        'id' => $ta->id,
        'no' => $no++,
        'nama' => format_string($ta->tahun_ajaran),

        'is_active' => $isactive,
        'status_label' => $isactive ? 'Aktif' : 'Arsip',
        'status_class' => $isactive ? 'on' : 'off',

        'edit_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/form.php', [
            'id' => $ta->id,
        ]))->out(false),

        'aktif_url' => (new moodle_url(
            '/local/akademikmonitor/pages/tahun_ajaran/aktifkan.php'
        ))->out(false),

        'aktif_id' => (int)$ta->id,
        'aktif_sesskey' => sesskey(),
    ];
}

$data = [
    'items' => $items,
    'q' => s($q),

    'tambah_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/form.php'))->out(false),
    'search_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),

    // Flags sidebar.
    'is_dashboard' => false,
    'is_tahun_ajaran' => true,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => false,
    'is_notif' => false,
    'is_ekskul' => false,
    'is_mitra' => false,

    // URL sidebar.
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
echo $OUTPUT->render_from_template('local_akademikmonitor/tahun_ajaran', $data);
echo $OUTPUT->footer();