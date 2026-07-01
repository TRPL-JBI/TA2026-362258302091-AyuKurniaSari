<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\mapping_service;

require_login();

global $PAGE, $OUTPUT;

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);

$jurusanid = optional_param('jurusanid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$pageurl = new moodle_url('/local/akademikmonitor/pages/mapping/index.php');

if ($jurusanid > 0) {
    $pageurl->param('jurusanid', $jurusanid);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Mapping Course ke Mata Pelajaran');
$PAGE->set_heading('Mapping Course ke Mata Pelajaran');

$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');
$PAGE->requires->js_call_amd(
    'local_akademikmonitor/searchable',
    'init'
);
/**
 * Simpan mapping.
 */
if ($action === 'save') {
    require_sesskey();

    $courseid = required_param('courseid', PARAM_INT);
    $kmid = required_param('kmid', PARAM_INT);

    $saved = mapping_service::save_mapping($courseid, $kmid);

    if ($saved) {
        redirect(
            $pageurl,
            'Mapping course berhasil disimpan.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    redirect(
        $pageurl,
        'Mapping gagal disimpan. Pastikan course dan mata pelajaran sudah dipilih.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

/**
 * Hapus mapping.
 */
if ($action === 'delete') {
    require_sesskey();

    $courseid = required_param('courseid', PARAM_INT);

    mapping_service::delete_mapping($courseid);

    redirect(
        $pageurl,
        'Mapping course berhasil dihapus.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/**
 * Data halaman.
 */
$jurusan = mapping_service::get_jurusan($jurusanid);
$courses = mapping_service::get_courses();
$kmapel = mapping_service::get_kurikulum_mapel($jurusanid);
$mappings = mapping_service::get_existing_mappings($jurusanid);

$courseitems = [];

foreach ($courses as $course) {
    $courseitems[] = [
        'id' => (int)$course->id,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'idnumber' => $course->idnumber,
        'semester_label' => $course->semester_label,
        'semester_warning' => ((int)$course->semester === 0),
    ];
}

$kmapelitems = [];

foreach ($kmapel as $item) {
    $kmapelitems[] = [
        'id' => (int)$item->id,
        'label' => $item->label,
        'nama_mapel' => $item->nama_mapel,
        'nama_kurikulum' => $item->nama_kurikulum,
        'nama_jurusan' => $item->nama_jurusan,
        'kode_jurusan' => $item->kode_jurusan,
        'tingkat_kelas' => $item->tingkat_kelas,
        'kktp' => $item->kktp,
    ];
}

$mappingitems = [];
$no = 1;

foreach ($mappings as $mapping) {
    $deleteurl = new moodle_url('/local/akademikmonitor/pages/mapping/index.php', [
        'action' => 'delete',
        'courseid' => $mapping->courseid,
        'sesskey' => sesskey(),
    ]);

    if ($jurusanid > 0) {
        $deleteurl->param('jurusanid', $jurusanid);
    }

    $mappingitems[] = [
        'no' => $no++,
        'courseid' => (int)$mapping->courseid,
        'coursefullname' => $mapping->coursefullname,
        'courseshortname' => $mapping->courseshortname,
        'courseidnumber' => $mapping->courseidnumber,
        'mapellabel' => $mapping->mapellabel,
        'semester_label' => $mapping->semester_label,
        'semester_warning' => $mapping->semester_warning,
        'delete_url' => $deleteurl->out(false),
    ];
}

$backurl = new moodle_url('/local/akademikmonitor/pages/jurusan/index.php');

$templatecontext = [
    'sesskey' => sesskey(),
    'form_action' => $pageurl->out(false),

    'jurusanid' => $jurusanid,
    'has_jurusan' => $jurusan ? true : false,
    'nama_jurusan' => $jurusan ? format_string((string)$jurusan->nama_jurusan) : '',
    'kode_jurusan' => $jurusan ? format_string((string)($jurusan->kode_jurusan ?? '')) : '',

    'courses' => $courseitems,
    'has_courses' => !empty($courseitems),

    'kmapel' => $kmapelitems,
    'has_kmapel' => !empty($kmapelitems),

    'mappings' => $mappingitems,
    'has_mappings' => !empty($mappingitems),

    'back_url' => $backurl->out(false),

    /**
     * Flag sidebar admin.
     */
    'is_dashboard' => false,
    'is_tahun_ajaran' => false,
    'is_kurikulum' => false,
    'is_manajemen_jurusan' => true,
    'is_manajemen_kelas' => false,
    'is_matpel' => false,
    'is_kktp' => false,
    'is_notif' => false,
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
echo $OUTPUT->render_from_template('local_akademikmonitor/mapping', $templatecontext);
echo $OUTPUT->footer();