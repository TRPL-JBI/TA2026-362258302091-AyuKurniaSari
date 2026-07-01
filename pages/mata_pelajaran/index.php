<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

global $PAGE, $OUTPUT, $DB;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$q = optional_param('q', '', PARAM_TEXT);
$q = trim($q);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php', [
    'q' => $q,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Data Mata Pelajaran');
$PAGE->set_heading('Data Mata Pelajaran');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

/**
 * Ambil data mata pelajaran.
 * Kalau q diisi, data difilter berdasarkan nama_mapel.
 */
if ($q !== '') {
    $select = $DB->sql_like('nama_mapel', ':q', false);
    $params = [
        'q' => '%' . $DB->sql_like_escape($q) . '%',
    ];

    $mapels = $DB->get_records_select(
        'mata_pelajaran',
        $select,
        $params,
        'id DESC'
    );
} else {
    $mapels = $DB->get_records('mata_pelajaran', null, 'id DESC');
}

/**
 * Grouping berdasarkan prefix kategori.
 * Contoh:
 * [umum] Bahasa Indonesia  => kategori umum
 * [kejuruan] Pemrograman   => kategori kejuruan
 */
$grouped = [];

foreach ($mapels as $m) {
    $rawname = (string) $m->nama_mapel;

    $kategori = 'lainnya';

    if (preg_match('/^\[(.*?)\]\s*/', $rawname, $match)) {
        $kategori = strtolower(trim($match[1]));
    }

    $namabersih = preg_replace('/^\[.*?\]\s*/', '', $rawname);
    $namabersih = trim($namabersih);

    if ($namabersih === '') {
        $namabersih = $rawname;
    }

    if (!isset($grouped[$kategori])) {
        $grouped[$kategori] = [
            'nama_kategori' => ucfirst($kategori),
            'items' => [],
            'no' => 1,
        ];
    }

    $grouped[$kategori]['items'][] = [
        'id' => $m->id,
        'no' => $grouped[$kategori]['no']++,
        'nama' => format_string($namabersih),

        'edit_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/form.php', [
            'id' => $m->id,
        ]))->out(false),

        /**
         * delete.php wajib membawa sesskey karena proses hapus mengubah data.
         */
        'delete_action' => (new moodle_url(
            '/local/akademikmonitor/pages/mata_pelajaran/delete.php'
        ))->out(false),

        'delete_id' => $m->id,
        'delete_sesskey' => sesskey(),
    ];
}

$kategori_list = array_values($grouped);

$data = [
    'kategori_list' => $kategori_list,
    'q' => s($q),

    'tambah_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/form.php'))->out(false),
    'import_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/import.php'))->out(false),
    'search_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),

    // Sidebar active state.
    'is_dashboard' => false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => false,
    'is_manajemen_kelas' => false,
    'is_matpel' => true,
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
echo $OUTPUT->render_from_template('local_akademikmonitor/mata_pelajaran', $data);
echo $OUTPUT->footer();