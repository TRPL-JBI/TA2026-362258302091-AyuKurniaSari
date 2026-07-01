<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

global $PAGE, $OUTPUT, $DB;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/form.php', [
    'id' => $id,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($id ? 'Edit Mata Pelajaran' : 'Tambah Mata Pelajaran');
$PAGE->set_heading($id ? 'Edit Mata Pelajaran' : 'Tambah Mata Pelajaran');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

$record = null;

$data = [
    'id' => $id,
    'nama' => '',
    'kategori_custom' => '',
    'is_umum' => false,
    'is_kejuruan' => true,
    'is_lainnya' => false,
];

if ($id) {
    $record = $DB->get_record('mata_pelajaran', ['id' => $id], '*', MUST_EXIST);

    $rawname = (string) $record->nama_mapel;

    $kategori = 'kejuruan';

    if (preg_match('/^\[(.*?)\]\s*/', $rawname, $match)) {
        $kategori = strtolower(trim($match[1]));
    }

    $namabersih = preg_replace('/^\[.*?\]\s*/', '', $rawname);
    $namabersih = trim($namabersih);

    $data['id'] = $record->id;
    $data['nama'] = $namabersih;
    $data['is_umum'] = ($kategori === 'umum');
    $data['is_kejuruan'] = ($kategori === 'kejuruan');
    $data['is_lainnya'] = !in_array($kategori, ['umum', 'kejuruan'], true);
    $data['kategori_custom'] = $data['is_lainnya'] ? $kategori : '';
}

/**
 * Proses tambah/edit langsung di form.php.
 * Ini menggantikan save.php agar tidak terjadi 404 seperti sebelumnya.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $postid = optional_param('id', 0, PARAM_INT);
    $nama = required_param('nama', PARAM_TEXT);
    $kategori = required_param('kategori', PARAM_TEXT);
    $kategori_custom = optional_param('kategori_custom', '', PARAM_TEXT);

    $nama = trim($nama);
    $kategori = strtolower(trim($kategori));
    $kategori_custom = strtolower(trim($kategori_custom));

    if ($postid > 0) {
        $id = $postid;
    }

    if ($nama === '') {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/form.php', [
                'id' => $id,
            ]),
            'Nama mata pelajaran wajib diisi.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($kategori === 'lainnya') {
        if ($kategori_custom === '') {
            redirect(
                new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/form.php', [
                    'id' => $id,
                ]),
                'Kategori lainnya wajib diisi.',
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        $kategori = $kategori_custom;
    }

    /**
     * Simpan tetap memakai format lama:
     * [kategori] Nama Mapel
     *
     * Kenapa dipertahankan?
     * Karena struktur tabel mata_pelajaran hanya punya kolom nama_mapel,
     * dan fitur lama sudah membaca kategori dari prefix [kategori].
     * Jadi kita tidak perlu mengubah tabel database.
     */
    $namalengkap = '[' . $kategori . '] ' . $nama;

    if ($id) {
        $select = 'nama_mapel = :nama_mapel AND id <> :id';
        $params = [
            'nama_mapel' => $namalengkap,
            'id' => $id,
        ];

        $exists = $DB->record_exists_select('mata_pelajaran', $select, $params);
    } else {
        $exists = $DB->record_exists('mata_pelajaran', [
            'nama_mapel' => $namalengkap,
        ]);
    }

    if ($exists) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/form.php', [
                'id' => $id,
            ]),
            'Mata pelajaran dengan kategori tersebut sudah ada.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $save = new stdClass();
    $save->nama_mapel = $namalengkap;

    if ($id) {
        $save->id = $id;
        $DB->update_record('mata_pelajaran', $save);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
            'Data mata pelajaran berhasil diperbarui.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $DB->insert_record('mata_pelajaran', $save);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
        'Data mata pelajaran berhasil ditambahkan.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$data['title'] = $id ? 'Edit Mata Pelajaran' : 'Tambah Mata Pelajaran';
$data['breadcrumb'] = $id ? '/ Mata Pelajaran / Edit' : '/ Mata Pelajaran / Tambah';
$data['action_url'] = (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/form.php', [
    'id' => $id,
]))->out(false);
$data['back_url'] = (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false);
$data['sesskey'] = sesskey();

// Sidebar active state.
$data['is_dashboard'] = false;
$data['is_tahun_ajaran'] = false;
$data['is_kurikulum'] = false;
$data['is_manajemen_jurusan'] = false;
$data['is_manajemen_kelas'] = false;
$data['is_matpel'] = true;
$data['is_kktp'] = false;
$data['is_notif'] = false;
$data['is_ekskul'] = false;
$data['is_mitra'] = false;

// Sidebar URLs.
$data['dashboard_url'] = (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false);
$data['tahun_ajaran_url'] = (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false);
$data['kurikulum_url'] = (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false);
$data['manajemen_jurusan_url'] = (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false);
$data['manajemen_kelas_url'] = (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false);
$data['matpel_url'] = (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false);
$data['kktp_url'] = (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false);
$data['notif_url'] = (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false);
$data['ekskul_url'] = (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false);
$data['mitra_url'] = (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/mata_pelajaran_form', $data);
echo $OUTPUT->footer();