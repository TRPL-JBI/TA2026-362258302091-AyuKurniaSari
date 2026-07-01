<?php
require_once(__DIR__ . '/../../../config.php');

use local_akademikmonitor\service\access_service;

access_service::require_manage();

global $CFG, $DB, $PAGE, $OUTPUT;

$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('AkademikMonitoring');
$PAGE->set_heading('Akademik & Monitoring');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/dashboard.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');


$data = [
    'welcometitle' => 'Selamat Datang di Plugin Akademik & Monitoring',
    'welcomesubtitle' => 'SMK PGRI 2 Giri Banyuwangi',
    'welcomedesc' => 'Kelola data akademik, monitoring siswa, ekstrakurikuler, mitra DU/DI, dan kebutuhan administrasi sekolah dalam satu dashboard yang terintegrasi.',

    'cards' => [
        [
            'title' => 'Tahun Ajaran',
            'desc' => 'Kelola data tahun ajaran aktif dan riwayat periode akademik.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
            'icon' => '📅',
        ],
        [
            'title' => 'Kurikulum',
            'desc' => 'Atur data kurikulum yang digunakan dalam pengelolaan akademik.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
            'icon' => '📘',
        ],
        [
            'title' => 'Manajemen Jurusan',
            'desc' => 'Kelola data jurusan untuk kebutuhan struktur akademik sekolah.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
            'icon' => '🏫',
        ],
        [
            'title' => 'Manajemen Kelas',
            'desc' => 'Kelola pembagian kelas dan kebutuhan data kelas siswa.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
            'icon' => '🏷️',
        ],
        [
            'title' => 'Mata Pelajaran',
            'desc' => 'Kelola mata pelajaran yang terhubung dengan course dan kurikulum.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
            'icon' => '📚',
        ],
        [
            'title' => 'Import Mata Pelajaran',
            'desc' => 'Upload CSV untuk menambahkan banyak mata pelajaran sekaligus.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/import.php'))->out(false),
            'icon' => '📥',
        ],
        [
            'title' => 'Import Siswa',
            'desc' => 'Upload CSV untuk membuat atau memperbarui akun siswa tanpa keluar dari plugin.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/import_users.php', ['type' => 'siswa']))->out(false),
            'icon' => '👨‍🎓',
        ],
        [
            'title' => 'Import Guru',
            'desc' => 'Upload CSV untuk membuat atau memperbarui akun guru tanpa keluar dari plugin.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/import_users.php', ['type' => 'guru']))->out(false),
            'icon' => '👨‍🏫',
        ],
        [
            'title' => 'Pengaturan KKTP',
            'desc' => 'Kelola data KKTP untuk penilaian dan capaian kompetensi peserta didik.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
            'icon' => '📝',
        ],
        [
            'title' => 'Pengaturan Notifikasi',
            'desc' => 'Atur notifikasi sistem untuk mendukung informasi akademik.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
            'icon' => '🔔',
        ],
        [
            'title' => 'Ekstrakurikuler',
            'desc' => 'Kelola kegiatan ekstrakurikuler beserta pembina dan data pendukungnya.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
            'icon' => '🏅',
        ],
        [
            'title' => 'Mitra DU/DI',
            'desc' => 'Kelola data mitra dunia usaha dan dunia industri untuk kebutuhan PKL.',
            'url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
            'icon' => '🏢',
        ],
    ],

    // Flag sidebar.
    'is_dashboard' => true,
    'is_tahun_ajaran' => false,
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
echo $OUTPUT->render_from_template('local_akademikmonitor/dashboard', $data);
echo $OUTPUT->footer();